'use strict';
/**
 * 客户端本地 SSH。跑在主进程。
 *
 * 存在的理由：以前 SSH 由服务端代连，目标机的日志里留下的是本站源站 IP，
 * 等于把服务器地址送给每一个绑过主机的用户。改成客户端自己连之后，
 * 目标机看到的是用户自己的出口 IP，本站完全不参与连接。
 *
 * 远端 job 文件的约定与服务端 inc/ssh_run.php 保持一致（.log/.pid/.rc），
 * 好处是同一台机器上网页端起的任务、客户端也读得到，反之亦然，
 * 用户在两端之间切换不会看到任务凭空消失。改这里之前先看那边。
 */
const { Client } = require('ssh2');
const crypto = require('crypto');
/* 与服务端 SSH_TIMEOUT / SSH_JOB_DIR 对齐。这两个值改了要两边一起改，
   否则同一个任务在两端表现不一致。 */
const 辅助超时毫秒 = 30000;  // 内部辅助命令（起任务、读进度）的往返超时，与 SSH_TIMEOUT 无关
/* 用户命令一律走 job 机制在远端后台跑，客户端不给它设超时上限，
   跟服务端 SSH_TIMEOUT=240 的同步执行路径不同：那个限制来自 HTTP 生命周期，
   本地长连接没有这个天花板，编译几小时也不会被掐断。 */
const JOB目录 = '/tmp/.kiro_jobs';
const 输出上限 = 40000;      // 与服务端一致，超了截断并提示
/* 连接池。键是主机 id，值是 { conn, 用中, 空闲起点 }。
   复用连接的原因：一次 AI 对话里常常连续跑十几条命令，
   每条都重新握手加认证要多花几百毫秒，密码认证的机器更慢。 */
const 池 = new Map();
/* 空闲超过这个时长就断开。留着不断是因为紧接着很可能还有下一条命令；
   但也不能永久留，用户合上笔记本睡一夜，那条连接早就是死的了。 */
const 空闲上限 = 3 * 60 * 1000;
setInterval(() => {
  const 现在 = Date.now();
  for (const [id, 项] of 池) {
    if (!项.用中 && 现在 - 项.空闲起点 > 空闲上限) {
      try { 项.conn.end(); } catch (e) {}
      池.delete(id);
    }
  }
}, 30 * 1000).unref();
/**
 * 算主机公钥指纹。必须与服务端 ssh_fingerprint_of() 完全一致：
 * SHA1(公钥 blob) 的十六进制大写。算法不一致会让用户已存的指纹全部失效，
 * 每台主机都提示「指纹变了、可能被中间人攻击」，那是误报但很吓人。
 */
function 算指纹(公钥blob) {
  if (!公钥blob || !公钥blob.length) { return ''; }
  return crypto.createHash('sha1').update(公钥blob).digest('hex').toUpperCase();
}
/** shell 单引号转义。用户的命令原样带过去，不做任何改写。 */
function 引(s) {
  return "'" + String(s).replace(/'/g, "'\\''") + "'";
}
/**
 * 建立一条连接。凭据由调用方从服务端取来，这里不碰网络请求。
 *
 * @param 凭据 { host, port, username, auth_type, secret, key_pass, fingerprint }
 * @returns { ok, conn, fingerprint, error, 指纹不符 }
 */
function 连(凭据) {
  return new Promise((完成) => {
    const c = new Client();
    let 实测指纹 = '';
    let 已回 = false;
    const 回 = (r) => { if (!已回) { 已回 = true; 完成(r); } };
    c.on('ready', () => 回({ ok: true, conn: c, fingerprint: 实测指纹, error: '' }));
    c.on('error', (e) => {
      const m = (e && e.message) || String(e);
      let 说明 = m;
      // 把 ssh2 的英文错误换成用户看得懂的话
      if (/All configured authentication methods failed/i.test(m)) {
        说明 = '认证失败，请检查用户名与密码/私钥';
      } else if (/ECONNREFUSED/i.test(m)) {
        说明 = '连接被拒绝，请确认 SSH 端口是否正确、服务是否在运行';
      } else if (/ETIMEDOUT|Timed out/i.test(m)) {
        说明 = '连接超时，请确认地址、端口和防火墙';
      } else if (/ENOTFOUND|EAI_AGAIN/i.test(m)) {
        说明 = '域名解析失败，请检查主机地址';
      } else if (/Cannot parse privateKey|OpenSSH.*format/i.test(m)) {
        说明 = '私钥解析失败，可能格式不对或口令错误';
      }
      try { c.end(); } catch (x) {}
      回({ ok: false, conn: null, fingerprint: 实测指纹, error: 说明 });
    });
    const 配置 = {
      host: 凭据.host,
      port: Number(凭据.port) || 22,
      username: 凭据.username,
      readyTimeout: 20000,
      /* 主机密钥算法顺序必须与服务端（inc/ssh_run.php 里 setPreferredAlgorithms）
         逐项一致，ssh-rsa 打头。
         这不是可选的兼容性调优，而是正确性要求：一台机器通常同时有 RSA 和
         ED25519 两把主机密钥，指纹算的是协商出来的那一把。服务端为兼容历史
         记录把 ssh-rsa 排在最前，而 ssh2 默认偏好 ed25519 —— 顺序不一致时
         同一台机器会算出两个完全不同的指纹，已登记的主机会被误判成中间人攻击
         而拒绝连接。改这里之前先确认服务端那份顺序没变。 */
      algorithms: {
        serverHostKey: ['ssh-rsa', 'ssh-dss', 'ecdsa-sha2-nistp256', 'ssh-ed25519'],
      },
      keepaliveInterval: 15000,
      keepaliveCountMax: 4,
      /* 指纹校验。首次连接（服务端存的指纹为空）时放过并把实测值带回去，
         由调用方回存到服务端；之后每次都比对，不符就断开。 */
      hostVerifier: (key) => {
        实测指纹 = 算指纹(key);
        const 已存 = String(凭据.fingerprint || '').trim().toUpperCase();
        if (已存 === '') { return true; }
        if (已存 === 实测指纹) { return true; }
        回({
          ok: false, conn: null, fingerprint: 实测指纹, 指纹不符: true,
          error: '主机指纹与记录不符，连接已中断。可能是服务器重装过系统，'
               + '也可能是中间人攻击。确认无误后请在主机管理里清空指纹重连。'
        });
        return false;
      }
    };
    if (凭据.auth_type === 'key') {
      配置.privateKey = 凭据.secret;
      if (凭据.key_pass) { 配置.passphrase = 凭据.key_pass; }
    } else {
      配置.password = 凭据.secret;
      // 有些机器只开了 keyboard-interactive，密码认证要走这条才过
      配置.tryKeyboard = true;
      c.on('keyboard-interactive', (n, i, il, prompts, 交) => 交([凭据.secret]));
    }
    try {
      c.connect(配置);
    } catch (e) {
      回({ ok: false, conn: null, fingerprint: '', error: '发起连接出错：' + ((e && e.message) || e) });
    }
  });
}
/** 从池里拿一条可用连接，没有就新建。 */
async function 取连接(主机id, 凭据) {
  const 项 = 池.get(主机id);
  if (项) {
    项.用中 = true;
    return { ok: true, conn: 项.conn, fingerprint: '', error: '', 复用: true };
  }
  const r = await 连(凭据);
  if (r.ok) {
    池.set(主机id, { conn: r.conn, 用中: true, 空闲起点: Date.now() });
    // 连接掉了就从池里摘掉，下次自动重连，不要留个死连接反复用
    r.conn.on('close', () => { 池.delete(主机id); });
    r.conn.on('end', () => { 池.delete(主机id); });
  }
  return r;
}
function 归还(主机id) {
  const 项 = 池.get(主机id);
  if (项) { 项.用中 = false; 项.空闲起点 = Date.now(); }
}
/** 在已连上的通道上跑一条命令，收全部输出。用于短命令和内部辅助命令。 */
function 执行(conn, cmd, 超时毫秒) {
  return new Promise((完成) => {
    const t0 = Date.now();
    let 已回 = false;
    const 回 = (r) => { if (!已回) { 已回 = true; 完成(r); } };
    let 计时 = null;
    conn.exec(cmd, { pty: false }, (e, s) => {
      if (e) {
        return 回({ ok: false, out: '', exit: -1, ms: Date.now() - t0,
                    error: '命令下发失败：' + ((e && e.message) || e) });
      }
      let out = '';
      let 码 = 0;
      const 收 = (d) => {
        out += d.toString('utf8');
        // 超上限就不再往内存里堆，避免 cat 大文件把客户端撑爆
        if (out.length > 输出上限 * 2) { out = out.slice(0, 输出上限 * 2); }
      };
      s.on('data', 收);
      s.stderr.on('data', 收);
      s.on('exit', (c) => { 码 = (c === null || c === undefined) ? 0 : c; });
      s.on('close', () => {
        if (计时) { clearTimeout(计时); }
        let 文 = out;
        if (文.length > 输出上限) {
          文 = 文.slice(0, 输出上限) + '\n…（输出超过 ' + 输出上限 + ' 字符已截断）';
        }
        回({ ok: true, out: 文, exit: 码, ms: Date.now() - t0, error: '' });
      });
      if (超时毫秒 > 0) {
        计时 = setTimeout(() => {
          try { s.close(); } catch (x) {}
          回({ ok: false, out: out.slice(0, 输出上限), exit: -1,
               ms: Date.now() - t0, error: '命令执行超时' });
        }, 超时毫秒);
      }
    });
  });
}
/**
 * 起一个后台任务。约定与服务端 ssh_job_start() 一致：
 * 日志写 .log，PID 写 .pid，退出码最后写 .rc（前端靠 .rc 判断是否跑完）。
 * stdin 接 /dev/null，交互式命令会立刻收到 EOF 而不是永远挂着等输入。
 */
async function 起任务(conn, cmd) {
  const job = 'j' + Date.now().toString(36) + crypto.randomBytes(4).toString('hex');
  const log = JOB目录 + '/' + job + '.log';
  const pid = JOB目录 + '/' + job + '.pid';
  const rc  = JOB目录 + '/' + job + '.rc';
  const 内 = 'exec </dev/null >' + 引(log) + ' 2>&1; '
           + 'bash -lc ' + 引(cmd) + '; '
           + 'echo $? > ' + 引(rc);
  /* 后台进程必须把 stdin/stdout/stderr 全部从 SSH exec 通道上摘掉。
     只在内层 exec 重定向不够：setsid 启动那一刻就继承了通道的 fd，
     而通道要等所有持有它的进程退出才 close，于是 执行() 会一直挂着，
     起任务 变成同步等待（实测 6 秒的命令要等 6.8 秒才返回），
     轮询拿不到任何中间输出，实时进度全废。
     这里在外层就重定向到 /dev/null，让后台进程与通道彻底脱钩。 */
  const 外 = 'mkdir -p -m 700 ' + 引(JOB目录) + ' && '
           + '{ setsid bash -c ' + 引(内) + ' </dev/null >/dev/null 2>&1 & '
           + 'echo $! > ' + 引(pid) + '; } ; sleep 0.2; echo __JOB_OK__';
  const r = await 执行(conn, 外, 30000);
  if (!r.ok || r.out.indexOf('__JOB_OK__') < 0) {
    return { ok: false, job: '', error: r.error || ('任务启动失败：' + (r.out || '').trim().slice(0, 200)) };
  }
  return { ok: true, job, error: '' };
}
/** 读任务进度。from 是已收到的字节数，只取后面新增的部分。 */
async function 读任务(conn, job, from) {
  const log = JOB目录 + '/' + job + '.log';
  const pid = JOB目录 + '/' + job + '.pid';
  const rc  = JOB目录 + '/' + job + '.rc';
  /* 一次往返取齐四样：新增输出、日志总长、退出码、进程是否还活着。
     用分隔标记切开，比分四条命令跑省三个往返。 */
  const cmd = 'tail -c +' + (Number(from) + 1) + ' ' + 引(log) + ' 2>/dev/null; '
            + 'echo "__SEP__"; wc -c < ' + 引(log) + ' 2>/dev/null || echo 0; '
            + 'echo "__SEP__"; cat ' + 引(rc) + ' 2>/dev/null || echo ""; '
            + 'echo "__SEP__"; if [ -f ' + 引(pid) + ' ] && kill -0 "$(cat ' + 引(pid) + ')" 2>/dev/null; '
            + 'then echo 1; else echo 0; fi';
  const r = await 执行(conn, cmd, 30000);
  if (!r.ok) { return { ok: false, error: r.error }; }
  const 段 = r.out.split('__SEP__');
  const 新增 = 段[0] || '';
  const 总长 = parseInt((段[1] || '0').trim(), 10) || 0;
  const 码文 = (段[2] || '').trim();
  const 活 = (段[3] || '').trim() === '1';
  const 完了 = 码文 !== '';
  return {
    ok: true, done: 完了, exit: 完了 ? (parseInt(码文, 10) || 0) : -3,
    out: 新增, size: 总长, next: Number(from) + Buffer.byteLength(新增, 'utf8'),
    alive: 活, error: ''
  };
}
/** 终止任务。杀整个进程组，孤儿进程不会留下继续跑。 */
async function 停任务(conn, job) {
  const pid = JOB目录 + '/' + job + '.pid';
  const rc  = JOB目录 + '/' + job + '.rc';
  const cmd = 'p="$(cat ' + 引(pid) + ' 2>/dev/null)"; '
            + 'if [ -n "$p" ]; then kill -TERM -"$p" 2>/dev/null || kill -TERM "$p" 2>/dev/null; '
            + 'sleep 0.3; kill -KILL -"$p" 2>/dev/null || true; fi; '
            + '[ -f ' + 引(rc) + ' ] || echo 143 > ' + 引(rc) + '; echo __KILLED__';
  const r = await 执行(conn, cmd, 20000);
  return { ok: r.ok && r.out.indexOf('__KILLED__') >= 0, error: r.error || '' };
}
/** 清掉一天前的残留任务文件。跟服务端 ssh_job_gc 一个意思。 */
async function 清残留(conn) {
  const cmd = '[ -d ' + 引(JOB目录) + ' ] && find ' + 引(JOB目录)
            + ' -maxdepth 1 -type f -mtime +1 -delete 2>/dev/null; echo ok';
  await 执行(conn, cmd, 15000).catch(() => {});
}
/** 断开某台主机的连接。用户改了主机配置或删主机时调。 */
function 断开(主机id) {
  const 项 = 池.get(主机id);
  if (项) {
    try { 项.conn.end(); } catch (e) {}
    池.delete(主机id);
  }
}
/** 全部断开。退出应用时调，不留悬挂连接。 */
function 全断() {
  for (const [id, 项] of 池) {
    try { 项.conn.end(); } catch (e) {}
  }
  池.clear();
}
/* ================= 交互式终端 =================
   终端不用上面那个连接池，每个终端窗口独占一条连接。
   原因：池里的连接空闲 3 分钟就断，而终端可能开着一整天不敲一个字，
   复用池会把用户正开着的终端掐掉。另外终端的生命周期由窗口决定，
   跟命令卡片那种「用完就归还」的模型不是一回事。 */
const 终端会话 = new Map();   // 会话号 -> { conn, stream }
/**
 * 开一个交互式 shell。
 * @param 会话号 调用方生成的唯一标识，通常用终端窗口的 webContents.id
 * @param 凭据   与 连() 相同的字段：host/port/username/auth_type/secret/fingerprint
 * @param 回调   { 出数据, 关闭 }，主进程用它把数据推给终端页
 */
async function 开终端(会话号, 凭据, 回调) {
  关终端(会话号);           // 同号重开先清掉旧的，避免连接泄漏
  const c = await 连(凭据);
  if (!c.ok) { return { ok: false, error: c.error, 指纹不符: !!c.指纹不符, fingerprint: c.fingerprint }; }
  return new Promise((完成) => {
    /* term=xterm-256color 让远端启用 256 色，跟 xterm.js 的能力对得上。
       尺寸给个初值，页面 fit 完会立刻发一次 resize 覆盖掉。 */
    c.conn.shell({ term: 'xterm-256color', cols: 80, rows: 24 }, (err, stream) => {
      if (err) {
        try { c.conn.end(); } catch (x) {}
        完成({ ok: false, error: '开终端失败：' + (err.message || err) });
        return;
      }
      终端会话.set(会话号, { conn: c.conn, stream });
      /* shell 的 stdout 和 stderr 都要转发。
         交互式程序（vim、top）的绘制序列可能走 stderr，漏了会花屏。
         按 binary 字符串转发而不是 utf8 解码：一个多字节汉字可能被
         拆到两次 data 事件里，提前解码会得到乱码，交给 xterm.js 自己拼。 */
      stream.on('data', (d) => 回调.出数据(d.toString('binary')));
      if (stream.stderr) { stream.stderr.on('data', (d) => 回调.出数据(d.toString('binary'))); }
      stream.on('close', () => { 关终端(会话号); 回调.关闭(); });
      c.conn.on('close', () => { 终端会话.delete(会话号); 回调.关闭(); });
      完成({ ok: true, fingerprint: c.fingerprint, user: 凭据.username, host: 凭据.host, error: '' });
    });
  });
}
/** 把用户的按键写进 shell。
    输入按 utf8 写，不能用 binary：binary 会把汉字这类多字节字符截断，
    输入「中文」到远端会变成乱码，还可能蹦出 ! 之类的字符让 bash 报
    event not found。这跟输出方向是不对称的——输出保持 binary，
    因为一个汉字的 UTF-8 字节可能被拆到两次 data 事件里，
    提前解码必然乱码，要交给 xterm.js 自己拼。 */
function 写终端(会话号, 数据) {
  const 项 = 终端会话.get(会话号);
  if (!项) { return false; }
  try { 项.stream.write(Buffer.from(String(数据), 'utf8')); return true; }
  catch (e) { return false; }
}
/** 窗口大小变了要同步给远端，否则 vim/top 的排版会错位。 */
function 改尺寸(会话号, cols, rows) {
  const 项 = 终端会话.get(会话号);
  if (!项) { return false; }
  try { 项.stream.setWindow(Number(rows) || 24, Number(cols) || 80, 0, 0); return true; }
  catch (e) { return false; }
}
/** 关掉一个终端会话，连接一起断。窗口关闭时必须调，否则连接泄漏。 */
function 关终端(会话号) {
  const 项 = 终端会话.get(会话号);
  if (!项) { return; }
  终端会话.delete(会话号);
  try { 项.stream.end(); } catch (e) {}
  try { 项.conn.end(); } catch (e) {}
}
module.exports = {
  连, 取连接, 归还, 执行, 起任务, 读任务, 停任务, 清残留, 断开, 全断,
  开终端, 写终端, 改尺寸, 关终端,
  算指纹, 辅助超时毫秒, 输出上限, JOB目录
};
