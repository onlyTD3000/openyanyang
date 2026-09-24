<?php
/**
 * 后台：聊天记录（会话列表）
 * 只读查看，支持按用户账号、日期、消息内容关键词筛选。
 */
$adminOn = 'chats';
$pageTitle = '聊天记录';
require __DIR__ . '/_head.php';

$kw   = trim($_GET['kw'] ?? '');       // 用户账号
$q    = trim($_GET['q'] ?? '');        // 消息内容关键词
$uid  = (int) ($_GET['uid'] ?? 0);     // 指定用户
$d1   = trim($_GET['d1'] ?? '');
$d2   = trim($_GET['d2'] ?? '');
$page = max(1, (int) ($_GET['p'] ?? 1));
$per  = 25;
$off  = ($page - 1) * $per;

$w = [];
$a = [];
if ($uid > 0)   { $w[] = 'c.user_id = ?';        $a[] = $uid; }
if ($kw !== '') { $w[] = 'u.username LIKE ?';    $a[] = '%' . $kw . '%'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) { $w[] = 'c.updated_at >= ?'; $a[] = $d1 . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) { $w[] = 'c.updated_at <= ?'; $a[] = $d2 . ' 23:59:59'; }
// 按消息内容搜索：命中任意一条消息即算该会话命中
if ($q !== '') {
    // hidden=1 是历史遗留的工具回执，不参与内容搜索
    $w[] = 'EXISTS (SELECT 1 FROM messages m WHERE m.conv_id = c.id AND m.hidden = 0 AND m.content LIKE ?)';
    $a[] = '%' . $q . '%';
}
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$total = (int) db_val(
    'SELECT COUNT(*) FROM conversations c LEFT JOIN users u ON u.id = c.user_id ' . $where, $a);

$rows = db_all(
    'SELECT c.id, c.user_id, c.title, c.msg_count, c.created_at, c.updated_at,
            u.username, u.status AS user_status,
            (SELECT COUNT(*) FROM messages m WHERE m.conv_id = c.id AND m.hidden = 0) AS real_cnt,
            (SELECT COALESCE(SUM(m.cost),0) FROM messages m WHERE m.conv_id = c.id) AS spend
       FROM conversations c
       LEFT JOIN users u ON u.id = c.user_id ' . $where .
    ' ORDER BY c.updated_at DESC LIMIT ' . $per . ' OFFSET ' . $off, $a);

$pages = max(1, (int) ceil($total / $per));
$qs = fn(array $ex = []) => http_build_query(array_merge(
    ['kw' => $kw, 'q' => $q, 'uid' => $uid ?: '', 'd1' => $d1, 'd2' => $d2], $ex));

// 审计：记录本次列表查看
audit_log((int) $me['id'], 'view_chat_list', $uid, 0,
    trim('筛选 kw=' . $kw . ' q=' . $q));

$focusUser = $uid > 0 ? db_one('SELECT username FROM users WHERE id = ?', [$uid]) : null;
?>
<div class="page-head">
  <h1 class="page-title">聊天记录</h1>
  <?php if ($focusUser): ?>
    <span class="hint">当前只看用户 <b><?= h($focusUser['username']) ?></b>
      <a href="/admin/chats.php">（查看全部）</a></span>
  <?php endif; ?>
</div>

<div class="alert alert-info mb-16">
  这里可以查看所有用户的对话内容，属于高权限操作。每次访问都会记入审计日志。
</div>

<div class="card mb-16">
  <div class="card-body">
    <form class="inline-form" method="get" style="flex-wrap:wrap">
      <?php if ($uid > 0): ?><input type="hidden" name="uid" value="<?= (int) $uid ?>"><?php endif; ?>
      <input class="input" style="width:150px" name="kw" value="<?= h($kw) ?>" placeholder="用户账号">
      <input class="input" style="width:200px" name="q" value="<?= h($q) ?>" placeholder="消息内容包含…">
      <input class="input" style="width:150px" type="date" name="d1" value="<?= h($d1) ?>">
      <input class="input" style="width:150px" type="date" name="d2" value="<?= h($d2) ?>">
      <button class="btn btn-primary" type="submit">筛选</button>
      <a class="btn" href="/admin/chats.php">重置</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>会话</th><th>用户</th><th>消息数</th><th>该会话消费</th>
                 <th>创建时间</th><th>最后活动</th><th>操作</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="empty">没有符合条件的会话</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td>
            <div><?= h($r['title'] !== '' ? $r['title'] : '未命名会话') ?></div>
            <div class="hint">#<?= (int) $r['id'] ?></div>
          </td>
          <td>
            <?php if ($r['username'] === null): ?>
              <span class="hint">用户已删除</span>
            <?php else: ?>
              <a href="?<?= h($qs(['uid' => (int) $r['user_id'], 'p' => 1])) ?>"><?= h($r['username']) ?></a>
              <?php if ((int) $r['user_status'] !== 1): ?>
                <span class="badge badge-off">禁用</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?= fmt_int($r['real_cnt']) ?></td>
          <td>￥<?= money($r['spend']) ?></td>
          <td class="nowrap"><?= h($r['created_at']) ?></td>
          <td class="nowrap"><?= h($r['updated_at']) ?></td>
          <td><a class="btn btn-sm" href="/admin/chat_view.php?id=<?= (int) $r['id'] ?>">查看对话</a></td>
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
