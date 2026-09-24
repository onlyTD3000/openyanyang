/*
 * 终端窗口的文件管理：基于 ssh2 的 SFTP 子系统。
 *
 * 连接从 本地ssh.js 的池子里借，不自己新建：
 * 终端已经是用户 IP 直连了，文件管理再连一次会在目标机上多出一条登录记录，
 * 而且要重新走一遍认证。借池子还能共享断线回收逻辑。
 *
 * 每个 SFTP 会话开完就关，只留连接。sftp 子系统很轻，反复开销可以忽略，
 * 长期持有反而容易在断线后留下不可用的句柄。
 */
'use strict';
const path = require('path');
/* 在一条 ssh 连接上开 SFTP，用完自动关。
   所有对外函数都通过它拿 sftp，避免每处都写一遍 try/finally。 */
function 用SFTP(conn, 活儿) {
  return new Promise((resolve) => {
    let 已回 = false;
    const 回 = (值) => { if (!已回) { 已回 = true; resolve(值); } };
    // SFTP 子系统偶尔会既不回调也不报错（对端 Subsystem 被禁用时），加硬超时
    const 闹钟 = setTimeout(() => 回({ error: 'SFTP 打开超时，可能服务器禁用了 sftp 子系统' }), 20000);
    conn.sftp(async (err, sftp) => {
      clearTimeout(闹钟);
      if (err) {
        const m = String(err && err.message || err);
        回({ error: /No such file|not found/i.test(m)
          ? 'SFTP 不可用：服务器没有开 sftp-server'
          : 'SFTP 打开失败：' + m });
        return;
      }
      try {
        回(await 活儿(sftp));
      } catch (e) {
        回({ error: 说人话(e) });
      } finally {
        try { sftp.end(); } catch (e2) {}
      }
    });
  });
}
/* ssh2/SFTP 的错误码是数字，直接抛给用户看不懂 */
function 说人话(e) {
  const m = String(e && e.message || e || '');
  if (/No such file|ENOENT|code 2/i.test(m)) return '路径不存在';
  if (/Permission denied|EACCES|code 3/i.test(m)) return '没有权限';
  if (/not a directory/i.test(m)) return '这不是一个目录';
  if (/Failure|code 4/i.test(m)) return '操作被服务器拒绝（可能是目录非空或磁盘满）';
  return m || '未知错误';
}
/* SFTP 路径一律用正斜杠，不能用 path.join：
   客户端跑在 Windows 上，path.join 会生成反斜杠，服务器不认 */
function 规范(p) {
  let s = String(p || '/').replace(/\\/g, '/');
  if (!s.startsWith('/')) s = '/' + s;
  // 折叠 .. 和 . ，防止越权拼路径
  const 段 = [];
  for (const 片 of s.split('/')) {
    if (!片 || 片 === '.') continue;
    if (片 === '..') { 段.pop(); continue; }
    段.push(片);
  }
  return '/' + 段.join('/');
}
function 权限文本(mode) {
  const 位 = ['---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx'];
  const n = mode & 0o777;
  return 位[(n >> 6) & 7] + 位[(n >> 3) & 7] + 位[n & 7];
}
/* 列目录。返回时目录排在前面，各自按名字排，和常见文件管理器一致 */
async function 列(conn, 目录) {
  const 路 = 规范(目录);
  return await 用SFTP(conn, (sftp) => new Promise((resolve) => {
    sftp.readdir(路, (err, list) => {
      if (err) { resolve({ error: 说人话(err) }); return; }
      const 项 = (list || []).map((it) => {
        const a = it.attrs || {};
        const 是目录 = (a.mode & 0o170000) === 0o040000;
        const 是链接 = (a.mode & 0o170000) === 0o120000;
        return {
          名: it.filename,
          路径: 路 === '/' ? '/' + it.filename : 路 + '/' + it.filename,
          是目录, 是链接,
          大小: Number(a.size || 0),
          改时间: Number(a.mtime || 0) * 1000,
          权限: 权限文本(Number(a.mode || 0)),
          属主: Number(a.uid || 0),
          mode: Number(a.mode || 0)
        };
      });
      项.sort((x, y) => {
        if (x.是目录 !== y.是目录) return x.是目录 ? -1 : 1;
        return x.名.localeCompare(y.名, 'zh');
      });
      resolve({ ok: 1, 目录: 路, 项 });
    });
  }));
}
/* 读文本文件。大文件和二进制直接拒，别把终端窗口卡死 */
const 文本上限 = 2 * 1024 * 1024;
async function 读(conn, 路径) {
  const 路 = 规范(路径);
  return await 用SFTP(conn, (sftp) => new Promise((resolve) => {
    sftp.stat(路, (e1, st) => {
      if (e1) { resolve({ error: 说人话(e1) }); return; }
      if ((st.mode & 0o170000) === 0o040000) { resolve({ error: '这是目录，不是文件' }); return; }
      const 大小 = Number(st.size || 0);
      if (大小 > 文本上限) {
        resolve({ error: '文件太大（' + (大小 / 1048576).toFixed(1) + ' MB），超过 2 MB 不在窗口里打开' });
        return;
      }
      const 块 = [];
      const 流 = sftp.createReadStream(路);
      流.on('data', (d) => 块.push(d));
      流.on('error', (e2) => resolve({ error: 说人话(e2) }));
      流.on('end', () => {
        const buf = Buffer.concat(块);
        // NUL 字节基本可以断定是二进制，编辑器打开只会显示乱码
        if (buf.includes(0)) {
          resolve({ error: '这是二进制文件，不能在编辑器里打开' });
          return;
        }
        resolve({ ok: 1, 文本: buf.toString('utf8'), 大小, 路径: 路 });
      });
    });
  }));
}
/* 写文件。先写同目录的临时文件再改名，避免写一半断线把原文件截断 */
async function 写(conn, 路径, 内容) {
  const 路 = 规范(路径);
  const 临时 = 路 + '.yy_tmp_' + Date.now();
  return await 用SFTP(conn, (sftp) => new Promise((resolve) => {
    // 原文件权限要保留，不能让改完变成默认 0644
    sftp.stat(路, (e0, st) => {
      const 原mode = e0 ? null : (Number(st.mode || 0) & 0o777);
      const 流 = sftp.createWriteStream(临时, { mode: 原mode == null ? 0o644 : 原mode });
      流.on('error', (e1) => {
        try { sftp.unlink(临时, () => {}); } catch (x) {}
        resolve({ error: 说人话(e1) });
      });
      流.on('close', () => {
        // rename 在 SFTP 里目标存在会失败，先删原文件。
        // 这里有个极小的窗口，但比写一半截断安全得多
        sftp.unlink(路, () => {
          sftp.rename(临时, 路, (e2) => {
            if (e2) {
              sftp.unlink(临时, () => {});
              resolve({ error: '保存失败：' + 说人话(e2) });
              return;
            }
            resolve({ ok: 1, 路径: 路, 大小: Buffer.byteLength(内容, 'utf8') });
          });
        });
      });
      流.end(Buffer.from(String(内容), 'utf8'));
    });
  }));
}
/* 上传本地文件。字节由渲染层传过来（拖拽拿到的是 File） */
async function 传(conn, 目标目录, 文件名, 字节) {
  const 目录 = 规范(目标目录);
  const 名 = path.basename(String(文件名 || '')).replace(/[\\/]/g, '');
  if (!名) return { error: '文件名不合法' };
  const 路 = 目录 === '/' ? '/' + 名 : 目录 + '/' + 名;
  return await 用SFTP(conn, (sftp) => new Promise((resolve) => {
    const 流 = sftp.createWriteStream(路);
    流.on('error', (e) => resolve({ error: 说人话(e) }));
    流.on('close', () => resolve({ ok: 1, 路径: 路, 大小: 字节.length }));
    流.end(Buffer.from(字节));
  }));
}
/* 下载到本地。返回字节，由主进程写盘 */
async function 下(conn, 路径) {
  const 路 = 规范(路径);
  return await 用SFTP(conn, (sftp) => new Promise((resolve) => {
    sftp.stat(路, (e1, st) => {
      if (e1) { resolve({ error: 说人话(e1) }); return; }
      const 大小 = Number(st.size || 0);
      if (大小 > 200 * 1024 * 1024) { resolve({ error: '文件超过 200 MB，请用命令行传' }); return; }
      const 块 = [];
      const 流 = sftp.createReadStream(路);
      流.on('data', (d) => 块.push(d));
      流.on('error', (e2) => resolve({ error: 说人话(e2) }));
      流.on('end', () => resolve({ ok: 1, 字节: Buffer.concat(块), 大小 }));
    });
  }));
}
/* 删除。目录要递归删，SFTP 的 rmdir 只能删空目录。
   递归在这里手写而不是 rm -rf：走 SFTP 不用起 shell，
   也不会因为路径里有空格或特殊字符出事。 */
async function 删(conn, 路径, 是目录) {
  const 路 = 规范(路径);
  if (路 === '/') return { error: '不能删根目录' };
  return await 用SFTP(conn, async (sftp) => {
    const 读目录 = (p) => new Promise((r) => sftp.readdir(p, (e, l) => r(e ? [] : (l || []))));
    const 删文件 = (p) => new Promise((r) => sftp.unlink(p, (e) => r(!e)));
    const 删空目录 = (p) => new Promise((r) => sftp.rmdir(p, (e) => r(!e)));
    if (!是目录) {
      return (await 删文件(路)) ? { ok: 1 } : { error: '删除失败，可能没有权限' };
    }
    // 广度展开再从深到浅删，避免递归太深爆栈
    const 待查 = [路];
    const 全部目录 = [路];
    const 全部文件 = [];
    while (待查.length) {
      const 当前 = 待查.pop();
      for (const it of await 读目录(当前)) {
        const 子 = 当前 === '/' ? '/' + it.filename : 当前 + '/' + it.filename;
        if ((it.attrs.mode & 0o170000) === 0o040000) { 待查.push(子); 全部目录.push(子); }
        else 全部文件.push(子);
      }
    }
    for (const f of 全部文件) await 删文件(f);
    全部目录.sort((a, b) => b.length - a.length);   // 深的先删
    for (const d of 全部目录) await 删空目录(d);
    return { ok: 1, 删了: 全部文件.length + 全部目录.length };
  });
}
async function 建目录(conn, 路径) {
  const 路 = 规范(路径);
  return await 用SFTP(conn, (sftp) => new Promise((resolve) => {
    sftp.mkdir(路, (e) => resolve(e ? { error: 说人话(e) } : { ok: 1, 路径: 路 }));
  }));
}
async function 改名(conn, 旧, 新) {
  const a = 规范(旧), b = 规范(新);
  return await 用SFTP(conn, (sftp) => new Promise((resolve) => {
    sftp.rename(a, b, (e) => resolve(e ? { error: 说人话(e) } : { ok: 1, 路径: b }));
  }));
}
/* 家目录。开窗口时先落在这里，比一上来就 / 更实用 */
async function 家(conn) {
  return await 用SFTP(conn, (sftp) => new Promise((resolve) => {
    sftp.realpath('.', (e, p) => resolve(e ? { ok: 1, 路径: '/' } : { ok: 1, 路径: 规范(p) }));
  }));
}
module.exports = { 列, 读, 写, 传, 下, 删, 建目录, 改名, 家, 规范 };
