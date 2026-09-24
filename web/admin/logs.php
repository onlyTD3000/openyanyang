<?php
$adminOn = 'logs';
$pageTitle = '调用日志';
require __DIR__ . '/_head.php';

$kw    = trim($_GET['kw'] ?? '');
$model = trim($_GET['model'] ?? '');
$st    = trim($_GET['st'] ?? '');
$d1    = trim($_GET['d1'] ?? '');
$d2    = trim($_GET['d2'] ?? '');
$page  = max(1, (int) ($_GET['p'] ?? 1));
$per   = 30;
$off   = ($page - 1) * $per;

$w = [];
$a = [];
if ($kw !== '')    { $w[] = 'u.username LIKE ?';  $a[] = '%' . $kw . '%'; }
if ($model !== '') { $w[] = 'l.model_name = ?';   $a[] = $model; }
if ($st === 'ok')  { $w[] = "l.status = 'ok'"; }
if ($st === 'err') { $w[] = "l.status <> 'ok'"; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) { $w[] = 'l.created_at >= ?'; $a[] = $d1 . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) { $w[] = 'l.created_at <= ?'; $a[] = $d2 . ' 23:59:59'; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$total = (int) db_val('SELECT COUNT(*) FROM usage_logs l LEFT JOIN users u ON u.id = l.user_id ' . $where, $a);
$agg = db_one('SELECT COALESCE(SUM(l.cost),0) c,
                     COALESCE(SUM(IF(l.tokens_in >= l.tokens_cache + l.tokens_cache_create, l.tokens_in - l.tokens_cache - l.tokens_cache_create, 0)),0) ti,
                     COALESCE(SUM(l.tokens_out),0) tox,
                     COALESCE(SUM(l.tokens_cache),0) tc,
                     COALESCE(SUM(l.tokens_cache_create),0) tcc
                 FROM usage_logs l LEFT JOIN users u ON u.id = l.user_id ' . $where, $a);
$rows = db_all('SELECT l.*, u.username, COALESCE((SELECT m.display_name FROM models m WHERE m.model_name = l.model_name AND m.channel_id = l.channel_id LIMIT 1), (SELECT m.display_name FROM models m WHERE m.model_name = l.model_name LIMIT 1), \'\') AS model_display_name FROM usage_logs l LEFT JOIN users u ON u.id = l.user_id '
    . $where . ' ORDER BY l.id DESC LIMIT ' . $per . ' OFFSET ' . $off, $a);
$models = db_all('SELECT DISTINCT model_name, display_name FROM models ORDER BY model_name');
$pages = max(1, (int) ceil($total / $per));
$qs = fn(array $ex = []) => http_build_query(array_merge(
    ['kw' => $kw, 'model' => $model, 'st' => $st, 'd1' => $d1, 'd2' => $d2], $ex));
?>
<div class="page-head"><h1 class="page-title">调用日志</h1></div>

<div class="card mb-16">
  <div class="card-body">
    <form class="inline-form" method="get" style="flex-wrap:wrap">
      <input class="input" style="width:150px" name="kw" value="<?= h($kw) ?>" placeholder="用户账号">
      <select class="select" style="width:180px" name="model">
        <option value="">全部模型</option>
        <?php foreach ($models as $m): ?>
          <option value="<?= h($m['model_name']) ?>" <?= $model === $m['model_name'] ? 'selected' : '' ?>>
            <?= h($m['display_name'] ?: $m['model_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select class="select" style="width:130px" name="st">
        <option value="">全部状态</option>
        <option value="ok"  <?= $st === 'ok' ? 'selected' : '' ?>>成功</option>
        <option value="err" <?= $st === 'err' ? 'selected' : '' ?>>失败</option>
      </select>
      <input class="input" style="width:150px" type="date" name="d1" value="<?= h($d1) ?>">
      <input class="input" style="width:150px" type="date" name="d2" value="<?= h($d2) ?>">
      <button class="btn btn-primary" type="submit">筛选</button>
      <a class="btn" href="/admin/logs.php">重置</a>
    </form>
  </div>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat-label">记录数</div><div class="stat-value"><?= fmt_int($total) ?></div></div>
  <div class="stat"><div class="stat-label">输入 tokens</div><div class="stat-value"><?= fmt_int($agg['ti']) ?></div></div>
  <div class="stat"><div class="stat-label">↑缓存 tokens</div><div class="stat-value"><?= fmt_int($agg['tcc']) ?></div></div>
  <div class="stat"><div class="stat-label">↓缓存 tokens</div><div class="stat-value"><?= fmt_int($agg['tc']) ?></div></div>
  <div class="stat"><div class="stat-label">输出 tokens</div><div class="stat-value"><?= fmt_int($agg['tox']) ?></div></div>
  <div class="stat"><div class="stat-label">合计消费</div><div class="stat-value">￥<?= money($agg['c']) ?></div></div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>时间</th><th>用户</th><th>来源</th><th>模型</th><th>显示名</th><th>输入</th>
                 <th>缓存</th><th>输出</th>
                 <th>费用</th><th>TTFT/端到端/速率</th><th>状态</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="11" class="empty">没有符合条件的记录</td></tr>
      <?php else: foreach ($rows as $r):
        $纯输入 = max(0, (int) $r['tokens_in'] - (int) $r['tokens_cache'] - (int) $r['tokens_cache_create']); ?>
        <tr>
          <td class="nowrap"><?= h($r['created_at']) ?></td>
          <td><?= h($r['username'] ?? '已删除') ?><?php if (!empty($r['user_id'])): ?><br><small style="color:#888;font-size:11px">ID: <?= (int) $r['user_id'] ?></small><?php endif; ?></td>
          <td class="nowrap"><?php
            $来源 = trim((string) ($r['client_type'] ?? ''));
            if (!empty($r['sk_card_id']) && (int)$r['sk_card_id'] > 0) echo '<span style="color:#f59e0b">🔑 SK卡密</span>';
            elseif ($来源 === 'android') echo '<span style="color:#10b981">📱 安卓</span>';
            elseif ($来源 === 'desktop') echo '<span style="color:#3b82f6">💻 客户端</span>';
            elseif ($来源 === 'web') echo '<span style="color:#8b5cf6">🌐 网页</span>';
            else echo '<span style="color:#9ca3af">—</span>';
          ?></td>
          <td><?= h($r['model_name']) ?><?= (int) $r['is_estimated'] === 1 ? '<span class="badge badge-off">估算</span>' : '' ?></td>
          <td><?= h($r['model_display_name'] ?: '—') ?></td>
          <td><?= fmt_int($纯输入) ?></td>
          <td>↑<?= fmt_int((int)$r['tokens_cache_create']) ?><br>↓<?= fmt_int((int)$r['tokens_cache']) ?></td>
          <td><?= fmt_int($r['tokens_out']) ?></td>
          <td>￥<?= money($r['cost']) ?></td>
          <td class="nowrap">
            <?php
              $耗时ms = (int) $r['latency_ms'];
              $ttftMs = isset($r['ttft_ms']) && $r['ttft_ms'] !== null && $r['ttft_ms'] !== '' ? (int) $r['ttft_ms'] : null;
              if ($ttftMs === null) {
                  $ttft文本 = '—';
                  $ttft色   = '#888';
              } elseif ($ttftMs <= 3000) {
                  $ttft文本 = number_format($ttftMs / 1000, 1) . 's';
                  $ttft色   = '#15803d';
              } elseif ($ttftMs <= 10000) {
                  $ttft文本 = number_format($ttftMs / 1000, 1) . 's';
                  $ttft色   = '#b45309';
              } else {
                  $ttft文本 = number_format($ttftMs / 1000, 1) . 's';
                  $ttft色   = '#dc3545';
              }
            ?>
            <span style="color:<?= $ttft色 ?>;font-weight:600"><?= $ttft文本 ?></span>
            / <?= number_format($耗时ms / 1000, 1) ?>s
            <div style="font-size:11px;color:#888;margin-top:2px">流·<?= number_format($耗时ms > 0 ? $r['tokens_out'] / ($耗时ms / 1000) : 0, 1) ?> t/s</div>
          </td>
          <td><?php if ($r['status'] === 'ok'): ?>
                <span class="badge badge-ok">成功</span>
              <?php else: ?>
                <span class="badge badge-err" title="<?= h($r['error_msg']) ?>">失败</span>
                <div class="hint"><?= h(mb_substr((string) $r['error_msg'], 0, 40)) ?></div>
              <?php endif; ?></td>
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