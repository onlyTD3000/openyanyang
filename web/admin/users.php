<?php
$adminOn = 'users';
$pageTitle = '用户管理';

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/tool_results.php';
require_once __DIR__ . '/../inc/aff.php';
$me = require_admin();

// 弹窗里搜索候选推介人：走 AJAX，返回 JSON。
// 放在 require_admin() 之后，所以已经是管理员身份。
if (($_GET['api'] ?? '') === 'search_referrer') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $forUid = (int) ($_GET['for'] ?? 0);
    if ($q === '') {
        echo json_encode(['ok' => true, 'list' => []]);
        exit;
    }
    // 三种输入都支持：纯数字按 ID 精确匹配，其余按账号和邮箱模糊匹配。
    // 排除本人（不能自己推自己）和已被禁用的账号（禁用的上级拿不到返现）。
    $like = '%' . $q . '%';
    $rows = db_all(
        'SELECT id, username, email, status FROM users
          WHERE id <> ? AND status = 1
            AND (username LIKE ? OR email LIKE ? OR id = ?)
          ORDER BY (id = ?) DESC, id ASC LIMIT 20',
        [$forUid, $like, $like, ctype_digit($q) ? (int) $q : 0, ctype_digit($q) ? (int) $q : 0]
    );
    $list = [];
    foreach ($rows as $r) {
        $list[] = [
            'id' => (int) $r['id'],
            'username' => (string) $r['username'],
            'email' => (string) ($r['email'] ?? ''),
            'invited' => (int) db_val('SELECT COUNT(*) FROM users WHERE referrer_id = ?', [(int) $r['id']]),
        ];
    }
    echo json_encode(['ok' => true, 'list' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = $_POST['act'] ?? '';
    $uid = (int) ($_POST['uid'] ?? 0);
    $target = $uid ? db_one('SELECT * FROM users WHERE id = ?', [$uid]) : null;

    if ($act === 'create') {
        $un = trim($_POST['username'] ?? '');
        $pw = (string) ($_POST['password'] ?? '');
        $bal = max(0, (float) ($_POST['balance'] ?? 0));
        $quota = max(0, (int) ($_POST['token_quota'] ?? 0));
        if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fff}]{2,50}$/u', $un)) {
            $err = '账号格式不正确';
        } elseif (mb_strlen($pw) < 6) {
            $err = '密码至少 6 位';
        } elseif (db_one('SELECT id FROM users WHERE username = ?', [$un])) {
            $err = '账号已存在';
        } else {
            $nid = db_insert('INSERT INTO users (username, email, password_hash, role, status, balance, token_quota, remark, created_at)
                              VALUES (?,?,?,?,1,?,?,?,NOW())',
                [$un, trim($_POST['email'] ?? ''), password_hash($pw, PASSWORD_DEFAULT),
                 ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
                 number_format($bal, 6, '.', ''), $quota, trim($_POST['remark'] ?? '')]);
            if ($bal > 0) {
                db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, admin_id, created_at)
                         VALUES (?,?,?,?,?,?,NOW())',
                    [$nid, $bal, $bal, 'admin', '开户初始余额', $me['id']]);
            }
            $msg = '用户已创建';
        }
    } elseif ($target) {
        if ($act === 'recharge') {
            $amt = (float) ($_POST['amount'] ?? 0);
            if ($amt == 0) {
                $err = '金额不能为 0';
            } else {
                // 加钱时区分能不能提现：admin 可提现，admin_gift 只能消费。
                // 扣款不需要区分，统一记 admin。
                $可提现 = ($_POST['withdrawable'] ?? '1') === '1';
                $类型 = ($amt > 0 && !$可提现) ? 'admin_gift' : 'admin';
                $默认备注 = $amt > 0
                    ? ($可提现 ? '管理员充值' : '管理员赠送（不可提现）')
                    : '管理员扣减';

                db_exec('UPDATE users SET balance = balance + ? WHERE id = ?',
                    [number_format($amt, 6, '.', ''), $uid]);
                $after = db_val('SELECT balance FROM users WHERE id = ?', [$uid]);
                db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, admin_id, created_at)
                         VALUES (?,?,?,?,?,?,NOW())',
                    [$uid, number_format($amt, 6, '.', ''), $after, $类型,
                     trim($_POST['note'] ?? '') !== '' ? trim($_POST['note']) : $默认备注, $me['id']]);
                $msg = '余额已调整，当前 ￥' . money($after)
                     . ($amt > 0 ? '（' . ($可提现 ? '可提现' : '不可提现') . '）' : '');
            }
        } elseif ($act === 'quota') {
            db_exec('UPDATE users SET token_quota = ? WHERE id = ?',
                [max(0, (int) ($_POST['token_quota'] ?? 0)), $uid]);
            $msg = 'Token 配额已更新';
        } elseif ($act === 'reset_used') {
            db_exec('UPDATE users SET used_tokens = 0 WHERE id = ?', [$uid]);
            $msg = '已用 token 计数已清零';
        } elseif ($act === 'toggle') {
            if ((int) $target['id'] === (int) $me['id']) {
                $err = '不能禁用自己';
            } else {
                $将要禁用 = (int) $target['status'] === 1;
                $理由 = trim((string) ($_POST['ban_reason'] ?? ''));
                // 禁用必须写理由：过后翻记录时才知道当初为什么封，也便于处理申诉。
                // 解封理由可留空（多数情况就是「已核实为误判」这类，不强求打字）。
                if ($将要禁用 && mb_strlen($理由) < 2) {
                    $err = '请填写禁用理由（至少 2 个字）';
                } else {
                    if (mb_strlen($理由) > 500) {
                        $理由 = mb_substr($理由, 0, 500);
                    }
                    db_exec('UPDATE users SET status = 1 - status WHERE id = ?', [$uid]);
                    db_exec('INSERT INTO user_ban_logs
                               (user_id, admin_id, action, reason, source, ip, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, NOW())',
                        [$uid, (int) $me['id'], $将要禁用 ? 'ban' : 'unban',
                         $理由, 'admin', client_ip()]);
                    $msg = $将要禁用 ? '账号已禁用，理由已记录' : '账号已启用';
                }
            }
        } elseif ($act === 'passwd') {
            $pw = (string) ($_POST['password'] ?? '');
            if (mb_strlen($pw) < 6) {
                $err = '密码至少 6 位';
            } else {
                db_exec('UPDATE users SET password_hash = ? WHERE id = ?',
                    [password_hash($pw, PASSWORD_DEFAULT), $uid]);
                $msg = '密码已重置';
            }
        } elseif ($act === 'role') {
            if ((int) $target['id'] === (int) $me['id']) {
                $err = '不能修改自己的角色';
            } else {
                db_exec('UPDATE users SET role = ? WHERE id = ?',
                    [$target['role'] === 'admin' ? 'user' : 'admin', $uid]);
                $msg = '角色已切换';
            }
        } elseif ($act === 'login_as') {
            // 模拟登录：成功后身份已变成普通用户，不能再回 /admin/（会 403），直接去前台首页
            $e = impersonate_start((int) $target['id']);
            if ($e === '') {
                flash_set('ok', "已登入【{$target['username']}】的账号，右上角横幅可一键返回管理员");
                header('Location: /index.php', true, 303);
                exit;
            }
            $err = $e;
        } elseif ($act === 'bind_referrer') {
            $rid = (int) ($_POST['referrer_id'] ?? 0);
            $ref = $rid ? db_one('SELECT id, username, status FROM users WHERE id = ?', [$rid]) : null;
            if (!$ref) {
                $err = '推介人不存在';
            } elseif ($rid === $uid) {
                $err = '不能把自己设为推介人';
            } elseif ((int) $ref['status'] !== 1) {
                $err = '该账号已被禁用，不能作为推介人';
            } elseif ((int) $target['referrer_id'] > 0) {
                $err = '该用户已有推介人，不能重复绑定';
            } elseif ((int) db_val('SELECT referrer_id FROM users WHERE id = ?', [$rid]) === $uid) {
                // 防止两人互为上下级形成环，返现会在两边来回结算
                $err = '对方的推介人正是该用户，不能互相绑定';
            } elseif (!aff_enabled()) {
                $err = '推介功能未开启，请先到推介设置里打开';
            } elseif (!aff_bind($uid, $rid)) {
                $err = '绑定失败，请重试';
            } else {
                $msg = '已把 ' . $ref['username'] . ' 设为 ' . $target['username'] . ' 的推介人';
                // 补绑之后回溯历史已付订单，把本该有的返现一次性补给上级
                $bf = aff_backfill_recharges($uid);
                // 留一条回溯记录：这笔钱是人工补的，事后对账要能查到是谁在什么时候补的
                db_exec('INSERT INTO aff_backfill_logs
                          (user_id, referrer_id, admin_id, order_count, total_amount, rate, action, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, \'bind\', NOW())',
                    [$uid, $rid, (int) $me['id'], $bf['count'], $bf['total'], aff_rate()]);
                if ($bf['count'] > 0) {
                    $msg .= '；已回溯 ' . $bf['count'] . ' 笔历史充值，补返 '
                          . number_format($bf['total'], 4, '.', '') . ' 元到对方余额';
                } else {
                    $msg .= '；该用户没有可补算的历史充值';
                }
            }
        } elseif ($act === 'unbind_referrer') {
            if ((int) $target['referrer_id'] <= 0) {
                $err = '该用户本来就没有推介人';
            } else {
                // 只断关系，已发放的返现不追回：那笔钱可能已经被消费掉了，
                // 硬扣会把上级余额扣成负数。需要追回请手动调整余额。
                $oldRid = (int) $target['referrer_id'];
                db_exec('UPDATE users SET referrer_id = 0, referred_at = NULL WHERE id = ?', [$uid]);
                // 解绑不产生金额变动，但要留痕：否则关系断了就查不出原来挂在谁名下
                db_exec('INSERT INTO aff_backfill_logs
                          (user_id, referrer_id, admin_id, order_count, total_amount, rate, action, created_at)
                          VALUES (?, ?, ?, 0, 0, 0, \'unbind\', NOW())',
                    [$uid, $oldRid, (int) $me['id']]);
                $msg = '已解除推介关系，此前发放的返现不作追回';
            }
        } elseif ($act === 'capability') {
            // 21 个细粒度开关用白名单枚举处理，避免再出现字段漏存。
            // cap_file_pull 列虽在库里，但 file_pull 工具尚未实现，暂不纳入。
            $cap字段 = [
                'cap_ssh_exec',
                'cap_sftp_read', 'cap_sftp_write', 'cap_sftp_patch', 'cap_sftp_list', 'cap_sftp_delete',
                'cap_file_list', 'cap_file_read', 'cap_file_write', 'cap_file_patch', 'cap_file_delete', 'cap_file_push',
                'cap_ws_list', 'cap_ws_read', 'cap_ws_write', 'cap_ws_patch', 'cap_ws_zip', 'cap_ws_delete',
                'cap_web_open', 'cap_web_search', 'cap_ppt_generate',
            ];
            $set片段 = [];
            $参数 = [];
            foreach ($cap字段 as $字段) {
                $set片段[] = $字段 . ' = ?';
                $参数[] = (($_POST[$字段] ?? '') === '1') ? 1 : 0;
            }
            $参数[] = $uid;
            db_exec('UPDATE users SET ' . implode(', ', $set片段) . ' WHERE id = ?', $参数);
            $msg = '权限设置已更新';
        } elseif ($act === 'delete') {
            if ((int) $target['id'] === (int) $me['id']) {
                $err = '不能删除自己';
            } else {
                db_exec('DELETE FROM messages WHERE user_id = ?', [$uid]);
                // 回执已改存文件，连目录一起清
                tool_result_drop_user($uid);
                db_exec('DELETE FROM conversations WHERE user_id = ?', [$uid]);
                db_exec('DELETE FROM usage_logs WHERE user_id = ?', [$uid]);
                db_exec('DELETE FROM balance_logs WHERE user_id = ?', [$uid]);
                db_exec('DELETE FROM users WHERE id = ?', [$uid]);
                $msg = '用户及其数据已删除';
            }
        }
    } else {
        $err = '用户不存在';
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

$kw = trim($_GET['kw'] ?? '');
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 20;
$off = ($page - 1) * $per;
$where = $kw !== '' ? 'WHERE u.username LIKE ?' : '';
$args = $kw !== '' ? ['%' . $kw . '%'] : [];
$total = (int) db_val('SELECT COUNT(*) FROM users u ' . $where, $args);
// LEFT JOIN 一次带出推介人账号，避免在渲染循环里逐行查库
$users = db_all(
    'SELECT u.*, r.username AS referrer_name
       FROM users u
       LEFT JOIN users r ON r.id = u.referrer_id
     ' . $where . ' ORDER BY u.id DESC LIMIT ' . $per . ' OFFSET ' . $off,
    $args
);
$pages = max(1, (int) ceil($total / $per));
?>
<div class="page-head">
  <h1 class="page-title">用户管理</h1>
  <div class="spacer"></div>
  <form class="inline-form" method="get">
    <input class="input" style="width:190px" type="text" name="kw" value="<?= h($kw) ?>" placeholder="搜索账号">
    <button class="btn" type="submit">搜索</button>
  </form>
  <button class="btn btn-primary" type="button" onclick="document.getElementById('newUser').hidden=!document.getElementById('newUser').hidden">+ 新增用户</button>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<div class="card mb-16" id="newUser" hidden>
  <div class="card-head">新增用户</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="create">
      <div class="form-grid">
        <label class="field"><span class="field-label">账号</span>
          <input class="input" name="username" required></label>
        <label class="field"><span class="field-label">密码</span>
          <input class="input" name="password" type="text" required minlength="6"></label>
        <label class="field"><span class="field-label">邮箱(可选)</span>
          <input class="input" name="email" type="email"></label>
        <label class="field"><span class="field-label">初始余额(元)</span>
          <input class="input" name="balance" type="number" step="0.0001" value="1"></label>
        <label class="field"><span class="field-label">Token 配额(0=不限)</span>
          <input class="input" name="token_quota" type="number" value="0"></label>
        <label class="field"><span class="field-label">角色</span>
          <select class="select" name="role"><option value="user">普通用户</option><option value="admin">管理员</option></select></label>
        <label class="field"><span class="field-label">备注</span>
          <input class="input" name="remark"></label>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit">创建</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr><th>ID</th><th>账号</th><th>邮箱</th><th>角色</th><th>状态</th><th>余额(元)</th>
            <th>已用 tokens</th><th>配额</th><th>累计消费</th><th>最近登录</th><th>操作</th></tr>
      </thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="11" class="empty">暂无用户</td></tr>
      <?php else: foreach ($users as $u): ?>
        <tr>
          <td><?= (int) $u['id'] ?></td>
          <td><?= h($u['username']) ?><?= $u['remark'] ? '<div class="hint">' . h($u['remark']) . '</div>' : '' ?>
            <?php if ((int) $u['referrer_id'] > 0): ?>
              <div class="hint">推介人：<a href="#" class="ref-unbind" data-uid="<?= (int) $u['id'] ?>"
                   data-name="<?= h($u['username']) ?>"
                   title="点击解除推介关系"><?= h($u['referrer_name'] ?? '已注销') ?>（#<?= (int) $u['referrer_id'] ?>）</a></div>
            <?php else: ?>
              <div class="hint">推介人：<a href="#" class="ref-bind" data-uid="<?= (int) $u['id'] ?>"
                   data-name="<?= h($u['username']) ?>" title="点击绑定推介人">无</a></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($u['email'] !== ''): ?>
              <?= h($u['email']) ?>
              <?php if ($u['email_verified_at']): ?>
                <span class="badge badge-ok" style="margin-left:4px">已验证</span>
              <?php else: ?>
                <span class="badge badge-off" style="margin-left:4px">未验证</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= $u['role'] === 'admin' ? '<span class="badge badge-admin">管理员</span>' : '<span class="badge badge-off">用户</span>' ?></td>
          <td><?= (int) $u['status'] === 1 ? '<span class="badge badge-ok">正常</span>' : '<span class="badge badge-off">禁用</span>' ?></td>
          <td><b>￥<?= money($u['balance']) ?></b></td>
          <td><?= fmt_int($u['used_tokens']) ?></td>
          <td><?= (int) $u['token_quota'] > 0 ? fmt_int($u['token_quota']) : '不限' ?></td>
          <td>￥<?= money($u['total_cost']) ?></td>
          <td class="nowrap"><?= h($u['last_login_at'] ?: '—') ?></td>
          <td class="acts">
            <a class="btn btn-sm" href="/admin/user_edit.php?id=<?= (int) $u['id'] ?>">管理</a>
            <a class="btn btn-sm" href="/admin/chats.php?uid=<?= (int) $u['id'] ?>">对话</a>
            <?php // 只对「正常状态的普通用户」显示；管理员和禁用账号后端也会拦
            if ($u['role'] !== 'admin' && (int) $u['status'] === 1 && (int) $u['id'] !== (int) $me['id']): ?>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('以【<?= h($u['username']) ?>】的身份登入？\n\n你当前的管理员会话会被暂存，页面顶部会出现横幅，点一下即可切回管理员，无需重新输密码。');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="act" value="login_as">
              <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
              <button class="btn btn-sm" type="submit" title="以该用户身份登入，可一键切回">登入账号</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
      <?php if ($i === $page): ?><span class="on"><?= $i ?></span>
      <?php else: ?><a href="?p=<?= $i ?>&kw=<?= urlencode($kw) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- 绑定推介人弹窗：搜索候选人 → 选中 → 提交。
     解绑走同一个表单，只是动作不同，省一套结构。 -->
<div class="ref-mask" id="refMask" hidden>
  <div class="ref-dlg">
    <div class="ref-dlg-head">
      <span id="refDlgTitle">绑定推介人</span>
      <a href="#" class="ref-close" id="refClose">&times;</a>
    </div>
    <div class="ref-dlg-body">
      <p class="hint" id="refDlgTip">给 <b id="refTargetName"></b> 指定推介人。可按账号、邮箱或用户 ID 搜索。</p>
      <input class="input" type="text" id="refSearch" placeholder="输入账号、邮箱或用户 ID" autocomplete="off">
      <div class="ref-list" id="refList"><div class="ref-empty">输入关键词开始搜索</div></div>
      <div class="alert alert-info ref-note">
        绑定后会按当前返佣比例回溯该用户已完成的充值订单，一次性把返现补进推介人余额。已经算过的订单不会重复计算。
      </div>
    </div>
    <div class="ref-dlg-foot">
      <form method="post" id="refForm">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="bind_referrer">
        <input type="hidden" name="uid" id="refUid">
        <input type="hidden" name="referrer_id" id="refRid">
        <span class="hint" id="refChosen">未选择</span>
        <span class="spacer"></span>
        <button class="btn" type="button" id="refCancel">取消</button>
        <button class="btn btn-primary" type="submit" id="refSubmit" disabled>确认绑定</button>
      </form>
    </div>
  </div>
</div>

<!-- 解绑用的隐藏表单，确认后直接提交 -->
<form method="post" id="unbindForm" hidden>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="act" value="unbind_referrer">
  <input type="hidden" name="uid" id="unbindUid">
</form>

<style>
  .ref-mask { position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 900;
              display: flex; align-items: center; justify-content: center; padding: 20px; }
  .ref-dlg { background: #fff; border-radius: 10px; width: 100%; max-width: 480px;
             box-shadow: 0 20px 50px rgba(0,0,0,.25); display: flex; flex-direction: column; max-height: 86vh; }
  .ref-dlg-head { padding: 14px 16px; border-bottom: 1px solid #eef0f3; font-weight: 600;
                  display: flex; align-items: center; }
  .ref-dlg-head .ref-close { margin-left: auto; color: #94a3b8; text-decoration: none;
                             font-size: 22px; line-height: 1; }
  .ref-dlg-body { padding: 14px 16px; overflow: auto; }
  .ref-dlg-body .input { width: 100%; margin: 8px 0 10px; }
  .ref-list { border: 1px solid #eef0f3; border-radius: 8px; max-height: 260px; overflow: auto; }
  .ref-item { padding: 9px 12px; border-bottom: 1px solid #f4f6f8; cursor: pointer;
              display: flex; align-items: center; gap: 8px; }
  .ref-item:last-child { border-bottom: 0; }
  .ref-item:hover { background: #f8fafc; }
  .ref-item.on { background: #eff6ff; }
  .ref-item .rid { color: #94a3b8; font-size: 12px; flex: 0 0 auto; }
  .ref-item .rname { font-weight: 500; }
  .ref-item .rmail { color: #94a3b8; font-size: 12px; margin-left: auto;
                     max-width: 46%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ref-empty { padding: 18px; text-align: center; color: #94a3b8; font-size: 13px; }
  .ref-note { margin: 10px 0 0; font-size: 12px; line-height: 1.6; }
  .ref-dlg-foot { padding: 12px 16px; border-top: 1px solid #eef0f3; }
  .ref-dlg-foot form { display: flex; align-items: center; gap: 8px; }
  .ref-dlg-foot .spacer { flex: 1; }
  td .hint a { margin-left: 4px; font-size: 12px; }
</style>

<script>
(function () {
  var mask = document.getElementById('refMask');
  var 搜索框 = document.getElementById('refSearch');
  var 列表 = document.getElementById('refList');
  var 提交按钮 = document.getElementById('refSubmit');
  var 已选提示 = document.getElementById('refChosen');
  var 当前uid = 0;
  var 定时器 = null;

  function 打开(uid, name) {
    当前uid = uid;
    document.getElementById('refUid').value = uid;
    document.getElementById('refTargetName').textContent = name;
    document.getElementById('refRid').value = '';
    已选提示.textContent = '未选择';
    提交按钮.disabled = true;
    搜索框.value = '';
    列表.innerHTML = '<div class="ref-empty">输入关键词开始搜索</div>';
    mask.hidden = false;
    搜索框.focus();
  }

  function 关闭() { mask.hidden = true; }

  function 渲染(list) {
    if (!list.length) {
      列表.innerHTML = '<div class="ref-empty">没有匹配的账号</div>';
      return;
    }
    列表.innerHTML = '';
    list.forEach(function (u) {
      var 行 = document.createElement('div');
      行.className = 'ref-item';
      行.dataset.id = u.id;
      行.dataset.name = u.username;
      var 邮箱 = u.email ? '<span class="rmail">' + u.email + '</span>' : '';
      行.innerHTML = '<span class="rid">#' + u.id + '</span>'
                   + '<span class="rname"></span>' + 邮箱;
      // 账号名用 textContent 写入，避免用户名里的特殊字符被当成标签
      行.querySelector('.rname').textContent = u.username
                   + (u.invited > 0 ? '（已推介 ' + u.invited + ' 人）' : '');
      行.addEventListener('click', function () {
        var 旧 = 列表.querySelector('.ref-item.on');
        if (旧) { 旧.classList.remove('on'); }
        行.classList.add('on');
        document.getElementById('refRid').value = u.id;
        已选提示.textContent = '已选：' + u.username + '（#' + u.id + '）';
        提交按钮.disabled = false;
      });
      列表.appendChild(行);
    });
  }

  // 输入防抖，避免每敲一个字就打一次请求
  搜索框.addEventListener('input', function () {
    clearTimeout(定时器);
    var q = 搜索框.value.trim();
    if (q === '') {
      列表.innerHTML = '<div class="ref-empty">输入关键词开始搜索</div>';
      return;
    }
    定时器 = setTimeout(function () {
      列表.innerHTML = '<div class="ref-empty">搜索中…</div>';
      fetch('?api=search_referrer&for=' + 当前uid + '&q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (d) { 渲染(d.list || []); })
        .catch(function () { 列表.innerHTML = '<div class="ref-empty">搜索失败，请重试</div>'; });
    }, 250);
  });

  document.querySelectorAll('.ref-bind').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      打开(a.dataset.uid, a.dataset.name);
    });
  });

  document.querySelectorAll('.ref-unbind').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      if (!confirm('解除【' + a.dataset.name + '】的推介关系？\n\n此前已发放的返现不会追回。')) { return; }
      document.getElementById('unbindUid').value = a.dataset.uid;
      document.getElementById('unbindForm').submit();
    });
  });

  document.getElementById('refClose').addEventListener('click', function (e) { e.preventDefault(); 关闭(); });
  document.getElementById('refCancel').addEventListener('click', 关闭);
  mask.addEventListener('click', function (e) { if (e.target === mask) { 关闭(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !mask.hidden) { 关闭(); } });
})();
</script>
<?php require __DIR__ . '/_foot.php'; ?>