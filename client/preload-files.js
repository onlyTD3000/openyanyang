/* 文件管理窗口专用 preload。
   只暴露 SFTP 那几个通道，不给这个页面任何别的能力。
   通道名和主进程里注册的一致（文件:xxx），主进程靠 e.sender.id
   找出这个窗口对应哪台主机，所以这里不用也不该传主机 id —— 
   传了反而给了页面指定任意主机的机会。 */
const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('文件桥', {
  列: (目录) => ipcRenderer.invoke('sftp:列', 目录),
  读: (路径) => ipcRenderer.invoke('sftp:读', 路径),
  写: (路径, 内容) => ipcRenderer.invoke('sftp:写', 路径, 内容),
  /* 字节用 Uint8Array 传。File 和 ArrayBuffer 直接过 contextBridge
     会被结构化克隆搞成空对象，主进程那边收到的是 {} */
  传: (目录, 名, 字节) => ipcRenderer.invoke('sftp:传', 目录, 名, 字节),
  下: (路径, 名) => ipcRenderer.invoke('sftp:下', 路径, 名),
  删: (路径, 是目录) => ipcRenderer.invoke('sftp:删', 路径, 是目录),
  建目录: (路径) => ipcRenderer.invoke('sftp:建目录', 路径),
  改名: (旧, 新) => ipcRenderer.invoke('sftp:改名', 旧, 新),
  家: () => ipcRenderer.invoke('sftp:家')
});
