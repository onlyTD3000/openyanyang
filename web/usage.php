<?php
require_once __DIR__ . '/inc/helpers.php';
$me = require_login();
$navOn = 'usage';

$page = max(1, (int) ($_GET['p'] ?? 1));
$per  = 20;
$off  = ($page - 1) * $per;

$total = (int) db_val('SELECT COUNT(*) FROM usage_logs WHERE user_id = ?', [$me['id']]);
$logs  = db_all('SELECT * FROM usage_logs WHERE user_id = ?
                  ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . $off, [$me['id']]);
$pages = max(1, (int) ceil($total / $per));

$sum = db_one('SELECT COALESCE(SUM(tokens_in),0) ti, COALESCE(SUM(tokens_out),0) to_,
                      COALESCE(SUM(cost),0) c, COUNT(*) n
                 FROM usage_logs WHERE user_id = ?', [$me['id']]);
$today = db_one('SELECT COALESCE(SUM(cost),0) c, COUNT(*) n FROM usage_logs
                  WHERE user_id = ? AND created_at >= CURDATE()', [$me['id']]);
$quotaLeft = (int) $me['token_quota'] > 0
    ? max(0, (int) $me['token_quota'] - (int) $me['used_tokens']) : null;
$siteName = app_name();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var ls=localStorage.getItem('theme');if(ls==='dark'||(!ls&&<?= (int)($me['dark_mode'] ?? 0) ?>))document.documentElement.classList.add('dark');})();</script>
<title>我的用量 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>
<div class="app">
<?php require __DIR__ . '/inc/topbar.php'; ?>
<div class="page">
  <div class="page-head"><h1 class="page-title">我的用量</h1></div>

  <div class="stat-grid">
    <div class="stat">
      <div class="stat-label">账户余额</div>
      <div class="stat-value">￥<?= money($me['balance']) ?></div>
      <div class="stat-sub">累计消费 ￥<?= money($me['total_cost']) ?></div>
    </div>
    <div class="stat">
      <div class="stat-label">Token 配额</div>
      <div class="stat-value"><?= $quotaLeft === null ? '不限' : fmt_int($quotaLeft) ?></div>
      <div class="stat-sub">已用 <?= fmt_int($me['used_tokens']) ?> tokens</div>
    </div>
    <div class="stat">
      <div class="stat-label">今日消费</div>
      <div class="stat-value">￥<?= money($today['c']) ?></div>
      <div class="stat-sub">今日 <?= fmt_int($today['n']) ?> 次调用</div>
    </div>
    <div class="stat">
      <div class="stat-label">累计 Tokens</div>
      <div class="stat-value"><?= fmt_int((int) $sum['ti'] + (int) $sum['to_']) ?></div>
      <div class="stat-sub"><?= fmt_int($sum['n']) ?> 次调用</div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">调用明细</div>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>时间</th><th>模型</th><th>输入</th><th>缓存</th><th>输出</th><th>费用</th><th>耗时/速率</th><th>计量</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="8" class="empty">暂无调用记录</td></tr>
        <?php else: foreach ($logs as $l):
          $纯输入 = max(0, (int) $l['tokens_in'] - (int) $l['tokens_cache'] - (int) $l['tokens_cache_create']);
          $耗时ms = (int) $l['latency_ms']; ?>
          <tr>
            <td class="nowrap"><?= h($l['created_at']) ?></td>
            <td><?= h($l['model_name']) ?></td>
            <td><?= fmt_int($纯输入) ?></td>
            <td>↑<?= fmt_int((int) $l['tokens_cache_create']) ?><br>↓<?= fmt_int((int) $l['tokens_cache']) ?></td>
            <td><?= fmt_int($l['tokens_out']) ?></td>
            <td>￥<?= money($l['cost']) ?></td>
            <td class="nowrap">
              <?= number_format($耗时ms / 1000, 1) ?>s
              <div style="font-size:11px;color:#888;margin-top:2px">流·<?= number_format($耗时ms > 0 ? $l['tokens_out'] / ($耗时ms / 1000) : 0, 1) ?> t/s</div>
            </td>
            <td><?= ((int) $l['is_estimated'])
                    ? '<span class="badge badge-off">估算</span>'
                    : '<span class="badge badge-ok">精确</span>' ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
        <?php if ($i === $page): ?><span class="on"><?= $i ?></span>
        <?php else: ?><a href="?p=<?= $i ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
</div>
</body>
</html>
