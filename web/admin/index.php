<?php
ob_start();
$adminOn = 'dash';
$pageTitle = '后台概览';
require __DIR__ . '/_head.php';

$uCount   = (int) db_val('SELECT COUNT(*) FROM users');
$uActive  = (int) db_val('SELECT COUNT(*) FROM users WHERE status = 1');
$chCount  = (int) db_val('SELECT COUNT(*) FROM channels WHERE status = 1');
$mCount   = (int) db_val('SELECT COUNT(*) FROM models WHERE status = 1');
$sum      = db_one('SELECT COALESCE(SUM(cost),0) c, COALESCE(SUM(tokens_in+tokens_out),0) t, COUNT(*) n FROM usage_logs');
$today    = db_one('SELECT COALESCE(SUM(cost),0) c, COUNT(*) n FROM usage_logs WHERE created_at >= CURDATE()');
$balSum   = db_val('SELECT COALESCE(SUM(balance),0) FROM users');
$todayR   = db_one('SELECT COALESCE(SUM(pay_amount),0) p, COALESCE(SUM(credit_amount),0) c, COUNT(*) n
                      FROM recharge_orders WHERE status = "paid" AND paid_at >= CURDATE()');
$rangeMap = [
  'day'   => "CURDATE()",
  'week'  => "DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
  'month' => "DATE_FORMAT(CURDATE(), '%Y-%m-01')",
  'year'  => "DATE_FORMAT(CURDATE(), '%Y-01-01')",
];
$range = $_GET['range'] ?? 'day';
if (!isset($rangeMap[$range])) $range = 'day';
$rangeCol = $rangeMap[$range];
$rangeLabel = ['day'=>'今日','week'=>'近7天','month'=>'本月','year'=>'本年'][$range];
$topUsers = db_all("SELECT u.username, COALESCE(SUM(l.cost),0) c, COALESCE(SUM(l.tokens_in+l.tokens_out),0) t
                      FROM usage_logs l JOIN users u ON u.id = l.user_id
                     WHERE l.created_at >= $rangeCol
                     GROUP BY l.user_id ORDER BY c DESC LIMIT 8");
$recent   = db_all('SELECT l.*, u.username FROM usage_logs l LEFT JOIN users u ON u.id = l.user_id
                     ORDER BY l.id DESC LIMIT 10');

/* AJAX 接口：只返回消费排行 JSON */
if (($_GET['ajax'] ?? '') === 'rank') {
  ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'range'  => $range,
    'label'  => $rangeLabel,
    'rows'   => array_map(fn($t) => [
      'username' => $t['username'],
      'tokens'   => fmt_int($t['t']),
      'cost'     => money($t['c']),
    ], $topUsers),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
?>
<div class="page-head"><h1 class="page-title">概览</h1></div>

<div class="stat-grid">
  <div class="stat">
    <div class="stat-label">用户总数</div>
    <div class="stat-value"><?= fmt_int($uCount) ?></div>
    <div class="stat-sub">正常 <?= fmt_int($uActive) ?> 个</div>
  </div>
  <div class="stat">
    <div class="stat-label">今日充值</div>
    <div class="stat-value">￥<?= money($todayR['p'] ?? 0) ?></div>
    <div class="stat-sub">今日支付 <?= fmt_int($todayR['n'] ?? 0) ?> 笔 · 到账 ￥<?= money($todayR['c'] ?? 0) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">今日消费</div>
    <div class="stat-value">￥<?= money($today['c']) ?></div>
    <div class="stat-sub">今日调用 <?= fmt_int($today['n']) ?> 次</div>
  </div>
  <div class="stat">
    <div class="stat-label">累计消费</div>
    <div class="stat-value">￥<?= money($sum['c']) ?></div>
    <div class="stat-sub"><?= fmt_int($sum['t']) ?> tokens / <?= fmt_int($sum['n']) ?> 次</div>
  </div>
  <div class="stat">
    <div class="stat-label">用户余额合计</div>
    <div class="stat-value">￥<?= money($balSum) ?></div>
    <div class="stat-sub">渠道 <?= $chCount ?> 个 · 模型 <?= $mCount ?> 个</div>
  </div>
</div>

<?php if ($chCount === 0 || $mCount === 0): ?>
<div class="alert alert-info">
  还没有可用模型。请先到 <a href="/admin/channels.php">API 渠道</a> 添加中转平台地址与密钥，
  再到 <a href="/admin/models.php">模型与定价</a> 添加模型并设置输入/输出价格，用户才能使用。
</div>
<?php endif; ?>

<div class="form-grid" style="align-items:start">
  <div class="card">
    <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
      <span>消费排行</span>
      <div class="range-tabs" style="display:inline-flex;gap:2px;background:var(--bg-3,#e8e8e8);border-radius:6px;padding:2px">
        <?php foreach (['day'=>'日','week'=>'周','month'=>'月','year'=>'年'] as $k => $v): ?>
          <button type="button" data-range="<?= $k ?>" class="range-tab rank-tab" style="padding:3px 12px;border-radius:4px;font-size:13px;border:none;cursor:pointer;<?= $range===$k?'background:var(--accent,#4f7cff);color:#fff;':'color:var(--text-2,#666);background:transparent;' ?>"><?= $v ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>用户</th><th>Tokens</th><th>消费</th></tr></thead>
        <tbody id="rank-body">
        <?php if (!$topUsers): ?>
          <tr><td colspan="3" class="empty">暂无数据（<?= $rangeLabel ?>）</td></tr>
        <?php else: foreach ($topUsers as $t): ?>
          <tr>
            <td><?= h($t['username']) ?></td>
            <td><?= fmt_int($t['t']) ?></td>
            <td>￥<?= money($t['c']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head">最近调用</div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>时间</th><th>用户</th><th>模型</th><th>费用</th></tr></thead>
        <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="4" class="empty">暂无数据</td></tr>
        <?php else: foreach ($recent as $r): ?>
          <tr>
            <td class="nowrap"><?= h(substr($r['created_at'], 5, 11)) ?></td>
            <td><?= h($r['username'] ?? '—') ?></td>
            <td><?= h($r['model_name']) ?></td>
            <td>￥<?= money($r['cost']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
(function(){
  var tabs = document.querySelectorAll('.rank-tab');
  var body = document.getElementById('rank-body');
  tabs.forEach(function(btn){
    btn.addEventListener('click', function(){
      var range = btn.getAttribute('data-range');
      /* 高亮当前标签 */
      tabs.forEach(function(t){
        t.style.background = 'transparent';
        t.style.color = 'var(--text-2,#666)';
      });
      btn.style.background = 'var(--accent,#4f7cff)';
      btn.style.color = '#fff';
      /* 请求数据 */
      body.innerHTML = '<tr><td colspan="3" class="empty" style="opacity:.5">加载中…</td></tr>';
      fetch('index.php?ajax=rank&range=' + range)
        .then(function(r){ return r.json(); })
        .then(function(data){
          if(!data.rows || data.rows.length === 0){
            body.innerHTML = '<tr><td colspan="3" class="empty">暂无数据（' + data.label + '）</td></tr>';
            return;
          }
          body.innerHTML = data.rows.map(function(r){
            return '<tr><td>' + r.username + '</td><td>' + r.tokens + '</td><td>￥' + r.cost + '</td></tr>';
          }).join('');
        })
        .catch(function(){
          body.innerHTML = '<tr><td colspan="3" class="empty" style="color:#f87171">加载失败，请重试</td></tr>';
        });
    });
  });
})();
</script>
<?php require __DIR__ . '/_foot.php'; ?>
