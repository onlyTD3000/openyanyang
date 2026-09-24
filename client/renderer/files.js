/*
 * 文件管理窗口。贴在 SSH 窗口右侧，位置由主进程跟着终端窗口同步。
 * 所有远端操作走 文件桥（preload-files.js 暴露），底层是主进程的 SFTP，
 * 复用终端那条 ssh 连接，不会在目标机上多出登录记录。
 */
'use strict';
const 桥 = window.文件桥;
const 元 = {
  路径: document.getElementById('路径'),
  上级: document.getElementById('上级'),
  家: document.getElementById('家'),
  刷新: document.getElementById('刷新'),
  新建夹: document.getElementById('新建夹'),
  新建件: document.getElementById('新建件'),
  上传: document.getElementById('上传'),
  选文件: document.getElementById('选文件'),
  全选: document.getElementById('全选'),
  批删: document.getElementById('批删'),
  选中数: document.getElementById('选中数'),
  行们: document.getElementById('行们'),
  提示: document.getElementById('提示'),
  拖罩: document.getElementById('拖罩'),
  进度: document.getElementById('进度'),
  进度文: document.getElementById('进度文'),
  进度块: document.getElementById('进度块'),
  编辑层: document.getElementById('编辑层'),
  编辑名: document.getElementById('编辑名'),
  编辑态: document.getElementById('编辑态'),
  编辑区: document.getElementById('编辑区'),
  编辑存: document.getElementById('编辑存'),
  编辑关: document.getElementById('编辑关')
};
const 态 = {
  目录: '/',
  项: [],
  选中: new Set(),      // 存路径
  在编辑: null,          // { 路径, 原文 }
  忙: false
};
/* ---------- 小工具 ---------- */
function 人话大小(n) {
  if (n < 1024) return n + ' B';
  if (n < 1048576) return (n / 1024).toFixed(1) + ' K';
  if (n < 1073741824) return (n / 1048576).toFixed(1) + ' M';
  return (n / 1073741824).toFixed(2) + ' G';
}
function 人话时间(毫秒) {
  if (!毫秒) return '';
  const d = new Date(毫秒);
  const 补 = (n) => String(n).padStart(2, '0');
  const 今年 = new Date().getFullYear();
  const 年 = d.getFullYear();
  const 月日 = 补(d.getMonth() + 1) + '-' + 补(d.getDate());
  const 时分 = 补(d.getHours()) + ':' + 补(d.getMinutes());
  // 同一年就不显示年份，窄窗口里省一列宽度
  return 年 === 今年 ? 月日 + ' ' + 时分 : 年 + '-' + 月日;
}
/* 上一级目录。/ 的上级还是 / */
function 上一级(p) {
  const s = String(p || '/').replace(/\/+$/, '');
  if (!s || s === '') return '/';
  const i = s.lastIndexOf('/');
  return i <= 0 ? '/' : s.slice(0, i);
}
function 显示提示(文本, 是错) {
  元.提示.textContent = 文本 || '';
  元.提示.hidden = !文本;
  元.提示.classList.toggle('错', !!是错);
}
/* 操作期间禁掉按钮，避免连点导致并发 SFTP 请求打架 */
function 设忙(忙) {
  态.忙 = 忙;
  for (const k of ['上级', '家', '刷新', '新建夹', '新建件', '上传']) {
    元[k].disabled = 忙;
  }
  元.批删.disabled = 忙 || 态.选中.size === 0;
}
/* ---------- 列目录 ---------- */
async function 去(目录) {
  设忙(true);
  显示提示('正在读取…');
  元.行们.innerHTML = '';
  const r = await 桥.列(目录);
  设忙(false);
  if (!r || r.error) {
    显示提示(r && r.error ? r.error : '读取失败', true);
    return;
  }
  态.目录 = r.目录;
  态.项 = r.项 || [];
  态.选中.clear();
  元.全选.checked = false;
  元.路径.value = r.目录;
  画列表();
  刷新选中数();
}
function 画列表() {
  元.行们.innerHTML = '';
  if (!态.项.length) {
    显示提示('这个目录是空的');
    return;
  }
  显示提示('');
  const 碎片 = document.createDocumentFragment();
  for (const it of 态.项) {
    const tr = document.createElement('tr');
    tr.dataset.路径 = it.路径;
    if (it.是目录) tr.classList.add('是夹');
    // 勾选
    const td勾 = document.createElement('td');
    const 勾 = document.createElement('input');
    勾.type = 'checkbox';
    勾.addEventListener('change', () => {
      if (勾.checked) 态.选中.add(it.路径); else 态.选中.delete(it.路径);
      tr.classList.toggle('选中', 勾.checked);
      刷新选中数();
    });
    td勾.appendChild(勾);
    // 名称。目录点了进去，文件点了开编辑器
    const td名 = document.createElement('td');
    const 格 = document.createElement('div');
    格.className = '名格';
    const 图 = document.createElement('span');
    图.className = '图';
    图.textContent = it.是目录 ? '📁' : (it.是链接 ? '🔗' : '📄');
    const 名 = document.createElement('span');
    名.className = '名字';
    名.textContent = it.名;
    名.title = it.名 + '\n' + it.权限;
    名.addEventListener('click', () => {
      if (it.是目录 || it.是链接) 去(it.路径); else 开编辑(it);
    });
    格.appendChild(图); 格.appendChild(名);
    td名.appendChild(格);
    const td小 = document.createElement('td');
    td小.className = '列小';
    td小.textContent = it.是目录 ? '-' : 人话大小(it.大小);
    const td时 = document.createElement('td');
    td时.className = '列时';
    td时.textContent = 人话时间(it.改时间);
    // 操作：改名、下载（文件才有）、删除
    const td操 = document.createElement('td');
    const 操 = document.createElement('div');
    操.className = '操作';
    操.appendChild(小钮('改名', () => 改名(it)));
    if (!it.是目录) 操.appendChild(小钮('下载', () => 下载(it)));
    操.appendChild(小钮('删', () => 删一个(it), true));
    td操.appendChild(操);
    tr.appendChild(td勾); tr.appendChild(td名);
    tr.appendChild(td小); tr.appendChild(td时); tr.appendChild(td操);
    碎片.appendChild(tr);
  }
  元.行们.appendChild(碎片);
}
function 小钮(字, 点, 危) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = '微钮' + (危 ? ' 危' : '');
  b.textContent = 字;
  b.addEventListener('click', (e) => { e.stopPropagation(); 点(); });
  return b;
}
function 刷新选中数() {
  const n = 态.选中.size;
  元.选中数.textContent = n ? '选中 ' + n + ' 项' : '';
  元.批删.disabled = 态.忙 || n === 0;
}
/* ---------- 增删改 ---------- */
async function 改名(it) {
  const 新名 = prompt('新名字', it.名);
  if (新名 == null) return;
  const 干净 = String(新名).trim();
  if (!干净 || 干净 === it.名) return;
  if (/[\/\\]/.test(干净)) { alert('名字里不能有斜杠'); return; }
  设忙(true);
  const 目标 = (态.目录 === '/' ? '' : 态.目录) + '/' + 干净;
  const r = await 桥.改名(it.路径, 目标);
  设忙(false);
  if (r && r.error) { alert('改名失败：' + r.error); return; }
  去(态.目录);
}
async function 删一个(it) {
  const 说明 = it.是目录
    ? '要删除文件夹「' + it.名 + '」及其中所有内容吗？这个操作不可恢复。'
    : '要删除文件「' + it.名 + '」吗？这个操作不可恢复。';
  if (!confirm(说明)) return;
  设忙(true);
  const r = await 桥.删(it.路径, it.是目录);
  设忙(false);
  if (r && r.error) { alert('删除失败：' + r.error); return; }
  去(态.目录);
}
async function 批量删() {
  const 要删 = 态.项.filter((x) => 态.选中.has(x.路径));
  if (!要删.length) return;
  const 有夹 = 要删.some((x) => x.是目录);
  if (!confirm('要删除选中的 ' + 要删.length + ' 项吗？'
      + (有夹 ? '其中包含文件夹，会连同里面的内容一起删。' : '')
      + '这个操作不可恢复。')) return;
  设忙(true);
  const 失败 = [];
  for (const it of 要删) {
    const r = await 桥.删(it.路径, it.是目录);
    if (r && r.error) 失败.push(it.名 + '：' + r.error);
  }
  设忙(false);
  if (失败.length) alert('有 ' + 失败.length + ' 项没删掉：\n' + 失败.join('\n'));
  去(态.目录);
}
async function 新建文件夹() {
  const 名 = prompt('新文件夹名字');
  if (名 == null) return;
  const 干净 = String(名).trim();
  if (!干净) return;
  if (/[\/\\]/.test(干净)) { alert('名字里不能有斜杠'); return; }
  设忙(true);
  const r = await 桥.建目录((态.目录 === '/' ? '' : 态.目录) + '/' + 干净);
  设忙(false);
  if (r && r.error) { alert('新建失败：' + r.error); return; }
  去(态.目录);
}
async function 新建文件() {
  const 名 = prompt('新文件名字');
  if (名 == null) return;
  const 干净 = String(名).trim();
  if (!干净) return;
  if (/[\/\\]/.test(干净)) { alert('名字里不能有斜杠'); return; }
  设忙(true);
  const 路 = (态.目录 === '/' ? '' : 态.目录) + '/' + 干净;
  const r = await 桥.写(路, '');
  设忙(false);
  if (r && r.error) { alert('新建失败：' + r.error); return; }
  await 去(态.目录);
  // 建完直接打开，省一次点击
  开编辑({ 名: 干净, 路径: 路, 是目录: false });
}
/* ---------- 编辑器 ---------- */
async function 开编辑(it) {
  设忙(true);
  const r = await 桥.读(it.路径);
  设忙(false);
  if (!r || r.error) { alert(r && r.error ? r.error : '打开失败'); return; }
  态.在编辑 = { 路径: it.路径, 原文: r.文本 };
  元.编辑名.textContent = it.路径;
  元.编辑名.title = it.路径;
  元.编辑区.value = r.文本;
  元.编辑态.textContent = 人话大小(r.大小);
  元.编辑层.hidden = false;
  元.编辑区.focus();
}
async function 存编辑() {
  if (!态.在编辑) return;
  元.编辑存.disabled = true;
  元.编辑态.textContent = '保存中…';
  const r = await 桥.写(态.在编辑.路径, 元.编辑区.value);
  元.编辑存.disabled = false;
  if (!r || r.error) {
    元.编辑态.textContent = '';
    alert(r && r.error ? r.error : '保存失败');
    return;
  }
  态.在编辑.原文 = 元.编辑区.value;
  元.编辑态.textContent = '已保存 ' + 人话大小(r.大小);
  去(态.目录);
}
function 关编辑() {
  // 有未保存改动要拦一下，textarea 里敲了半天被点掉太亏
  if (态.在编辑 && 元.编辑区.value !== 态.在编辑.原文) {
    if (!confirm('有未保存的修改，确定关闭吗？')) return;
  }
  态.在编辑 = null;
  元.编辑层.hidden = true;
  元.编辑区.value = '';
  元.编辑态.textContent = '';
}
/* ---------- 上传下载 ---------- */
async function 下载(it) {
  设忙(true);
  const r = await 桥.下(it.路径, it.名);
  设忙(false);
  if (r && r.error) { alert('下载失败：' + r.error); return; }
  if (r && r.取消) return;
  if (r && r.存到) 闪进度('已保存到 ' + r.存到);
}
function 闪进度(文本) {
  元.进度.hidden = false;
  元.进度文.textContent = 文本;
  元.进度块.style.width = '100%';
  setTimeout(() => { 元.进度.hidden = true; 元.进度块.style.width = '0'; }, 2600);
}
/* 逐个传，不并发：同一条 ssh 连接上并发开多个 sftp 写流，
   慢的机器上容易互相拖死，串行反而更快出结果 */
async function 传一批(文件们) {
  const 总 = 文件们.length;
  if (!总) return;
  设忙(true);
  元.进度.hidden = false;
  const 失败 = [];
  for (let i = 0; i < 总; i++) {
    const f = 文件们[i];
    元.进度文.textContent = '上传 ' + (i + 1) + '/' + 总 + '：' + f.name;
    元.进度块.style.width = Math.round(i / 总 * 100) + '%';
    try {
      const buf = new Uint8Array(await f.arrayBuffer());
      const r = await 桥.传(态.目录, f.name, buf);
      if (r && r.error) 失败.push(f.name + '：' + r.error);
    } catch (e) {
      失败.push(f.name + '：' + (e && e.message ? e.message : '读取本地文件失败'));
    }
  }
  元.进度块.style.width = '100%';
  设忙(false);
  if (失败.length) {
    元.进度文.textContent = '完成，' + 失败.length + ' 个失败';
    alert('这些文件没传上去：\n' + 失败.join('\n'));
  } else {
    闪进度('已上传 ' + 总 + ' 个文件');
  }
  去(态.目录);
}
/* ---------- 事件绑定 ---------- */
元.刷新.addEventListener('click', () => 去(态.目录));
元.上级.addEventListener('click', () => 去(上一级(态.目录)));
元.家.addEventListener('click', async () => {
  const r = await 桥.家();
  去(r && r.路径 ? r.路径 : '/');
});
元.路径.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); 去(元.路径.value); }
});
元.新建夹.addEventListener('click', 新建文件夹);
元.新建件.addEventListener('click', 新建文件);
元.批删.addEventListener('click', 批量删);
元.上传.addEventListener('click', () => 元.选文件.click());
元.选文件.addEventListener('change', () => {
  const 们 = Array.from(元.选文件.files || []);
  元.选文件.value = '';        // 清掉，下次选同一个文件才会再触发 change
  传一批(们);
});
元.全选.addEventListener('change', () => {
  const 开 = 元.全选.checked;
  态.选中.clear();
  const 勾们 = 元.行们.querySelectorAll('input[type=checkbox]');
  态.项.forEach((it, i) => {
    if (勾们[i]) 勾们[i].checked = 开;
    if (开) 态.选中.add(it.路径);
    if (勾们[i]) 勾们[i].closest('tr').classList.toggle('选中', 开);
  });
  刷新选中数();
});
元.编辑存.addEventListener('click', 存编辑);
元.编辑关.addEventListener('click', 关编辑);
// Ctrl+S 保存，Esc 关闭。改配置文件时手会自动去按 Ctrl+S
元.编辑区.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    e.preventDefault(); 存编辑();
  } else if (e.key === 'Escape') {
    e.preventDefault(); 关编辑();
  }
});
/* 拖拽上传。dragover 必须 preventDefault，否则 drop 根本不触发。
   计数是因为拖过子元素时 dragenter/dragleave 会成对乱冒泡，
   只看 dragleave 会导致罩子在窗口里闪。 */
let 拖计数 = 0;
window.addEventListener('dragenter', (e) => {
  e.preventDefault();
  if (态.在编辑) return;      // 编辑器开着就不接受拖拽，落点会很迷惑
  拖计数++;
  元.拖罩.hidden = false;
});
window.addEventListener('dragover', (e) => e.preventDefault());
window.addEventListener('dragleave', (e) => {
  e.preventDefault();
  拖计数 = Math.max(0, 拖计数 - 1);
  if (拖计数 === 0) 元.拖罩.hidden = true;
});
window.addEventListener('drop', (e) => {
  e.preventDefault();
  拖计数 = 0;
  元.拖罩.hidden = true;
  if (态.在编辑) return;
  const 们 = Array.from((e.dataTransfer && e.dataTransfer.files) || []);
  // 文件夹拖进来 size 是 0 且 type 空，SFTP 这条路传不了目录，先挡掉
  const 能传 = 们.filter((f) => f.size > 0 || f.type);
  if (们.length && !能传.length) {
    alert('看起来拖进来的是文件夹。这里只能上传文件，请压缩后再传。');
    return;
  }
  if (能传.length) 传一批(能传);
});
/* F5 刷新列表而不是重载页面 */
window.addEventListener('keydown', (e) => {
  if (e.key === 'F5') { e.preventDefault(); if (!态.忙) 去(态.目录); }
});
/* 开窗先落在家目录，比一上来就 / 实用 */
(async function 起() {
  const r = await 桥.家();
  await 去(r && r.路径 ? r.路径 : '/');
})();
