<?php
/**
 * 后台「服务器名单」：管理用户可登记的服务器地址黑白名单。
 *
 * 规则说明（与 inc/ip_rules.php 保持一致）：
 *   - 内网、回环、保留地址硬禁止，名单管不了，也解除不了。
 *   - 黑名单：命中的地址禁止登记与连接。
 *   - 白名单：仅用于解除黑名单的拦截，优先级高于黑名单。
 */
$adminOn   = 'ip_rules';
$pageTitle = '服务器名单';

// POST 要在 _head.php 之前处理完，否则重定向发不出去，刷新会重发表单。
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();
require_once __DIR__ . '/../inc/ip_rules.php';

ip_rules_ensure_table();

$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = $_POST['act'] ?? '';

    if ($act === 'add') {
        $kind    = ($_POST['kind'] ?? 'deny') === 'allow' ? 'allow' : 'deny';
        $pattern = strtolower(trim($_POST['pattern'] ?? ''));
        $note    = trim($_POST['note'] ?? '');
        $bad     = ip_rule_validate($pattern);

        if ($bad !== '') {
            $err = $bad;
        } elseif (mb_strlen($note) > 200) {
            $err = '备注过长（上限 200 字）';
        } else {
            $dup = db_val('SELECT id FROM ip_rules WHERE kind = ? AND pattern = ?', [$kind, $pattern]);
            if ($dup) {
                $err = '这条规则已存在，不用重复添加';
            } else {
                db_exec(
                    'INSERT INTO ip_rules (kind, pattern, note, created_by) VALUES (?, ?, ?, ?)',
                    [$kind, $pattern, $note, (int) $me['id']]
                );
                audit_log($me['id'], 'ip_rule_add', ($kind === 'allow' ? '加白' : '拉黑') . '：' . $pattern);
                $msg = ($kind === 'allow' ? '已加入白名单' : '已加入黑名单') . '：' . $pattern;
            }
        }
    } elseif ($act === 'toggle') {
        $id  = (int) ($_POST['id'] ?? 0);
        $row = db_one('SELECT * FROM ip_rules WHERE id = ?', [$id]);
        if ($row) {
            $to = $row['enabled'] ? 0 : 1;
            db_exec('UPDATE ip_rules SET enabled = ? WHERE id = ?', [$to, $id]);
            audit_log($me['id'], 'ip_rule_toggle',
                ($to ? '启用' : '停用') . '规则：' . $row['pattern']);
            $msg = ($to ? '已启用' : '已停用') . '：' . $row['pattern'];
        }
    } elseif ($act === 'del') {
        $id  = (int) ($_POST['id'] ?? 0);
        $row = db_one('SELECT * FROM ip_rules WHERE id = ?', [$id]);
        if ($row) {
            db_exec('DELETE FROM ip_rules WHERE id = ?', [$id]);
            audit_log($me['id'], 'ip_rule_del', '删除规则：' . $row['pattern']);
            $msg = '已删除：' . $row['pattern'];
        }
    }

    flash_set($err !== '' ? 'error' : 'ok', $err !== '' ? $err : $msg);
    redirect_self();
}

[$flash类型, $flash文本] = flash_get();
if ($flash类型 === 'error') {
    $err = $flash文本;
} elseif ($flash类型 === 'ok') {
    $msg = $flash文本;
}

require __DIR__ . '/_head.php';

$黑 = db_all('SELECT r.*, u.username FROM ip_rules r'
    . ' LEFT JOIN users u ON u.id = r.created_by'
    . ' WHERE r.kind = "deny" ORDER BY r.id DESC');
$白 = db_all('SELECT r.*, u.username FROM ip_rules r'
    . ' LEFT JOIN users u ON u.id = r.created_by'
    . ' WHERE r.kind = "allow" ORDER BY r.id DESC');
?>

<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="card-head">名单是怎么生效的</div>
  <div class="card-body rule-note">
    <p><b>内网地址永久禁止。</b>192.168.x、10.x、172.16-31.x、127.x 以及各类保留地址一律不允许登记，
      加白名单也解除不了。这是防止平台被当作跳板去访问内网的底线，不提供开关。</p>
    <p><b>黑名单</b>拦住指定地址，用户既不能登记也不能连接。</p>
    <p><b>白名单</b>只用来解除黑名单的拦截，优先级高于黑名单。
      比如拉黑了整个 203.0.113.0/24，又想放行其中一台，就把那台加白。</p>
    <p><b>平台自身 IP 不再自动禁止。</b>如需禁止用户连接本平台服务器，请自行加进黑名单。</p>
  </div>
</div>

<div class="card">
  <div class="card-head">添加规则</div>
  <div class="card-body">
    <form method="post" class="rule-form">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="add">
      <div class="rule-form-row">
        <label class="rule-field">
          <span>名单类型</span>
          <select name="kind">
            <option value="deny">黑名单（禁止连接）</option>
            <option value="allow">白名单（解除拉黑）</option>
          </select>
        </label>
        <label class="rule-field rule-field-wide">
          <span>地址</span>
          <input type="text" name="pattern" required maxlength="120"
                 placeholder="203.0.113.5 或 203.0.113.0/24 或 *.example.com">
        </label>
        <label class="rule-field rule-field-wide">
          <span>备注（可选，会显示给用户）</span>
          <input type="text" name="note" maxlength="200" placeholder="如：机房已下线">
        </label>
        <button class="btn btn-primary" type="submit">添加</button>
      </div>
      <p class="rule-hint">支持单个 IP、CIDR 网段（1.2.3.0/24）、域名（example.com）、通配域名（*.example.com）。
        填域名时会连它解析出的 IP 一起比对，用户拿域名指向被拉黑的 IP 也绕不过去。</p>
    </form>
  </div>
</div>

<?php
/** 渲染一张名单表格 */
function 名单表格(array $rows, string $kind, string $csrf): void
{
    $空话 = $kind === 'deny' ? '还没有黑名单规则' : '还没有白名单规则';
?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>地址</th><th>备注</th><th>状态</th>
          <th>添加人</th><th>添加时间</th><th style="width:130px">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="empty"><?= h($空话) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['enabled'] ? '' : 'is-off' ?>">
            <td><code><?= h($r['pattern']) ?></code></td>
            <td><?= $r['note'] !== '' ? h($r['note']) : '<span class="dim">—</span>' ?></td>
            <td>
              <?php if ($r['enabled']): ?>
                <span class="badge badge-ok">生效中</span>
              <?php else: ?>
                <span class="badge badge-off">已停用</span>
              <?php endif; ?>
            </td>
            <td><?= $r['username'] !== null ? h($r['username']) : '<span class="dim">—</span>' ?></td>
            <td class="dim"><?= h(substr((string) $r['created_at'], 0, 16)) ?></td>
            <td class="acts">
              <form method="post" class="inline-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="act" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $r['enabled'] ? '停用' : '启用' ?></button>
              </form>
              <form method="post" class="inline-form"
                    onsubmit="return confirm('确定删除规则 <?= h($r['pattern']) ?> ？');">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="act" value="del">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">删除</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php
}
?>

<div class="card">
  <div class="card-head">黑名单 <span class="head-count"><?= count($黑) ?> 条</span></div>
  <div class="card-body"><?php 名单表格($黑, 'deny', $csrf); ?></div>
</div>

<div class="card">
  <div class="card-head">白名单 <span class="head-count"><?= count($白) ?> 条</span></div>
  <div class="card-body"><?php 名单表格($白, 'allow', $csrf); ?></div>
</div>

<div class="card">
  <div class="card-head">测一下某个地址会不会被拦</div>
  <div class="card-body">
    <form method="get" class="rule-form" action="/admin/ip_rules.php#test">
      <div class="rule-form-row">
        <label class="rule-field rule-field-wide">
          <span>输入 IP 或域名</span>
          <input type="text" name="probe" maxlength="120"
                 value="<?= h($_GET['probe'] ?? '') ?>" placeholder="203.0.113.5">
        </label>
        <button class="btn" type="submit">检测</button>
      </div>
    </form>
    <?php
    $probe = trim((string) ($_GET['probe'] ?? ''));
    if ($probe !== ''):
        require_once __DIR__ . '/../inc/ssh_guard.php';
        $why = ssh_host_deny_reason($probe);
    ?>
      <div class="probe-result <?= $why === '' ? 'is-ok' : 'is-deny' ?>" id="test">
        <?php if ($why === ''): ?>
          <b>允许</b>　<?= h($probe) ?> 可以登记和连接。
        <?php else: ?>
          <b>拒绝</b>　<?= h($probe) ?> —— <?= h($why) ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
