<?php
/**
 * 支付宝同步跳转页：用户在支付宝付完款后浏览器回到这里。
 *
 * 这个页面只负责「告诉用户结果」，不能把它当作入账依据——
 * 用户完全可以不跳转就关掉页面，也可以伪造这个 GET 请求。
 * 真正的入账走异步通知 alipay_notify.php。
 *
 * 不过异步通知偶尔会晚到或打不进来，所以这里会主动查一次订单状态，
 * 查到已付就顺手入账（pay_settle 是幂等的，跟回调重复也不会加两次）。
 */
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/pay.php';

// 这里不能用 require_login()。
// 手机网站支付付完款跳回来时，浏览器常常带不上会话 cookie：
// 支付宝 App 内置 webview 和系统浏览器是两套 cookie 罐，
// 跨站跳转回来 SameSite=Lax 也可能拦掉。
// 结果就是钱已经到账、却被重定向到登录页，用户以为充值失败。
// 所以这一页做成「登录与否都能看结果」，身份用另外两种方式确认。
$me       = current_user();          // 可能为 null
$siteName = app_name();
$navOn    = 'recharge';

$orderNo = (string) ($_GET['out_trade_no'] ?? '');
$paid    = false;
$ord     = null;
$tip     = '';

if ($orderNo !== '') {
    if ($me) {
        // 已登录：只查自己的订单，防止拿别人的订单号来看信息
        $ord = db_one('SELECT * FROM recharge_orders WHERE order_no = ? AND user_id = ? LIMIT 1',
            [$orderNo, $me['id']]);
    } elseif (alipay_verify($_GET)) {
        // 未登录：只有当这串参数确实是支付宝签发的（验签通过）才允许查看。
        // 光凭订单号就放行的话，别人可以枚举订单号窥探他人充值记录。
        $ord = db_one('SELECT * FROM recharge_orders WHERE order_no = ? LIMIT 1', [$orderNo]);
    } else {
        $tip = '无法确认这笔订单的归属，请登录后到充值页查看充值记录。';
    }
}

if (!$ord) {
    // 上面若已经给出更具体的原因（比如验签没过），别用这句笼统的话盖掉
    if ($tip === '') {
        $tip = '找不到这笔订单，如果已经付款请到充值页点「我已支付」核对。';
    }
} elseif ((string) $ord['status'] === 'paid') {
    $paid = true;
} else {
    // 同步跳转的参数也带签名，但支付宝同步返回的验签规则和异步不同，
    // 这里干脆不依赖它，直接调接口问一次，最可靠。
    $q = alipay_query($orderNo);
    if ($q['ok'] && $q['paid']) {
        pay_settle($orderNo, (string) $q['trade_no'], (string) $q['buyer_id'],
            'return:' . json_encode($q, JSON_UNESCAPED_UNICODE));
        $paid = true;
        $ord  = db_one('SELECT * FROM recharge_orders WHERE order_no = ? LIMIT 1', [$orderNo]);
    } else {
        $tip = '支付宝还没有返回付款成功的状态。如果你已经付款，稍等几秒刷新即可，'
             . '款项确认后余额会自动到账。';
    }
}

// 重新读一次余额，页面上显示最新值。
// current_user() 内部有静态缓存，入账后的余额要重新查库才拿得到。
if ($me) {
    $me = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $me['id']]) ?: $me;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>支付结果 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>
<div class="app">
<?php if ($me): ?>
  <?php require __DIR__ . '/../inc/topbar.php'; ?>
<?php else: ?>
  <!-- 未登录时顶栏用不了（它要读用户名和余额），退化成只有站名的简版 -->
  <header class="topbar">
    <div class="topbar-brand"><span class="logo-dot"></span><?= h($siteName) ?></div>
  </header>
<?php endif; ?>
<div class="page">
  <div class="page-head"><h1 class="page-title">支付结果</h1></div>

  <div class="card">
    <div class="card-body" style="text-align:center;padding:32px 20px">
      <?php if ($paid): ?>
        <div style="font-size:40px;line-height:1">✅</div>
        <h2 style="margin:12px 0 6px">充值成功</h2>
        <p class="muted">
          到账 <b>￥<?= h(number_format((float) $ord['credit_amount'], 2)) ?></b>
          ，实付 ￥<?= h(number_format((float) $ord['pay_amount'], 2)) ?>
        </p>
        <?php if ($me): ?>
          <p>当前余额 <b>￥<?= money($me['balance']) ?></b></p>
        <?php else: ?>
          <p class="muted">余额已到账，登录后即可查看和使用。</p>
        <?php endif; ?>
      <?php else: ?>
        <div style="font-size:40px;line-height:1">⏳</div>
        <h2 style="margin:12px 0 6px">尚未确认到账</h2>
        <p class="muted" style="max-width:520px;margin:0 auto"><?= h($tip) ?></p>
      <?php endif; ?>

      <div class="mt-16">
        <?php if ($me): ?>
          <a class="btn btn-primary" href="/recharge.php">返回充值页</a>
          <a class="btn" href="/chat.php">去对话</a>
        <?php else: ?>
          <a class="btn btn-primary" href="/login.php">登录查看余额</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</div>
</body>
</html>
