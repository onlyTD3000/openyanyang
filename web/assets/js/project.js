/**
 * 项目侧栏：项目 → 该项目下的对话，两层结构。
 *
 * 与 chat.js 的分工：
 *   本文件负责项目的增删改、侧栏渲染、选中哪个项目/对话；
 *   chat.js 负责消息收发，通过 window.当前项目 / window.currentConvId 读取当前上下文。
 */
(function () {
  'use strict';

  var $列 = document.getElementById('projList');
  var $罩 = document.getElementById('pjMask');
  var $菜 = document.getElementById('pjPop');
  if (!$列) { return; }

  var 项目缓存 = [];     // 最近一次拉到的项目数据
  var 展开集 = {};       // { 项目id: true } 记住哪些项目是展开的
  var 菜单针对 = 0;      // 操作菜单当前对着哪个项目

  // ---------- 本地记忆：上次看的项目与对话，按用户隔离 ----------
  function 键(名) { return '云智_' + 名 + '_' + (window.UID || 0); }

  function 存最后项目(pid) {
    try { localStorage.setItem(键('项目'), String(pid || 0)); } catch (e) {}
  }
  function 取最后项目() {
    try { return Number(localStorage.getItem(键('项目')) || 0); } catch (e) { return 0; }
  }
  function 存展开() {
    try { localStorage.setItem(键('展开'), JSON.stringify(展开集)); } catch (e) {}
  }
  function 读展开() {
    try { 展开集 = JSON.parse(localStorage.getItem(键('展开')) || '{}') || {}; }
    catch (e) { 展开集 = {}; }
  }

  // ---------- 请求封装 ----------
  function 提交(接口, 表单) {
    var fd = new FormData();
    fd.append('csrf', window.CSRF || '');
    Object.keys(表单 || {}).forEach(function (k) { fd.append(k, 表单[k]); });
    return fetch(接口, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }
  function 读取(地址) {
    return fetch(地址, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  function 转义(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ---------- 渲染 ----------
  function 画项目(p) {
    var 展开 = !!展开集[p.id];
    var 标签 = '';
    标签 += '<span class="tag-mini type-cloud" title="云端项目">云端</span>';
    if (p.host_name) { 标签 += '<span class="tag-mini" title="部署服务器">' + 转义(p.host_name) + '</span>'; }
    if (p.deploy_dir) { 标签 += '<span class="tag-mini dir" title="' + 转义(p.deploy_dir) + '">' + 转义(p.deploy_dir) + '</span>'; }
    return '' +
      '<div class="proj-item' + (展开 ? ' open' : '') + '" data-pid="' + p.id + '">' +
        '<div class="proj-row">' +
          '<span class="caret" aria-hidden="true">' + (展开 ? '▾' : '▸') + '</span>' +
          '<span class="proj-name" title="' + 转义(p.name) + '">' + 转义(p.name) + '</span>' +
          (Number(p.pinned) ? '<span class="proj-pin" title="已置顶">★</span>' : '') +
          '<span class="proj-n">' + Number(p.conv_count || 0) + '</span>' +
          '<button class="proj-menu" type="button" title="项目设置" data-menu="' + p.id + '" aria-label="项目设置">⋯</button>' +
        '</div>' +
        (标签 ? '<div class="proj-meta">' + 标签 + '</div>' : '') +
        '<div class="proj-convs"' + (展开 ? '' : ' hidden') + '></div>' +
      '</div>';
  }

  function 重画() {
    if (!项目缓存.length) {
      $列.innerHTML = '<div class="conv-empty">还没有项目，点上面新建一个</div>';
      return;
    }
    $列.innerHTML = 项目缓存.map(画项目).join('');
    // 展开状态的项目要把对话列表补上
    项目缓存.forEach(function (p) {
      if (展开集[p.id]) { 载入对话(p.id); }
    });
    标记选中();
  }

  function 刷新项目(回调) {
    读取('/api/project.php?act=list').then(function (j) {
      项目缓存 = (j && j.list) || [];
      重画();
      if (typeof 回调 === 'function') { 回调(); }
    });
  }

  // ---------- 项目下的对话 ----------
  /**
   * @param {number}  pid    项目
   * @param {boolean} 自动开 列表拉到后顺手把最新那条对话打开。
   *        用户手动切项目时为 true，保证右侧聊天区跟着项目走。
   */
  function 载入对话(pid, 自动开) {
    var $盒 = $列.querySelector('.proj-item[data-pid="' + pid + '"] .proj-convs');
    if (!$盒) { return; }
    $盒.innerHTML = '<div class="conv-loading">加载中…</div>';
    读取('/api/conv.php?act=list&project_id=' + pid).then(function (j) {
      var 表 = (j && j.list) || [];
      if (!表.length) {
        $盒.innerHTML = '<div class="conv-none">还没有对话' +
          '<button type="button" class="lnk" data-newconv="' + pid + '">新建一条</button></div>';
        // 空项目：把上一个项目的聊天记录撤掉，否则看着像还在旧项目里
        if (自动开 && window.清空聊天区) {
          window.清空聊天区('这个项目还没有对话');
        }
        return;
      }
      $盒.innerHTML = 表.map(function (c) {
        return '<div class="conv-item" data-id="' + c.id + '" data-pid="' + pid + '">' +
          '<span class="t" title="' + 转义(c.title) + '">' + 转义(c.title) + '</span>' +
          '<button class="ren" type="button" title="重命名对话" data-ren="' + c.id + '" aria-label="重命名对话">' +
          // 铅笔图标用内联 svg：不额外请求图标字体，改颜色跟随 currentColor
          '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false">' +
          '<path fill="currentColor" d="M11.4 1.3a1.1 1.1 0 0 1 1.6 0l1.7 1.7a1.1 1.1 0 0 1 0 1.6l-.9.9-3.3-3.3.9-.9zM9.6 3.1l3.3 3.3-6.7 6.7a1 1 0 0 1-.45.26l-3.2.86a.55.55 0 0 1-.68-.68l.86-3.2a1 1 0 0 1 .26-.45l6.6-6.6z"/>' +
          '</svg></button>' +
          '<button class="del" type="button" title="删除对话" data-del="' + c.id + '">×</button>' +
          '</div>';
      }).join('') +
      '<button type="button" class="conv-add" data-newconv="' + pid + '">+ 新对话</button>';
      标记选中();

      // 手动切项目：把聊天区切到这个项目最新那条对话上。
      // 正在生成回答时不切，免得打断——chat.js 的打开对话会拦并提示。
      if (自动开 && window.打开对话) {
        if (window.对话进行中 && window.对话进行中()) { return; }
        var 现在的 = Number(window.currentConvId) || 0;
        var 已在本项目 = 表.some(function (c) { return Number(c.id) === 现在的; });
        if (!已在本项目) {
          window.打开对话(表[0].id);
        }
      }
    });
  }

  function 标记选中() {
    var pid = Number(window.当前项目 && window.当前项目.id) || 0;
    var cid = Number(window.currentConvId) || 0;
    Array.prototype.forEach.call($列.querySelectorAll('.proj-item'), function (el) {
      el.classList.toggle('cur', Number(el.dataset.pid) === pid);
    });
    Array.prototype.forEach.call($列.querySelectorAll('.conv-item'), function (el) {
      el.classList.toggle('on', Number(el.dataset.id) === cid);
    });
  }

  // ---------- 切换当前项目 ----------
  /**
   * @param {number}  pid     目标项目
   * @param {boolean} 不展开  只切上下文，不展开侧栏（点具体某条对话时用）
   * @param {boolean} 跟着切聊天区
   *        用户手动点项目行时传 true：把聊天区也切到这个项目下。
   *        以前不做这件事，导致切了项目、右边还挂着上一个项目的对话——
   *        用户接着提问就发到了看不见的那条会话里。
   *        首屏恢复不传，那条路径由 chat.js 自己接管上次的会话。
   */
  function 选项目(pid, 不展开, 跟着切聊天区) {
    var p = 项目缓存.filter(function (x) { return Number(x.id) === Number(pid); })[0];
    if (!p) { return; }
    var 原来的 = Number((window.当前项目 || {}).id) || 0;
    var 换了项目 = 原来的 !== Number(p.id);

    window.当前项目 = p;
    存最后项目(p.id);
    显示项目条(p);
    if (!不展开) {
      展开集[p.id] = true;
      存展开();
      var $项 = $列.querySelector('.proj-item[data-pid="' + p.id + '"]');
      if ($项) {
        $项.classList.add('open');
        var $c = $项.querySelector('.caret'); if ($c) { $c.textContent = '▾'; }
        var $盒 = $项.querySelector('.proj-convs');
        if ($盒) { $盒.hidden = false; }
        载入对话(p.id, 跟着切聊天区 && 换了项目);
      }
    }
    标记选中();
  }

  function 显示项目条(p) {
    var $条 = document.getElementById('curProj');
    var $名 = document.getElementById('curProjName');
    var $元 = document.getElementById('curProjMeta');
    if (!$条) { return; }
    if (!p) { $条.hidden = true; return; }
    $条.hidden = false;
    $名.textContent = p.name;
    var 片 = [];
    if (p.host_name) { 片.push('服务器 ' + p.host_name); }
    if (p.deploy_dir) { 片.push(p.deploy_dir); }
    if (p.stack) { 片.push(p.stack); }
    $元.textContent = 片.join(' · ');
    $元.title = 片.join(' · ');
  }

  // 暴露给 chat.js：新建对话后刷新计数、切走对话时同步高亮
  window.项目侧栏 = {
    刷新: 刷新项目,
    刷新对话: 载入对话,
    标记: 标记选中,
    选中: 选项目,
    当前: function () { return window.当前项目 || null; }
  };

  读展开();
  // 首屏：拉项目，恢复上次看的那个
  刷新项目(function () {
    var 上次 = 取最后项目();
    var 有 = 项目缓存.some(function (x) { return Number(x.id) === 上次; });
    if (有) { 选项目(上次); }
    else if (项目缓存.length) { 选项目(项目缓存[0].id); }
  });

  // 下半部分（事件绑定、弹窗）在 project_ui.js
  window.__项目内部 = {
    提交: 提交, 读取: 读取, 转义: 转义,
    取缓存: function () { return 项目缓存; },
    刷新: 刷新项目, 载入对话: 载入对话, 选项目: 选项目,
    展开集: 展开集, 存展开: 存展开, 标记选中: 标记选中,
    菜单: function (v) { if (v !== undefined) { 菜单针对 = v; } return 菜单针对; },
    元素: { 列: $列, 罩: $罩, 菜: $菜 }
  };
})();
