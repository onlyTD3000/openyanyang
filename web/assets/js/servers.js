/**
 * 「我的服务器」页面交互：登记、编辑、删除、测试连通。
 */
(function () {
  'use strict';

  var hostModal = document.getElementById('hostModal');
  var outModal  = document.getElementById('outModal');
  var form      = document.getElementById('hostForm');
  var errBox    = document.getElementById('hostErr');

  /** 打开弹窗并把焦点移到首个输入框，便于键盘操作 */
  function open(el) {
    el.hidden = false;
    var f = el.querySelector('input, textarea, button');
    if (f) { f.focus(); }
  }
  function close(el) { el.hidden = true; }

  function showErr(msg) {
    errBox.textContent = msg;
    errBox.hidden = false;
  }

  /** 统一的接口请求。CSRF 令牌随每次请求带上 */
  function post(url, data) {
    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { error: '服务端返回异常' }; }); });
  }

  /** 按认证方式切换密码/私钥字段的显示 */
  function syncAuth() {
    var isKey = document.getElementById('f_auth').value === 'key';
    document.getElementById('labSecret').textContent = isKey ? '私钥内容' : '登录密码';
    document.getElementById('wrapKpass').style.display = isKey ? '' : 'none';
    var ta = document.getElementById('f_secret');
    ta.placeholder = isKey
      ? '粘贴私钥全文，含 -----BEGIN ... KEY----- 与结尾行'
      : '输入登录密码';
    ta.rows = isKey ? 6 : 2;
  }
  document.getElementById('f_auth').addEventListener('change', syncAuth);

  // ---- 新增 ----
  document.getElementById('btnAdd').addEventListener('click', function () {
    form.reset();
    form.querySelector('[name=id]').value = '0';
    document.getElementById('hostModalTitle').textContent = '登记服务器';
    document.getElementById('reqSecret').hidden = false;
    errBox.hidden = true;
    syncAuth();
    open(hostModal);
  });

  // ---- 编辑 / 删除 / 测试，用事件委托绑定 ----
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-edit],[data-del],[data-test],[data-close]');
    if (!t) { return; }

    if (t.hasAttribute('data-close')) {
      close(t.closest('.modal'));
      return;
    }

    if (t.hasAttribute('data-edit')) {
      form.reset();
      form.querySelector('[name=id]').value    = t.getAttribute('data-edit');
      form.querySelector('[name=name]').value  = t.getAttribute('data-name');
      form.querySelector('[name=host]').value  = t.getAttribute('data-host');
      form.querySelector('[name=port]').value  = t.getAttribute('data-port');
      form.querySelector('[name=username]').value  = t.getAttribute('data-user');
      form.querySelector('[name=auth_type]').value = t.getAttribute('data-auth');
      document.getElementById('hostModalTitle').textContent = '编辑服务器';
      // 编辑时凭据非必填，留空即不改
      document.getElementById('reqSecret').hidden = true;
      errBox.hidden = true;
      syncAuth();
      open(hostModal);
      return;
    }

    if (t.hasAttribute('data-del')) {
      var nm = t.getAttribute('data-dname');
      if (!confirm('确定删除服务器「' + nm + '」？\n登记的凭据会一并删除，此操作不可撤销。')) {
        return;
      }
      post('/api/ssh_hosts.php', { act: 'del', id: t.getAttribute('data-del') })
        .then(function (r) {
          if (r.ok) { location.reload(); } else { alert(r.error || '删除失败'); }
        });
      return;
    }

    if (t.hasAttribute('data-test')) {
      var old = t.textContent;
      t.textContent = '连接中…';
      t.disabled = true;
      post('/api/ssh_hosts.php', { act: 'test', id: t.getAttribute('data-test') })
        .then(function (r) {
          t.textContent = old;
          t.disabled = false;
          var box = document.getElementById('outText');
          if (r.ok) {
            box.textContent = '连接成功（' + r.ms + ' ms）\n\n' + r.out;
          } else {
            box.textContent = '连接失败：\n\n' + (r.error || '未知错误');
          }
          open(outModal);
        });
    }
  });

  // ---- 保存 ----
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errBox.hidden = true;
    var btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.textContent = '保存中…';

    var d = {
      act:       'save',
      id:        form.querySelector('[name=id]').value,
      name:      form.querySelector('[name=name]').value,
      host:      form.querySelector('[name=host]').value,
      port:      form.querySelector('[name=port]').value,
      username:  form.querySelector('[name=username]').value,
      auth_type: form.querySelector('[name=auth_type]').value,
      secret:    form.querySelector('[name=secret]').value,
      key_pass:  form.querySelector('[name=key_pass]').value
    };
    post('/api/ssh_hosts.php', d).then(function (r) {
      btn.disabled = false;
      btn.textContent = '保存';
      if (r.ok) { location.reload(); } else { showErr(r.error || '保存失败'); }
    });
  });

  // Esc 关闭弹窗
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      [hostModal, outModal].forEach(function (m) {
        if (m && !m.hidden) { close(m); }
      });
    }
  });
})();
