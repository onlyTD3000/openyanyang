<?php
/**
 * 后台充值订单列表：按账号/订单号/状态/日期筛选，可看单笔详情。
 */
$adminOn   = 'recharge';
$pageTitle = '充值订单';
require __DIR__ . '/_head.php';
require_once __DIR__ . '/../inc/pay.php';

$kw = trim($_GET['kw'] ?? '');
$st = trim($_GET['st'] ?? '');
$d1 = trim($_GET['d1'] ?? '');
$d2 = trim($_GET['d2'] ?? '');
$view = trim($_GET['view'] ?? '');

$page = max(1, (int) ($_GET['p'] ?? 1));
$per  = 30;
$off  = ($page - 1) * $per;

$w = [];
$a = [];
if ($kw !== '') {
    $w[] = '(u.username LIKE ? OR o.order_no LIKE ? OR o.trade_no LIKE ?)';
    $a[] = '%' . $kw . '%';
    $a[] = '%' . $kw . '%';
    $a[] = '%' . $kw . '%';
}
if (in_array($st, ['pending', 'paid', 'closed', 'failed'], true)) {
    $w[] = 'o.status = ?';
    $a[] = $st;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) { $w[] = 'o.created_at >= ?'; $a[] = $d1 . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) { $w[] = 'o.created_at <= ?'; $a[] = $d2 . ' 23:59:59'; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$total = (int) db_val('SELECT COUNT(*) FROM recharge_orders o
                        LEFT JOIN users u ON u.id = o.user_id ' . $where, $a);
// 汇总只统计已支付的，未付订单算进金额没有意义
$agg = db_one('SELECT COALESCE(SUM(CASE WHEN o.status = \'paid\' THEN o.pay_amount END),0) sp,
                      COALESCE(SUM(CASE WHEN o.status = \'paid\' THEN o.credit_amount END),0) sc,
                      COALESCE(SUM(o.status = \'paid\'),0) np
                 FROM recharge_orders o LEFT JOIN users u ON u.id = o.user_id ' . $where, $a);
$rows = db_all('SELECT o.*, u.username FROM recharge_orders o
                 LEFT JOIN users u ON u.id = o.user_id ' . $where . '
                ORDER BY o.id DESC LIMIT ' . $per . ' OFFSET ' . $off, $a);
$pages = max(1, (int) ceil($total / $per));
$qs = fn(array $ex = []) => http_build_query(array_merge(
    ['kw' => $kw, 'st' => $st, 'd1' => $d1, 'd2' => $d2], $ex));

$detail = null;
if ($view !== '') {
    $detail = db_one('SELECT o.*, u.username FROM recharge_orders o
                       LEFT JOIN users u ON u.id = o.user_id
                      WHERE o.order_no = ? LIMIT 1', [$view]);
}

$stName  = ['pending' => '待支付', 'paid' => '已支付', 'closed' => '已关闭', 'failed' => '下单失败'];
$stClass = ['pending' => 'badge-off', 'paid' => 'badge-ok', 'closed' => 'badge-off', 'failed' => 'badge-red'];
$chName  = ['alipay' => '支付宝'];
$scName  = ['page' => '电脑网站', 'wap' => '手机网站', 'qr' => '扫码'];
?>
<div class="page-head"><h1 class="page-title">充值订单</h1></div>

<div class="card mb-16">
  <div class="card-body">
    <form class="inline-form" method="get" style="flex-wrap:wrap">
      <input class="input" style="width:220px" name="kw" value="<?= h($kw) ?>"
             placeholder="账号 / 订单号 / 支付宝流水号">
      <select class="select" style="width:140px" name="st">
        <option value="">全部状态</option>
        <?php foreach ($stName as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $st === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
      <input class="input" style="width:150px" type="date" name="d1" value="<?= h($d1) ?>">
      <input class="input" style="width:150px" type="date" name="d2" value="<?= h($d2) ?>">
      <button class="btn btn-primary" type="submit">筛选</button>
      <a class="btn" href="/admin/recharge_orders.php">重置</a>
    </form>
  </div>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat-label">订单数</div><div class="stat-value"><?= fmt_int($total) ?></div></div>
  <div class="stat"><div class="stat-label">成功笔数</div><div class="stat-value"><?= fmt_int($agg['np']) ?></div></div>
  <div class="stat"><div class="stat-label">实收金额</div><div class="stat-value">￥<?= money($agg['sp']) ?></div></div>
  <div class="stat"><div class="stat-label">到账额度</div><div class="stat-value">￥<?= money($agg['sc']) ?></div></div>
</div>

<?php if ($detail): ?>
<div class="card mb-16">
  <div class="card-head">订单详情 <?= h($detail['order_no']) ?></div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="tbl">
        <tbody>
          <tr><th style="width:140px">用户</th><td><?= h($detail['username'] ?? '已删除') ?>（ID <?= (int) $detail['user_id'] ?>）</td></tr>
          <tr><th>状态</th><td><span class="badge <?= h($stClass[$detail['status']] ?? '') ?>"><?= h($stName[$detail['status']] ?? $detail['status']) ?></span></td></tr>
          <tr><th>到账额度</th><td>￥<?= money($detail['credit_amount']) ?></td></tr>
          <tr><th>实付金额</th><td>￥<?= money($detail['pay_amount']) ?>
            （优惠 <?= h(number_format((float) $detail['discount_rate'], 3, '.', '')) ?>%）</td></tr>
          <tr><th>支付方式</th><td><?= h($chName[$detail['channel']] ?? $detail['channel']) ?>
            / <?= h($scName[$detail['pay_scene']] ?? $detail['pay_scene']) ?></td></tr>
          <tr><th>支付宝流水号</th><td><?= h($detail['trade_no'] ?: '-') ?></td></tr>
          <tr><th>付款账号</th><td><?= h($detail['buyer_id'] ?: '-') ?></td></tr>
          <tr><th>下单时间</th><td><?= h($detail['created_at']) ?></td></tr>
          <tr><th>支付时间</th><td><?= h($detail['paid_at'] ?: '-') ?></td></tr>
          <tr><th>下单 IP</th><td><?= h($detail['client_ip'] ?: '-') ?></td></tr>
          <tr><th>回调原文</th><td><pre style="white-space:pre-wrap;word-break:break-all;margin:0;font-size:12.5px"><?= h($detail['notify_raw'] ?: '（无）') ?></pre></td></tr>
        </tbody>
      </table>
    </div>
    <a class="btn" href="?<?= h($qs(['p' => $page])) ?>">收起</a>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>下单时间</th><th>订单号</th><th>用户</th><th>到账</th><th>实付</th>
                 <th>优惠</th><th>方式</th><th>状态</th><th>支付时间</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="10" class="empty">没有符合条件的订单</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= h($r['created_at']) ?></td>
          <td class="nowrap"><?= h($r['order_no']) ?></td>
          <td><?= h($r['username'] ?? '已删除') ?></td>
          <td>￥<?= money($r['credit_amount']) ?></td>
          <td>￥<?= money($r['pay_amount']) ?></td>
          <td><?= h(rtrim(rtrim(number_format((float) $r['discount_rate'], 3, '.', ''), '0'), '.')) ?>%</td>
          <td class="nowrap"><?= h($scName[$r['pay_scene']] ?? $r['pay_scene']) ?></td>
          <td><span class="badge <?= h($stClass[$r['status']] ?? '') ?>">
                <?= h($stName[$r['status']] ?? $r['status']) ?></span></td>
          <td class="nowrap"><?= h($r['paid_at'] ?: '-') ?></td>
          <td><a class="btn btn-sm" href="?<?= h($qs(['p' => $page, 'view' => $r['order_no']])) ?>">详情</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): ?>
      <?php if ($i === $page): ?><span class="on"><?= $i ?></span>
      <?php else: ?><a href="?<?= h($qs(['p' => $i])) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_foot.php'; ?>
