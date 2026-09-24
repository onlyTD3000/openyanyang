'use strict';
/**
 * 响铃时压低其他程序的音量，响完恢复。
 *
 * 底层是 duck.ps1 调 Windows Core Audio，这边负责三件事：
 *   1. 把 PowerShell 拉起来常驻，不是每次响铃新起一个进程
 *   2. 决定什么时候恢复
 *   3. 出任何问题都别影响响铃本身
 *
 * 为什么要常驻：ps1 里的 Add-Type 要现场编译 C#，冷启动一两秒。
 * 等它编译完铃都响完了，压低就没意义。所以开机拉起来一直挂着，
 * 响铃时只往 stdin 写一行，毫秒级。
 *
 * 只在 Windows 上有效，其他平台所有方法都是空操作。
 */
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const 是否可用 = process.platform === 'win32';
let 进程 = null;
let 就绪 = false;
let 恢复定时器 = null;
let 已压低 = false;
let 放弃 = false;       // 启动失败过就不再重试，避免反复拉起失败的进程
function 日志(...参数) {
  console.log('[压低音量]', ...参数);
}
/**
 * 把 ps1 拷到临时目录，返回可执行的路径。
 *
 * 打包后 duck.ps1 在 app.asar 里面。Node 的 fs 能读 asar（Electron 打了补丁），
 * 但 PowerShell 是外部进程，它眼里 asar 就是一个二进制文件，
 * 没法从里面取出某个路径。所以必须先落地到真实文件系统。
 */
function 准备脚本() {
  const 源 = path.join(__dirname, 'duck.ps1');
  const 内容 = fs.readFileSync(源);          // 按 Buffer 读，保住 UTF-8 BOM
  const 目标目录 = path.join(os.tmpdir(), 'yy-chat-audio');
  fs.mkdirSync(目标目录, { recursive: true });
  const 目标 = path.join(目标目录, 'duck.ps1');
  // 已存在且内容一致就不重写，省一次磁盘写入。
  // 版本升级后脚本变了，这里的比较能自动覆盖旧的。
  try {
    if (fs.readFileSync(目标).equals(内容)) return 目标;
  } catch (e) { /* 不存在或读不了，往下写 */ }
  fs.writeFileSync(目标, 内容);
  return 目标;
}
/** 拉起常驻进程。可以重复调用，已经活着就直接返回。 */
function 启动() {
  if (!是否可用 || 放弃 || 进程) return;
  let 脚本路径;
  try {
    脚本路径 = 准备脚本();
  } catch (e) {
    日志('脚本落地失败，功能停用：', e.message);
    放弃 = true;
    return;
  }
  try {
    进程 = spawn('powershell.exe', [
      '-NoProfile',
      '-ExecutionPolicy', 'Bypass',
      '-File', 脚本路径,
      String(process.pid),        // 传自己的 pid，让 ps1 跳过本进程的会话
    ], {
      windowsHide: true,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
  } catch (e) {
    日志('拉起 PowerShell 失败，功能停用：', e.message);
    放弃 = true;
    进程 = null;
    return;
  }
  let 缓冲 = '';
  进程.stdout.on('data', (块) => {
    缓冲 += 块.toString();
    let 换行;
    while ((换行 = 缓冲.indexOf('\n')) >= 0) {
      const 行 = 缓冲.slice(0, 换行).trim();
      缓冲 = 缓冲.slice(换行 + 1);
      if (行 === '') continue;
      if (行 === 'ready') {
        就绪 = true;
        日志('已就绪');
      } else if (行.startsWith('ducked')) {
        // ducked 后面是这次改了几个会话，0 说明当时没有别的程序在出声
        日志(行 === 'ducked 0' ? '当前无其他程序出声' : 行);
      } else if (行.startsWith('err')) {
        日志(行);
      }
    }
  });
  进程.stderr.on('data', (块) => {
    const 文本 = 块.toString().trim();
    if (文本) 日志('PowerShell 报错：', 文本);
  });
  进程.on('exit', (码) => {
    日志('进程退出，码', 码);
    进程 = null;
    就绪 = false;
    已压低 = false;
    if (恢复定时器) { clearTimeout(恢复定时器); 恢复定时器 = null; }
    // 不自动重启。真要挂了大概是环境问题，反复重启只会刷日志。
    // 下次调用 启动() 会再试一次。
  });
  进程.on('error', (e) => {
    日志('进程出错：', e.message);
    放弃 = true;
  });
}
/** 往 stdin 写一行指令。写不进去就当没这回事，不抛错。 */
function 发指令(指令) {
  if (!进程 || !进程.stdin || 进程.stdin.destroyed) return false;
  try {
    进程.stdin.write(指令 + '\n');
    return true;
  } catch (e) {
    // 进程刚死但 exit 事件还没到，会走到这里。忽略即可。
    日志('指令发送失败：', e.message);
    return false;
  }
}
/**
 * 压低其他程序的音量。
 *
 * @param {number} 系数     压到原音量的几倍，0.2 表示压到两成。范围 0~1。
 * @param {number} 兜底毫秒 最长压这么久，到点强制恢复。防止 恢复() 因为
 *                          异常没被调用，音量一直低着。
 */
function 压低(系数 = 0.2, 兜底毫秒 = 60000) {
  if (!是否可用) return;
  启动();   // 没起来就顺手起一下，起过了是空操作
  // 还没编译完就跳过这次。排队等没意义——等到能压的时候铃已经响完了，
  // 那时候压下去反而是在用户接起电话之后才生效。
  if (!就绪) {
    日志('尚未就绪，跳过本次压低');
    return;
  }
  const 有效系数 = Math.min(1, Math.max(0, 系数));
  if (!发指令('duck ' + 有效系数.toFixed(2))) return;
  已压低 = true;
  if (恢复定时器) clearTimeout(恢复定时器);
  恢复定时器 = setTimeout(() => {
    恢复定时器 = null;
    日志('到兜底时间，强制恢复');
    恢复();
  }, 兜底毫秒);
}
/** 恢复到压低前的音量。没压低过就什么都不做。 */
function 恢复() {
  if (!是否可用) return;
  if (恢复定时器) { clearTimeout(恢复定时器); 恢复定时器 = null; }
  if (!已压低) return;
  已压低 = false;
  发指令('restore');
}
/**
 * 退出前收尾。
 *
 * 先发 quit 让 ps1 自己恢复音量再退，这是最干净的路径。
 * 但 Electron 退出时不一定给足时间，所以再直接 kill 一次兜底——
 * ps1 那边 stdin 一断也会先恢复再退，两条路都能保住用户的音量。
 */
function 关闭() {
  if (!进程) return;
  发指令('quit');
  try {
    进程.stdin.end();
  } catch (e) { /* 已经断了 */ }
  const 目标 = 进程;
  setTimeout(() => {
    try { if (!目标.killed) 目标.kill(); } catch (e) { /* 已经没了 */ }
  }, 300);
}
module.exports = { 是否可用, 启动, 压低, 恢复, 关闭 };
