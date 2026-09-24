'use strict';
/* 预览栏的承载层。
   原来用 <webview> 标签，废弃了：guest 的绘制表面只认 attach 那一刻的尺寸，
   之后无论改元素行内宽高、CSS zoom/transform，还是 enableDeviceEmulation 的
   scale，表面都停在初始值（实测 guest 恒为 299x150），页面按大视口布局却只有
   150 高的画布，于是只画出顶上一条。查了九个版本，这是根因，宿主侧改不到。
   现在改用 WebContentsView：尺寸由主进程 setBounds 显式给定，不经过 CSS 布局，
   没有「传不下去」的问题。渲染进程只负责报告占位元素的坐标。 */
const { WebContentsView, ipcMain, shell } = require('electron');
let 窗 = null;         // 宿主窗口
let 视图 = null;       // 当前 WebContentsView
let 上次范围 = null;   // 记住最后一次 bounds，改设备模拟后要重新贴一次
/** 只允许 http/https。file:// 能读本机文件，data:/javascript: 是注入载体。 */
function 网址可用(网址) {
  try {
    const 协 = new URL(String(网址)).protocol.toLowerCase();
    return 协 === 'http:' || 协 === 'https:';
  } catch (e) { return false; }
}
/** 往渲染进程报事件。窗口没了就别发，发了会抛。 */
function 报(通道, 数据) {
  if (窗 && !窗.isDestroyed()) {
    窗.webContents.send(通道, 数据);
  }
}
/** 建视图。已存在就先销毁——重复建会留下野视图占着内存和网络。 */
function 建(网址) {
  销毁();
  if (!窗 || 窗.isDestroyed()) { return { ok: 0, 错: '窗口不在' }; }
  视图 = new WebContentsView({
    webPreferences: {
      // 预览只需要「显示网页」：不挂 preload、不给 Node、开足隔离，
      // 远程页面拿不到 window.后端 也读不了文件系统
      preload: undefined,
      nodeIntegration: false,
      contextIsolation: true,
      webSecurity: true,
      allowRunningInsecureContent: false,
      sandbox: true,
      // 独立分区：预览站点的 Cookie 不和主程序混，登录态互不影响
      partition: 'persist:预览'
    }
  });
  const wc = 视图.webContents;
  /* 弹窗一律不开新窗口，改用系统浏览器。
     预览场景不需要多窗口，而没有地址栏的弹窗是常见的钓鱼面。 */
  wc.setWindowOpenHandler(({ url }) => {
    if (网址可用(url)) { shell.openExternal(url); }
    return { action: 'deny' };
  });
  // 站内跳转也挡住非 http(s)，防止页面用 file:// 之类往外跳
  wc.on('will-navigate', (e, url) => {
    if (!网址可用(url)) { e.preventDefault(); }
  });
  // 事件转发给界面，让地址栏和按钮状态跟着走
  wc.on('did-start-loading', () => 报('预览:加载中', true));
  wc.on('did-stop-loading', () => {
    报('预览:加载中', false);
    报('预览:按钮', 按钮态());
  });
  wc.on('did-navigate', (e, url) => {
    报('预览:地址', url || '');
    报('预览:按钮', 按钮态());
  });
  wc.on('did-navigate-in-page', (e, url, 是主框架) => {
    if (是主框架) { 报('预览:地址', url || ''); }
  });
  /* 加载失败要给说法，否则用户只看到一片白，分不清是网站没起来还是程序坏了。
     errorCode -3 是 ABORTED（用户自己打断的跳转），不算错误。 */
  wc.on('did-fail-load', (e, 码, 描述, 网址, 是主框架) => {
    if (!是主框架 || 码 === -3) { return; }
    报('预览:失败', { 码: 码, 描述: 描述 || '', 网址: 网址 || '' });
  });
  wc.on('render-process-gone', () => 报('预览:崩了', true));
  窗.contentView.addChildView(视图);
  if (网址可用(网址)) { wc.loadURL(网址); }
  return { ok: 1, 编号: wc.id };
}
/** 销毁视图，连带从窗口摘下来。关预览栏时必须调，否则页面还在后台跑。 */
function 销毁() {
  if (!视图) { return { ok: 1 }; }
  try {
    if (窗 && !窗.isDestroyed()) { 窗.contentView.removeChildView(视图); }
    if (视图.webContents && !视图.webContents.isDestroyed()) {
      视图.webContents.close();
    }
  } catch (e) { /* 已经没了就算了 */ }
  视图 = null;
  上次范围 = null;
  return { ok: 1 };
}
/** 当前能不能前进后退，给界面刷按钮用 */
function 按钮态() {
  if (!活着()) { return { 后: false, 前: false, 能用: false }; }
  const wc = 视图.webContents;
  let 后 = false, 前 = false;
  try { 后 = wc.canGoBack(); 前 = wc.canGoForward(); } catch (e) {}
  return { 后: 后, 前: 前, 能用: true };
}
function 活着() {
  return !!(视图 && 视图.webContents && !视图.webContents.isDestroyed());
}
/** 摆位置。渲染进程传的是 CSS 像素坐标，setBounds 用的也是，不用换算。 */
function 定位(范围) {
  if (!活着()) { return { ok: 0 }; }
  const b = {
    x: Math.round(Number(范围 && 范围.x) || 0),
    y: Math.round(Number(范围 && 范围.y) || 0),
    width: Math.max(0, Math.round(Number(范围 && 范围.宽) || 0)),
    height: Math.max(0, Math.round(Number(范围 && 范围.高) || 0))
  };
  上次范围 = b;
  try { 视图.setBounds(b); } catch (e) { return { ok: 0, 错: String(e.message || e) }; }
  return { ok: 1 };
}
/** 藏起来：宽高设 0。WebContentsView 没有 hide()，这是通行做法。 */
function 藏() {
  if (!活着()) { return { ok: 0 }; }
  try { 视图.setBounds({ x: 0, y: 0, width: 0, height: 0 }); } catch (e) {}
  return { ok: 1 };
}
/* 设备模拟。视口尺寸和 dpr 只有 webContents.enableDeviceEmulation 能改。
   这里 viewSize 传设备原始尺寸、scale 由渲染进程按舞台大小算，
   Chromium 自己在渲染层等比缩小——不需要宿主侧做任何 CSS 变换。
   deviceScaleFactor 传 0 表示跟随宿主，不硬套真机的 2/3：
   那要求按物理像素分配表面，和 setBounds 给的 CSS 尺寸对不上。 */
function 设模拟(规格) {
  if (!活着()) { return { ok: 0 }; }
  const wc = 视图.webContents;
  const 宽 = Math.min(Math.max(Number(规格 && 规格.宽) || 0, 200), 3000);
  const 高 = Math.min(Math.max(Number(规格 && 规格.高) || 0, 200), 3000);
  const 缩 = Math.min(Math.max(Number(规格 && 规格.缩放) || 1, 0.1), 1);
  try {
    wc.enableDeviceEmulation({
      screenPosition: 'mobile',
      screenSize: { width: 宽, height: 高 },
      viewSize: { width: 宽, height: 高 },
      deviceScaleFactor: 0,
      viewPosition: { x: 0, y: 0 },
      scale: 缩
    });
  } catch (e) {
    return { ok: 0, 错: String(e && e.message || e) };
  }
  /* 触摸特征。不做这步 Chromium 仍按桌面算输入能力，
     pointer:coarse / hover:hover 会走桌面分支，响应式站点判断会错。
     失败不影响视口尺寸，所以不当错误往上报。 */
  if (规格 && 规格.移动) {
    try {
      wc.debugger.isAttached() || wc.debugger.attach('1.3');
      wc.debugger.sendCommand('Emulation.setTouchEmulationEnabled',
        { enabled: true, maxTouchPoints: 5 });
      wc.debugger.sendCommand('Emulation.setEmitTouchEventsForMouse',
        { enabled: true, configuration: 'mobile' });
    } catch (e) {}
  }
  // 模拟改了视口，重新贴一次 bounds，避免残留旧尺寸的表面
  if (上次范围) { try { 视图.setBounds(上次范围); } catch (e) {} }
  return { ok: 1, 宽: 宽, 高: 高, 缩放: 缩 };
}
function 停模拟() {
  if (!活着()) { return { ok: 0 }; }
  const wc = 视图.webContents;
  try { wc.disableDeviceEmulation(); } catch (e) {}
  try {
    if (wc.debugger.isAttached()) {
      wc.debugger.sendCommand('Emulation.setTouchEmulationEnabled', { enabled: false });
      wc.debugger.detach();
    }
  } catch (e) {}
  if (上次范围) { try { 视图.setBounds(上次范围); } catch (e) {} }
  return { ok: 1 };
}
/** UA。设备模拟要配对应的 UA，否则服务端按桌面发内容。 */
function 设UA(串) {
  if (!活着()) { return { ok: 0 }; }
  try {
    if (串) { 视图.webContents.setUserAgent(String(串)); }
    else { 视图.webContents.setUserAgent(视图.webContents.getUserAgent()); }
  } catch (e) {}
  return { ok: 1 };
}
/** 导航动作合在一个 handler 里，省得开五个通道 */
function 导航(动作, 参数) {
  if (!活着()) { return { ok: 0 }; }
  const wc = 视图.webContents;
  try {
    if (动作 === '后退' && wc.canGoBack()) { wc.goBack(); }
    else if (动作 === '前进' && wc.canGoForward()) { wc.goForward(); }
    else if (动作 === '刷新') { wc.reload(); }
    else if (动作 === '停止') { wc.stop(); }
    else if (动作 === '跳转') {
      if (!网址可用(参数)) { return { ok: 0, 错: '只支持 http/https' }; }
      wc.loadURL(String(参数));
    }
  } catch (e) { return { ok: 0, 错: String(e.message || e) }; }
  return { ok: 1 };
}
/* 诊断。把主进程记的 bounds 和页面里实测的 innerWidth 摆在一起看。
   两边对得上说明模拟生效了；对不上就是模拟没吃进去。
   服务器上没有图形环境，只能靠这些实测数字判断，不能靠肉眼看画面。 */
async function 诊断() {
  if (!活着()) { return { ok: 0, 错: '预览未开' }; }
  const wc = 视图.webContents;
  const 出 = { ok: 1, 范围: 上次范围 || null, 地址: '' };
  try { 出.地址 = wc.getURL(); } catch (e) {}
  try {
    const 串 = await wc.executeJavaScript(
      'JSON.stringify({w:innerWidth,h:innerHeight,d:devicePixelRatio,' +
      'bh:document.body?document.body.scrollHeight:0,' +
      'cw:document.documentElement.clientWidth,' +
      'coarse:matchMedia("(pointer:coarse)").matches})', true);
    出.页面 = JSON.parse(串);
  } catch (e) {
    出.页面错 = String(e && e.message || e);
  }
  return 出;
}
/** 把 IPC 接上。窗口建好后调一次。 */
function 接线(宿主窗口) {
  窗 = 宿主窗口;
  // 窗口关掉时把视图一起收走，否则预览页面会在后台继续跑
  窗.on('closed', () => { 视图 = null; 上次范围 = null; });
  ipcMain.handle('预览:建', (e, 网址) => 建(网址));
  ipcMain.handle('预览:销毁', () => 销毁());
  ipcMain.handle('预览:定位', (e, 范围) => 定位(范围));
  ipcMain.handle('预览:藏', () => 藏());
  ipcMain.handle('预览:设模拟', (e, 规格) => 设模拟(规格));
  ipcMain.handle('预览:停模拟', () => 停模拟());
  ipcMain.handle('预览:设UA', (e, 串) => 设UA(串));
  ipcMain.handle('预览:导航', (e, 动作, 参数) => 导航(动作, 参数));
  ipcMain.handle('预览:按钮态', () => 按钮态());
  ipcMain.handle('预览:诊断', () => 诊断());
}
module.exports = { 接线: 接线, 销毁: 销毁 };
