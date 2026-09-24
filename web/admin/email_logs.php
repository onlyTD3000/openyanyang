<?php
$adminOn = 'email';
$pageTitle = '邮件日志';
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();

// 分页参数
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

// 筛选条件
$where   = '1=1';
$params  = [];

$search  = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $where .= ' AND (l.to_email LIKE ? OR l.subject LIKE ? OR l.reason LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like]);
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && in_array($statusFilter, ['ok', 'fail', 'pending'], true)) {
    $where .= ' AND l.status = ?';
    $params[] = $statusFilter;
}

$reasonFilter = trim((string) ($_GET['reason'] ?? ''));
if ($reasonFilter !== '') {
    $where .= ' AND l.reason LIKE ?';
    $params[] = '%' . $reasonFilter . '%';
}

$total = (int) db_val(
    "SELECT COUNT(*) FROM email_logs l WHERE $where",
    $params
);
$totalPages = max(1, (int) ceil($total / $perPage));

$logs = db_all(
    "SELECT l.*, u.username
     FROM email_logs l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE $where
     ORDER BY l.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// 统计
$stats = db_one(
    'SELECT COUNT(*) total, SUM(status="ok") ok, SUM(status="fail") fail, SUM(status="pending") pending
     FROM email_logs'
);

$csrf = csrf_token();
require __DIR__ . '/_head.php';

// 查询参数拼接
$qp = function(array $overrides = []) use ($search, $statusFilter, $reasonFilter) {
    $arr = array_merge([
        'search' => $search,
        'status' => $statusFilter,
        'reason' => $reasonFilter,
    ], $overrides);
    return http_build_query(array_filter($arr, fn($v) => $v !== ''));
};
?>
<div class="page-head">
  <h1 class="page-title">邮件日志</h1>
  <div class="page-actions">
    <a href="/admin/email_settings.php" class="btn btn-secondary">邮箱设置</a>
  </div>
</div>

<!-- 统计卡片 -->
<div class="stat-grid" style="margin-bottom: 16px;">
  <div class="stat">
    <div class="stat-label">总计</div>
    <div class="stat-value"><?= fmt_int((int) $stats['total']) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><span class="badge badge-ok">成功</span></div>
    <div class="stat-value"><?= fmt_int((int) $stats['ok']) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><span class="badge badge-err">失败</span></div>
    <div class="stat-value"><?= fmt_int((int) $stats['fail']) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><span class="badge" style="background:#e5e7eb;color:#6b7280">待发送</span></div>
    <div class="stat-value"><?= fmt_int((int) $stats['pending']) ?></div>
  </div>
</div>

<!-- 筛选栏 -->
<div class="card" style="margin-bottom: 16px;">
  <div class="card-body" style="padding: 12px 16px;">
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
      <label class="field" style="min-width:180px;margin:0">
        <span class="field-label">搜索（收件人/主题/原因）</span>
        <input class="input" type="text" name="search" value="<?= h($search) ?>" placeholder="输入关键词">
      </label>
      <label class="field" style="min-width:100px;margin:0">
        <span class="field-label">状态</span>
        <select class="input" name="status">
          <option value="">全部</option>
          <option value="ok" <?= $statusFilter === 'ok' ? 'selected' : '' ?>>成功</option>
          <option value="fail" <?= $statusFilter === 'fail' ? 'selected' : '' ?>>失败</option>
          <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>待发送</option>
        </select>
      </label>
      <label class="field" style="min-width:120px;margin:0">
        <span class="field-label">发送原因</span>
        <input class="input" type="text" name="reason" value="<?= h($reasonFilter) ?>" placeholder="注册验证/测试">
      </label>
      <button class="btn btn-primary" type="submit" style="height:38px">筛选</button>
      <?php if ($search || $statusFilter || $reasonFilter): ?>
        <a href="/admin/email_logs.php" class="btn btn-secondary" style="height:38px;line-height:38px">清除</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- 邮件列表 -->
<div class="card">
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th style="width:55px">ID</th>
          <th style="width:80px">状态</th>
          <th style="width:140px">时间</th>
          <th>收件人</th>
          <th>主题</th>
          <th>发送原因</th>
          <th>关联用户</th>
          <th>错误信息</th>
          <th style="width:60px">详情</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="9" class="empty">暂无邮件记录</td></tr>
        <?php else: foreach ($logs as $l): ?>
          <tr>
            <td><?= $l['id'] ?></td>
            <td>
              <?php if ($l['status'] === 'ok'): ?>
                <span class="badge badge-ok">成功</span>
              <?php elseif ($l['status'] === 'fail'): ?>
                <span class="badge badge-err">失败</span>
              <?php else: ?>
                <span class="badge" style="background:#e5e7eb;color:#6b7280">待发</span>
              <?php endif; ?>
            </td>
            <td class="nowrap"><?= h(substr($l['created_at'], 0, 16)) ?></td>
            <td><?= h($l['to_email']) ?></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="<?= h($l['subject']) ?>"><?= h($l['subject']) ?></td>
            <td><?= h($l['reason'] ?: '—') ?></td>
            <td><?= h($l['username'] ?? '—') ?></td>
            <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#ef4444"
                title="<?= h($l['error_msg'] ?? '') ?>"><?= h(mb_substr($l['error_msg'] ?? '', 0, 40)) ?></td>
            <td>
              <a href="?<?= $qp() ?>&detail=<?= $l['id'] ?>" class="btn btn-sm">查看</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div style="padding:12px 16px;display:flex;gap:8px;justify-content:center;border-top:1px solid #e5e7eb">
      <?php if ($page > 1): ?>
        <a class="btn btn-secondary" href="?<?= $qp(['page' => $page - 1]) ?>">上一页</a>
      <?php endif; ?>
      <span style="line-height:36px;color:#6b7280;font-size:14px">
        第 <?= $page ?> / <?= $totalPages ?> 页，共 <?= fmt_int($total) ?> 条
      </span>
      <?php if ($page < $totalPages): ?>
        <a class="btn btn-secondary" href="?<?= $qp(['page' => $page + 1]) ?>">下一页</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<!-- 邮件详情弹窗 -->
<?php
$detailId = (int) ($_GET['detail'] ?? 0);
if ($detailId > 0):
  $detail = db_one(
    'SELECT l.*, u.username FROM email_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ?',
    [$detailId]
  );
  if ($detail):
?>
<div class="card" style="margin-top:16px">
  <div class="card-head">邮件详情 #<?= $detail['id'] ?>
    <a href="?<?= $qp() ?>" style="float:right;font-size:13px;color:#6366f1">关闭</a>
  </div>
  <div class="card-body">
    <table class="tbl">
      <tbody>
        <tr><td style="width:100px;font-weight:600">状态</td>
          <td><?= $detail['status'] === 'ok' ? '<span class="badge badge-ok">成功</span>' :
              ($detail['status'] === 'fail' ? '<span class="badge badge-err">失败</span>' : '<span class="badge">待发送</span>') ?></td></tr>
        <tr><td>时间</td><td><?= h($detail['created_at']) ?></td></tr>
        <tr><td>收件人</td><td><?= h($detail['to_email']) ?></td></tr>
        <tr><td>主题</td><td><?= h($detail['subject']) ?></td></tr>
        <tr><td>发送原因</td><td><?= h($detail['reason'] ?: '—') ?></td></tr>
        <tr><td>关联用户</td><td><?= h($detail['username'] ?? '—') ?> (ID: <?= $detail['user_id'] ?: '—' ?>)</td></tr>
        <?php if ($detail['error_msg']): ?>
        <tr><td>错误信息</td><td style="color:#ef4444"><?= h($detail['error_msg']) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <div style="margin-top:16px">
      <div class="field-label" style="margin-bottom:8px">邮件内容（HTML）</div>
      <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb;max-height:600px;overflow:auto">
        <?= $detail['body'] ?>
      </div>
    </div>
  </div>
</div>
<?php endif; endif; ?>

<?php require __DIR__ . '/_foot.php'; ?>