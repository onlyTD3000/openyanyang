/* SSH 终端窗口。
   两条连接路径，客户端优先走直连：
   直连（客户端）—— window.终端桥.直连开 存在时走这条。主进程用 ssh2 直接
   连目标机开交互式 shell，本站完全不参与，目标机 sshd 日志里记录的是用户
   自己的出口 IP。按键经 直连写 推上去，回显靠 收数据 订阅推下来。
   网关（网页端或旧版主进程）—— 上面那个通道不存在时回落。先用 Bearer 密钥
   向 /api/wsssh_token.php 换一张一次性令牌，再拿令牌连 wss:///ws/。
   令牌 60 秒内用一次即废，所以每次连接都重新取。
   协议顺序服务端有约束：auth 通过后才能 connect，反了会被拒。
   这个分支是客户端专属逻辑，从网页端覆盖本文件时要保留，
   否则终端会退回服务端代连，目标机日志里又会出现本站源站 IP。 */
/* 异常兜底：任何未捕获错误都直接写到状态栏。
   不加这层的时候，建终端阶段报错会让状态栏永远停在初始文案，
   看上去像“一直在连”，实际上脚本早就挂了。 */
window.addEventListener('error', (ev) => {
  const 元 = document.getElementById('状态文');
  const 点 = document.getElementById('状态点');
  if (元) { 元.textContent = '脚本出错：' + (ev.message || '未知') + ' @' + (ev.filename || '').split('/').pop() + ':' + ev.lineno; }
  if (点) { 点.className = '出错'; }
});
window.addEventListener('unhandledrejection', (ev) => {
  const 元 = document.getElementById('状态文');
  const 点 = document.getElementById('状态点');
  const 原因 = ev.reason && (ev.reason.message || ev.reason);
  if (元) { 元.textContent = '异步出错：' + 原因; }
  if (点) { 点.className = '出错'; }
});
const 元状态点 = document.getElementById('状态点');
const 元状态文 = document.getElementById('状态文');
const 元重连 = document.getElementById('重连钮');
const 元终端 = document.getElementById('终端');
let 终端 = null;
let 适配 = null;
let 连接 = null;
let 参数 = null;      // { ws地址, 主机id, 主机名 }
let 已连上 = false;
let 主动关 = false;   // 窗口关闭时置真，避免触发「已断开」的红字
/* 走不走直连。桥上有 直连开 就走，没有就回落网关。
   判断只做一次并存下来，避免每次发数据都探测一遍。 */
const 直连 = !!(window.终端桥 && window.终端桥.直连开);
let 退订数据 = null;   // 直连的订阅退订函数，重连前要先调，否则监听器越堆越多
let 退订断开 = null;
/* 能不能往远端发数据。两条路径的判据不一样：
   直连只看 已连上（IPC 通道不会「断」，会话在主进程侧）；
   网关还要看 WebSocket 是不是 OPEN。 */
function 可发() {
  if (直连) { return 已连上; }
  return !!(连接 && 连接.readyState === WebSocket.OPEN && 已连上);
}
/* 把用户输入送到远端。两条路径的封装不同，统一在这里分流，
   调用方（按键、粘贴）不用各自判断走哪条。 */
function 发输入(数据) {
  if (!可发()) { return; }
  if (直连) { window.终端桥.直连写(数据); }
  else { 连接.send(JSON.stringify({ action: 'input', data: 数据 })); }
}
/* 告诉远端终端尺寸变了。不同步的话 vim/top 这类全屏程序排版会错位。 */
function 发尺寸(cols, rows) {
  if (!可发()) { return; }
  if (直连) { window.终端桥.直连尺寸(cols, rows); }
  else { 连接.send(JSON.stringify({ action: 'resize', cols: cols, rows: rows })); }
}
function 报状态(文, 类) {
  元状态文.textContent = 文;
  元状态点.className = 类 || '';
}
function 报错(文) {
  报状态('连接失败：' + 文, '出错');
  元重连.hidden = false;
  if (终端) { 终端.writeln('\r\n\x1b[31m[错误] ' + 文 + '\x1b[0m'); }
}
/* 读剪贴板并发给服务端。
   直接走 onData 那条路会绕开「已连上」判断，所以这里自己判一次。
   剪贴板由主进程读（见 preload-term.js 里的说明），不受 file:// 源的权限限制。
   多行内容原样发：终端里粘贴多行命令就是要它逐行执行。 */
async function 粘贴() {
  if (!可发()) return;
  try {
    const 文本 = await window.终端桥.读剪贴板();
    if (文本) 发输入(文本);
  } catch (e) {
    // 读不到剪贴板不该弹窗打断操作，写一行提示就够
    终端.write('\r\n\x1b[33m[无法读取剪贴板：' + (e.message || e) + ']\x1b[0m\r\n');
  }
}
/* 终端只建一次，重连时复用同一个实例，历史输出留着方便对照 */
function 建终端() {
  if (终端) return;
  终端 = new Terminal({
    fontFamily: 'Consolas, "Courier New", monospace',
    fontSize: 13,
    cursorBlink: true,
    scrollback: 5000,
    theme: {
      background: '#12141a', foreground: '#d4d8de', cursor: '#d4d8de',
      selectionBackground: '#3a4152'
    }
  });
  适配 = new FitAddon.FitAddon();
  终端.loadAddon(适配);
  终端.open(元终端);
  适配.fit();
  // 键盘输入转发到服务端。没连上时丢弃，不然会攒一堆无处可去的字符
  终端.onData((数据) => 发输入(数据));
  /* 剪贴板。xterm 自己不接管系统剪贴板，不绑的话 Ctrl+V 完全没反应。
     Ctrl+C 在终端里是中断信号（发 SIGINT），不能拿去做复制，
     所以复制用 Ctrl+Shift+C；粘贴两个都收，Ctrl+V 是大家的肌肉记忆，
     Ctrl+Shift+V 是 Linux 终端的习惯。
     返回 false 表示这个按键已被接管，不要再往服务端转发。 */
  终端.attachCustomKeyEventHandler((事件) => {
    if (事件.type !== 'keydown' || !事件.ctrlKey) return true;
    const 键 = 事件.key.toLowerCase();
    // 复制：有选中内容才拦，没选中时放行，免得挡了别的用途
    if (事件.shiftKey && 键 === 'c') {
      const 选中 = 终端.getSelection();
      if (选中) { window.终端桥.写剪贴板(选中); return false; }
      return true;
    }
    if (键 === 'v') { 粘贴(); return false; }
    return true;
  });
  /* 右键直接粘贴，跟 PuTTY 的习惯一致。
     有选中内容时右键改成复制，这样不用记快捷键也能来回搬。 */
  元终端.addEventListener('contextmenu', (事件) => {
    事件.preventDefault();
    const 选中 = 终端.getSelection();
    if (选中) { window.终端桥.写剪贴板(选中); 终端.clearSelection(); }
    else { 粘贴(); }
  });
  // 窗口大小变了要告诉服务端，否则 vim/top 这类全屏程序的排版会错位
  window.addEventListener('resize', () => {
    if (!适配) return;
    适配.fit();
    发尺寸(终端.cols, 终端.rows);
  });
}
/* 取令牌。这个页面是 file:// 源，自己发 https 请求会被判跨源，
   所以交给主进程代发（它用 Bearer 鉴权，服务端对 Token 请求会跳过 CSRF）。 */
async function 取令牌() {
  // 桥不在说明 preload 没加载成功，早点说清楚，别让页面干等
  if (!window.终端桥 || !window.终端桥.要令牌) {
    throw new Error('内部通道未就绪（preload 未加载），请重装客户端');
  }
  // 三重保险：
  // 1) invoke 本身可能 reject（主进程 handler 抛异常），必须 catch，
  //    否则 await 永远悬着，页面就一直停在「正在取握手令牌…」
  // 2) 主进程网络层可能卡在 DNS/TLS 阶段，那时它的 socket 超时不触发，
  //    所以这里再压一道 20 秒的总超时
  let 结果;
  // 超时定时器的 id 必须存下来：竞速由取令牌胜出后要立刻清掉它。
  // 否则令牌虽然拿到了，这个定时器仍会在 20 秒后触发并抛「取令牌超时」，
  // 把已经建好的 WebSocket 连接一起带崩（网关侧表现为 Client closed）。
  let 超时id = null;
  try {
    结果 = await Promise.race([
      window.终端桥.要令牌(参数.主机id),
      new Promise((_, 拒) => {
        超时id = setTimeout(() => {
          拒(new Error('取令牌超时（20 秒无响应），请检查网络后点重连'));
        }, 20000);
      })
    ]);
  } catch (e) {
    throw new Error(e && e.message ? e.message : '取令牌失败');
  } finally {
    // 不管成功、失败还是超时都要清掉，避免留下悬空的定时器
    if (超时id !== null) clearTimeout(超时id);
  }
  if (!结果) { throw new Error('取令牌失败：主进程没有返回内容'); }
  if (结果.error) { throw new Error(结果.error); }
  if (!结果.token) { throw new Error('取令牌失败：返回里没有令牌'); }
  return 结果.token;
}
function 开连接(令牌) {
  const 地址 = 参数.ws地址;
  try {
    连接 = new WebSocket(地址);
  } catch (e) {
    报错('WebSocket 创建失败：' + e.message);
    return;
  }
  连接.onopen = () => {
    报状态('已连上网关，正在鉴权…');
    连接.send(JSON.stringify({ action: 'auth', token: 令牌 }));
  };
  连接.onmessage = (e) => {
    let 消息;
    try { 消息 = JSON.parse(e.data); } catch (err) { return; }
    if (消息.type === 'authed') {
      报状态('鉴权通过，正在登录 SSH…');
      连接.send(JSON.stringify({ action: 'connect', host_id: 参数.主机id }));
    } else if (消息.type === 'connected') {
      已连上 = true;
      报状态('已连接　' + 消息.user + '@' + 消息.host, '连上');
      元重连.hidden = true;
      // 等一帧再报尺寸，此时终端的实际像素尺寸才稳定
      setTimeout(() => {
        if (!适配) return;
        适配.fit();
        连接.send(JSON.stringify({ action: 'resize', cols: 终端.cols, rows: 终端.rows }));
      }, 100);
      终端.focus();
    } else if (消息.type === 'output') {
      终端.write(消息.data);
    } else if (消息.type === 'error') {
      报错(消息.msg || '服务端返回错误');
    }
  };
  连接.onclose = () => {
    已连上 = false;
    if (主动关) return;
    报状态('已断开', '出错');
    元重连.hidden = false;
  };
  连接.onerror = () => {
    if (!已连上) { 报错('连不上网关，确认终端服务是否在运行'); }
  };
}
/* 直连：主进程侧开 shell，回显靠订阅推下来。
   不需要令牌，也不经本站，所以比网关那条路少一次往返。 */
async function 直连连() {
  报状态('正在连接服务器…');
  // 重连前先退掉上一轮的订阅，否则同一份数据会被写进终端好几遍
  if (退订数据) { try { 退订数据(); } catch (e) {} 退订数据 = null; }
  if (退订断开) { try { 退订断开(); } catch (e) {} 退订断开 = null; }
  /* 先订阅再开连接。反过来的话，shell 一开就吐的登录横幅会赶在
     订阅建立之前到达，那几行输出就永远看不到了。 */
  退订数据 = window.终端桥.收数据((d) => {
    // 主进程按 binary 字符串传，这里还原成字节再交给 xterm 自己解 UTF-8。
    // 直接 write 字符串会让汉字变成乱码。
    if (终端) { 终端.write(Uint8Array.from(d, (c) => c.charCodeAt(0) & 0xff)); }
  });
  退订断开 = window.终端桥.收断开(() => {
    已连上 = false;
    if (主动关) return;
    报状态('已断开', '出错');
    元重连.hidden = false;
  });
  let r;
  try {
    r = await window.终端桥.直连开();
  } catch (e) {
    报错('内部通道出错：' + (e && e.message ? e.message : e));
    return;
  }
  if (!r || r.error) { 报错((r && r.error) || '主进程没有返回结果'); return; }
  已连上 = true;
  报状态('已连接　' + r.user + '@' + r.host, '连上');
  元重连.hidden = true;
  // 等一帧再报尺寸，此时终端的实际像素尺寸才稳定
  setTimeout(() => {
    if (!适配) return;
    适配.fit();
    发尺寸(终端.cols, 终端.rows);
  }, 100);
  终端.focus();
}
async function 连() {
  元重连.hidden = true;
  已连上 = false;
  if (直连) { await 直连连(); return; }
  报状态('正在取握手令牌…');
  try {
    const 令牌 = await 取令牌();
    开连接(令牌);
  } catch (e) {
    报错(e.message || String(e));
  }
}
元重连.addEventListener('click', () => {
  // 直连要先让主进程把旧会话和那条 SSH 连接关掉，不关会漏连接
  if (直连) { try { window.终端桥.直连关(); } catch (e) {} }
  if (连接) { try { 连接.close(); } catch (e) {} 连接 = null; }
  连();
});
window.addEventListener('beforeunload', () => {
  主动关 = true;
  /* 直连会话活在主进程里，页面卸载不会自动带走它。
     主进程的 窗.on('closed') 也会兜一次，这里主动关是为了让连接立刻断开，
     而不是等窗口销毁事件绕一圈。 */
  if (直连) { try { window.终端桥.直连关(); } catch (e) {} }
  if (连接) { try { 连接.close(); } catch (e) {} }
});
/* ---------- 查看服务器配置 ----------
   采集不走终端那条 WebSocket，改由主进程发 HTTP 到 /api/ssh_run.php。
   两个原因：终端连接是交互式 shell，往里塞命令会把采集输出混进用户的屏幕；
   而且用户可能正跑着 vim/top，插一条命令会打乱它们的界面。 */
const 元配置层 = document.getElementById('配置层');
const 元配置体 = document.getElementById('配置体');
const 元配置钮 = document.getElementById('配置钮');
const 元文件钮 = document.getElementById('文件钮');
/* 文件管理开在独立窗口里，贴着终端窗右边、跟着一起移动。
   位置同步由主进程做，这里只管把窗口叫出来。
   不做成终端里的分栏：那会挤掉终端宽度，xterm 列数一变，
   正在跑的 top / vim 会整屏重排。 */
元文件钮.addEventListener('click', async () => {
  元文件钮.disabled = true;
  try {
    const r = await window.终端桥.开文件窗();
    if (r && r.error) { alert(r.error); }
  } catch (e) {
    alert('打开文件管理失败：' + (e && e.message ? e.message : e));
  } finally {
    // 稍等一下再放开，防止连点开出两个窗口的竞态
    setTimeout(() => { 元文件钮.disabled = false; }, 400);
  }
});
const 元配置刷新 = document.getElementById('配置刷新');
const 元配置关 = document.getElementById('配置关');
const 元配置复制 = document.getElementById('配置复制');
let 配置原文 = '';     // 存一份原始文本，供「复制」用
let 采集中 = false;
// 段标记 -> 显示用的小标题
const 段名映射 = {
  'CPU': '处理器',
  '内存': '内存',
  '磁盘': '磁盘使用',
  '块设备': '物理磁盘（ROTA=1 机械 / 0 固态）',
  '系统': '操作系统',
  '负载': '负载与核心数'
};
/* 把采集回来的整段文本按 ###标记### 切成若干组。
   用 textContent 逐段塞进 pre 而不是拼 HTML 字符串——
   命令输出里可能含 < > 之类字符，拼字符串就成了注入口子。 */
function 渲染配置(文本) {
  元配置体.innerHTML = '';
  const 行们 = String(文本).split('\n');
  let 当前名 = null;
  let 缓冲 = [];
  const 收一组 = () => {
    if (当前名 === null) return;
    const 组 = document.createElement('div');
    组.className = '组';
    const 标 = document.createElement('h4');
    标.textContent = 段名映射[当前名] || 当前名;
    const 体 = document.createElement('pre');
    // 去掉首尾空行，段与段之间的间距靠 CSS 给，不靠空行撑
    体.textContent = 缓冲.join('\n').replace(/^\n+|\n+$/g, '') || '（未取到）';
    组.appendChild(标);
    组.appendChild(体);
    元配置体.appendChild(组);
  };
  for (const 行 of 行们) {
    const 命中 = /^###(.+?)###\s*$/.exec(行);
    if (命中) { 收一组(); 当前名 = 命中[1]; 缓冲 = []; continue; }
    if (当前名 !== null) 缓冲.push(行);
  }
  收一组();
  if (!元配置体.children.length) {
    元配置体.innerHTML = '<div id="配置提示"></div>';
    元配置体.firstChild.textContent = '没有解析出内容，原始输出：\n' + 文本.slice(0, 500);
  }
}
function 配置提示(文) {
  元配置体.innerHTML = '';
  const 元 = document.createElement('div');
  元.id = '配置提示';
  元.textContent = 文;
  元配置体.appendChild(元);
}
async function 采集配置() {
  if (采集中) return;          // 连点会发出多个请求，白等还多连几次 SSH
  if (!参数 || !参数.主机id) { 配置提示('还没拿到主机信息，请稍后再试'); return; }
  采集中 = true;
  元配置钮.disabled = true;
  元配置刷新.disabled = true;
  配置提示('正在采集…这一步会另开一条 SSH 连接，通常几秒内返回');
  try {
    const 结果 = await window.终端桥.要配置(参数.主机id);
    if (!结果 || 结果.error) {
      配置提示('采集失败：' + ((结果 && 结果.error) || '未知原因'));
    } else {
      配置原文 = String(结果.out || '');
      渲染配置(配置原文);
    }
  } catch (e) {
    配置提示('采集出错：' + (e.message || String(e)));
  } finally {
    采集中 = false;
    元配置钮.disabled = false;
    元配置刷新.disabled = false;
  }
}
元配置钮.addEventListener('click', () => {
  元配置层.hidden = false;
  // 已经采过就直接看旧的，不每次都重连一遍。要最新的点「重新采集」
  if (配置原文) 渲染配置(配置原文); else 采集配置();
});
元配置刷新.addEventListener('click', 采集配置);
元配置关.addEventListener('click', () => { 元配置层.hidden = true; 终端 && 终端.focus(); });
元配置复制.addEventListener('click', () => {
  if (!配置原文) return;
  window.终端桥.写剪贴板(配置原文);
  元配置复制.textContent = '已复制';
  setTimeout(() => { 元配置复制.textContent = '复制'; }, 1500);
});
// 点遮罩空白处和按 Esc 都能关，别只留一个关闭按钮
元配置层.addEventListener('click', (事件) => {
  if (事件.target === 元配置层) { 元配置层.hidden = true; 终端 && 终端.focus(); }
});
window.addEventListener('keydown', (事件) => {
  if (事件.key === 'Escape' && !元配置层.hidden) {
    元配置层.hidden = true;
    终端 && 终端.focus();
  }
});
/* 启动：页面主动向主进程要连接参数。
   以前靠主进程 send 推，推的时候本脚本往往还没执行完，
   接收函数还没定义，消息就静静丢掉，页面卡在「准备连接…」不动。
   改成 invoke 后不再依赖时序：谁先就绪都不影响结果。 */
async function 启动() {
  报状态('正在取连接参数…');
  let 传入 = null;
  try {
    传入 = await window.终端桥.要参数();
  } catch (e) {
    报错('取连接参数失败：' + (e.message || String(e)));
    return;
  }
  if (!传入 || !传入.ws地址) {
    报错('没拿到连接参数，请关掉窗口重新打开');
    return;
  }
  参数 = 传入;
  document.title = 'SSH · ' + (参数.主机名 || 参数.主机id);
  建终端();
  连();
}
启动();
