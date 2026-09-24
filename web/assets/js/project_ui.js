/**
 * 项目侧栏的交互部分：点击展开、操作菜单、新建/编辑弹窗。
 * 依赖 project.js 暴露的 window.__项目内部。
 */
(function () {
  'use strict';

  var 内 = window.__项目内部;
  if (!内) { return; }

  var $列 = 内.元素.列, $罩 = 内.元素.罩, $菜 = 内.元素.菜;

  // ---------- 侧栏点击 ----------
  $列.addEventListener('click', function (e) {
    // 项目操作菜单
    var $菜钮 = e.target.closest('[data-menu]');
    if ($菜钮) {
      e.stopPropagation();
      开菜单(Number($菜钮.dataset.menu), $菜钮);
      return;
    }

    // 在某项目下新建对话
    var $新 = e.target.closest('[data-newconv]');
    if ($新) {
      e.stopPropagation();
      新建对话(Number($新.dataset.newconv));
      return;
    }

    // 重命名对话
    // 必须放在「点对话切过去」之前拦掉，否则冒泡上去会顺便切换会话
    var $改 = e.target.closest('[data-ren]');
    if ($改) {
      e.stopPropagation();
      var rcid = Number($改.dataset.ren);
      var $项行 = $改.closest('.conv-item');
      var rpid = Number($项行.dataset.pid);
      var 原名 = ($项行.querySelector('.t') || {}).textContent || '';
      var 新名 = prompt('给这条对话起个名字：', 原名);
      if (新名 === null) { return; }          // 用户点了取消
      新名 = 新名.replace(/^\s+|\s+$/g, '');
      if (新名 === '' || 新名 === 原名) { return; }   // 空名和没改都不必发请求
      内.提交('/api/conv.php', { act: 'rename', conv_id: rcid, title: 新名 }).then(function (j) {
        if (j && j.ok) {
          内.载入对话(rpid);   // 对话名只在侧栏列表显示，重画列表就够了
        } else {
          alert((j && j.error) || '改名失败');
        }
      });
      return;
    }
    // 删除对话
    var $删 = e.target.closest('[data-del]');
    if ($删) {
      e.stopPropagation();
      var cid = Number($删.dataset.del);
      var pid = Number($删.closest('.conv-item').dataset.pid);
      if (!confirm('删除这条对话？聊天记录会一并删除，不可恢复。')) { return; }
      内.提交('/api/conv.php', { act: 'del', conv_id: cid }).then(function (j) {
        if (j && j.ok) {
          if (Number(window.currentConvId) === cid) {
            window.currentConvId = 0;
            if (window.清空聊天区) { window.清空聊天区(); }
          }
          内.载入对话(pid);
          内.刷新();
        } else {
          alert((j && j.error) || '删除失败');
        }
      });
      return;
    }

    // 点对话：切过去
    var $对 = e.target.closest('.conv-item');
    if ($对) {
      var id = Number($对.dataset.id);
      内.选项目(Number($对.dataset.pid), true);
      if (window.打开对话) { window.打开对话(id); }
      内.标记选中();
      // 移动端：点击对话后自动收起侧边栏
      var sidebar = document.getElementById('convSide');
      if (sidebar && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
      }
      return;
    }

    // 点项目行：展开/收起 + 设为当前
    var $行 = e.target.closest('.proj-row');
    if ($行) {
      var $项 = $行.closest('.proj-item');
      var ppid = Number($项.dataset.pid);
      var 已开 = !!内.展开集[ppid];
      if (已开 && Number((内.取缓存().filter(function (x) { return Number(x.id) === ppid; })[0] || {}).id) === Number((window.当前项目 || {}).id)) {
        // 当前项目再点一次才收起，避免误收
        delete 内.展开集[ppid];
        内.存展开();
        $项.classList.remove('open');
        $项.querySelector('.caret').textContent = '▸';
        $项.querySelector('.proj-convs').hidden = true;
      } else {
        // 第三个参数 = 聊天区跟着切到这个项目，别停在上一个项目的对话上
        内.选项目(ppid, false, true);
      }
    }
  });

  // ---------- 操作菜单 ----------
  function 开菜单(pid, $锚) {
    内.菜单(pid);
    var r = $锚.getBoundingClientRect();
    $菜.hidden = false;
    $菜.style.left = Math.min(r.left, window.innerWidth - 190) + 'px';
    $菜.style.top = (r.bottom + 4) + 'px';
  }
  function 关菜单() { $菜.hidden = true; 内.菜单(0); }

  document.addEventListener('click', function (e) {
    if (!$菜.hidden && !e.target.closest('#pjPop') && !e.target.closest('[data-menu]')) { 关菜单(); }
  });

  $菜.addEventListener('click', function (e) {
    var $b = e.target.closest('[data-do]');
    if (!$b) { return; }
    var 做 = $b.dataset.do;
    var pid = 内.菜单();
    var p = 内.取缓存().filter(function (x) { return Number(x.id) === Number(pid); })[0];
    关菜单();
    if (!p) { return; }

    if (做 === 'edit') { 开弹窗(p); return; }
    if (做 === 'newconv') { 新建对话(pid); return; }

    if (做 === 'pin') {
      内.提交('/api/project.php', { act: 'pin', id: pid, pinned: Number(p.pinned) ? 0 : 1 })
        .then(function () { 内.刷新(); });
      return;
    }
    if (做 === 'archive') {
      if (!confirm('归档「' + p.name + '」？归档后侧栏不再显示，对话和记录都保留。')) { return; }
      内.提交('/api/project.php', { act: 'archive', id: pid, archived: 1 }).then(function (j) {
        if (j && j.ok) {
          if (Number((window.当前项目 || {}).id) === Number(pid)) {
            window.当前项目 = null; window.currentConvId = 0;
            if (window.清空聊天区) { window.清空聊天区(); }
            var $条 = document.getElementById('curProj'); if ($条) { $条.hidden = true; }
          }
          内.刷新();
        } else { alert((j && j.error) || '归档失败'); }
      });
      return;
    }
    if (做 === 'del') {
      if (!confirm('删除项目「' + p.name + '」？此操作不可恢复。\n（项目下还有对话时会拒绝删除）')) { return; }
      内.提交('/api/project.php', { act: 'del', id: pid }).then(function (j) {
        if (j && j.ok) {
          if (Number((window.当前项目 || {}).id) === Number(pid)) {
            window.当前项目 = null; window.currentConvId = 0;
            if (window.清空聊天区) { window.清空聊天区(); }
          }
          内.刷新();
        } else { alert((j && j.error) || '删除失败'); }
      });
    }
  });

  // ---------- 新建对话 ----------
  function 新建对话(pid) {
    内.提交('/api/conv.php', { act: 'new', project_id: pid }).then(function (j) {
      if (!j || !j.ok) { alert((j && j.error) || '新建失败'); return; }
      内.选项目(pid, true);
      内.展开集[pid] = true; 内.存展开();
      window.currentConvId = j.conv_id;
      if (window.打开对话) { window.打开对话(j.conv_id); }
      内.载入对话(pid);
      内.刷新();
    });
  }
  window.新建项目对话 = 新建对话;

  // ---------- 弹窗 ----------
  var $ = function (id) { return document.getElementById(id); };

  function 开弹窗(p) {
    p = p || {};
    $('pjTitle').textContent = p.id ? '项目设置' : '新建项目';
    $('pjId').value = p.id || 0;
    $('pjName').value = p.name || '';
    $('pjIntro').value = p.intro || '';
    $('pjStack').value = p.stack || '';
    $('pjHost').value = String(p.host_id || 0);
    $('pjDir').value = p.deploy_dir || '';
    $('pjUrl').value = p.site_url || '';
    $('pjErr').hidden = true;
    $罩.hidden = false;
    setTimeout(function () { $('pjName').focus(); }, 30);
  }
  function 关弹窗() { $罩.hidden = true; }

  var $新钮 = $('btnNewProj');
  if ($新钮) { $新钮.addEventListener('click', function () { 开弹窗(null); }); }
  $('pjClose').addEventListener('click', 关弹窗);
  $('pjCancel').addEventListener('click', 关弹窗);
  $罩.addEventListener('click', function (e) { if (e.target === $罩) { 关弹窗(); } });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !$罩.hidden) { 关弹窗(); }
  });

  $('pjSave').addEventListener('click', function () {
    var 表 = {
      act: 'save',
      id: $('pjId').value,
      name: $('pjName').value.trim(),
      intro: $('pjIntro').value.trim(),
      stack: $('pjStack').value.trim(),
      host_id: $('pjHost').value,
      deploy_dir: $('pjDir').value.trim(),
      site_url: $('pjUrl').value.trim()
    };
    if (!表.name) { 报错('项目名称不能为空'); return; }
    var $s = $('pjSave');
    $s.disabled = true; $s.textContent = '保存中…';
    内.提交('/api/project.php', 表).then(function (j) {
      $s.disabled = false; $s.textContent = '保存';
      if (!j || !j.ok) { 报错((j && j.error) || '保存失败'); return; }
      关弹窗();
      var 新建 = !Number(表.id);
      内.刷新(function () {
        内.选项目(Number(j.id));
        // 新建的项目顺手开一条对话，用户点完就能直接聊
        if (新建) { 新建对话(Number(j.id)); }
      });
    }).catch(function () {
      $s.disabled = false; $s.textContent = '保存';
      报错('网络异常，请重试');
    });
  });

  function 报错(文) {
    var $e = $('pjErr');
    $e.textContent = 文;
    $e.hidden = false;
  }

  // 头部「+ 新对话」
  var $新对 = $('btnNewConv');
  if ($新对) {
    $新对.addEventListener('click', function () {
      var p = window.当前项目;
      if (!p) { alert('请先在左侧选择一个项目'); return; }
      新建对话(p.id);
    });
  }
})();
