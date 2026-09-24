<?php
$adminOn = 'aff';
$pageTitle = '推介回溯记录';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/aff.php';
$me = require_admin();
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 30;
$off = ($page - 1) * $per;
$total = (int) db_val('SELECT COUNT(*) FROM aff_backfill_logs');
// LEFT JOIN 取下级、上级、操作人的账号。用 LEFT 是因为用户可能已被删除，
// 删了也要能看到这条历史记录。
$logs = db_all(
    'SELECT l.*, d.username AS down_name, r.username AS ref_name, a.username AS admin_name
       FROM aff_backfill_logs l
       LEFT JOIN users d ON d.id = l.user_id
       LEFT JOIN users r ON r.id = l.referrer_id
       LEFT JOIN users a ON a.id = l.admin_id
     ORDER BY l.id DESC LIMIT ' . $per . ' OFFSET ' . $off
);
$pages = max(1, (int) ceil($total / $per));
$sum = db_one('SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) s, COALESCE(SUM(order_count),0) c FROM aff_backfill_logs WHERE action = \'bind\'');
require __DIR__ . '/_head.php';
?>
<div class="page-head">
  <h1 class="page-title">推介回溯记录</h1>
  <div class="spacer"></div>
  <a class="btn" href="/admin/aff.php">推介设置</a>
  <a class="btn" href="/admin/users.php">用户管理</a>
</div>
<div class="card">
  <div class="hint" style="margin-bottom:12px">
    后台手动绑定或解除推介关系的记录。绑定时按当时的返佣比例回溯下级已完成的充值订单，
    把返现一次性补进推介人余额；解绑只断关系，已发放的返现不追回。
  </div>
  <div class="bf-stats">
    <div class="bf-box"><b><?= (int) $sum['n'] ?></b><span>累计绑定次数</span></div>
    <div class="bf-box"><b><?= (int) $sum['c'] ?></b><span>回溯订单笔数</span></div>
    <div class="bf-box"><b><?= number_format((float) $sum['s'], 4, '.', '') ?></b><span>补返总金额(元)</span></div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>ID</th><th>操作</th><th>下级用户</th><th>推介人</th>
            <th>回溯笔数</th><th>补返金额(元)</th><th>比例</th><th>操作人</th><th>时间</th></tr>
      </thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr><td colspan="9" class="empty">暂无回溯记录</td></tr>
      <?php else: foreach ($logs as $l): ?>
        <tr>
          <td><?= (int) $l['id'] ?></td>
          <td><?php if ($l['action'] === 'bind'): ?><span class="bf-tag bf-ok">绑定</span>
              <?php else: ?><span class="bf-tag bf-warn">解绑</span><?php endif; ?></td>
          <td><?= h($l['down_name'] ?? '已删除') ?>（#<?= (int) $l['user_id'] ?>）</td>
          <td><?= h($l['ref_name'] ?? '已删除') ?>（#<?= (int) $l['referrer_id'] ?>）</td>
          <td><?= $l['action'] === 'bind' ? (int) $l['order_count'] : '—' ?></td>
          <td><?= $l['action'] === 'bind' ? number_format((float) $l['total_amount'], 4, '.', '') : '—' ?></td>
          <td><?= $l['action'] === 'bind' ? number_format((float) $l['rate'], 1, '.', '') . '%' : '—' ?></td>
          <td><?= h($l['admin_name'] ?? '已删除') ?></td>
          <td class="nowrap"><?= h($l['created_at']) ?></td>
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
<style>
  .bf-stats { display:flex; gap:12px; margin:14px 0; flex-wrap:wrap; }
  .bf-box { flex:1; min-width:120px; padding:14px 16px; background:#f9fafb;
            border:1px solid #e5e7eb; border-radius:8px; }
  .bf-box b { display:block; font-size:22px; font-weight:600; color:#111827; }
  .bf-box span { font-size:12px; color:#6b7280; }
  .bf-tag { display:inline-block; padding:2px 8px; border-radius:4px; font-size:12px; }
  .bf-ok { background:#dcfce7; color:#166534; }
  .bf-warn { background:#fef3c7; color:#92400e; }
</style>
<?php require __DIR__ . '/_foot.php'; ?>
