<?php
/**
 * 后台：审计日志。记录管理员查看用户对话的行为，便于事后追溯。
 */
$adminOn = 'audit';
$pageTitle = '审计日志';
require __DIR__ . '/_head.php';

$kw   = trim($_GET['kw'] ?? '');   // 管理员账号
$tu   = trim($_GET['tu'] ?? '');   // 被查看的用户账号
$page = max(1, (int) ($_GET['p'] ?? 1));
$per  = 40;
$off  = ($page - 1) * $per;

$w = [];
$a = [];
if ($kw !== '') { $w[] = 'ua.username LIKE ?'; $a[] = '%' . $kw . '%'; }
if ($tu !== '') { $w[] = 'ut.username LIKE ?'; $a[] = '%' . $tu . '%'; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$sqlFrom = 'FROM audit_logs l
              LEFT JOIN users ua ON ua.id = l.admin_id
              LEFT JOIN users ut ON ut.id = l.target_user_id ' . $where;

$total = (int) db_val('SELECT COUNT(*) ' . $sqlFrom, $a);
$rows = db_all('SELECT l.*, ua.username AS admin_name, ut.username AS target_name '
    . $sqlFrom . ' ORDER BY l.id DESC LIMIT ' . $per . ' OFFSET ' . $off, $a);

$pages = max(1, (int) ceil($total / $per));
$qs = fn(array $ex = []) => http_build_query(array_merge(['kw' => $kw, 'tu' => $tu], $ex));

$actionText = [
    'view_conv'      => '查看对话详情',
    'view_chat_list' => '浏览聊天记录列表',
];
?>
<div class="page-head"><h1 class="page-title">审计日志</h1>
  <span class="hint">共 <?= fmt_int($total) ?> 条</span></div>

<div class="card mb-16">
  <div class="card-body">
    <form class="inline-form" method="get" style="flex-wrap:wrap">
      <input class="input" style="width:170px" name="kw" value="<?= h($kw) ?>" placeholder="操作管理员账号">
      <input class="input" style="width:170px" name="tu" value="<?= h($tu) ?>" placeholder="被查看用户账号">
      <button class="btn btn-primary" type="submit">筛选</button>
      <a class="btn" href="/admin/audit.php">重置</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>时间</th><th>操作管理员</th><th>行为</th>
                 <th>涉及用户</th><th>会话</th><th>来源 IP</th><th>备注</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="empty">暂无审计记录</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= h($r['created_at']) ?></td>
          <td><?= h($r['admin_name'] ?? ('ID ' . (int) $r['admin_id'])) ?></td>
          <td><?= h($actionText[$r['action']] ?? $r['action']) ?></td>
          <td><?= (int) $r['target_user_id'] === 0
                ? '<span class="hint">全部</span>'
                : h($r['target_name'] ?? ('ID ' . (int) $r['target_user_id'])) ?></td>
          <td><?php if ((int) $r['target_conv_id'] > 0): ?>
                <a href="/admin/chat_view.php?id=<?= (int) $r['target_conv_id'] ?>">#<?= (int) $r['target_conv_id'] ?></a>
              <?php else: ?><span class="hint">—</span><?php endif; ?></td>
          <td class="nowrap"><?= h($r['ip']) ?></td>
          <td class="hint"><?= h(mb_substr((string) $r['note'], 0, 60)) ?></td>
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
