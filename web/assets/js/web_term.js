/**
 * 网页终端：服务器列表里点「网页终端」，直连 ssh 执行命令。
 *
 * 链路：点按钮 → 找 PHP 要一张一次性令牌 → 连 ws → 发 auth → 发 connect → 收发数据。
 * 令牌那一步不能省：浏览器的 WebSocket API 不让加自定义头，跨端口 Cookie 也带不过去，
 * ws 服务端没法直接读 PHP 会话，只能靠这张短时效的票确认身份和主机归属。
 *
 * 没用 xterm.js：多一个几百 KB 的依赖，而这里只需要「看输出 + 敲命令」。
 * 代价是不支持 vim/top 这类全屏程序的光标控制，够用就行。
 */
(function () {
  'use strict';

  var 罩 = document.getElementById('termMask');
  if (!罩) { return; }

  var 体 = document.getElementById('termBody');
  var 标 = document.getElementById('termTitle');
  var 态 = document.getElementById('termStat');
  var 框 = document.getElementById('termInput');
  var 送 = document.getElementById('termSend');
  var 关 = document.getElementById('termClose');

  var 连 = null;          // WebSocket
  var 主机id = 0;
  var 历史 = [];
  var 历史位 = -1;
  var 心跳 = 0;

  function 设态(文, 类) {
    态.textContent = 文;
    态.className = 'term-stat' + (类 ? ' ' + 类 : '');
  }

  /* 追加输出。终端输出量可能很大，超过上限就砍掉前面的，避免页面越来越卡。 */
  function 写(文, 类) {
    var 到底 = 体.scrollHeight - 体.scrollTop - 体.clientHeight < 40;
    var 段 = document.createElement('span');
    if (类) { 段.className = 类; }
    段.textContent = 文;
    体.appendChild(段);
    while (体.childNodes.length > 800) {
      体.removeChild(体.firstChild);
    }
    // 只在原本就贴着底部时才自动滚，否则会打断用户往上翻查看
    if (到底) { 体.scrollTop = 体.scrollHeight; }
  }

  function 发(对象) {
    if (连 && 连.readyState === 1) {
      连.send(JSON.stringify(对象));
      return true;
    }
    return false;
  }

  function 收尾(文) {
    if (心跳) { clearInterval(心跳); 心跳 = 0; }
    if (连) {
      try { 连.close(); } catch (e) {}
      连 = null;
    }
    if (文) { 设态(文, 'bad'); }
    框.disabled = true;
    送.disabled = true;
  }

  function 开(id, 名) {
    主机id = id;
    历史 = [];
    历史位 = -1;
    体.textContent = '';
    标.textContent = '网页终端 · ' + 名;
    设态('取令牌…', '');
    罩.hidden = false;
    框.disabled = true;
    送.disabled = true;

    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    fd.append('host_id', String(id));

    fetch('/api/wsssh_token.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { error: '返回格式异常' }; }); })
      .then(function (r) {
        if (!r || r.error) {
          收尾('失败');
          写('取令牌失败：' + ((r && r.error) || '未知错误') + '\n', 'term-err');
          return;
        }
        写('连接 ' + r.host.addr + ' …\n', 'term-dim');
        连ws(r.token);
      })
      .catch(function (e) {
        收尾('失败');
        写('取令牌请求出错：' + (e && e.message ? e.message : e) + '\n', 'term-err');
      });
  }

  function 连ws(票) {
    设态('握手…', '');
    // 页面是 https 时必须用 wss，混用会被浏览器直接拦掉
    var 协议 = location.protocol === 'https:' ? 'wss://' : 'ws://';
    var 地址 = 协议 + location.host + '/ws/';

    try {
      连 = new WebSocket(地址);
    } catch (e) {
      收尾('失败');
      写('无法建立 WebSocket：' + e.message + '\n', 'term-err');
      return;
    }

    连.onopen = function () {
      设态('鉴权…', '');
      发({ action: 'auth', token: 票 });
    };

    连.onmessage = function (ev) {
      var m;
      try { m = JSON.parse(ev.data); } catch (e) { return; }

      if (m.type === 'authed') {
        设态('连接主机…', '');
        发({ action: 'connect', host_id: 主机id });
      } else if (m.type === 'connected') {
        设态('已连接', 'good');
        框.disabled = false;
        送.disabled = false;
        框.focus();
        // 定时 ping，避免中间的 nginx 或负载均衡把空闲连接掐掉
        心跳 = setInterval(function () { 发({ action: 'ping' }); }, 25000);
      } else if (m.type === 'output') {
        写(m.data || '');
      } else if (m.type === 'error') {
        写('\n[错误] ' + (m.msg || '') + '\n', 'term-err');
        设态('出错', 'bad');
      }
    };

    连.onerror = function () {
      写('\n[连接出错]\n', 'term-err');
      设态('连接出错', 'bad');
    };

    连.onclose = function () {
      收尾('已断开');
      写('\n[连接已关闭]\n', 'term-dim');
    };
  }

  /* 送一行命令。行尾补 \n 才会被远端 shell 当成一次回车执行。 */
  function 送行() {
    if (框.disabled) { return; }
    var 文 = 框.value;
    if (文 !== '') {
      历史.push(文);
      if (历史.length > 200) { 历史.shift(); }
    }
    历史位 = -1;
    if (!发({ action: 'input', data: 文 + '\n' })) {
      写('\n[连接不可用，命令没发出去]\n', 'term-err');
      return;
    }
    框.value = '';
  }

  送.addEventListener('click', 送行);

  框.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      送行();
      return;
    }
    // ↑↓ 翻历史
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!历史.length) { return; }
      历史位 = 历史位 < 0 ? 历史.length - 1 : Math.max(0, 历史位 - 1);
      框.value = 历史[历史位];
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (历史位 < 0) { return; }
      历史位++;
      if (历史位 >= 历史.length) { 历史位 = -1; 框.value = ''; }
      else { 框.value = 历史[历史位]; }
      return;
    }
    // Ctrl+C 送中断信号（\x03），用来打断跑飞的命令
    if (e.ctrlKey && (e.key === 'c' || e.key === 'C')) {
      // 有选中文字时让浏览器正常复制，不抢这个快捷键
      if (window.getSelection && String(window.getSelection()).length) { return; }
      e.preventDefault();
      发({ action: 'input', data: '\u0003' });
      写('^C\n', 'term-dim');
    }
  });

  function 收起() {
    收尾('');
    罩.hidden = true;
    体.textContent = '';
  }

  关.addEventListener('click', 收起);

  // 点遮罩空白处关闭，但点终端框内部不关
  罩.addEventListener('mousedown', function (e) {
    if (e.target === 罩) { 收起(); }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !罩.hidden) { 收起(); }
  });

  // 离开页面时主动断开，别把 ssh 会话留在服务端
  window.addEventListener('beforeunload', function () {
    if (连) { try { 连.close(); } catch (e) {} }
  });

  /* 列表里的「网页终端」按钮。用事件委托，不用给每行单独绑。 */
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-term]') : null;
    if (!b) { return; }
    var id = parseInt(b.getAttribute('data-term'), 10);
    if (!id) { return; }
    开(id, b.getAttribute('data-tname') || ('#' + id));
  });
})();
