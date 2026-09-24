/* 工作中心 · 项目代码标签页
   拉取项目代码、看文件树、读正文、看本地改动与历史版本、回传。
   与文件库标签页共用页面，互不干扰；本文件只操作 #paneCode 内的元素。 */
(function () {
  'use strict';

  var $tabs = document.querySelectorAll('.ws-tab');
  // 面板 key 必须和 .ws-tab 的 data-pane 一致：file=文件库，code=项目代码
  var $panes = { file: document.getElementById('paneFile'), code: document.getElementById('paneCode') };
  var $proj = document.getElementById('repoProj');
  var $pull = document.getElementById('repoPull');
  var $upBtn = document.getElementById('repoUpBtn');
  var $upIn = document.getElementById('repoUpInput');
  var $onlyDirty = document.getElementById('repoOnlyDirty');
  var $pickAllWrap = document.getElementById('repoPickAllWrap');
  var $pickAll = document.getElementById('repoPickAll');
  var $delBtn = document.getElementById('repoDelBtn');
  var $tip = document.getElementById('repoTip');
  var $msg = document.getElementById('repoMsg');
  var $list = document.getElementById('repoList');
  var $view = document.getElementById('repoView');
  var $head = document.getElementById('repoHead');

  if (!$proj) return;   // 页面结构不对就安静退出

  var 当前项目 = 0;
  var 仅改动 = false;
  var 文件缓存 = [];
  var 当前文件 = '';
  var 已选 = [];        // 勾选的文件路径。切项目、切筛选、删完都要清空

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function 提示(文, 类型) {
    if (!文) { $msg.hidden = true; return; }
    $msg.hidden = false;
    $msg.className = 'ws-msg' + (类型 ? ' is-' + 类型 : '');
    $msg.textContent = 文;
  }

  function 请求(动作, 参数, 用POST) {
    参数 = 参数 || {};
    参数.act = 动作;
    if (当前项目) 参数.project_id = 当前项目;
    if (用POST) {
      var fd = new FormData();
      Object.keys(参数).forEach(function (k) { fd.append(k, 参数[k]); });
      fd.append('csrf', window.CSRF);
      return fetch('/api/repo.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); });
    }
    var qs = Object.keys(参数).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(参数[k]);
    }).join('&');
    return fetch('/api/repo.php?' + qs, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  /* ---------- 标签页切换 ---------- */
  Array.prototype.forEach.call($tabs, function (b) {
    b.addEventListener('click', function () {
      var which = b.dataset.pane;
      Array.prototype.forEach.call($tabs, function (x) {
        var on = x === b;
        x.classList.toggle('on', on);
        x.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Object.keys($panes).forEach(function (k) {
        if ($panes[k]) $panes[k].hidden = (k !== which);
      });
      try { sessionStorage.setItem('ws_tab', which); } catch (e) { /* 忽略 */ }
    });
  });

  // 记住上次停在哪个标签
  try {
    var 上次 = sessionStorage.getItem('ws_tab');
    if (上次 === 'code') {
      var b = document.querySelector('.ws-tab[data-pane="code"]');
      if (b) b.click();
    }
  } catch (e) { /* 忽略 */ }

  /* ---------- 项目切换 ---------- */
  $proj.addEventListener('change', function () {
    当前项目 = Number($proj.value) || 0;
    当前文件 = '';
    // 勾选和文件缓存都要清干净：留着上个项目的路径，删除就会打到别处
    已选 = [];
    文件缓存 = [];
    刷新选中态();
    $view.innerHTML = '<div class="ws-empty">点左侧文件名查看内容</div>';
    提示('');
    var opt = $proj.selectedOptions[0];
    var 就绪 = opt && opt.dataset.ready === '1';
    $pull.disabled = !当前项目 || !就绪;
    if ($upBtn) { $upBtn.disabled = !当前项目 || !就绪; }
    if (!当前项目) {
      $tip.textContent = '';
      $head.textContent = '选一个项目查看';
      $list.innerHTML = '<div class="ws-empty">选一个项目，然后点「从服务器拉取」</div>';
      $onlyDirty.hidden = true;
      return;
    }
    if (!就绪) {
      $tip.textContent = '这个项目还没绑定服务器或部署目录';
      $list.innerHTML = '<div class="ws-empty">先去项目设置里绑定服务器和部署目录，才能拉代码</div>';
      $onlyDirty.hidden = true;
      return;
    }
    $tip.textContent = (opt.dataset.host ? opt.dataset.host + ' · ' : '') + (opt.dataset.dir || '');
    载入信息();
    载入树();
  });

  function 载入信息() {
    请求('info').then(function (j) {
      if (!j.ok) { $head.textContent = j.error || '读取失败'; return; }
      var r = j.repo;
      var 段 = [r.file_count + ' 个文件', r.bytes_text];
      if (r.dirty > 0) 段.push('本地改动 ' + r.dirty + ' 个');
      if (r.last_pull) 段.push('拉取于 ' + String(r.last_pull).slice(0, 16));
      $head.textContent = 段.join(' · ');
      $onlyDirty.hidden = !(r.file_count > 0);
    }).catch(function () { $head.textContent = '网络错误'; });
  }

  /* ---------- 文件树 ---------- */
  function 载入树() {
    $list.innerHTML = '<div class="ws-loading">正在加载…</div>';
    var 参数 = 仅改动 ? { dirty: 1 } : {};
    请求('tree', 参数).then(function (j) {
      if (!j.ok) {
        $list.innerHTML = '<div class="ws-empty">' + esc(j.error || '加载失败') + '</div>';
        return;
      }
      文件缓存 = j.list || [];
      // 树重载后，仓里已经没有的路径要从已选里剔掉，不然会删到不存在的东西
      已选 = 已选.filter(function (p) {
        return 文件缓存.some(function (f) { return f.path === p; });
      });
      if (!文件缓存.length) {
        $list.innerHTML = '<div class="ws-empty">' +
          (仅改动 ? '没有本地改动' : '这个项目还没拉过代码，点上面「从服务器拉取」') + '</div>';
        刷新选中态();
        return;
      }
      $list.innerHTML = 树HTML(文件缓存);
      刷新选中态();
    }).catch(function () {
      $list.innerHTML = '<div class="ws-empty">网络错误，请重试</div>';
    });
  }

  // 按目录分组，一层折叠，够直观又不至于做成完整树控件
  function 树HTML(表) {
    var 组 = {};
    表.forEach(function (f) {
      var i = f.path.lastIndexOf('/');
      var 目录 = i < 0 ? '（根目录）' : f.path.slice(0, i);
      (组[目录] = 组[目录] || []).push(f);
    });
    var 目录名 = Object.keys(组).sort(function (a, b) {
      if (a === '（根目录）') return -1;
      if (b === '（根目录）') return 1;
      return a < b ? -1 : 1;
    });
    return 目录名.map(function (d) {
      var 项 = 组[d].map(function (f) {
        var 名 = f.path.indexOf('/') < 0 ? f.path : f.path.slice(f.path.lastIndexOf('/') + 1);
        var 标 = '';
        if (f.state === 'edited') 标 = '<span class="repo-badge is-edit">改</span>';
        else if (f.state === 'new') 标 = '<span class="repo-badge is-new">新</span>';
        if (!f.is_text) 标 += '<span class="repo-badge">二进制</span>';
        var 选中 = 已选.indexOf(f.path) >= 0;
        return '<div class="repo-file-row">' +
          '<input type="checkbox" class="repo-pick" data-pick="' + esc(f.path) + '"' +
          (选中 ? ' checked' : '') +
          ' aria-label="选择 ' + esc(f.path) + '">' +
          '<button class="repo-file' + (f.path === 当前文件 ? ' on' : '') + '" type="button"' +
          ' data-path="' + esc(f.path) + '"' + (f.is_text ? '' : ' data-bin="1"') + '>' +
          '<span class="fn">' + esc(名) + '</span>' + 标 +
          '<span class="fs">' + esc(f.size_text) + '</span></button></div>';
      }).join('');
      // 目录级全选：这一组全勾上时它自己也显示为勾选
      var 组内全选 = 组[d].every(function (f) { return 已选.indexOf(f.path) >= 0; });
      return '<div class="repo-dir"><div class="repo-dir-h">' +
        '<input type="checkbox" class="repo-pick-dir" data-dir="' + esc(d) + '"' +
        (组内全选 ? ' checked' : '') + ' aria-label="选择目录 ' + esc(d) + ' 下全部文件">' +
        '<span class="dn">' + esc(d) + '</span>' +
        '<span class="hint" style="margin:0">' + 组[d].length + '</span></div>' + 项 + '</div>';
    }).join('');
  }

  /* ---------- 勾选与删除 ---------- */

  // 按钮文案与全选框状态都由已选数量决定，集中在这里改，别处只管改 已选
  function 刷新选中态() {
    var 有文件 = 文件缓存.length > 0;
    $pickAllWrap.style.display = 有文件 ? 'inline-flex' : 'none';
    $delBtn.hidden = 已选.length === 0;
    $delBtn.textContent = '删除所选（' + 已选.length + '）';
    $pickAll.checked = 有文件 && 已选.length === 文件缓存.length;
    // 部分选中时显示为半选，比单纯的勾/不勾更准确
    $pickAll.indeterminate = 已选.length > 0 && 已选.length < 文件缓存.length;
  }

  // 单文件删除：走同一个 del 接口，只是只传一个路径
  function 删单个(路径) {
    if (!confirm('确定从本地副本删掉 ' + 路径 + ' 吗？\n\n'
               + '只删本地副本，服务器上那份不受影响（回传从不删远端文件）。'
               + '该文件的历史版本会一起清掉，这一步无法撤销。')) {
      return;
    }
    提示('正在删除…');
    请求('del', { path: 路径 }, true).then(function (j) {
      if (!j.ok) { 提示(j.error || '删除失败', 'err'); return; }
      提示(j.msg || '已删除', 'good');
      if (当前文件 === 路径) {
        当前文件 = '';
        $view.innerHTML = '<div class="ws-empty">点左侧文件名查看内容</div>';
      }
      选中(路径, false);
      载入信息();
      载入树();
    }).catch(function () {
      提示('网络错误，删除未完成', 'err');
    });
  }

  function 选中(路径, 要选) {
    var i = 已选.indexOf(路径);
    if (要选 && i < 0) { 已选.push(路径); }
    if (!要选 && i >= 0) { 已选.splice(i, 1); }
  }

  // 目录名的算法和 树HTML 里保持一致，否则目录级全选会对不上
  function 所属目录(路径) {
    var i = 路径.lastIndexOf('/');
    return i < 0 ? '（根目录）' : 路径.slice(0, i);
  }

  $list.addEventListener('change', function (e) {
    var 单 = e.target.closest('.repo-pick');
    if (单) {
      选中(单.dataset.pick, 单.checked);
      // 同目录的全选框可能要跟着变
      var d = 所属目录(单.dataset.pick);
      var 框 = $list.querySelector('.repo-pick-dir[data-dir="' + CSS.escape(d) + '"]');
      if (框) {
        var 同组 = 文件缓存.filter(function (f) { return 所属目录(f.path) === d; });
        框.checked = 同组.every(function (f) { return 已选.indexOf(f.path) >= 0; });
      }
      刷新选中态();
      return;
    }
    var 目录 = e.target.closest('.repo-pick-dir');
    if (目录) {
      var 名 = 目录.dataset.dir;
      文件缓存.forEach(function (f) {
        if (所属目录(f.path) === 名) { 选中(f.path, 目录.checked); }
      });
      $list.querySelectorAll('.repo-pick').forEach(function (c) {
        if (所属目录(c.dataset.pick) === 名) { c.checked = 目录.checked; }
      });
      刷新选中态();
    }
  });

  $pickAll.addEventListener('change', function () {
    var 要选 = $pickAll.checked;
    已选 = 要选 ? 文件缓存.map(function (f) { return f.path; }) : [];
    $list.querySelectorAll('.repo-pick, .repo-pick-dir').forEach(function (c) {
      c.checked = 要选;
    });
    刷新选中态();
  });

  $delBtn.addEventListener('click', function () {
    if (!已选.length) return;
    var 条 = 已选.length === 1
      ? '确定从本地副本删掉 ' + 已选[0] + ' 吗？'
      : '确定从本地副本删掉这 ' + 已选.length + ' 个文件吗？';
    // 说清边界：这里删的是本地副本，回传不会删远端，所以线上那份还在
    if (!confirm(条 + '\n\n只删本地副本，服务器上那份不受影响（回传从不删远端文件）。'
               + '删掉的文件历史版本也会一起清掉，这一步无法撤销。')) {
      return;
    }
    $delBtn.disabled = true;
    var 原文 = $delBtn.textContent;
    $delBtn.textContent = '删除中…';
    提示('正在删除 ' + 已选.length + ' 个文件…');
    请求('del', { paths: JSON.stringify(已选) }, true).then(function (j) {
      $delBtn.disabled = false;
      $delBtn.textContent = 原文;
      if (!j.ok) { 提示(j.error || '删除失败', 'err'); return; }
      var 文 = j.msg || '已删除';
      if (j.failed && j.failed.length) {
        文 += '\n没删成的：' + j.failed.slice(0, 5).join('；')
            + (j.failed.length > 5 ? ' 等' : '');
      }
      提示(文, j['失败'] ? 'warn' : 'good');
      // 当前正在看的文件被删了，右侧要清掉，不然看的是已经不存在的内容
      if (当前文件 && 已选.indexOf(当前文件) >= 0) {
        当前文件 = '';
        $view.innerHTML = '<div class="ws-empty">点左侧文件名查看内容</div>';
      }
      已选 = [];
      载入信息();
      载入树();
    }).catch(function () {
      $delBtn.disabled = false;
      $delBtn.textContent = 原文;
      提示('网络错误，删除未完成', 'err');
    });
  });

  $list.addEventListener('click', function (e) {
    var b = e.target.closest('[data-path]');
    if (!b) return;
    if (b.dataset.bin) { 提示('二进制文件不支持在线查看', 'warn'); return; }
    当前文件 = b.dataset.path;
    $list.querySelectorAll('.repo-file.on').forEach(function (x) { x.classList.remove('on'); });
    b.classList.add('on');
    读文件(当前文件);
  });

  /* ---------- 读正文 ---------- */
  function 读文件(路径) {
    $view.innerHTML = '<div class="ws-loading">正在读取…</div>';
    请求('read', { path: 路径 }).then(function (j) {
      if (!j.ok) {
        $view.innerHTML = '<div class="ws-empty">' + esc(j.error || '读取失败') + '</div>';
        return;
      }
      var 头 = '<div class="repo-view-h"><code>' + esc(j.path) + '</code>' +
        '<span class="hint" style="margin:0">' + j.lines + ' 行' +
        (j.state === 'edited' ? ' · 本地已改' : '') +
        (j.ver > 0 ? ' · 第 ' + j.ver + ' 版' : '') + '</span>' +
        (j.ver > 0 ? '<button class="btn btn-sm" type="button" id="repoVers">历史版本</button>' : '') +
        '<button class="btn btn-sm btn-danger" type="button" id="repoDelOne">删除</button>' +
        '</div>';
      var 正文 = '<pre class="repo-code">' + 带行号(j.text) + '</pre>';
      if (j.truncated) {
        正文 += '<div class="hint">文件过大，只显示了前一部分</div>';
      }
      $view.innerHTML = 头 + 正文;
      var vb = document.getElementById('repoVers');
      if (vb) vb.addEventListener('click', function () { 看版本(j.path); });
      var db = document.getElementById('repoDelOne');
      if (db) db.addEventListener('click', function () { 删单个(j.path); });
    }).catch(function () {
      $view.innerHTML = '<div class="ws-empty">网络错误，请重试</div>';
    });
  }

  function 带行号(文) {
    var 行 = String(文).replace(/\r\n?/g, '\n').split('\n');
    return 行.map(function (l, i) {
      return '<span class="ln">' + (i + 1) + '</span>' + esc(l);
    }).join('\n');
  }

  /* ---------- 历史版本 ---------- */
  function 看版本(路径) {
    请求('vers', { path: 路径 }).then(function (j) {
      if (!j.ok) { 提示(j.error || '读取失败', 'err'); return; }
      var 表 = (j.list || []).map(function (v) {
        return '<tr><td>第 ' + v.ver + ' 版</td><td>' + esc(v.action) + '</td>' +
          '<td>' + esc(String(v.created_at).slice(0, 16)) + '</td>' +
          '<td>' + esc(v.note || '') + '</td>' +
          '<td><button class="btn btn-sm" type="button" data-diff="' + v.ver + '">看差异</button></td></tr>';
      }).join('');
      $view.innerHTML = '<div class="repo-view-h"><code>' + esc(路径) + '</code>' +
        '<button class="btn btn-sm" type="button" id="repoBack">返回正文</button></div>' +
        (表 ? '<div class="table-wrap"><table class="table"><thead><tr>' +
          '<th>版本</th><th>动作</th><th>时间</th><th>说明</th><th></th></tr></thead>' +
          '<tbody>' + 表 + '</tbody></table></div>'
          : '<div class="ws-empty">还没有历史版本</div>');
      document.getElementById('repoBack').addEventListener('click', function () { 读文件(路径); });
      $view.querySelectorAll('[data-diff]').forEach(function (b) {
        b.addEventListener('click', function () { 看差异(路径, Number(b.dataset.diff)); });
      });
    });
  }

  function 看差异(路径, 版本) {
    请求('diff', { path: 路径, ver: 版本 }).then(function (j) {
      if (!j.ok) { 提示(j.error || '读取失败', 'err'); return; }
      var 行 = (j.lines || []).map(function (l) {
        var 类 = l.t === '+' ? 'add' : (l.t === '-' ? 'del' : '');
        return '<div class="dl ' + 类 + '"><span class="ln">' + (l.n || '') + '</span>' +
          esc(l.t + ' ' + l.s) + '</div>';
      }).join('');
      $view.innerHTML = '<div class="repo-view-h"><code>' + esc(路径) + '</code>' +
        '<span class="hint" style="margin:0">当前 vs 第 ' + 版本 + ' 版 · +' +
        j.add + ' -' + j.del + '</span>' +
        '<button class="btn btn-sm" type="button" id="repoBack">返回正文</button></div>' +
        '<div class="repo-diff">' + (行 || '<div class="ws-empty">没有差异</div>') + '</div>';
      document.getElementById('repoBack').addEventListener('click', function () { 读文件(路径); });
    });
  }

  /* ---------- 拉取 ---------- */
  $pull.addEventListener('click', function () {
    if (!当前项目) return;
    $pull.disabled = true;
    var 原文 = $pull.textContent;
    $pull.textContent = '拉取中…';
    提示('正在从服务器拉取，文件多时要等一会…');
    请求('pull', {}, true).then(function (j) {
      if (!j.ok) { 提示(j.error || '拉取失败', 'err'); return; }
      提示(j.msg || '拉取完成', 'good');
      载入信息();
      载入树();
    }).catch(function () {
      提示('网络错误，拉取未完成', 'err');
    }).then(function () {
      $pull.disabled = false;
      $pull.textContent = 原文;
    });
  });

  /* ---------- 上传代码 ---------- */
  if ($upBtn && $upIn) {
    var $extract = document.getElementById('repoUploadExtract');

    $upBtn.addEventListener('click', function () {
      if (!当前项目) { 提示('先选一个项目', 'warn'); return; }
      $upIn.value = '';
      $upIn.click();
    });

    $upIn.addEventListener('change', function () {
      var 队 = Array.prototype.slice.call($upIn.files || []);
      if (!队.length) { return; }
      var 子目录 = window.prompt(
        '放到仓内哪个目录？留空表示仓根目录（例如 admin 或 assets/js）', '');
      if (子目录 === null) { return; }
      子目录 = String(子目录).trim();
      var 解压 = !$extract || $extract.checked;
      上传队列(队, 子目录, 解压);
    });
  }

  function 上传队列(队, 子目录, 解压) {
    var 总数 = 队.length;
    var 文件成 = 0, 条目成 = 0;
    var 失败 = [], 跳过 = [], 警告 = [];
    var 原文 = $upBtn.textContent;
    $upBtn.disabled = true;
    $pull.disabled = true;

    function 下一个() {
      if (!队.length) {
        $upBtn.disabled = false;
        $upBtn.textContent = 原文;
        $pull.disabled = false;
        var 文 = '上传完成：' + 文件成 + '/' + 总数 + ' 个上传项成功，共收下 '
               + 条目成 + ' 个文件';
        if (跳过.length) {
          文 += '。跳过 ' + 跳过.length + ' 个：'
              + 跳过.slice(0, 5).join('；') + (跳过.length > 5 ? ' 等' : '');
        }
        if (失败.length) { 文 += '。失败：' + 失败.join('；'); }
        文 += '。已按完整内容入仓，改动还未回传到服务器。';
        // 后端对压缩包这类文件会带回 warn，去重后单独起一行显示
        if (警告.length) { 文 += '\n注意：' + 警告.join(' '); }
        提示(文, 失败.length ? 'err' : (警告.length ? 'warn' : 'good'));
        载入信息();
        载入树();
        return;
      }
      var f = 队.shift();
      var 是压缩包 = /\.(zip|tar|gz|tgz|bz2|rar|7z)$/i.test(f.name);
      var 模式 = 是压缩包 && 解压 ? 'extract' : 'keep';
      $upBtn.textContent = '上传中 ' + (总数 - 队.length) + '/' + 总数 + '…';
      提示('正在上传 ' + f.name +
        (是压缩包 ? (模式 === 'extract' ? '（解压中，包大时要等一会）' : '（原样入仓）') : '') + '…');

      var fd = new FormData();
      fd.append('csrf', window.CSRF);
      fd.append('act', 'upload');
      fd.append('project_id', 当前项目);
      fd.append('file', f);
      fd.append('name', f.name);
      fd.append('mode', 模式);
      fd.append('full', '1');
      if (子目录) { fd.append('dir', 子目录); }

      fetch('/api/repo.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json().catch(function () { return { error: '服务端返回异常' }; });
        })
        .then(function (j) {
          if (!j || !j.ok) {
            失败.push(f.name + '：' + ((j && j.error) || '上传失败'));
          } else {
            文件成++;
            条目成 += Number(j['成功'] || 1);
            if (j.skipped && j.skipped.length) {
              跳过 = 跳过.concat(j.skipped);
            }
            if (j.warn && 警告.indexOf(j.warn) < 0) {
              警告.push(j.warn);
            }
          }
        })
        .catch(function () {
          失败.push(f.name + '：网络错误');
        })
        .then(下一个);
    }
    下一个();
  }

  /* ---------- 只看改动 ---------- */
  $onlyDirty.addEventListener('click', function () {
    仅改动 = !仅改动;
    $onlyDirty.classList.toggle('on', 仅改动);
    $onlyDirty.textContent = 仅改动 ? '看全部文件' : '只看改动';
    // 两个视图的文件集合不同，勾选留着容易误删，直接清掉
    已选 = [];
    载入树();
  });
})();
