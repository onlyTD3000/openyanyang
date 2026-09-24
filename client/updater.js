/**
 * 客户端在线更新。
 *
 * 对接后台「软件控制」页：GET /api/version.php?cur=版本&plat=win
 * 返回 { update, force, latest, url, sha256, size, notes }
 *
 * 流程：查版本 → 下载到临时目录 → 校验 sha256 → 拉起安装程序 → 退出自己。
 *
 * 为什么不用 electron-updater：
 *   它要求服务端按固定格式提供 latest.yml 和签名文件，后台那套是自定义结构，
 *   接起来得反过来改服务端。这份手写的够用：NSIS 安装包能覆盖安装，
 *   装完自动重启，用户感知和自动更新差不多，只是多一次点击确认。
 *
 * 安全上有一条硬要求：sha256 不匹配必须删包并放弃。
 * 下载地址来自后台配置，万一被改成恶意地址，校验是唯一的拦截点。
 */
const { app, shell, dialog, BrowserWindow } = require('electron');
const path = require('path');
const stream = require('stream');   // pipeline：下载落盘用，自动串联错误
const fs = require('fs');
const https = require('https');
const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');
/** 安装包临时落地目录。用 temp 而非 userData：装完就没用了，交给系统清理。 */
const 包目录 = () => path.join(app.getPath('temp'), 'yanyang-update');
/**
 * 发一个 GET 请求，跟随重定向。
 * 后台下载地址可能配的是 CDN 或短链，302 很常见，不跟就拿不到文件。
 * @param {string} 地址
 * @param {number} [剩余跳数] 防重定向死循环
 */
function 请求(地址, 剩余跳数 = 5) {
  return new Promise((resolve, reject) => {
    let u;
    try { u = new URL(地址); } catch (e) { return reject(new Error('下载地址不合法')); }
    const 库 = u.protocol === 'http:' ? http : https;
    const req = 库.get(地址, { timeout: 20000 }, (res) => {
      const 码 = res.statusCode || 0;
      // 3xx 带 location 就继续跟
      if (码 >= 300 && 码 < 400 && res.headers.location) {
        res.resume();   // 必须消费掉响应体，否则连接不释放
        if (剩余跳数 <= 0) return reject(new Error('重定向次数过多'));
        // location 可能是相对路径，用当前地址做基准补全
        return 请求(new URL(res.headers.location, 地址).href, 剩余跳数 - 1)
          .then(resolve, reject);
      }
      if (码 !== 200) {
        res.resume();
        return reject(new Error('HTTP ' + 码));
      }
      resolve(res);
    });
    req.on('timeout', () => { req.destroy(); reject(new Error('连接超时')); });
    req.on('error', reject);
  });
}
/**
 * 问服务端有没有新版。
 * @param {string} 服务端  站点根地址
 * @returns {Promise<object>} 失败时返回 { error }，调用方按「没更新」处理
 */
async function 查版本(服务端) {
  const 地址 = 服务端.replace(/\/+$/, '') +
    '/api/version.php?cur=' + encodeURIComponent(app.getVersion()) +
    '&plat=win&_=' + Date.now();
  try {
    const res = await 请求(地址);
    let 正文 = '';
    res.setEncoding('utf8');
    for await (const 块 of res) { 正文 += 块; }
    const j = JSON.parse(正文);
    return j && j.ok ? j : { error: (j && j.error) || '返回格式异常' };
  } catch (e) {
    // 查更新失败不该打扰用户：网断了、服务端在维护都会走到这儿
    return { error: e.message };
  }
}
/**
 * 下载安装包并校验 sha256。
 * @param {object} 信息 查版本的返回
 * @param {function} [报进度] 收到 (已下载字节, 总字节) 回调，用来画进度条
 * @returns {Promise<string>} 校验通过的本地文件路径
 */
async function 下载(信息, 报进度) {
  if (!信息.url) throw new Error('后台没有配置下载地址');
  const 目录 = 包目录();
  fs.mkdirSync(目录, { recursive: true });
  // 文件名带版本号，不同版本的包不会互相覆盖
  const 目标 = path.join(目录, 'yanyang-setup-' + (信息.latest || 'new') + '.exe');
  // 先下到 .part，校验通过才改名。中途崩了不会留个半截包被当成完整的用
  const 临时 = 目标 + '.part';
  const res = await 请求(信息.url);
  const 总长 = parseInt(res.headers['content-length'], 10) || 信息.size || 0;
  /* 必须等 close 而不是 finish。
     finish 只表示 end() 调过了、数据交给了操作系统，文件未必落盘；
     紧接着去算哈希会读到写了一半的内容，校验必然失败——
     表现就是进度条走到 100% 才报「安装包校验失败」。
     用 pipeline 一并接管两端的错误，省掉手接 error 时重复 reject 的坑。 */
  await new Promise((resolve, reject) => {
    const 写 = fs.createWriteStream(临时, { flags: 'w' });
    let 已下 = 0;
    res.on('data', (块) => {
      已下 += 块.length;
      if (报进度) 报进度(已下, 总长);
    });
    写.on('close', resolve);
    stream.pipeline(res, 写, (err) => { if (err) reject(err); });
  });
  /* 先比大小再比哈希。两者都能发现下载不完整，但大小对不上时报出具体字节数，
     比只说一句「校验失败」好定位——少了就是传输被截断，多了通常是代理插了东西。 */
  const 实大小 = fs.statSync(临时).size;
  const 期大小 = parseInt(信息.size, 10) || 0;
  if (期大小 > 0 && 实大小 !== 期大小) {
    try { fs.unlinkSync(临时); } catch (e) {}
    throw new Error('下载不完整：应为 ' + 期大小 + ' 字节，实际收到 ' + 实大小 +
                    ' 字节。可能是网络中断或代理干扰，重试一次通常就好了');
  }
  // 校验 sha256。后台没填就跳过——不理想但不该因此卡住更新，
  // 毕竟传输走的是 HTTPS，已经有一层保护。
  const 期望 = String(信息.sha256 || '').trim().toLowerCase();
  if (期望) {
    const 实际 = await 算哈希(临时);
    if (实际 !== 期望) {
      try { fs.unlinkSync(临时); } catch (e) {}
      // 把两个哈希都带上，排查时能直接看出是文件被换过还是下载损坏
      throw new Error('安装包校验失败，已丢弃。\n期望 ' + 期望 + '\n实际 ' + 实际);
    }
  }
  // 目标已存在（上次下过同版本）先删，rename 在 Windows 上不会覆盖已存在的文件
  try { if (fs.existsSync(目标)) fs.unlinkSync(目标); } catch (e) {}
  fs.renameSync(临时, 目标);
  return 目标;
}
/** 算文件 sha256。流式读，避免大文件一次性进内存。 */
function 算哈希(文件) {
  return new Promise((resolve, reject) => {
    const h = crypto.createHash('sha256');
    const 读 = fs.createReadStream(文件);
    读.on('data', (块) => h.update(块));
    读.on('end', () => resolve(h.digest('hex')));
    读.on('error', reject);
  });
}
/**
 * 拉起安装程序并退出自己。
 *
 * 必须退出：NSIS 覆盖安装时要替换正在运行的 exe，进程不退会被文件占用挡住。
 * openPath 是异步的，等它返回再退，否则可能还没真正启动就把自己杀了。
 */
async function 装(包路径) {
  const 错 = await shell.openPath(包路径);
  if (错) throw new Error('启动安装程序失败：' + 错);
  // 给系统一点时间把安装程序拉起来，再退出
  setTimeout(() => app.quit(), 1200);
}
/**
 * 完整的更新流程，带界面交互。
 *
 * @param {string} 服务端
 * @param {object} [选项]
 * @param {boolean} [选项.静默] true 时没有新版就不弹窗（启动自检用）。
 *        手动点「检查更新」要走 false，否则用户点了没反应会以为坏了。
 */
/* 进程级并发锁。
   入口有两个：启动时的静默自动检查（main.js 里那次）和用户手动点按钮。
   渲染层按钮上的「在查」标志只锁得住按钮自己那一路，管不到自动检查，
   所以两者会同时开下载：两个流各自累加已下字节往同一个按钮报进度，
   数字就来回乱跳；更糟的是它们写同一个 .part 文件，内容互相踩烂，
   最后 sha256 必然校验失败。锁必须放在这里，这是两条入口的唯一交汇点。 */
let 在跑 = null;
async function 检查更新(服务端, 选项 = {}) {
  /* 已经有一轮在跑（通常是启动时那次静默自检）：不再开第二个下载，
     等它跑完就行。但手动点的这次要给用户一个回应——直接复用静默那轮的
     Promise 会让用户点了完全没反应，以为按钮坏了。 */
  if (在跑) {
    const 结果 = await 在跑;
    /* 只有「确认没有新版」这一种情况需要补提示：静默轮不弹窗，用户点了会没反应。
       其余情况（发现新版、下载完、失败）静默轮都已经弹过窗跟用户交互了，
       这里再弹就是两个窗叠着。 */
    if (!选项.静默 && 结果 && 结果.ok === true && 结果.update === 0) {
      await dialog.showMessageBox(BrowserWindow.getAllWindows()[0] || null, {
        type: 'info', title: '检查更新',
        message: '已经是最新版本',
        detail: '当前版本 ' + app.getVersion(), buttons: ['好']
      });
    }
    return 结果;
  }
  在跑 = 真检查更新(服务端, 选项).finally(() => { 在跑 = null; });
  return 在跑;
}
async function 真检查更新(服务端, 选项 = {}) {
  const 静默 = !!选项.静默;
  const 窗 = BrowserWindow.getAllWindows()[0] || null;
  const 信息 = await 查版本(服务端);
  if (信息.error) {
    if (!静默) {
      await dialog.showMessageBox(窗, {
        type: 'warning', title: '检查更新',
        message: '没查到更新信息',
        detail: 信息.error, buttons: ['知道了']
      });
    }
    return { ok: false, msg: 信息.error };
  }
  if (!信息.update) {
    if (!静默) {
      await dialog.showMessageBox(窗, {
        type: 'info', title: '检查更新',
        message: '已经是最新版本',
        detail: '当前版本 ' + app.getVersion(), buttons: ['好']
      });
    }
    return { ok: true, update: 0 };
  }
  // 强制更新只给一个按钮，但仍允许关窗口——不能把用户锁死在弹窗里，
  // 万一下载地址配错了，用户连退出都做不到。
  const 强制 = !!信息.force;
  const 按钮 = 强制 ? ['立即更新', '退出程序'] : ['立即更新', '以后再说'];
  const 选 = await dialog.showMessageBox(窗, {
    type: 'info',
    title: '发现新版本',
    message: '新版本 ' + 信息.latest + ' 可用',
    detail: (信息.notes ? 信息.notes + '\n\n' : '') +
            '当前版本 ' + app.getVersion() +
            (强制 ? '\n\n这是必须更新的版本，旧版本可能无法正常使用。' : ''),
    buttons: 按钮,
    defaultId: 0,
    cancelId: 强制 ? -1 : 1   // 强制更新时禁掉 Esc 关闭
  });
  if (选.response !== 0) {
    // 强制更新选了「退出程序」
    if (强制) app.quit();
    return { ok: true, update: 1, 跳过: 1 };
  }
  // 下载。进度推给渲染层画进度条，没有窗口时就只走主进程。
  try {
    const 包 = await 下载(信息, (已下, 总长) => {
      if (窗 && !窗.isDestroyed()) {
        窗.webContents.send('更新:进度', { 已下, 总长 });
        // 任务栏进度条，Windows 上直接显示在图标上，比窗口内的进度条显眼
        if (总长 > 0) 窗.setProgressBar(已下 / 总长);
      }
    });
    if (窗 && !窗.isDestroyed()) 窗.setProgressBar(-1);   // -1 = 收起进度条
    const 装吗 = await dialog.showMessageBox(窗, {
      type: 'info', title: '下载完成',
      message: '新版本已下载，现在安装吗？',
      detail: '安装时程序会先退出，装完会自动打开新版本。',
      buttons: ['现在安装', '稍后手动安装'], defaultId: 0
    });
    if (装吗.response === 0) {
      await 装(包);
      return { ok: true, 已装: 1 };
    }
    // 用户选稍后，把包所在目录打开，省得他找不到文件
    shell.showItemInFolder(包);
    return { ok: true, 下载完: 1, 路径: 包 };
  } catch (e) {
    if (窗 && !窗.isDestroyed()) 窗.setProgressBar(-1);
    await dialog.showMessageBox(窗, {
      type: 'error', title: '更新失败',
      message: '更新没能完成',
      detail: e.message + '\n\n可以稍后重试，或到官网手动下载安装包。',
      buttons: ['知道了']
    });
    return { ok: false, msg: e.message };
  }
}
module.exports = { 检查更新, 查版本, 当前版本: () => app.getVersion() };
