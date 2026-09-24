'use strict';

const { contextBridge, ipcRenderer } = require('electron');

/* 渲染进程只能看到这几个方法，拿不到 fs、https、ipcRenderer 本身。
   密钥全程留在主进程，界面那边只在用户输入和「读出来回填」时碰得到一次，
   不会把 token 存进 localStorage——那玩意儿明文可读。 */
contextBridge.exposeInMainWorld('后端', {
  /* 密钥 */
  存密钥: (token) => ipcRenderer.invoke('密钥:存', token),
  读密钥: () => ipcRenderer.invoke('密钥:读'),
  清密钥: () => ipcRenderer.invoke('密钥:清'),
  服务端地址: () => ipcRenderer.invoke('服务端地址'),

  /* 普通接口。方法默认 POST，只读接口传 'GET'。 */
  调接口: (路径, 参数, token, 方法) =>
    ipcRenderer.invoke('接口', { 路径, 参数, token, 方法: 方法 || 'POST' }),

  /* 流式对话 */
  开始对话: (流号, 参数, token) =>
    ipcRenderer.invoke('对话:开始', { 流号, 参数, token }),
  停止对话: (流号, run_id, conv_id, token) =>
    ipcRenderer.invoke('对话:停止', { 流号, run_id, conv_id, token }),

  /* 图片。文件是 { name, type, 字节 }，字节为 Uint8Array，
     IPC 走结构化克隆能直接传，不必先转 base64。
     取图是因为 /api/img.php 要 Bearer 头，img 标签的 src 发不出请求头。 */
  传图: (文件, token) => ipcRenderer.invoke('图:传', 文件, token),
  /* 通用上传：路径和附加字段自己给，代码仓上传压缩包走这条。 */
  传文件: (路径, 文件, 字段, token) =>
    ipcRenderer.invoke('文件:传', { 路径, 文件, 字段, token }),
  读剪贴板图: () => ipcRenderer.invoke('图:读剪贴板'),
  开终端: (主机id, 主机名, token) => ipcRenderer.invoke('终端:开', 主机id, 主机名, token),
  取图: (路径, token) => ipcRenderer.invoke('图:取', 路径, token),

  /* 窗口提醒。任务跑完时用：窗口没聚焦就闪任务栏图标，
     点通知能把窗口拉到前面来。 */
  闪任务栏: () => ipcRenderer.invoke('窗口:闪'),
  唤起窗口: () => ipcRenderer.invoke('窗口:唤起'),
  /* 自定义标题栏的三个控制按钮。frame: false 之后系统不再画边框，
     最小化/最大化/关闭只能由界面主动调过来。 */
  最小化窗口: () => ipcRenderer.invoke('窗口:最小化'),
  最大化切换: () => ipcRenderer.invoke('窗口:最大化切换'),
  关闭窗口: () => ipcRenderer.invoke('窗口:关闭'),
  是否最大化: () => ipcRenderer.invoke('窗口:是否最大化'),
  /* 订阅最大化状态变化。拖到屏幕顶端、Win+↑ 这些路径不经过按钮，
     得靠主进程广播才能把图标切对。返回退订函数。 */
  收最大化变化: (回调) => {
    const 处理 = (e, 最大化) => 回调(!!最大化);
    ipcRenderer.on('窗口:最大化变了', 处理);
    return () => ipcRenderer.removeListener('窗口:最大化变了', 处理);
  },
  /* 响铃时压低其他程序的音量，响完调 恢复音量 还原。
     不用暂停：暂停会打断用户的播放进度，压低响完就自己还原了。
     系数是压到原音量的几倍，省略为 0.2（两成）。只在 Windows 有效，
     其他平台调了不报错也不生效。
     成对使用——压低了一定要恢复，否则要等 60 秒兜底才还原。 */
  压低音量: (系数) => ipcRenderer.invoke('音量:压低', 系数),
  恢复音量: () => ipcRenderer.invoke('音量:恢复'),
  /* 预览栏。整个预览由主进程的 WebContentsView 承载，
     渲染进程这边只有一个占位 div，负责把它的屏幕坐标报上去。
     为什么不用 <webview>：guest 的绘制表面只认 attach 那一刻的尺寸，
     后面改元素宽高、CSS 缩放、enableDeviceEmulation 的 scale 都改不到它，
     结果页面按大视口布局却只有 150 高的画布，只画出顶上一条。 */
  预览建: (网址) => ipcRenderer.invoke('预览:建', 网址),
  预览销毁: () => ipcRenderer.invoke('预览:销毁'),
  // 范围用 { x, y, 宽, 高 }，CSS 像素，相对窗口左上角
  预览定位: (范围) => ipcRenderer.invoke('预览:定位', 范围),
  预览藏: () => ipcRenderer.invoke('预览:藏'),
  预览设模拟: (规格) => ipcRenderer.invoke('预览:设模拟', 规格),
  预览停模拟: () => ipcRenderer.invoke('预览:停模拟'),
  预览设UA: (串) => ipcRenderer.invoke('预览:设UA', 串),
  // 动作：后退 / 前进 / 刷新 / 停止 / 跳转（跳转时第二个参数传网址）
  预览导航: (动作, 参数) => ipcRenderer.invoke('预览:导航', 动作, 参数),
  预览按钮态: () => ipcRenderer.invoke('预览:按钮态'),
  // 诊断：返回主进程记的 bounds 和页面里实测的视口数字
  预览诊断: () => ipcRenderer.invoke('预览:诊断'),
  /* 预览事件订阅。视图在主进程里，页面加载状态只能这样传回来。
     和 收流 一样，返回退订函数。 */
  预览事件: (名, fn) => {
    const 通道 = {
      加载中: '预览:加载中', 地址: '预览:地址', 按钮: '预览:按钮',
      失败: '预览:失败', 崩了: '预览:崩了'
    }[名];
    if (!通道) { return () => {}; }
    const 处理 = (e, 数据) => fn(数据);
    ipcRenderer.on(通道, 处理);
    return () => ipcRenderer.removeListener(通道, 处理);
  },
  /* 在线更新。检查更新走主进程弹原生对话框，界面只管发起和显示版本号。 */
  检查更新: () => ipcRenderer.invoke('更新:检查'),
  当前版本: () => ipcRenderer.invoke('更新:版本'),
  /* 用系统浏览器打开链接。支付宝网页支付走这条。 */
  开外链: (url) => ipcRenderer.invoke('开外链', url),
  // 支付页开在应用内窗口，不跳系统浏览器
  开支付窗: (url) => ipcRenderer.invoke('开支付窗', url),
  // 支付窗被关掉时通知渲染层，好立刻催一次查单
  支付窗关闭时: (fn) => ipcRenderer.on('支付窗已关', () => fn()),
  /* 订阅下载进度。跟 收流 一样返回退订函数。 */
  收更新进度: (回调) => {
    const 处理 = (e, 载荷) => 回调(载荷);
    ipcRenderer.on('更新:进度', 处理);
    return () => ipcRenderer.removeListener('更新:进度', 处理);
  },
  /* ---- 本地 SSH 直连 ----
     命令不再交服务端代连，改由主进程用 ssh2 直接连目标机，
     目标机的 sshd 日志里留下的是用户自己的出口 IP。
     命令一律在远端后台跑（job 机制），起任务和读进度是两步，
     所以长任务不受 HTTP 生命周期限制，编译几小时也不会被掐断。 */
  本地SSH起: (主机id, 命令, token) =>
    ipcRenderer.invoke('本地SSH:起', { 主机id, 命令, token }),
  本地SSH读: (主机id, job, from, token) =>
    ipcRenderer.invoke('本地SSH:读', { 主机id, job, from, token }),
  本地SSH停: (主机id, job, token) =>
    ipcRenderer.invoke('本地SSH:停', { 主机id, job, token }),
  /* 主机地址或密码改了要调，否则池里那条连接还连着旧目标 */
  本地SSH失效: (主机id) => ipcRenderer.invoke('本地SSH:失效', 主机id),
  /* 退出登录时调：凭据和连接都不该跨账号留着 */
  本地SSH清空: () => ipcRenderer.invoke('本地SSH:清空'),
  /* 订阅流事件。返回退订函数，避免重复登录时监听器越堆越多。 */
  收流: (回调) => {
    const 处理 = (e, 载荷) => 回调(载荷.流号, 载荷.事件, 载荷.数据);
    ipcRenderer.on('流', 处理);
    return () => ipcRenderer.removeListener('流', 处理);
  },
  /* ---- 本地文件夹写入 ---- */
  选择文件夹: () => ipcRenderer.invoke('文件夹:选择'),
  写入本地文件: (目录, 路径, 内容) => ipcRenderer.invoke('文件:写入本地', { 目录, 路径, 内容 }),
  读取本地文件: (目录, 路径) => ipcRenderer.invoke('文件:读取本地', { 目录, 路径 }),
  列出文件夹: (目录) => ipcRenderer.invoke('文件夹:列出', { 目录 })
});
