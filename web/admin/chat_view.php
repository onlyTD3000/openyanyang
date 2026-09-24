<?php
/**
 * 后台：单个会话的完整对话内容（只读）
 * 管理员可看任意用户的会话，进入即写审计日志。
 */
$adminOn= 'chats';
$pageTitle = '对话详情';
require __DIR__ . '/_head.php';

$convId  = (int) ($_GET['id']?? 0);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

if ($convId <= 0) {
    echo '<div class="alert alert-err">参数错误</div>';
    require __DIR__ . '/_foot.php';
    exit;
}

$conv = db_one(
    'SELECT c.*, u.username, u.status AS user_status, u.balance
       FROM conversations c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ? LIMIT 1', [$convId]);

if (!$conv) {
    echo '<div class="alert alert-err">会话不存在或已被删除</div>';
    require __DIR__ . '/_foot.php';
    exit;
}

audit_log((int) $me['id'], 'view_conv', (int) $conv['user_id'], $convId,
    '会话标题：' . mb_substr((string) $conv['title'], 0, 60));

// 分页按「逻辑组」切，不按消息行数：一组连续助手消息算一段。
// 否则一组 20 条工具调用就能占满整页，而组内默认只展开第一条，
// 页面上看起来只有一条消息。
// 这里只取 id 和 role 两列做分组计算，不含 content，开销很小。
$idx   = db_all('SELECT id, role FROM messages WHERE conv_id = ? AND hidden = 0 ORDER BY id ASC', [$convId]);
$total = count($idx);   // 消息总条数，页头「合计」仍按条显示

// 连续 assistant 合并为一段，得到每段包含的消息 id
$segments = [];
$i = 0;
$n = $total;
while ($i < $n) {
    if ($idx[$i]['role'] === 'assistant') {
        $run = [(int) $idx[$i]['id']];
        while ($i + 1 < $n && $idx[$i + 1]['role'] === 'assistant') {
            $i++;
            $run[] = (int) $idx[$i]['id'];
        }
        $segments[] = $run;
    } else {
        $segments[] = [(int) $idx[$i]['id']];
    }
    $i++;
}

$totalSeg  = count($segments);
$totalPage = max(1, (int) ceil($totalSeg / $perPage));
$page      = min($page, $totalPage);

$pageSegs = array_slice($segments, ($page - 1) * $perPage, $perPage);
$pageIds  = $pageSegs ? array_merge(...$pageSegs) : [];

// 按 id 精确取本页这几段的消息。切片保证了一段不会跨页，
// 所以后面 group_msgs() 重新分组的结果和这里算出的段一致。
$msgs = [];
if ($pageIds) {
    $in   = implode(',', array_fill(0, count($pageIds), '?'));
    $msgs = db_all(
        'SELECT id, role, content, images, tokens_in, tokens_out, cost, created_at
           FROM messages WHERE id IN (' . $in . ') ORDER BY id ASC', $pageIds);
}

$agg = db_one(
    'SELECT COALESCE(SUM(tokens_in),0) ti, COALESCE(SUM(tokens_out),0) tox,COALESCE(SUM(cost),0) c FROM messages WHERE conv_id = ?', [$convId]);

/** 把本页消息按角色连续分组：连续的assistant 消息合为一组 */
function group_msgs(array $msgs): array
{
    $groups = [];
    $i = 0;
    $n = count($msgs);
    while ($i < $n) {
        $m = $msgs[$i];
        if ($m['role'] === 'assistant') {
            $run = [$m];
            while ($i + 1 < $n && $msgs[$i + 1]['role'] === 'assistant') {
                $i++;
                $run[] = $msgs[$i];
            }
            $groups[] = ['type' => 'ai_run', 'msgs' => $run];
        } else {
            $groups[] = ['type' => 'single', 'msg' => $m];
        }
        $i++;
    }
    return $groups;
}

$groups = group_msgs($msgs);

/** 取图片行*/
function admin_msg_images(string $json): array
{
    $json = trim($json);
    if ($json === '') { return []; }
    $ids = json_decode($json, true);
    if (!is_array($ids) || !$ids) { return []; }
    $ids = array_slice(array_filter(array_map('intval', $ids), fn($v) => $v > 0), 0, 8);
    if (!$ids) { return []; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return db_all("SELECT id, path, width, height, size FROM uploads WHERE id IN ($in)", $ids);
}

/** 分页链接 */
function page_url(int $p, int $convId): string
{
    return '/admin/chat_view.php?id=' . $convId . '&page=' . $p;
}

/**
 * 生成分页按钮序列，含省略号占位符（null）。
 * 规则：始终显示第1页、末页、当前页前后各2页。
 */
function pager_seq(int $cur, int $total): array
{
    $show = [];
    $show[] = 1;
    for ($p = max(2, $cur - 2); $p <= min($total - 1, $cur + 2); $p++) {
        $show[] = $p;
    }
    if ($total > 1) {
        $show[] = $total;
    }
    $show = array_unique($show);
    sort($show);

    // 在不连续的位置插入 null 作省略号
    $result = [];
    $prev= 0;
    foreach ($show as $p) {
        if ($prev && $p - $prev > 1) {
            $result[] = null;
        }
        $result[] = $p;
        $prev = $p;
    }
    return $result;
}
?>
<div class="page-head">
  <h1 class="page-title">对话详情</h1>
  <div><a class="btn btn-sm" href="/admin/chats.php">返回列表</a>
    <?php if ($conv['username'] !== null): ?>
      <a class="btn btn-sm" href="/admin/chats.php?uid=<?= (int) $conv['user_id'] ?>">该用户全部会话</a>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-16">
  <div class="card-body">
    <div class="kv">
      <span class="k">会话</span>
      <span class="v"><?= h($conv['title'] !== '' ? $conv['title'] : '未命名会话') ?>
        <span class="hint">#<?= (int) $conv['id'] ?></span></span>
    </div>
    <div class="kv">
      <span class="k">所属用户</span>
      <span class="v"><?php if ($conv['username'] === null): ?>
          <span class="hint">用户已删除（user_id=<?= (int) $conv['user_id'] ?>）</span>
        <?php else: ?>
          <?= h($conv['username']) ?><span class="hint">ID <?= (int) $conv['user_id'] ?>，余额 ￥<?= money($conv['balance']) ?></span>
          <?php if ((int) $conv['user_status'] !== 1): ?><span class="badge badge-off">已禁用</span><?php endif; ?>
        <?php endif; ?>
      </span>
    </div>
    <div class="kv"><span class="k">创建 / 最后活动</span>
      <span class="v"><?= h($conv['created_at']) ?> / <?= h($conv['updated_at']) ?></span></div>
    <div class="kv"><span class="k">合计</span>
      <span class="v"><?= $total ?> 条消息，输入 <?= fmt_int($agg['ti']) ?> tokens，
        输出 <?= fmt_int($agg['tox']) ?> tokens，消费 ￥<?= money($agg['c']) ?></span></div>
  </div>
</div>

<?php
// 分页条（顶部 + 底部复用同一个宏）
function render_pager(int $page, int $totalPage, int $convId, int $totalSeg, int $perPage): void { ?>
<?php if ($totalPage > 1): ?>
<div class="cv-pager">
  <?php if ($page > 1): ?>
    <a class="btn btn-sm" href="<?= page_url(1, $convId) ?>">首页</a>
    <a class="btn btn-sm" href="<?= page_url($page - 1, $convId) ?>">‹ 上一页</a>
  <?php endif; ?>

  <?php foreach (pager_seq($page, $totalPage) as $p): ?>
    <?php if ($p === null): ?>
      <span class="cv-pager-ellipsis">…</span>
    <?php elseif ($p === $page): ?>
      <span class="btn btn-sm cv-pager-cur"><?= $p ?></span>
    <?php else: ?>
      <a class="btn btn-sm" href="<?= page_url($p, $convId) ?>"><?= $p ?></a>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if ($page < $totalPage): ?>
    <a class="btn btn-sm" href="<?= page_url($page + 1, $convId) ?>">下一页 ›</a>
    <a class="btn btn-sm" href="<?= page_url($totalPage, $convId) ?>">末页</a>
  <?php endif; ?>

  <form class="cv-jump" method="get" action="/admin/chat_view.php">
    <input type="hidden" name="id" value="<?= $convId ?>">
    <input class="cv-jump-in" type="number" name="page" min="1" max="<?= $totalPage ?>"
           placeholder="页码" aria-label="跳转到页码">
    <button class="btn btn-sm" type="submit">跳转</button>
    <span class="hint">共 <?= $totalPage ?> 页 · 第 <?= $totalSeg > 0 ? ($page - 1) * $perPage + 1 : 0 ?>–<?= min($page * $perPage, $totalSeg) ?> 段 / 共 <?= $totalSeg ?> 段</span>
  </form>
</div>
<?php endif; }
?>

<div class="card">
  <div class="card-body">
    <?php if (!$msgs): ?>
      <div class="empty">这个会话还没有消息</div>
    <?php else:
      static $msgIdx = 0;

      //渲染单条消息的内部内容（head + body），供单条和组内复用
      function render_msg_inner(array $m, int &$msgIdx): void
      {
          $isUser = $m['role'] === 'user';
          $isAi   = $m['role'] === 'assistant';
          $imgs   = admin_msg_images((string) $m['images']);
          $content = (string) $m['content'];
          // 任何角色的超长内容都折叠（此前只折叠助手消息，用户的长提问会整篇铺开）
          $needFold = mb_strlen($content) > 300;
          $preview  = $needFold ? mb_substr($content, 0, 300) : $content;
          $msgIdx++;
          $foldId ='msg-full-' . $msgIdx;
          ?>
          <div class="am-head">
            <span class="am-role"><?= $isUser ? '用户' : ($isAi ? '助手' : '系统') ?></span>
            <span class="hint"><?= h($m['created_at']) ?>
              <?php if ((int) $m['tokens_in'] || (int) $m['tokens_out']): ?>
                · 入<?= fmt_int($m['tokens_in']) ?> / 出 <?= fmt_int($m['tokens_out']) ?>· ￥<?= money($m['cost']) ?>
              <?php endif; ?>
            </span>
          </div>
          <?php if ($imgs): ?>
            <div class="am-imgs">
              <?php foreach ($imgs as $im): ?>
                <?php $cap = (int) $im['width'] . '×' . (int) $im['height']; ?>
                <a href="/api/img.php?id=<?= (int) $im['id'] ?>" target="_blank" rel="noopener"
                   title="图片 #<?= (int) $im['id'] ?>（<?= h($cap) ?>）">
                  <img src="/api/img.php?id=<?= (int) $im['id'] ?>"alt="图片 <?= h($cap) ?>" loading="lazy">
                </a>
              <?php endforeach; ?>
            </div>
          <?php elseif (trim((string) $m['images']) !== ''): ?>
            <div class="hint">（图片已被删除）</div>
          <?php endif; ?>
          <?php if ($needFold): ?>
            <div class="am-body am-fold" id="<?= $foldId ?>-pre"><?= nl2br(h($preview)) ?><span class="am-ellipsis">…</span></div>
            <div class="am-body" id="<?= $foldId ?>-full" hidden><?= nl2br(h($content)) ?></div>
            <div class="am-fold-bar">
              <button class="btn btn-sm cv-expand-btn" type="button"
                      data-pre="<?= $foldId ?>-pre" data-full="<?= $foldId ?>-full">
                查看完整内容（<?= mb_strlen($content) ?> 字）
              </button>
            </div>
          <?php else: ?>
            <div class="am-body"><?= nl2br(h($content)) ?></div>
          <?php endif; ?>
      <?php }

      foreach ($groups as $g):
        if ($g['type'] === 'single'):
          $m = $g['msg'];
          $isUser = $m['role'] === 'user'; ?>
          <div class="am-msg <?= $isUser ? 'am-user' : 'am-ai' ?>">
            <?php render_msg_inner($m, $msgIdx); ?>
          </div>

        <?php else:
          // 连续助手消息组
          $run= $g['msgs'];
          $cnt    = count($run);
          $msgIdx++;
          $groupId = 'ai-group-' . $msgIdx;
          if ($cnt === 1):
            // 只有一条，直接渲染
            ?>
            <div class="am-msg am-ai">
              <?php render_msg_inner($run[0], $msgIdx); ?>
            </div>
          <?php else: ?>
            <div class="am-ai-group">
              <div class="am-ai-group-hd">
                <span class="am-role" style="background:var(--c-muted)">助手</span>
                <span class="hint"><?= $cnt ?> 条连续消息 · 最早<?= h($run[0]['created_at']) ?></span><button class="btn btn-sm cv-group-btn" type="button"
                        data-group="<?= $groupId ?>">
                  展开全部 <?= $cnt ?> 条
                </button>
              </div>
              <?php foreach ($run as $ri => $rm): ?>
                <div class="am-msg am-ai<?= $ri > 0 ? ' am-group-hidden' : '' ?>"
                     <?= $ri > 0 ? 'data-group="' . $groupId . '" hidden' : '' ?>>
                  <?php render_msg_inner($rm, $msgIdx); ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif;
        endif;
      endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php render_pager($page, $totalPage, $convId, $totalSeg, $perPage); ?>

<script>
// 单条消息内容展开/收起
document.querySelectorAll('.cv-expand-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var pre  = document.getElementById(btn.dataset.pre);
    var full = document.getElementById(btn.dataset.full);
    var expanding = full.hidden;
    pre.hidden  = expanding;
    full.hidden = !expanding;
    var num = btn.textContent.match(/\d+/);
    btn.textContent = expanding ? '收起' : ('查看完整内容（' + (num ? num[0] : '') + ' 字）');
  });
});

// 连续助手消息组展开/收起
document.querySelectorAll('.cv-group-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var container = btn.closest('.am-ai-group');
    if (!container) return;
    // 只查本组内有data-group 属性的消息（即第2条起隐藏的那些）
    var items = container.querySelectorAll('.am-msg[data-group]');
    if (items.length === 0) return;
    var expanding = items[0].hasAttribute('hidden');
    items.forEach(function (el) {
      if (expanding) {
        el.removeAttribute('hidden');
      } else {
        el.setAttribute('hidden', '');
      }
    });
    var m = btn.textContent.match(/\d+/);
    btn.textContent = expanding
      ? ('收起（' + (m ? m[0] : '') + ' 条）')
      : ('展开全部 ' + (m ? m[0] : '') + ' 条');
  });
});
</script>

<?php require __DIR__ . '/_foot.php'; ?>