<?php
/**
 * 前台 - 余额充值。
 *
 * 面额从后台读，点一下选中就能付；优惠比例也是后台配的，
 * 页面上直接把「到账多少、实付多少」写清楚，避免用户到了支付宝才发现金额不对。
 */
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/pay.php';
$me = require_login();
$navOn = 'recharge';

$siteName = app_name();
$channels = pay_channels();
$amounts  = pay_amount_options();
$tiers    = pay_discount_tiers();
$customOn = (string) setting_get('pay_custom_on', '0') === '1';
$customMin = (float) setting_get('pay_custom_min', 1);
$customMax = (float) setting_get('pay_custom_max', 10000);
$csrf     = csrf_token();

// 最近的充值记录，让用户能自己核对
$orders = db_all('SELECT * FROM recharge_orders WHERE user_id = ?
                   ORDER BY id DESC LIMIT 10', [$me['id']]);

/** 订单状态中文名 */
function ro_status_name(string $s): string
{
    return ['pending' => '待支付', 'paid' => '已到账',
            'closed' => '已关闭', 'failed' => '失败'][$s] ?? $s;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var ls=localStorage.getItem('theme');if(ls==='dark'||(!ls&&<?= (int)($me['dark_mode'] ?? 0) ?>))document.documentElement.classList.add('dark');})();</script>
<title>余额充值 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>
<div class="app">
<?php require __DIR__ . '/inc/topbar.php'; ?>
<div class="page">
<div class="page-head">
  <h1 class="page-title">余额充值</h1>
  <div class="muted">当前余额 <b>￥<?= money($me['balance']) ?></b></div>
</div>

<?php if (!$channels): ?>
  <div class="alert alert-error">
    管理员还没有配置支付渠道，暂时无法在线充值。
  </div>
<?php elseif (!$amounts && !$customOn): ?>
  <div class="alert alert-error">管理员还没有设置充值面额。</div>
<?php else: ?>

<div class="card mb-16">
  <div class="card-head">
    选择充值金额
    <?php
    // 每一档都单独挂个标签，用户一眼能看出充多少更划算。
    // 门槛为 0 的那档写「全场」，写「满 0 元」很怪。
    foreach ($tiers as $t):
        $mTxt = rtrim(rtrim(number_format($t['min'], 2, '.', ''), '0'), '.');
        $rTxt = rtrim(rtrim(number_format($t['rate'], 3, '.', ''), '0'), '.');
    ?>
      <span class="badge badge-ok" style="margin-left:8px">
        <?= $t['min'] > 0 ? '满 ' . h($mTxt) . ' 元享' : '全场' ?>
        <?= h($rTxt) ?>% 优惠</span>
    <?php endforeach; ?>
  </div>
  <div class="card-body">
    <div class="amt-grid">
      <?php foreach ($amounts as $a): $c = pay_calc($a); ?>
        <button type="button" class="amt-item" data-credit="<?= h((string) $c['credit']) ?>"
                data-pay="<?= h(number_format($c['pay'], 2, '.', '')) ?>">
          <span class="amt-credit">￥<?= h(rtrim(rtrim(number_format($c['credit'], 2, '.', ''), '0'), '.')) ?></span>
          <?php if ($c['pay'] < $c['credit']): ?>
            <span class="amt-pay">实付 <?= h(number_format($c['pay'], 2)) ?></span>
          <?php else: ?>
            <span class="amt-pay">原价</span>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>

    <?php if ($customOn): ?>
      <div class="mt-12">
        <label class="field">
          <span class="field-label">或自定义金额（<?= h(number_format($customMin, 2)) ?>
            ~ <?= h(number_format($customMax, 2)) ?> 元）</span>
          <input class="input" id="customAmt" type="number" step="0.01"
                 min="<?= h((string) $customMin) ?>" max="<?= h((string) $customMax) ?>"
                 placeholder="输入到账金额" style="max-width:240px">
        </label>
      </div>
    <?php endif; ?>

    <?php if (count($channels) > 1): ?>
      <div class="mt-12">
        <span class="field-label">支付方式</span>
        <?php $first = true; foreach ($channels as $k => $name): ?>
          <label style="margin-right:14px">
            <input type="radio" name="paych" value="<?= h($k) ?>" <?= $first ? 'checked' : '' ?>>
            <?= h($name) ?>
          </label>
        <?php $first = false; endforeach; ?>
      </div>
    <?php else: ?>
      <input type="hidden" name="paych" value="<?= h((string) array_key_first($channels)) ?>">
    <?php endif; ?>

    <div class="pay-sum mt-12">
      <span>到账 <b id="sumCredit">--</b> 元</span>
      <span>需支付 <b id="sumPay" class="text-hl">--</b> 元</span>
      <span id="sumSave" class="muted"></span>
    </div>

    <div class="mt-12">
      <button class="btn btn-primary" id="btnPay" type="button" disabled>立即支付</button>
      <?php if ((string) setting_get('alipay_scene_qr', '0') === '1'): ?>
        <button class="btn" id="btnQr" type="button" disabled>扫码支付</button>
      <?php endif; ?>
      <span class="muted" id="payTip">请先选择充值金额</span>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">最近充值记录</div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>订单号</th><th>到账</th><th>实付</th><th>状态</th><th>时间</th><th></th></tr></thead>
      <tbody>
      <?php if (!$orders): ?>
        <tr><td colspan="6" class="empty">还没有充值记录</td></tr>
      <?php else: foreach ($orders as $o): ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= h($o['order_no']) ?></td>
          <td>￥<?= h(number_format((float) $o['credit_amount'], 2)) ?></td>
          <td>￥<?= h(number_format((float) $o['pay_amount'], 2)) ?></td>
          <td>
            <span class="badge <?= $o['status'] === 'paid' ? 'badge-ok' : '' ?>">
              <?= h(ro_status_name((string) $o['status'])) ?>
            </span>
          </td>
          <td class="muted"><?= h((string) $o['created_at']) ?></td>
          <td>
            <?php if ($o['status'] === 'pending'): ?>
              <button class="btn btn-sm js-recheck" data-no="<?= h($o['order_no']) ?>">我已支付</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</div>
<script>
window.CSRF = <?= json_encode($csrf) ?>;
// 自定义金额的实付预估要用到，服务端仍会重算一遍，这里只影响显示。
// 传整个阶梯表而不是单个比例：自定义金额落在哪一档只有拿到全表才能判断。
window.PAY_TIERS = <?= json_encode(array_map(function ($t) {
    return ['min' => (float) $t['min'], 'rate' => (float) $t['rate']];
}, $tiers)) ?>;
</script>
<script src="<?= asset('/assets/js/recharge.js') ?>"></script>
</body>
</html>
