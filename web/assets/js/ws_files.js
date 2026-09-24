/**
 * 工作中心文件库：文件和图片同一个列表，文本可在线编辑。
 *
 * 左侧列表 + 右侧查看/编辑区，和「项目代码」标签页的交互保持一致，
 * 用户在两个标签页之间切换时不用重新适应。
 *
 * 数据全部走 /api/ws.php，接口层按登录账号隔离，前端不传也不需要 user_id。
 */
(function () {
  'use strict';

  var $list   = document.getElementById('wsList');
  var $view   = document.getElementById('wsView');
  var $pager  = document.getElementById('wsPager');
  var $kind   = document.getElementById('wsKind');
  var $search = document.getElementById('wsSearch');
  var $msg    = document.getElementById('wsMsg');
  var $tip    = document.getElementById('wsTip');
  var $upBtn  = document.getElementById('wsUpBtn');
  var $upIn   = document.getElementById('wsUpInput');
  var $newBtn = document.getElementById('wsNewBtn');
  var $viewer      = document.getElementById('wsViewer');
  var $viewerImg   = document.getElementById('wsViewerImg');
  var $viewerClose = document.getElementById('wsViewerClose');

  if (!$list) { return; }

  var 页码 = 1;
  var 当前 = null;      // 当前打开的文件 {id,name,kind}
  var 原文 = '';        // 打开时的正文，用于判断有没有改动
  var 搜索延时 = null;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function 提示(文, 坏) {
    if (!$msg) { return; }
    $msg.textContent = 文;
    $msg.className = 'ws-msg ' + (坏 ? 'is-err' : 'is-good');
    $msg.hidden = !文;
    if (文 && !坏) {
      setTimeout(function () { $msg.hidden = true; }, 4000);
    }
  }

  function post(data) {
    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('/api/ws.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { error: '服务端返回异常' }; });
      });
  }

  /* ---------- 列表 ---------- */

  var 图标 = { text: '📄', image: '🖼', bin: '📦' };

  function 条目(f) {
    var 标记 = f.source === 'ai' ? '<span class="repo-badge">AI</span>' : '';
    var 版本 = f.ver > 1 ? '<span class="repo-dim">改过 ' + f.ver + ' 次</span>' : '';
    return '<div class="repo-item" data-id="' + f.id + '" data-kind="' + esc(f.kind) + '" ' +
             'data-name="' + esc(f.name) + '" data-url="' + esc(f.url) + '" ' +
             'data-pv="' + (f.previewable ? 1 : 0) + '" tabindex="0" role="button">' +
             '<span class="repo-i-ico">' + (图标[f.kind] || '📄') + '</span>' +
             '<span class="repo-i-name">' + esc(f.name) + '</span>' +
             标记 +
             '<span class="repo-i-size">' + esc(f.size_text) + '</span>' +
             版本 +
           '</div>';
  }

  function 载入(p) {
    页码 = p || 1;
    var q = new URLSearchParams({
      act: 'list', page: 页码, size: 40,
      kind: $kind ? $kind.value : 'all',
      q: $search ? $search.value.trim() : ''
    });
    fetch('/api/ws.php?' + q.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) {
          $list.innerHTML = '<div class="ws-empty">' + esc(j.error || '加载失败') + '</div>';
          return;
        }
        if (!j.list.length) {
          var 空话 = ($search && $search.value.trim())
            ? '没有匹配的文件'
            : '还没有文件。让 AI 帮你写一个，或者点上面的「上传文件」';
          $list.innerHTML = '<div class="ws-empty">' + 空话 + '</div>';
          $pager.innerHTML = '';
        } else {
          $list.innerHTML = j.list.map(条目).join('');
          分页(j.page, j.pages);
        }
        if ($tip) {
          $tip.textContent = '已用 ' + j.used_text + ' / ' + j.quota_text +
                             '（' + j.percent + '%）';
        }
        var u = document.getElementById('wsUsedText');
        if (u) { u.textContent = j.used_text; }
        var pc = document.getElementById('wsPct');
        if (pc) { pc.textContent = j.percent; }
      })
      .catch(function () {
        $list.innerHTML = '<div class="ws-empty">网络错误，请刷新重试</div>';
      });
  }

  /* 供 ws_card.js 在对话页调用；本页也用它刷新用量 */
  window.wsRefreshQuota = function () { 载入(页码); };

  function 分页(当前页, 总页) {
    if (总页 <= 1) { $pager.innerHTML = ''; return; }
    var h = '';
    if (当前页 > 1) { h += '<button type="button" class="btn btn-sm" data-p="' + (当前页 - 1) + '">上一页</button>'; }
    h += '<span class="pager-now">' + 当前页 + ' / ' + 总页 + '</span>';
    if (当前页 < 总页) { h += '<button type="button" class="btn btn-sm" data-p="' + (当前页 + 1) + '">下一页</button>'; }
    $pager.innerHTML = h;
  }

  /* ---------- 查看 / 编辑 ---------- */

  function 高亮(id) {
    Array.prototype.forEach.call($list.querySelectorAll('.repo-item'), function (el) {
      el.classList.toggle('on', Number(el.getAttribute('data-id')) === Number(id));
    });
  }

  function 打开(el) {
    var id   = Number(el.getAttribute('data-id'));
    var kind = el.getAttribute('data-kind');
    var name = el.getAttribute('data-name');
    var url  = el.getAttribute('data-url');
    高亮(id);

    if (kind === 'image') {
      当前 = { id: id, name: name, kind: kind };
      $view.innerHTML =
        '<div class="repo-view-h"><strong>' + esc(name) + '</strong>' +
          '<span class="repo-btns">' +
            '<button type="button" class="btn btn-sm" data-ws-rename>重命名</button>' +
            '<button type="button" class="btn btn-sm" data-ws-del>删除</button>' +
          '</span></div>' +
        '<div class="ws-img-box"><img src="' + esc(url) + '" alt="' + esc(name) +
          '" id="wsBigImg" style="max-width:100%;cursor:zoom-in"></div>';
      var big = document.getElementById('wsBigImg');
      if (big) {
        big.addEventListener('click', function () {
          $viewerImg.src = url;
          $viewer.hidden = false;
        });
      }
      return;
    }

    if (kind !== 'text') {
      当前 = { id: id, name: name, kind: kind };
      var 可预览 = el.getAttribute('data-pv') === '1';
      var 头 =
        '<div class="repo-view-h"><strong>' + esc(name) + '</strong>' +
          '<span class="repo-btns">' +
            '<a class="btn btn-sm" href="' + esc(url) + '">下载</a>' +
            '<button type="button" class="btn btn-sm" data-ws-rename>重命名</button>' +
            '<button type="button" class="btn btn-sm" data-ws-del>删除</button>' +
          '</span></div>';

      if (!可预览) {
        $view.innerHTML = 头 +
          '<div class="ws-empty">这是二进制文件，不能在线查看，点「下载」取用。</div>';
        return;
      }

      // Office 文档：服务端逐页转成 PNG，前端用 img 显示。
      // 不用 iframe 内嵌 PDF —— Chrome 的「下载 PDF 而不是自动打开」设置开着时，
      // iframe 里的 PDF 也会被当下载处理，服务端的 inline 头覆盖不了。图片没这问题。
      $view.innerHTML = 头 +
        '<div class="ws-pv-wrap" id="wsPvWrap">' +
          '<div class="ws-loading" id="wsPvTip">正在生成预览，首次转换需要几秒…</div>' +
        '</div>';

      var 壳 = document.getElementById('wsPvWrap');
      var 提 = document.getElementById('wsPvTip');
      fetch('/api/ws.php?act=pvinfo&id=' + id, { credentials: 'same-origin' })
        .then(function (r) {
          return r.json().catch(function () { return { error: '服务端返回异常' }; });
        })
        .then(function (j) {
          if (!j.ok) { throw new Error(j.error || '生成失败'); }
          var h = '<div class="ws-pv-bar">共 ' + j.pages + ' 页' +
                  '<a class="btn btn-sm" href="/api/ws.php?act=preview&id=' + id + '" ' +
                    'target="_blank" rel="noopener">用 PDF 打开</a></div>';
          for (var p = 1; p <= j.pages; p++) {
            // 第一页立即加载，其余交给浏览器懒加载，页数多时不会一次拉几十张
            h += '<img class="ws-pv-img" src="/api/ws.php?act=pvpage&id=' + id + '&p=' + p +
                 '" alt="第 ' + p + ' 页"' + (p === 1 ? '' : ' loading="lazy"') + '>';
          }
          壳.innerHTML = h;
        })
        .catch(function (e) {
          if (提) {
            提.className = 'ws-empty';
            提.textContent = '预览生成失败：' + (e && e.message ? e.message : e) +
                             '（可以点「下载」取原文件）';
          }
        });
      return;
    }

    $view.innerHTML = '<div class="ws-loading">正在读取…</div>';
    fetch('/api/ws.php?act=read&id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) {
          $view.innerHTML = '<div class="ws-empty">' + esc(j.error || '读取失败') + '</div>';
          return;
        }
        当前 = { id: j.id, name: j.name, kind: 'text' };
        原文 = j.text;
        $view.innerHTML =
          '<div class="repo-view-h"><strong>' + esc(j.name) + '</strong>' +
            '<span class="repo-dim">' + (j.lines || 0) + ' 行' +
            (j.truncated ? '（过长已截断，保存会丢失后半部分，请谨慎）' : '') + '</span>' +
            '<span class="repo-btns">' +
              '<button type="button" class="btn btn-sm btn-primary" data-ws-save>保存</button>' +
              '<a class="btn btn-sm" href="/api/ws.php?act=down&id=' + j.id + '">下载</a>' +
              '<button type="button" class="btn btn-sm" data-ws-rename>重命名</button>' +
              '<button type="button" class="btn btn-sm" data-ws-del>删除</button>' +
            '</span></div>' +
          '<textarea class="repo-edit" id="wsEdit" spellcheck="false" ' +
            'aria-label="文件内容">' + esc(j.text) + '</textarea>' +
          '<div class="hint" id="wsEditTip">改完点「保存」。保存会覆盖旧内容，' +
            '旧版本系统会自动留一份快照。</div>';
      })
      .catch(function () {
        $view.innerHTML = '<div class="ws-empty">网络错误</div>';
      });
  }

  function 保存() {
    var ta = document.getElementById('wsEdit');
    if (!ta || !当前) { return; }
    if (ta.value === 原文) { 提示('内容没有变化，不用保存'); return; }
    var btn = $view.querySelector('[data-ws-save]');
    if (btn) { btn.disabled = true; btn.textContent = '保存中…'; }
    post({ act: 'write', name: 当前.name, text: ta.value, note: '手动编辑', source: 'user' })
      .then(function (r) {
        if (btn) { btn.disabled = false; btn.textContent = '保存'; }
        if (!r || r.error) { 提示((r && r.error) || '保存失败', true); return; }
        原文 = ta.value;
        提示(r.msg || '已保存');
        载入(页码);
      });
  }

  function 重命名() {
    if (!当前) { return; }
    var 新 = window.prompt('新的文件名（可以带目录，如 docs/说明.md）', 当前.name);
    if (新 === null) { return; }
    新 = 新.trim();
    if (!新 || 新 === 当前.name) { return; }
    post({ act: 'rename', id: 当前.id, name: 新 }).then(function (r) {
      if (!r || r.error) { 提示((r && r.error) || '改名失败', true); return; }
      当前.name = r.name;
      提示(r.msg || '已改名');
      载入(页码);
      var t = $view.querySelector('.repo-view-h strong');
      if (t) { t.textContent = r.name; }
    });
  }

  function 删除() {
    if (!当前) { return; }
    if (!window.confirm('删除「' + 当前.name + '」？删掉就找不回来了。')) { return; }
    post({ act: 'del', id: 当前.id }).then(function (r) {
      if (!r || r.error) { 提示((r && r.error) || '删除失败', true); return; }
      提示(r.msg || '已删除');
      当前 = null;
      $view.innerHTML = '<div class="ws-empty">点左侧文件名查看内容，文本文件可以直接改</div>';
      载入(页码);
    });
  }

  function 新建() {
    var 名 = window.prompt('新文件的名字（带扩展名，如 备忘.md、scripts/run.sh）', '新建文件.md');
    if (名 === null) { return; }
    名 = 名.trim();
    if (!名) { return; }
    post({ act: 'write', name: 名, text: '', note: '手动新建', source: 'user' })
      .then(function (r) {
        if (!r || r.error) { 提示((r && r.error) || '新建失败', true); return; }
        提示(r.msg || '已新建');
        载入(1);
        // 建完直接打开编辑
        setTimeout(function () {
          var el = $list.querySelector('.repo-item[data-id="' + r.id + '"]');
          if (el) { 打开(el); }
        }, 300);
      });
  }

  /* ---------- 上传 ---------- */

  function 上传(files) {
    if (!files || !files.length) { return; }
    var 队 = Array.prototype.slice.call(files);
    var 成 = 0, 败 = [];

    function 下一个() {
      if (!队.length) {
        var 文 = '上传完成：成功 ' + 成 + ' 个';
        if (败.length) { 文 += '，失败 ' + 败.length + ' 个（' + 败.join('；') + '）'; }
        提示(文, 败.length > 0);
        载入(1);
        return;
      }
      var f = 队.shift();
      var fd = new FormData();
      fd.append('csrf', window.CSRF);
      fd.append('act', 'upload');
      fd.append('file', f);
      fd.append('name', f.name);
      提示('正在上传 ' + f.name + '…');
      fetch('/api/ws.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { error: '返回异常' }; }); })
        .then(function (j) {
          if (j && j.ok) { 成++; } else { 败.push(f.name + ': ' + ((j && j.error) || '失败')); }
          下一个();
        })
        .catch(function () { 败.push(f.name + ': 网络错误'); 下一个(); });
    }
    下一个();
  }

  /* ---------- 事件 ---------- */

  $list.addEventListener('click', function (e) {
    var it = e.target.closest('.repo-item');
    if (it) { 打开(it); }
  });
  $list.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') { return; }
    var it = e.target.closest('.repo-item');
    if (it) { e.preventDefault(); 打开(it); }
  });

  $view.addEventListener('click', function (e) {
    if (e.target.closest('[data-ws-save]'))   { 保存(); }
    if (e.target.closest('[data-ws-rename]')) { 重命名(); }
    if (e.target.closest('[data-ws-del]'))    { 删除(); }
  });

  // Ctrl/Cmd + S 保存，编辑长文件时省得去找按钮
  $view.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
      e.preventDefault();
      保存();
    }
  });

  $pager.addEventListener('click', function (e) {
    var b = e.target.closest('[data-p]');
    if (b) { 载入(Number(b.getAttribute('data-p'))); }
  });

  if ($kind) { $kind.addEventListener('change', function () { 载入(1); }); }
  if ($search) {
    $search.addEventListener('input', function () {
      clearTimeout(搜索延时);
      搜索延时 = setTimeout(function () { 载入(1); }, 300);
    });
  }
  if ($upBtn && $upIn) {
    $upBtn.addEventListener('click', function () { $upIn.click(); });
    $upIn.addEventListener('change', function () {
      上传($upIn.files);
      $upIn.value = '';
    });
  }
  if ($newBtn) { $newBtn.addEventListener('click', 新建); }

  if ($viewerClose) {
    $viewerClose.addEventListener('click', function () { $viewer.hidden = true; });
  }
  if ($viewer) {
    $viewer.addEventListener('click', function (e) {
      if (e.target === $viewer) { $viewer.hidden = true; }
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && $viewer && !$viewer.hidden) { $viewer.hidden = true; }
  });

  // 离开页面前提醒未保存的改动
  window.addEventListener('beforeunload', function (e) {
    var ta = document.getElementById('wsEdit');
    if (ta && 当前 && ta.value !== 原文) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  载入(1);
})();
