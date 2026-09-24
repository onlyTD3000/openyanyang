/* 终端窗口专用 preload。
   两件事：给页面提供取连接参数的通道、暴露一个要令牌的通道。
   终端页不持有登录密钥，也不直接发 HTTP 请求（file:// 源发 https 会被判跨源），
   要令牌一律经主进程代办。
   参数改成页面主动要（invoke），不再由主进程 send 推。
   推的做法有竞态：主进程在 loadFile 之后就 send，但那时 term.js 还没执行完，
   接收函数尚未定义，消息就丢了，页面永远卡在「准备连接…」。 */
const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('终端桥', {
  要参数: () => ipcRenderer.invoke('终端:要参数'),
  /* ---- 本地直连 ----
     终端不再连本站的 ws 网关，改由主进程用 ssh2 直接连目标机，
     目标机的登录日志里记录的是用户自己的出口 IP。
     数据是双向流：按键用 直连写 推上去，回显靠 收数据 订阅推下来。 */
  直连开: () => ipcRenderer.invoke('终端:直连开'),
  直连写: (数据) => ipcRenderer.invoke('终端:直连写', 数据),
  直连尺寸: (cols, rows) => ipcRenderer.invoke('终端:直连尺寸', cols, rows),
  直连关: () => ipcRenderer.invoke('终端:直连关'),
  /* 订阅回显。返回退订函数：点重连会重新订阅，
     不退订的话监听器会越堆越多，同一份数据被写进终端好几遍。 */
  收数据: (回调) => {
    const 处理 = (e, d) => 回调(d);
    ipcRenderer.on('终端:数据', 处理);
    return () => ipcRenderer.removeListener('终端:数据', 处理);
  },
  收断开: (回调) => {
    const 处理 = () => 回调();
    ipcRenderer.on('终端:已断', 处理);
    return () => ipcRenderer.removeListener('终端:已断', 处理);
  },
  要令牌: (主机id) => ipcRenderer.invoke('终端:要令牌', 主机id),
  /* 剪贴板经主进程读写。
     navigator.clipboard 在 file:// 页面里要权限、还依赖用户手势，
     Electron 的 clipboard 模块没这些限制，直接可用。 */
  // 查看服务器配置：主进程代发 HTTP 去采集，终端页自己发不了
  要配置: (主机id) => ipcRenderer.invoke('终端:要配置', 主机id),
  /* 打开文件管理窗口。具体的 SFTP 操作在那个窗口自己的 preload 里，
     终端页不碰文件，免得两个页面都能改远端文件 */
  开文件窗: () => ipcRenderer.invoke('sftp:开窗'),
  读剪贴板: () => ipcRenderer.invoke('终端:读剪贴板'),
  写剪贴板: (文本) => ipcRenderer.invoke('终端:写剪贴板', 文本)
});
