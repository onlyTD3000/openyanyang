/**
 * 充值页交互：选面额、算金额、下单跳转、扫码轮询。
 *
 * 页面上显示的实付金额只是给用户看的，真正的金额由服务端按后台配置算，
 * 这里改数字没用，下单接口不看前端传的实付值。
 */
(function () {
  var $items   = document.querySelectorAll('.amt-item');
  var $custom  = document.getElementById('customAmt');
  var $btn     = document.getElementById('btnPay');
  var $btnQr   = document.getElementById('btnQr');
  var $tip     = document.getElementById('payTip');
  var $sumC    = document.getElementById('sumCredit');
  var $sumP    = document.getElementById('sumPay');
  var $sumS    = document.getElementById('sumSave');
  if (!$btn) { return; }

  var 选中额度 = 0;
  var 选中实付 = 0;

  function 刷新汇总() {
    if (选中额度 <= 0) {
      $sumC.textContent = '--';
      $sumP.textContent = '--';
      $sumS.textContent = '';
      $btn.disabled = true;
      if ($btnQr) { $btnQr.disabled = true; }
      $tip.textContent = '请先选择充值金额';
      return;
    }
    $sumC.textContent = 选中额度.toFixed(2);
    $sumP.textContent = 选中实付.toFixed(2);
    var 省 = 选中额度 - 选中实付;
    $sumS.textContent = 省 > 0.004 ? '（省 ' + 省.toFixed(2) + ' 元）' : '';
    $btn.disabled = false;
    if ($btnQr) { $btnQr.disabled = false; }
    $tip.textContent = '';
  }

  // 点面额卡片
  Array.prototype.forEach.call($items, function (el) {
    el.addEventListener('click', function () {
      Array.prototype.forEach.call($items, function (x) { x.classList.remove('on'); });
      el.classList.add('on');
      if ($custom) { $custom.value = ''; }
      选中额度 = parseFloat(el.dataset.credit) || 0;
      选中实付 = parseFloat(el.dataset.pay) || 0;
      刷新汇总();
    });
  });

  /* 自定义金额的实付预估。
     阶梯表由页面直接给出，不从面额反推：设了门槛以后第一个面额可能本身就没达标，
     反推出来的折扣是 1，自定义金额会一直显示原价。 */
  var 阶梯 = Array.isArray(window.PAY_TIERS) ? window.PAY_TIERS : [];

  // 取门槛不超过该额度的最高一档，跟服务端 pay_discount_for() 同一套规则。
  // 阶梯已按门槛升序排好，从后往前找到第一个就是。
  function 取折扣(额度) {
    var 分 = Math.round(额度 * 100);
    for (var i = 阶梯.length - 1; i >= 0; i--) {
      // 用「分」比较，避免浮点误差让「刚好满额」判成没满
      if (分 >= Math.round((阶梯[i].min || 0) * 100)) {
        return 阶梯[i].rate || 0;
      }
    }
    return 0;
  }

  function 估实付(额度) {
    var r = 取折扣(额度);
    var 分 = Math.ceil(Math.round(额度 * (100 - r) * 10000) / 10000);
    return Math.max(1, 分) / 100;
  }

  if ($custom) {
    $custom.addEventListener('input', function () {
      Array.prototype.forEach.call($items, function (x) { x.classList.remove('on'); });
      var v = parseFloat($custom.value) || 0;
      if (v <= 0) { 选中额度 = 0; 选中实付 = 0; 刷新汇总(); return; }
      选中额度 = v;
      选中实付 = 估实付(v);
      刷新汇总();
    });
  }

  function 当前渠道() {
    var r = document.querySelector('input[name="paych"]:checked')
         || document.querySelector('input[name="paych"]');
    return r ? r.value : 'alipay';
  }

  // 下单。scene 只是「希望用哪种形态」，最终由服务端按后台开关决定
  function 下单(scene) {
    if (选中额度 <= 0) { return; }
    $btn.disabled = true;
    if ($btnQr) { $btnQr.disabled = true; }
    $tip.textContent = '正在创建订单...';

    var fd = new FormData();
    fd.append('act', 'create');
    fd.append('credit', String(选中额度));
    fd.append('channel', 当前渠道());
    if (scene) { fd.append('scene', scene); }
    fd.append('csrf', window.CSRF);

    fetch('/api/pay.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.error) {
          刷新汇总();
          $tip.textContent = j.error;
          return;
        }
        if (j.type === 'redirect') {
          $tip.textContent = '正在跳转到支付宝...';
          location.href = j.url;
        } else if (j.type === 'qr') {
          显示二维码(j.order_no);
        }
      })
      .catch(function () {
        刷新汇总();
        $tip.textContent = '网络异常，请重试';
      });
  }

  $btn.addEventListener('click', function () { 下单(''); });
  if ($btnQr) {
    $btnQr.addEventListener('click', function () { 下单('qr'); });
  }

  /* 扫码支付：渲染二维码 + 每 3 秒查一次状态。
     图片由本站 /api/qrcode.php 生成，支付码串不出本服务器。 */
  function 显示二维码(订单号) {
    var box = document.createElement('div');
    box.className = 'qr-mask';
    box.innerHTML =
      '<div class="qr-box">' +
        '<h3>请用支付宝扫码支付</h3>' +
        '<img class="qr-img" alt="支付二维码" src="/api/qrcode.php?order_no=' +
          encodeURIComponent(订单号) + '">' +
        '<p class="muted">应付 <b>' + 选中实付.toFixed(2) + '</b> 元，到账 ' +
          选中额度.toFixed(2) + ' 元</p>' +
        '<p class="qr-state">等待支付...</p>' +
        '<button class="btn" type="button">取消</button>' +
      '</div>';
    document.body.appendChild(box);

    var 停 = false;
    var $state = box.querySelector('.qr-state');
    box.querySelector('button').addEventListener('click', function () {
      停 = true;
      box.remove();
      刷新汇总();
      $tip.textContent = '已取消，订单可在下方记录里继续支付';
    });

    function 查一次() {
      if (停) { return; }
      var fd = new FormData();
      fd.append('act', 'query');
      fd.append('order_no', 订单号);
      fd.append('csrf', window.CSRF);
      fetch('/api/pay.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (停) { return; }
          if (j.status === 'paid') {
            $state.textContent = '支付成功，正在刷新...';
            setTimeout(function () { location.reload(); }, 800);
          } else {
            setTimeout(查一次, 3000);
          }
        })
        .catch(function () { setTimeout(查一次, 5000); });
    }
    setTimeout(查一次, 3000);
  }

  /* 「我已支付」：主动查一次。
     异步回调有可能因为网络或防火墙没打进来，给用户一个自助补救的入口，
     比让他来问客服体验好得多。 */
  Array.prototype.forEach.call(document.querySelectorAll('.js-recheck'), function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      b.textContent = '查询中';
      var fd = new FormData();
      fd.append('act', 'query');
      fd.append('order_no', b.dataset.no);
      fd.append('csrf', window.CSRF);
      fetch('/api/pay.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.status === 'paid') { location.reload(); return; }
          b.disabled = false;
          b.textContent = '我已支付';
          alert(j.msg ? ('支付宝返回：' + j.msg) : '还没有查到付款成功，请稍后再试');
        })
        .catch(function () {
          b.disabled = false;
          b.textContent = '我已支付';
        });
    });
  });
})();
