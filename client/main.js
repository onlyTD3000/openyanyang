'use strict';

const { app, BrowserWindow, ipcMain, safeStorage, shell, clipboard, webContents, Menu, dialog, screen } = require('electron');
const path = require('path');
const fs = require('fs');
const https = require('https');
const crypto = require('crypto');
const { URL } = require('url');
const 更新 = require('./updater.js');
const 压低音量 = require('./audio/duck.js');
const 预览 = require('./preview-view.js');
/* 本地 SSH 直连模块在文件下方 require（连同那一大段设计说明）。
   终端和命令卡片的 handler 位置比它靠前，但 handler 体内的引用是
   被调用时才求值，那时模块早已加载，不存在时序问题。 */

/* 后端地址。换自建部署时改这一处即可。 */
const 服务端 = 'https://codex.77bot.cn';

let 主窗口 = null;

/* ---------- 密钥存储 ----------
   API Key 等于账号密码，不能明文落盘。
   safeStorage 走操作系统的凭据加密（Windows 上是 DPAPI），
   密文只有当前系统用户能解开，别人拷走文件也读不出来。 */
const 配置路径 = () => path.join(app.getPath('userData'), 'auth.dat');

function 存密钥(token) {
  if (!safeStorage.isEncryptionAvailable()) {
    // 系统不支持加密时宁可不存，也不明文落盘
    return { ok: false, msg: '当前系统不支持安全存储，密钥无法记住' };
  }
  fs.writeFileSync(配置路径(), safeStorage.encryptString(token));
  return { ok: true };
}

function 读密钥() {
  try {
    if (!fs.existsSync(配置路径())) return '';
    if (!safeStorage.isEncryptionAvailable()) return '';
    return safeStorage.decryptString(fs.readFileSync(配置路径()));
  } catch (e) {
    // 密文损坏或换了系统用户，当作没存过
    return '';
  }
}

function 清密钥() {
  try {
    fs.existsSync(配置路径()) && fs.unlinkSync(配置路径());
  } catch (e) { /* 删不掉就算了，下次覆盖写 */ }
}

/* ---------- 窗口 ---------- */
function 建窗口() {
  主窗口 = new BrowserWindow({
    width: 1180,
    height: 780,
    minWidth: 900,
    minHeight: 600,
    title: '岩羊Ai',
    backgroundColor: '#f7f8fa',
    autoHideMenuBar: true,
    // 用自定义标题栏：原生那条 Windows 边框由系统绘制，HTML 放不进按钮。
    // 关掉它以后拖动、最大化、关闭都得自己实现，见下面的「窗口:」几个 handler。
    frame: false,
    // 无边框窗口默认没有圆角阴影，这里保持系统默认的 thick 边框行为，
    // 让贴边分屏（Win+方向键、拖到屏幕边缘）仍然可用。
    thickFrame: true,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      // 渲染进程不给 Node 权限，特权操作全走 IPC，降低 XSS 被利用的后果
      nodeIntegration: false,
      contextIsolation: true,
      /* webviewTag 关掉。预览改用主进程的 WebContentsView 承载，
         不再需要 <webview> 标签。留着它等于给页面留一个能造 guest 的入口，
         没有用处的特权一律不开。 */
      webviewTag: false
    }
  });

  // 预览视图的 IPC 接线。必须在 loadFile 之前，界面一起来就可能调
  预览.接线(主窗口);
  主窗口.loadFile(path.join(__dirname, 'renderer', 'index.html'));
  // 最大化状态变了就告诉界面，好切换那个按钮的图标。
  // 不能只靠按钮自己回传：用户拖窗口到屏幕顶端、按 Win+↑、
  // 双击标题栏都会改变状态，那些路径不经过我们的按钮。
  const 报状态 = () => {
    if (主窗口 && !主窗口.isDestroyed()) {
      主窗口.webContents.send('窗口:最大化变了', 主窗口.isMaximized());
    }
  };
  主窗口.on('maximize', 报状态);
  主窗口.on('unmaximize', 报状态);

  // 站外链接交给系统浏览器，不在应用内开新窗口
  主窗口.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });
}

/* 右键菜单。
   Electron 默认不给任何右键菜单，只有快捷键能用，所以输入框和对话区右键
   都是没反应的。这里用 web-contents-created 一次挂上，新开的窗口（终端、
   预览）自动都有，不用逐个窗口再写一遍。
   菜单项按上下文给：有选中文字才给复制，可编辑区才给剪切/粘贴，
   免得在只读的对话区弹出一堆点了没用的项。
   终端页自己实现了 PuTTY 风格右键（选中即复制、否则粘贴），
   那边已经 preventDefault 了，不会走到这里。 */
app.on('web-contents-created', (_e, 内容) => {
  内容.on('context-menu', (事件, 参数) => {
    const 项 = [];
    const 有选中 = !!(参数.selectionText && 参数.selectionText.trim());
    const 可编辑 = !!参数.isEditable;
    if (可编辑 && 有选中) {
      项.push({ label: '剪切', role: 'cut' });
    }
    if (有选中) {
      项.push({ label: '复制', role: 'copy' });
    }
    if (可编辑) {
      项.push({ label: '粘贴', role: 'paste' });
    }
    // 链接和图片：光有复制粘贴不够用，右键这俩东西时给对应的项
    if (参数.linkURL) {
      if (项.length) 项.push({ type: 'separator' });
      项.push({ label: '复制链接地址', click: () => clipboard.writeText(参数.linkURL) });
      项.push({ label: '在浏览器中打开', click: () => shell.openExternal(参数.linkURL) });
    }
    if (参数.mediaType === 'image' && 参数.srcURL) {
      if (项.length) 项.push({ type: 'separator' });
      项.push({ label: '复制图片', click: () => 内容.copyImageAt(参数.x, 参数.y) });
    }
    if (可编辑) {
      if (项.length) 项.push({ type: 'separator' });
      项.push({ label: '全选', role: 'selectAll' });
    }
    if (!项.length) return;   // 没有可用项就不弹空菜单
    Menu.buildFromTemplate(项).popup({ window: BrowserWindow.fromWebContents(内容) || undefined });
  });
});
app.whenReady().then(() => {
  建窗口();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) 建窗口();
  });
  // 启动后静默查一次更新。延迟 8 秒：让界面先加载完，
  // 免得弹窗盖在半成品界面上，也不跟首屏请求抢带宽。
  setTimeout(() => {
    更新.检查更新(服务端, { 静默: true }).catch(() => {});
  }, 8000);
  // 提前把压低音量的 PowerShell 拉起来。它要现场编译 C#，冷启动一两秒，
  // 等第一次响铃时再起就来不及了。延迟 3 秒是为了不跟首屏加载抢 CPU，
  // 又比上面的更新检查早，保证真有电话进来时它已经就绪。
  setTimeout(() => 压低音量.启动(), 3000);
});
// 退出前把音量还回去。漏了这步用户的播放器就一直是压低状态，
// 而且他不会想到是本应用干的，只会觉得播放器出了问题。
app.on('before-quit', () => {
  压低音量.恢复();
  压低音量.关闭();
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

/* ---------- IPC：密钥与配置 ---------- */
/* 任务跑完的窗口提醒。
   flashFrame 在 Windows 上闪任务栏图标，聚焦后系统自动停，不用手动清。
   窗口已销毁时静默跳过，免得关窗瞬间正好有任务完成会抛异常。 */
ipcMain.handle('窗口:闪', () => {
  if (主窗口 && !主窗口.isDestroyed() && !主窗口.isFocused()) {
    主窗口.flashFrame(true);
  }
  return { ok: true };
});
/* 点通知把窗口拉到前台。最小化状态要先 restore，
   否则 show() 只是让它在任务栏亮一下，不会真正展开。 */
ipcMain.handle('窗口:唤起', () => {
  if (主窗口 && !主窗口.isDestroyed()) {
    if (主窗口.isMinimized()) 主窗口.restore();
    主窗口.show();
    主窗口.focus();
    主窗口.flashFrame(false);
  }
  return { ok: true };
});
/* ---------- 自定义标题栏的窗口控制 ----------
   frame: false 之后最小化/最大化/关闭这三件事系统不再代劳，
   得由界面上的按钮通过 IPC 调过来。 */
ipcMain.handle('窗口:最小化', () => {
  if (主窗口 && !主窗口.isDestroyed()) 主窗口.minimize();
  return { ok: true };
});
/* 最大化按钮是个切换：已经最大化就还原，否则最大化。
   返回切换后的状态，省得界面再问一次。 */
ipcMain.handle('窗口:最大化切换', () => {
  if (!主窗口 || 主窗口.isDestroyed()) return { 最大化: false };
  if (主窗口.isMaximized()) {
    主窗口.unmaximize();
  } else {
    主窗口.maximize();
  }
  return { 最大化: 主窗口.isMaximized() };
});
ipcMain.handle('窗口:关闭', () => {
  if (主窗口 && !主窗口.isDestroyed()) 主窗口.close();
  return { ok: true };
});
/* 界面启动时问一次当前是不是最大化，好把按钮图标画对。 */
ipcMain.handle('窗口:是否最大化', () => {
  return { 最大化: !!(主窗口 && !主窗口.isDestroyed() && 主窗口.isMaximized()) };
});

/* ---------- 响铃时压低其他程序音量 ---------- */
/* 压低而不是暂停：暂停会打断用户的播放进度，接完电话得自己回去点播放；
   压低是响完自动还原，用户几乎察觉不到。
   系数 0.2 是压到两成，兜底 60 秒——正常都是响铃结束时主动恢复，
   这个时间只防「恢复没被调到」的意外。 */
ipcMain.handle('音量:压低', (e, 系数) => {
  压低音量.压低(typeof 系数 === 'number' ? 系数 : 0.2, 60000);
  return { ok: true, 可用: 压低音量.是否可用 };
});
ipcMain.handle('音量:恢复', () => {
  压低音量.恢复();
  return { ok: true };
});
ipcMain.handle('密钥:存', (e, token) => 存密钥(String(token || '')));
ipcMain.handle('密钥:读', () => 读密钥());
ipcMain.handle('密钥:清', () => { 清密钥(); return { ok: true }; });
ipcMain.handle('服务端地址', () => 服务端);
/* 手动检查更新。静默传 false：用户主动点的，没更新也要给个「已是最新」的回应，
   否则点了没反应会以为按钮坏了。 */
ipcMain.handle('更新:检查', () => 更新.检查更新(服务端, { 静默: false }));
ipcMain.handle('更新:版本', () => app.getVersion());
/* 预览栏的承载和设备模拟全部搬到 预览视图.js。
   原来这里用 webContents.fromId 找 <webview> 的 guest 再做模拟，
   现在预览是主进程自己建的 WebContentsView，不需要按 id 找，也不需要
   校验 hostWebContents——视图句柄一直在主进程手里，外部拿不到。 */
/* 用系统浏览器打开外部链接。支付宝网页支付这类流程没法在客户端里跑完，
   只能丢给浏览器。只放行 http/https，否则 file:// 之类的协议能被拿来搞事。 */
ipcMain.handle('开外链', (e, url) => {
  const s = String(url || '');
  if (!/^https?:\/\//i.test(s)) return { ok: false };
  shell.openExternal(s);
  return { ok: true };
});
/* 支付页开在应用内的独立窗口。支付宝网页收银台自己会渲染二维码，
   用户在这个窗口里扫码或登录付款，人不用离开客户端。
   这个窗口不挂 preload、不给 Node 权限，第三方页面碰不到应用的 IPC。 */
let 支付窗 = null;
function 是支付域(u) {
  try {
    const h = new URL(u).hostname.toLowerCase();
    return h === 'alipay.com' || h.endsWith('.alipay.com') || h === 'code.77bot.cn';
  } catch (_) { return false; }
}
ipcMain.handle('开支付窗', (e, url) => {
  const s = String(url || '');
  if (!/^https?:\/\//i.test(s) || !是支付域(s)) return { ok: false };
  if (支付窗 && !支付窗.isDestroyed()) { 支付窗.focus(); return { ok: true }; }
  支付窗 = new BrowserWindow({
    width: 900, height: 720, parent: 主窗口, modal: false,
    title: '支付宝支付', backgroundColor: '#ffffff', autoHideMenuBar: true,
    webPreferences: { nodeIntegration: false, contextIsolation: true, partition: 'persist:pay' }
  });
  支付窗.loadURL(s);
  // 支付页里点出去的链接交给系统浏览器，不在支付窗里套娃
  支付窗.webContents.setWindowOpenHandler(({ url: u }) => { shell.openExternal(u); return { action: 'deny' }; });
  支付窗.on('closed', () => {
    支付窗 = null;
    // 窗口关了立刻催一次查单，用户可能已经付完了
    if (主窗口 && !主窗口.isDestroyed()) 主窗口.webContents.send('支付窗已关');
  });
  return { ok: true };
});

/* 拼 x-www-form-urlencoded。
   数组要拼成 images[]=2&images[]=3，PHP 那边才收得成数组。 */
function 拼表单(参数) {
  const 片段 = [];
  Object.keys(参数 || {}).forEach((k) => {
    const v = 参数[k];
    if (Array.isArray(v)) {
      v.forEach((一项) => 片段.push(encodeURIComponent(k) + '[]=' + encodeURIComponent(一项)));
    } else if (v !== undefined && v !== null) {
      片段.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    }
  });
  return 片段.join('&');
}

/* ---------- 普通接口调用 ----------
   conv.php 里 list/messages 只读收 $_GET，写操作收 $_POST，
   所以要按方法分别拼到 query 或 body，拼错了后端收不到参数。 */
ipcMain.handle('接口', async (e, { 路径, 参数, 方法, token }) => {
  const 是GET = String(方法 || 'POST').toUpperCase() === 'GET';
  const 表单 = 拼表单(参数);
  const u = new URL(路径 + (是GET && 表单 ? '?' + 表单 : ''), 服务端);

  return new Promise((resolve) => {
    const 头 = { 'Authorization': 'Bearer ' + token };
    if (!是GET) {
      头['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';
      头['Content-Length'] = Buffer.byteLength(表单);
    }
    const 请求 = https.request({
      hostname: u.hostname,
      port: u.port || 443,
      path: u.pathname + u.search,
      method: 是GET ? 'GET' : 'POST',
      headers: 头,
      timeout: 30000
    }, (响应) => {
      let 正文 = '';
      响应.setEncoding('utf8');
      响应.on('data', (块) => { 正文 += 块; });
      响应.on('end', () => {
        try {
          resolve(JSON.parse(正文));
        } catch (err) {
          // 后端偶发返回 HTML 错误页，别把解析异常抛给界面
          resolve({ error: '服务端返回异常（HTTP ' + 响应.statusCode + '）' });
        }
      });
    });
    请求.on('timeout', () => { 请求.destroy(); resolve({ error: '请求超时' }); });
    请求.on('error', (err) => resolve({ error: '网络错误：' + err.message }));
    if (!是GET) 请求.write(表单);
    请求.end();
  });
});

/* ---------- 传图 ----------
   Node 这边没有 FormData，手工拼 multipart 请求体。
   文件是 { name, type, 字节 }，字节是 Uint8Array，经 IPC 结构化克隆传过来。
   边界串用 crypto 生成随机值，避免跟文件内容撞上导致请求体被截断。 */
ipcMain.handle('图:传', async (e, 文件, token) => {
  // 边界串只能用 ASCII：它要拼进 Content-Type header，
  // HTTP header 不接受非 Latin-1 字符，带中文会被 Node 判 ERR_INVALID_CHAR
  const 边界 = '----YanYangBoundary' + crypto.randomBytes(16).toString('hex');
  const 名 = String((文件 && 文件.name) || 'image.png').replace(/["\r\n]/g, '');
  const 类型 = String((文件 && 文件.type) || 'application/octet-stream');
  const 内容 = Buffer.from((文件 && 文件.字节) || []);
  const 头部 = Buffer.from(
    '--' + 边界 + '\r\n' +
    'Content-Disposition: form-data; name="file"; filename="' + 名 + '"\r\n' +
    'Content-Type: ' + 类型 + '\r\n\r\n', 'utf8');
  const 尾部 = Buffer.from('\r\n--' + 边界 + '--\r\n', 'utf8');
  const 请求体 = Buffer.concat([头部, 内容, 尾部]);
  const u = new URL('/api/upload.php', 服务端);
  return new Promise((resolve) => {
    const 请求 = https.request({
      hostname: u.hostname,
      port: u.port || 443,
      path: u.pathname,
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'multipart/form-data; boundary=' + 边界,
        'Content-Length': 请求体.length
      },
      timeout: 60000
    }, (响应) => {
      let 正文 = '';
      响应.setEncoding('utf8');
      响应.on('data', (块) => { 正文 += 块; });
      响应.on('end', () => {
        try {
          resolve(JSON.parse(正文));
        } catch (err) {
          resolve({ error: '上传失败（HTTP ' + 响应.statusCode + '）' });
        }
      });
    });
    请求.on('timeout', () => { 请求.destroy(); resolve({ error: '上传超时' }); });
    请求.on('error', (err) => resolve({ error: '网络错误：' + err.message }));
    请求.write(请求体);
    请求.end();
  });
});
/* ---------- 通用文件上传 ----------
   和上面的「图:传」同一套 multipart 拼法，区别是路径和附加字段都由调用方给。
   代码仓上传（/api/repo.php act=upload）要带 act、project_id、dir、mode 这些字段，
   写死路径的图传通道办不到，所以另开一条。
   字段值一律当字符串写进表单，Node 这边不做类型推断，免得布尔值被写成 true/false 之外的样子。 */
ipcMain.handle('文件:传', async (e, { 路径, 文件, 字段, token }) => {
  const 边界 = '----YanYangBoundary' + crypto.randomBytes(16).toString('hex');
  const 名 = String((文件 && 文件.name) || 'file.bin').replace(/["\r\n]/g, '');
  const 类型 = String((文件 && 文件.type) || 'application/octet-stream');
  const 内容 = Buffer.from((文件 && 文件.字节) || []);
  const 块 = [];
  // 普通字段排在文件前面：PHP 读 $_POST 不挑顺序，但有些反代会截断超长 body，
  // 小字段先走完，日志里能看到 act 是什么，排查比只剩一坨二进制强。
  Object.keys(字段 || {}).forEach((k) => {
    块.push(Buffer.from(
      '--' + 边界 + '\r\n' +
      'Content-Disposition: form-data; name="' + String(k).replace(/["\r\n]/g, '') + '"\r\n\r\n' +
      String(字段[k]) + '\r\n', 'utf8'));
  });
  块.push(Buffer.from(
    '--' + 边界 + '\r\n' +
    'Content-Disposition: form-data; name="file"; filename="' + 名 + '"\r\n' +
    'Content-Type: ' + 类型 + '\r\n\r\n', 'utf8'));
  块.push(内容);
  块.push(Buffer.from('\r\n--' + 边界 + '--\r\n', 'utf8'));
  const 请求体 = Buffer.concat(块);
  const u = new URL(String(路径 || '/api/upload.php'), 服务端);
  return new Promise((resolve) => {
    const 请求 = https.request({
      hostname: u.hostname,
      port: u.port || 443,
      path: u.pathname,
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'multipart/form-data; boundary=' + 边界,
        'Content-Length': 请求体.length
      },
      // 解压入仓是逐个文件写库，几百个条目会比传图慢得多，给足时间
      timeout: 300000
    }, (响应) => {
      let 正文 = '';
      响应.setEncoding('utf8');
      响应.on('data', (块) => { 正文 += 块; });
      响应.on('end', () => {
        try {
          resolve(JSON.parse(正文));
        } catch (err) {
          resolve({ error: '上传失败（HTTP ' + 响应.statusCode + '）' });
        }
      });
    });
    请求.on('timeout', () => { 请求.destroy(); resolve({ error: '上传超时' }); });
    请求.on('error', (err) => resolve({ error: '网络错误：' + err.message }));
    请求.write(请求体);
    请求.end();
  });
});
/* 终端诊断日志。写到用户数据目录下的 终端诊断.log，
   排查连接问题时让用户把这个文件发回来即可。 */
function 终端日志(文本) {
  try {
    const 路径 = require('path').join(app.getPath('userData'), '终端诊断.log');
    require('fs').appendFileSync(路径,
      new Date().toISOString() + '  ' + 文本 + '\n', 'utf8');
  } catch (e) { /* 日志写不了不能影响主流程 */ }
}
/* ---------- 终端握手令牌 ----------
   终端页是 file:// 源，直接 fetch 到 https 会被判跨源拦下来（服务端没开 CORS，
   也不该为了这个把接口对所有网页放开）。所以令牌由主进程取，跟其他网络请求一个路子。
   令牌 60 秒内用一次即废，每次连接（含重连）都要重新取一张。 */
function 取终端令牌(主机id, token) {
  // 边界串只能用 ASCII：它要拼进 Content-Type header，
  // HTTP header 不接受非 Latin-1 字符，带中文会被 Node 判 ERR_INVALID_CHAR
  const 边界 = '----YanYangBoundary' + crypto.randomBytes(16).toString('hex');
  const 请求体 = Buffer.from(
    '--' + 边界 + '\r\n' +
    'Content-Disposition: form-data; name="host_id"\r\n\r\n' +
    String(主机id) + '\r\n' +
    '--' + 边界 + '--\r\n', 'utf8');
  const u = new URL('/api/wsssh_token.php', 服务端);
  return new Promise((resolve) => {
    // socket 的 timeout 只在连上之后计时，卡在 DNS/TLS 阶段它不会触发，
    // 所以另起一个硬闹钟，到点无论卡在哪一步都收摊
    let 已收 = false;
    const 收 = (值) => { if (!已收) { 已收 = true; clearTimeout(闹钟); resolve(值); } };
    const 闹钟 = setTimeout(() => {
      终端日志('取令牌硬超时，卡在连接阶段');
      try { 请求.destroy(); } catch (e) {}
      收({ error: '取令牌超时：连不上服务器，请检查网络' });
    }, 18000);
    const 请求 = https.request({
      hostname: u.hostname,
      port: u.port || 443,
      path: u.pathname,
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'multipart/form-data; boundary=' + 边界,
        'Content-Length': 请求体.length
      },
      timeout: 15000
    }, (响应) => {
      let 数据 = '';
      响应.on('data', (块) => { 数据 += 块; });
      响应.on('end', () => {
        try {
          const 结果 = JSON.parse(数据);
          终端日志('取令牌返回 HTTP ' + 响应.statusCode +
            (结果 && 结果.error ? ' 错误：' + 结果.error : ' 成功'));
          收(结果);
        } catch (err) {
          终端日志('取令牌返回非 JSON，HTTP ' + 响应.statusCode +
            ' 前 200 字：' + String(数据).slice(0, 200));
          收({ error: '返回格式异常（HTTP ' + 响应.statusCode + '）' });
        }
      });
    });
    请求.on('timeout', () => {
      终端日志('取令牌 socket 空闲超时');
      请求.destroy();
      收({ error: '取令牌超时' });
    });
    请求.on('error', (err) => {
      终端日志('取令牌网络错误：' + err.message);
      收({ error: '网络错误：' + err.message });
    });
    请求.write(请求体);
    请求.end();
  });
}

// 开终端时把密钥记在主进程，终端页要令牌时不用传密钥过来。
// 键是主机 id，关窗口时清掉。
const 终端密钥们 = new Map();

// webContents.id -> 连接参数。用 webContents.id 而不是主机 id 做键，
// 因为应答时只能从 e.sender 认出是哪个页面在要。
const 终端参数们 = new Map();
// 页面脚本跑起来后主动来取连接参数。
// 不用 send 推：推的时机很难掌握，推早了页面接不到。
ipcMain.handle('终端:要参数', async (e) => {
  return 终端参数们.get(e.sender.id) || null;
});
// 终端页每次要连（含点重连）都通过这个通道要一张新令牌
ipcMain.handle('终端:要令牌', async (e, 主机id) => {
  终端日志('要令牌 主机id=' + 主机id);
  const token = 终端密钥们.get(String(主机id));
  if (!token) {
    终端日志('密钥不在表里，表里有：' + [...终端密钥们.keys()].join(','));
    return { error: '会话已失效，请关掉终端窗口重新打开' };
  }
  return await 取终端令牌(主机id, token);
});
/* 终端页的剪贴板读写。
   走主进程而不是渲染层的 navigator.clipboard：终端页是 file:// 源，
   那套 API 要权限授权、还必须由用户手势触发，容易静默失败。
   Electron 的 clipboard 模块在主进程里没这些限制。 */
/* 采集服务器硬件配置。
   一条命令把 CPU、内存、磁盘、系统信息全取回来，用固定标记分段，
   渲染层按标记切开显示。分成多条请求会连多次 SSH，慢且没必要。
   每段都用 2>/dev/null 兜住：某些字段在容器或精简系统里不存在，
   不该因为一个字段缺失就让整个采集失败。 */
const 配置采集命令 = [
  'echo "###CPU###"',
  'lscpu 2>/dev/null | grep -iE "^(Architecture|Model name|CPU\\(s\\)|Thread|Core|Socket|CPU MHz|CPU max MHz|BogoMIPS|型号名称|架构)" || cat /proc/cpuinfo 2>/dev/null | grep -iE "model name|processor" | head -20',
  'echo "###内存###"',
  'free -h 2>/dev/null || cat /proc/meminfo 2>/dev/null | head -5',
  'echo "###磁盘###"',
  'df -hT 2>/dev/null | grep -vE "tmpfs|devtmpfs|overlay|squashfs" | head -15',
  'echo "###块设备###"',
  'lsblk -d -o NAME,SIZE,ROTA,MODEL 2>/dev/null | head -10',
  'echo "###系统###"',
  '(cat /etc/os-release 2>/dev/null | grep -E "^(PRETTY_NAME|NAME|VERSION)=" ; uname -r ; uptime 2>/dev/null)',
  'echo "###负载###"',
  '(cat /proc/loadavg 2>/dev/null ; nproc 2>/dev/null | sed "s/^/nproc=/")'
].join('; ');
ipcMain.handle('终端:要配置', async (e, 主机id) => {
  const token = 终端密钥们.get(String(主机id));
  if (!token) return { error: '会话已失效，请关掉终端窗口重新打开' };
  /* 优先本地直连采集。
     不这么做会很别扭：终端本身已经是用户 IP 直连了，点一下「查看配置」
     却从本站源站又连一次，目标机日志里凭空多出一条源站登录记录。
     这里用连接池那条路而不是终端会话——采集是一次性命令，
     跟交互式 shell 是两回事，塞进 shell 还会污染用户屏幕。
     直连失败（取不到凭据等）时回落到服务端代连，保证功能不断。 */
  try {
    const 凭 = await 备好凭据(Number(主机id) || 0, token);
    if (!凭.error) {
      const c = await 本地SSH.取连接(Number(主机id) || 0, 凭.凭据);
      if (c.ok) {
        const r = await 本地SSH.执行(c.conn, 配置采集命令, 40000);
        本地SSH.归还(Number(主机id) || 0);
        if (r.ok) { return { ok: 1, out: r.out, code: 0 }; }
      }
    }
    终端日志('直连采集未成功，回落服务端代连');
  } catch (err) {
    终端日志('直连采集异常，回落服务端代连：' + (err && err.message ? err.message : err));
  }
  const 边界 = '----YanYangBoundary' + crypto.randomBytes(16).toString('hex');
  const 段 = (名, 值) =>
    '--' + 边界 + '\r\n' +
    'Content-Disposition: form-data; name="' + 名 + '"\r\n\r\n' +
    String(值) + '\r\n';
  const 请求体 = Buffer.from(
    段('act', 'run') + 段('host_id', 主机id) +
    段('command', 配置采集命令) +
    '--' + 边界 + '--\r\n', 'utf8');
  const u = new URL('/api/ssh_run.php', 服务端);
  return await new Promise((resolve) => {
    let 已收 = false;
    const 收 = (值) => { if (!已收) { 已收 = true; clearTimeout(闹钟); resolve(值); } };
    // 同取令牌那里的理由：socket timeout 不管连接前的阶段，另起硬闹钟兜底
    const 闹钟 = setTimeout(() => {
      try { 请求.destroy(); } catch (e2) {}
      收({ error: '采集超时：服务器没有在 40 秒内返回' });
    }, 40000);
    const 请求 = https.request({
      hostname: u.hostname,
      port: u.port || 443,
      path: u.pathname,
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'multipart/form-data; boundary=' + 边界,
        'Content-Length': 请求体.length
      },
      timeout: 38000
    }, (响应) => {
      let 数据 = '';
      响应.on('data', (块) => { 数据 += 块; });
      响应.on('end', () => {
        try { 收(JSON.parse(数据)); }
        catch (err) {
          终端日志('采集配置返回非 JSON，HTTP ' + 响应.statusCode +
            ' 前 200 字：' + String(数据).slice(0, 200));
          收({ error: '返回格式异常（HTTP ' + 响应.statusCode + '）' });
        }
      });
    });
    请求.on('timeout', () => { try { 请求.destroy(); } catch (e2) {} 收({ error: '采集超时' }); });
    请求.on('error', (err) => 收({ error: '请求失败：' + err.message }));
    请求.end(请求体);
  });
});
ipcMain.handle('终端:读剪贴板', async () => {
  return clipboard.readText() || '';
});
ipcMain.handle('终端:写剪贴板', async (e, 文本) => {
  clipboard.writeText(String(文本 || ''));
  return true;
});

/* ---------- 终端本地直连 ----------
   终端原先走 wss://本站/ws/ 由服务端代连，目标机 sshd 日志里留下的是源站 IP。
   改成客户端直接连目标机之后，日志里是用户自己的出口 IP，本站不参与连接。
   与命令卡片那条路（本地SSH:起/读）的区别：这里是长连接的交互式 shell，
   会话跟终端窗口同生共死，不走连接池（池子空闲 3 分钟就断，会掐掉开着的终端）。
   会话号用终端窗口的 webContents.id，天然唯一且窗口关掉就失效。 */
ipcMain.handle('终端:直连开', async (e) => {
  const wcId = e.sender.id;
  const 参 = 终端参数们.get(wcId);
  if (!参) { return { error: '连接参数已失效，请关掉窗口重新打开' }; }
  const 主机id = Number(参.主机id) || 0;
  const token = 终端密钥们.get(String(主机id));
  if (!token) { return { error: '会话已失效，请关掉终端窗口重新打开' }; }
  const 凭 = await 备好凭据(主机id, token);
  if (凭.error) { return { error: 凭.error }; }
  const 送 = (通道, 载荷) => {
    // 窗口可能在数据回来之前就被关掉了，销毁后再 send 会抛异常
    if (!e.sender.isDestroyed()) { try { e.sender.send(通道, 载荷); } catch (x) {} }
  };
  const r = await 本地SSH.开终端(wcId, 凭.凭据, {
    出数据: (d) => 送('终端:数据', d),
    关闭: () => 送('终端:已断', null)
  });
  if (!r.ok) {
    // 指纹不符要清掉凭据缓存，否则用户反复撞同一个错
    if (r.指纹不符) { 凭据们.delete(主机id); }
    return { error: r.error };
  }
  // 首连拿到指纹就回存服务端，之后每次连接都校验
  if (r.fingerprint && !String(凭.凭据.fingerprint || '').trim()) {
    回存指纹(主机id, r.fingerprint, token);
    凭.凭据.fingerprint = r.fingerprint;
  }
  return { ok: 1, user: r.user, host: r.host };
});
ipcMain.handle('终端:直连写', (e, 数据) => {
  return 本地SSH.写终端(e.sender.id, 数据) ? { ok: 1 } : { error: '会话不存在' };
});
ipcMain.handle('终端:直连尺寸', (e, cols, rows) => {
  return 本地SSH.改尺寸(e.sender.id, cols, rows) ? { ok: 1 } : { error: '会话不存在' };
});
ipcMain.handle('终端:直连关', (e) => {
  本地SSH.关终端(e.sender.id);
  return { ok: 1 };
});
/* ---------- 文件管理窗口 ----------
   独立 BrowserWindow，贴在对应终端窗口的右边缘，跟着它移动和缩放。
   为什么不做成终端窗口里的右侧分栏：那样会挤掉终端的宽度，
   xterm 的列数跟着变，正在跑的 top/vim 会重排。独立窗口不动终端一根头发。
   为什么不设 parent：设了父窗口在 Windows 上就永远压在父窗口上方，
   而且父窗口最小化时子窗口的行为很别扭。这里手动同步位置，
   要的效果（跟随移动、能单独关）都能拿到，代价只是几个监听器。 */
const 文件窗口们 = new Map();   // 主机 id(字符串) -> BrowserWindow
const 文件窗宽 = 460;
/* 把文件窗贴到终端窗右边。
   用 getBounds 而不是 getPosition：还要知道终端窗多高，
   文件窗跟它一样高看起来才是一体的。 */
function 贴右侧(终端窗, 文件窗) {
  if (!终端窗 || 终端窗.isDestroyed()) return;
  if (!文件窗 || 文件窗.isDestroyed()) return;
  const b = 终端窗.getBounds();
  const 目标 = { x: b.x + b.width + 6, y: b.y, width: 文件窗宽, height: b.height };
  // 别让它跑到屏幕外。贴不下就翻到终端窗左边去
  try {
    const 屏 = screen.getDisplayMatching(b).workArea;
    if (目标.x + 目标.width > 屏.x + 屏.width) {
      const 左边 = b.x - 6 - 文件窗宽;
      目标.x = 左边 >= 屏.x ? 左边 : Math.max(屏.x, 屏.x + 屏.width - 文件窗宽);
    }
  } catch (err) {}
  const 现 = 文件窗.getBounds();
  // 位置没变就不设，setBounds 在 Windows 上会引起可见的抖动
  if (现.x !== 目标.x || 现.y !== 目标.y ||
      现.width !== 目标.width || 现.height !== 目标.height) {
    try { 文件窗.setBounds(目标); } catch (err) {}
  }
}
ipcMain.handle('sftp:开窗', async (e) => {
  const 参 = 终端参数们.get(e.sender.id);
  if (!参) return { error: '连接参数已失效，请关掉窗口重新打开' };
  const 键 = String(参.主机id);
  const 旧 = 文件窗口们.get(键);
  if (旧 && !旧.isDestroyed()) {
    // 已经开着就聚焦，不再开第二个
    if (旧.isMinimized()) 旧.restore();
    旧.focus();
    return { ok: 1, 复用: 1 };
  }
  const 终端窗 = BrowserWindow.fromWebContents(e.sender);
  const b = 终端窗 ? 终端窗.getBounds() : { x: 100, y: 100, width: 900, height: 560 };
  const 窗 = new BrowserWindow({
    width: 文件窗宽,
    height: b.height,
    x: b.x + b.width + 6,
    y: b.y,
    minWidth: 360,
    minHeight: 240,
    title: '文件 · ' + (参.主机名 || 参.主机id),
    backgroundColor: '#12141a',
    autoHideMenuBar: true,
    webPreferences: {
      preload: path.join(__dirname, 'preload-files.js'),
      nodeIntegration: false,
      contextIsolation: true
    }
  });
  const 文件wcId = 窗.webContents.id;
  文件窗口们.set(键, 窗);
  /* 关键：把文件窗的 webContents id 也登记到 终端参数们。
     上面那些 文件:xxx handler 都用 e.sender.id 反查主机，
     不登记的话文件窗发过来的请求会被当成「参数已失效」。
     两个 id 指向同一份参数对象，主机 id 自然一致。 */
  终端参数们.set(文件wcId, 参);
  // 跟随终端窗移动/缩放。move 在拖动过程中会连续触发，直接同步就行，
  // 加节流反而会让它跟不上手，看起来像卡顿
  const 同步 = () => 贴右侧(终端窗, 窗);
  if (终端窗) {
    终端窗.on('move', 同步);
    终端窗.on('resize', 同步);
    // 终端窗最小化时文件窗也收起来，两个窗口该表现得像一个整体
    终端窗.on('minimize', () => { try { 窗.minimize(); } catch (err) {} });
    终端窗.on('restore', () => { try { 窗.restore(); 同步(); } catch (err) {} });
    // 终端窗关了，文件窗留着没有意义（连接都跟着终端走的）
    终端窗.on('closed', () => { try { if (!窗.isDestroyed()) 窗.close(); } catch (err) {} });
  }
  窗.on('closed', () => {
    文件窗口们.delete(键);
    终端参数们.delete(文件wcId);
    // 监听器要摘掉，否则终端窗上会越积越多（反复开关文件窗时）
    if (终端窗 && !终端窗.isDestroyed()) {
      终端窗.removeListener('move', 同步);
      终端窗.removeListener('resize', 同步);
    }
  });
  窗.webContents.on('console-message', (ev, 级别, 消息, 行号, 源) => {
    const 名 = ['调试', '信息', '警告', '错误'][级别] || '信息';
    终端日志('[文件页·' + 名 + '] ' + 消息 + '  (' + 源 + ':' + 行号 + ')');
  });
  窗.webContents.on('preload-error', (ev, 路径, 错) => {
    终端日志('[文件页·preload 出错] ' + 路径 + ' → ' + (错 && 错.message));
  });
  await 窗.loadFile(path.join(__dirname, 'renderer', 'files.html'));
  return { ok: 1 };
});
/* ---------- 文件管理（SFTP）----------
   终端窗口里的「文件管理」用这套。连接从 本地SSH 的池子里借：
   终端已经直连着目标机了，文件管理另开一条会在目标机上多一条登录记录，
   还要重新走认证。借池子顺带共享了断线回收。
   每个操作都自己取一次连接（池里是同一条，取到就返回），用完归还。 */
async function 取文件连接(e) {
  const 参 = 终端参数们.get(e.sender.id);
  if (!参) return { error: '连接参数已失效，请关掉窗口重新打开' };
  const 主机id = Number(参.主机id) || 0;
  const token = 终端密钥们.get(String(主机id));
  if (!token) return { error: '会话已失效，请关掉终端窗口重新打开' };
  const 凭 = await 备好凭据(主机id, token);
  if (凭.error) return { error: 凭.error };
  const c = await 本地SSH.取连接(主机id, 凭.凭据);
  if (!c.ok) return { error: c.error || '连接失败' };
  return { ok: 1, conn: c.conn, 主机id };
}
/* 包一层：取连接、跑活儿、归还。省得每个 handler 都写一遍 */
async function 文件操作(e, 活儿) {
  const c = await 取文件连接(e);
  if (!c.ok) return { error: c.error };
  try {
    return await 活儿(c.conn);
  } catch (err) {
    return { error: String(err && err.message ? err.message : err) };
  } finally {
    本地SSH.归还(c.主机id);
  }
}
ipcMain.handle('sftp:列', (e, 目录) =>
  文件操作(e, (conn) => 本地SFTP.列(conn, 目录)));
ipcMain.handle('sftp:读', (e, 路径) =>
  文件操作(e, (conn) => 本地SFTP.读(conn, 路径)));
ipcMain.handle('sftp:写', (e, 路径, 内容) =>
  文件操作(e, (conn) => 本地SFTP.写(conn, 路径, 内容)));
ipcMain.handle('sftp:传', (e, 目录, 名, 字节) =>
  文件操作(e, (conn) => 本地SFTP.传(conn, 目录, 名, Buffer.from(字节))));
ipcMain.handle('sftp:建目录', (e, 路径) =>
  文件操作(e, (conn) => 本地SFTP.建目录(conn, 路径)));
ipcMain.handle('sftp:改名', (e, 旧, 新) =>
  文件操作(e, (conn) => 本地SFTP.改名(conn, 旧, 新)));
ipcMain.handle('sftp:家', (e) =>
  文件操作(e, (conn) => 本地SFTP.家(conn)));
/* 删除不在这里问「确定吗」，确认由渲染层做：
   主进程弹 dialog 会挡住终端窗口，而且删多个时要弹很多次。 */
ipcMain.handle('sftp:删', (e, 路径, 是目录) =>
  文件操作(e, (conn) => 本地SFTP.删(conn, 路径, !!是目录)));
/* 下载：SFTP 读出字节后弹保存对话框写盘。
   不用 session 的下载管理器，那套是给 http 下载用的。 */
ipcMain.handle('sftp:下', async (e, 路径, 名) => {
  const r = await 文件操作(e, (conn) => 本地SFTP.下(conn, 路径));
  if (!r || r.error) return r || { error: '下载失败' };
  const 窗 = BrowserWindow.fromWebContents(e.sender);
  const 存 = await dialog.showSaveDialog(窗, {
    title: '保存到本地',
    defaultPath: path.join(app.getPath('downloads'), String(名 || 'download'))
  });
  if (存.canceled || !存.filePath) return { ok: 1, 取消: 1 };
  try {
    fs.writeFileSync(存.filePath, r.字节);
    return { ok: 1, 存到: 存.filePath, 大小: r.大小 };
  } catch (err) {
    return { error: '写入本地失败：' + (err && err.message ? err.message : err) };
  }
});
/* ---------- SSH 终端窗口 ----------
   独立的 BrowserWindow，不是主窗口里的弹层，所以能随意拖到桌面任何位置、
   也能拖到第二块屏幕上。用系统边框（frame 默认 true），拖动和缩放交给系统处理最稳。
   同一台主机重复点只聚焦已开的窗口，避免开出一堆连着同一台机器的终端。 */
const 终端窗口们 = new Map();   // 主机 id -> BrowserWindow

ipcMain.handle('终端:开', async (e, 主机id, 主机名, token) => {
  const 键 = String(主机id);
  const 旧 = 终端窗口们.get(键);
  if (旧 && !旧.isDestroyed()) {
    if (旧.isMinimized()) 旧.restore();
    旧.focus();
    return { ok: 1, 复用: 1 };
  }

  const 窗 = new BrowserWindow({
    width: 900,
    height: 560,
    minWidth: 420,
    minHeight: 260,
    title: 'SSH · ' + (主机名 || 主机id),
    backgroundColor: '#12141a',
    autoHideMenuBar: true,
    // 不设 parent：挂了父窗口就只能待在父窗口上方，用户想单独摆到别处就做不到
    webPreferences: {
      preload: path.join(__dirname, 'preload-term.js'),
      nodeIntegration: false,
      contextIsolation: true
    }
  });
  const wcId = 窗.webContents.id;   // closed 里 webContents 已销毁，得先存下来
  终端窗口们.set(键, 窗);
  终端密钥们.set(键, String(token || ''));
  窗.on('closed', () => {
    终端窗口们.delete(键);
    终端密钥们.delete(键);   // 窗口关了就不留密钥在内存里
    终端参数们.delete(wcId);
    本地SSH.关终端(wcId);    // 直连会话跟窗口同生共死，不关会漏一条 SSH 连接
  });

  // 参数存起来给页面自己来取。
  // 以前是 loadFile 后直接 send，但 loadFile 的 await 只等到导航完成，
  // 此时 term.js 还没执行完，window.终端参数就绪 尚未定义，
  // preload 里的 readyState 也已经不是 loading，于是消息直接丢了，
  // 页面就永远卡在“准备连接…”。改成页面主动要，不再拼时序。
  // 不把密钥下发给终端页：令牌由主进程代取，页面只需要知道连哪个 ws 地址。
  终端参数们.set(窗.webContents.id, {
    ws地址: 服务端.replace(/^https:/, 'wss:').replace(/^http:/, 'ws:') + '/ws/',
    主机id: Number(主机id),
    主机名: String(主机名 || '')
  });
  // 把终端页的报错摊到主进程控制台。
  // 没这一层的时候，页面里报任何异常都只表现为“状态栏不动”，
  // 根本无法判断是哪一步挂的。
  窗.webContents.on('console-message', (ev, 级别, 消息, 行号, 源) => {
    const 名 = ['调试', '信息', '警告', '错误'][级别] || '信息';
    const 行 = '[终端页·' + 名 + '] ' + 消息 + '  (' + 源 + ':' + 行号 + ')';
    console.log(行);
    终端日志(行);
  });
  窗.webContents.on('preload-error', (ev, 路径, 错) => {
    const 行 = '[终端页·preload 出错] ' + 路径 + ' → ' + (错 && 错.message);
    console.log(行);
    终端日志(行);
  });
  窗.webContents.on('render-process-gone', (ev, 详情) => {
    const 行 = '[终端页·渲染进程挂了] ' + JSON.stringify(详情);
    console.log(行);
    终端日志(行);
  });
  窗.webContents.on('did-fail-load', (ev, 码, 说明) => {
    终端日志('[终端页·加载失败] ' + 码 + ' ' + 说明);
  });
  await 窗.loadFile(path.join(__dirname, 'renderer', 'term.html'));
  // 设了 YANYANG_TERM_DEBUG 环境变量就自动开开发者工具，
  // 方便现场排查而不用重新打包一个调试版。
  if (process.env.YANYANG_TERM_DEBUG) {
    窗.webContents.openDevTools({ mode: 'bottom' });
  }
  return { ok: 1 };
});

/* ---------- 读剪贴板图片 ----------
   Windows 系统截图（Win+Shift+S、QQ/微信截图）放进剪贴板的是 CF_DIB 位图，
   Chromium 的 clipboardData.items 里没有 image/* 项，渲染层的 paste 事件拿不到图。
   走 Electron 的 clipboard.readImage() 才读得到，转成 PNG 字节交回渲染层。
   返回 null 表示剪贴板里确实没有图片，渲染层据此决定是否放行默认粘贴行为。 */
ipcMain.handle('图:读剪贴板', async () => {
  try {
    const 图 = clipboard.readImage();
    if (!图 || 图.isEmpty()) return null;
    const buf = 图.toPNG();
    if (!buf || !buf.length) return null;
    const 尺 = 图.getSize();
    return { 字节: new Uint8Array(buf), 宽: 尺.width, 高: 尺.height };
  } catch (err) {
    return { error: '读剪贴板失败：' + err.message };
  }
});

/* ---------- 取图 ----------
   /api/img.php 和 /api/qrcode.php 都要 Bearer 头，而 <img src> 发不出请求头。
   所以由主进程带着密钥把二进制抓回来，转成 data URL 交给界面直接塞进 src。
   支付二维码走的也是这个通道，路径传 /api/qrcode.php?order_no=xxx 即可。 */
ipcMain.handle('图:取', async (e, 路径, token) => {
  const u = new URL(路径, 服务端);
  return new Promise((resolve) => {
    const 请求 = https.request({
      hostname: u.hostname,
      port: u.port || 443,
      path: u.pathname + u.search,
      method: 'GET',
      headers: { 'Authorization': 'Bearer ' + token },
      timeout: 30000
    }, (响应) => {
      if (响应.statusCode !== 200) {
        响应.resume();   // 不读完连接不会释放
        resolve({ error: '取图失败（HTTP ' + 响应.statusCode + '）' });
        return;
      }
      const 块们 = [];
      响应.on('data', (块) => 块们.push(块));
      响应.on('end', () => {
        const 全 = Buffer.concat(块们);
        const 类型 = String(响应.headers['content-type'] || 'image/png').split(';')[0];
        resolve({ ok: 1, data: 'data:' + 类型 + ';base64,' + 全.toString('base64') });
      });
    });
    请求.on('timeout', () => { 请求.destroy(); resolve({ error: '取图超时' }); });
    请求.on('error', (err) => resolve({ error: '网络错误：' + err.message }));
    请求.end();
  });
});
/* ---------- SSE 流式对话 ----------
   在跑的流按编号存着，停止时要能精确掐掉对应那一条。 */
const 在跑的流 = new Map();

ipcMain.handle('对话:开始', async (e, { 流号, 参数, token }) => {
  const 正文 = 拼表单(参数);
  const u = new URL('/api/chat.php', 服务端);
  const 发 = (事件, 数据) => {
    if (主窗口 && !主窗口.isDestroyed()) {
      主窗口.webContents.send('流', { 流号, 事件, 数据 });
    }
  };

  const 请求 = https.request({
    hostname: u.hostname,
    port: u.port || 443,
    path: u.pathname,
    method: 'POST',
    headers: {
      'Authorization': 'Bearer ' + token,
      'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8',
      'Content-Length': Buffer.byteLength(正文),
      'Accept': 'text/event-stream',
      'X-Client-Type': 'desktop'
    },
    // 流式不设总超时，长回答会跑很久；只靠底层 TCP 保活
    timeout: 0
  }, (响应) => {
    if (响应.statusCode !== 200) {
      // 402 余额不足、401 密钥失效这些，后端回的是 JSON 不是 SSE
      let 正文2 = '';
      响应.setEncoding('utf8');
      响应.on('data', (块) => { 正文2 += 块; });
      响应.on('end', () => {
        let 提示 = '请求失败（HTTP ' + 响应.statusCode + '）';
        try { 提示 = JSON.parse(正文2).error || 提示; } catch (err) { /* 非 JSON 就用兜底文案 */ }
        发('错误', { msg: 提示 });
        发('结束', {});
        在跑的流.delete(流号);
      });
      return;
    }

    响应.setEncoding('utf8');
    let 缓冲 = '';
    响应.on('data', (块) => {
      缓冲 += 块;
      // SSE 以空行分隔一条消息。最后一段可能不完整，留在缓冲里等下一个包。
      const 各条 = 缓冲.split('\n\n');
      缓冲 = 各条.pop();
      各条.forEach((一条) => {
        let 事件名 = 'message';
        const 数据行 = [];
        一条.split('\n').forEach((行) => {
          if (行.startsWith('event: ')) 事件名 = 行.slice(7).trim();
          else if (行.startsWith('data: ')) 数据行.push(行.slice(6));
        });
        if (!数据行.length) return;
        let 数据 = {};
        try { 数据 = JSON.parse(数据行.join('\n')); } catch (err) { return; }

        // 后端事件名映射到界面用的中文名
        const 映射 = {
          meta: '开始', delta: '增量', fold: '思考',
          tool_result: '工具', stopped: '已停', err: '错误', done: '完成'
        };
        发(映射[事件名] || 事件名, 数据);
      });
    });
    响应.on('end', () => { 发('结束', {}); 在跑的流.delete(流号); });
    响应.on('aborted', () => { 发('结束', {}); 在跑的流.delete(流号); });
  });

  请求.on('error', (err) => {
    // 主动停止时 destroy 也会触发 error，这时别再弹错误提示
    if (在跑的流.has(流号)) {
      发('错误', { msg: '连接中断：' + err.message });
      发('结束', {});
      在跑的流.delete(流号);
    }
  });

  在跑的流.set(流号, 请求);
  请求.write(正文);
  请求.end();
  return { ok: true };
});

/* 停止生成。先掐本地连接让界面立刻停住，
   再调 chat_stop.php 落 stop_req 标记，让服务端那轮也真正结束。
   conv_id 一并带上：run_id 可能是过期的那轮，后端会退回到会话里still running 的任务。 */
ipcMain.handle('对话:停止', async (e, { 流号, run_id, conv_id, token }) => {
  const 请求 = 在跑的流.get(流号);
  在跑的流.delete(流号);
  if (请求) { try { 请求.destroy(); } catch (err) { /* 已断开 */ } }

  if (!run_id && !conv_id) return { ok: 1 };
  const 正文 = 拼表单({ run_id: run_id || 0, conv_id: conv_id || 0 });
  const u = new URL('/api/chat_stop.php', 服务端);
  return new Promise((resolve) => {
    const r = https.request({
      hostname: u.hostname, port: u.port || 443, path: u.pathname, method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8',
        'Content-Length': Buffer.byteLength(正文)
      },
      timeout: 10000
    }, (响应) => {
      let 正文2 = '';
      响应.setEncoding('utf8');
      响应.on('data', (块) => { 正文2 += 块; });
      响应.on('end', () => {
        try { resolve(JSON.parse(正文2)); } catch (err) { resolve({ ok: 1 }); }
      });
    });
    r.on('timeout', () => { r.destroy(); resolve({ ok: 0 }); });
    r.on('error', () => resolve({ ok: 0 }));
    r.write(正文);
    r.end();
  });
});
/* ================= 本地 SSH 直连 =================
   以前命令由服务端代连执行，目标机的 sshd 日志里留下的是本站源站 IP。
   改成客户端本地直连后，目标机只看得到用户自己的出口 IP，本站不参与连接。
   分工：
     凭据仍存在服务端（加密），客户端用完即弃，不落盘。
     连接、执行、读进度全在本地，不经过服务端。
     执行结果由渲染层回传给 AI（走对话接口），服务端只拿到结果文本，
     这是 AI 分析所必需的，跟「谁去连服务器」是两回事。
   为什么凭据不缓存到磁盘：缓存了就等于在用户电脑上又存一份服务器密码，
   而客户端没有服务端那套主密钥保护。内存里留一份、进程退出即消失，
   代价只是每台主机每次会话多一个 HTTP 往返。 */
const 本地SSH = require('./本地ssh.js');
// 文件管理（SFTP）。复用上面 本地SSH 的连接池，不自己建连接
const 本地SFTP = require('./本地sftp.js');
/* 凭据内存缓存。键是主机 id。只在本次进程存活期间有效。
   带 时间 是为了让它别一直留着——用户在网页端改了密码，
   客户端这边不该拿着旧凭据反复失败。 */
const 凭据们 = new Map();
const 凭据有效期 = 10 * 60 * 1000;
/** 向服务端取一台主机的凭据。走 /api/ssh_cred.php，必须 HTTPS。 */
function 取凭据(主机id, token) {
  const 表单 = 拼表单({ host_id: 主机id });
  const u = new URL('/api/ssh_cred.php', 服务端);
  return new Promise((resolve) => {
    // 跟取终端令牌一个道理：卡在 DNS/TLS 阶段 socket timeout 不触发，
    // 另起硬闹钟兜底
    let 已收 = false;
    const 收 = (值) => { if (!已收) { 已收 = true; clearTimeout(闹钟); resolve(值); } };
    const 闹钟 = setTimeout(() => {
      try { 请求.destroy(); } catch (e) {}
      收({ error: '取凭据超时：连不上服务器，请检查网络' });
    }, 18000);
    const 请求 = https.request({
      hostname: u.hostname,
      port: u.port || 443,
      path: u.pathname,
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8',
        'Content-Length': Buffer.byteLength(表单)
      },
      timeout: 15000
    }, (响应) => {
      let 数据 = '';
      响应.setEncoding('utf8');
      响应.on('data', (块) => { 数据 += 块; });
      响应.on('end', () => {
        try { 收(JSON.parse(数据)); }
        catch (err) { 收({ error: '取凭据返回格式异常（HTTP ' + 响应.statusCode + '）' }); }
      });
    });
    请求.on('timeout', () => { 请求.destroy(); 收({ error: '取凭据超时' }); });
    请求.on('error', (err) => 收({ error: '网络错误：' + err.message }));
    请求.write(表单);
    请求.end();
  });
}
/** 拿凭据：先看缓存，过期或没有就向服务端要。 */
async function 备好凭据(主机id, token) {
  const 缓 = 凭据们.get(主机id);
  if (缓 && Date.now() - 缓.时间 < 凭据有效期) { return { ok: 1, 凭据: 缓.凭据 }; }
  const r = await 取凭据(主机id, token);
  if (!r || r.error || !r.ok) { return { error: (r && r.error) || '取凭据失败' }; }
  凭据们.set(主机id, { 凭据: r, 时间: Date.now() });
  return { ok: 1, 凭据: r };
}
/** 首次连接拿到指纹后回存服务端，下次才能比对。失败不影响本次执行。 */
function 回存指纹(主机id, 指纹, token) {
  const 表单 = 拼表单({ act: 'fingerprint', host_id: 主机id, fingerprint: 指纹 });
  const u = new URL('/api/ssh_hosts.php', 服务端);
  const r = https.request({
    hostname: u.hostname, port: u.port || 443, path: u.pathname, method: 'POST',
    headers: {
      'Authorization': 'Bearer ' + token,
      'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8',
      'Content-Length': Buffer.byteLength(表单)
    },
    timeout: 10000
  }, (响应) => { 响应.resume(); });
  r.on('timeout', () => { try { r.destroy(); } catch (e) {} });
  r.on('error', () => {});
  r.write(表单);
  r.end();
}
/* ---- 起任务：本地连上目标机，把命令丢到它的后台 ---- */
ipcMain.handle('本地SSH:起', async (e, { 主机id, 命令, token }) => {
  const id = Number(主机id) || 0;
  const cmd = String(命令 || '').trim();
  if (!id) { return { error: '主机编号不正确' }; }
  if (cmd === '') { return { error: '命令为空' }; }
  const 凭 = await 备好凭据(id, token);
  if (凭.error) { return { error: 凭.error }; }
  const c = await 本地SSH.取连接(id, 凭.凭据);
  if (!c.ok) {
    // 指纹不符是安全事件，凭据缓存一并清掉，避免用户反复撞同一个错
    if (c.指纹不符) { 凭据们.delete(id); }
    return { error: c.error };
  }
  // 首连拿到指纹就回存，之后每次都能校验
  if (!c.复用 && c.fingerprint && !String(凭.凭据.fingerprint || '').trim()) {
    回存指纹(id, c.fingerprint, token);
    凭.凭据.fingerprint = c.fingerprint;
  }
  try {
    本地SSH.清残留(c.conn);       // 顺手清一天前的残留，不等它
    const r = await 本地SSH.起任务(c.conn, cmd);
    if (!r.ok) { return { error: r.error }; }
    return { ok: 1, job: r.job, host_id: id };
  } catch (err) {
    return { error: '起任务出错：' + ((err && err.message) || err) };
  } finally {
    本地SSH.归还(id);
  }
});
/* ---- 读进度：from 之后新增的输出 ---- */
ipcMain.handle('本地SSH:读', async (e, { 主机id, job, from, token }) => {
  const id = Number(主机id) || 0;
  if (!id || !job) { return { error: '参数不完整' }; }
  const 凭 = await 备好凭据(id, token);
  if (凭.error) { return { error: 凭.error }; }
  const c = await 本地SSH.取连接(id, 凭.凭据);
  if (!c.ok) { return { error: c.error }; }
  try {
    const r = await 本地SSH.读任务(c.conn, String(job), Number(from) || 0);
    if (!r.ok) { return { error: r.error }; }
    return {
      ok: 1, done: r.done ? 1 : 0, exit: r.exit, out: r.out,
      size: r.size, next: r.next, alive: r.alive ? 1 : 0
    };
  } catch (err) {
    return { error: '读进度出错：' + ((err && err.message) || err) };
  } finally {
    本地SSH.归还(id);
  }
});
/* ---- 终止任务 ---- */
ipcMain.handle('本地SSH:停', async (e, { 主机id, job, token }) => {
  const id = Number(主机id) || 0;
  if (!id || !job) { return { error: '参数不完整' }; }
  const 凭 = await 备好凭据(id, token);
  if (凭.error) { return { error: 凭.error }; }
  const c = await 本地SSH.取连接(id, 凭.凭据);
  if (!c.ok) { return { error: c.error }; }
  try {
    const r = await 本地SSH.停任务(c.conn, String(job));
    return r.ok ? { ok: 1 } : { error: r.error || '终止失败' };
  } catch (err) {
    return { error: '终止出错：' + ((err && err.message) || err) };
  } finally {
    本地SSH.归还(id);
  }
});
/* ---- 主机配置变了：断开旧连接、清掉凭据缓存 ----
   用户在主机管理里改了地址或密码后调，否则池里那条老连接还连着旧目标。 */
ipcMain.handle('本地SSH:失效', (e, 主机id) => {
  const id = Number(主机id) || 0;
  if (id) { 凭据们.delete(id); 本地SSH.断开(id); }
  return { ok: 1 };
});
/* ---- 退出登录时清干净：凭据和连接都不该跨账号留着 ---- */
ipcMain.handle('本地SSH:清空', () => {
  凭据们.clear();
  本地SSH.全断();
  return { ok: 1 };
});

/* ---- 本地文件夹写入 ----
   dialog 已在文件顶部引入（文件管理的下载也要用它，
   放在这里的话我上面那些 handler 会因为 const 不提升而拿不到） */

ipcMain.handle('文件夹:选择', async () => {
  const { canceled, filePaths } = await dialog.showOpenDialog({
    properties: ['openDirectory']
  });
  return canceled ? { ok: 0 } : { ok: 1, path: filePaths[0] };
});

ipcMain.handle('文件:写入本地', async (e, { 目录, 路径, 内容 }) => {
  if (!目录 || !路径 || 内容 === undefined) return { ok: 0, msg: '参数不全' };
  const 完整 = path.join(目录, ...路径.split('/').filter(Boolean));
  try {
    fs.mkdirSync(path.dirname(完整), { recursive: true });
    fs.writeFileSync(完整, 内容, 'utf8');
    return { ok: 1, path: 完整 };
  } catch (err) {
    return { ok: 0, msg: err.message };
  }
});

/* 本地文件夹读取与列表 */
ipcMain.handle('文件:读取本地', async (e, { 目录, 路径 }) => {
  if (!目录 || !路径) return { ok: 0, msg: '参数不全' };
  const 完整 = path.join(目录, ...路径.split('/').filter(Boolean));
  try {
    const 内容 = fs.readFileSync(完整, 'utf8');
    return { ok: 1, content: 内容 };
  } catch (err) {
    return { ok: 0, msg: err.message };
  }
});

ipcMain.handle('文件夹:列出', async (e, { 目录 }) => {
  if (!目录) return { ok: 0, msg: '缺少目录' };
  const 目标 = path.resolve(目录);
  try {
    const 条目们 = fs.readdirSync(目标, { withFileTypes: true });
    const 列表 = 条目们.map(ent => ({
      name: ent.name,
      isDirectory: ent.isDirectory(),
      isFile: ent.isFile()
    }));
    return { ok: 1, list: 列表 };
  } catch (err) {
    return { ok: 0, msg: err.message };
  }
});

