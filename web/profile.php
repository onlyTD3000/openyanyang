<?php
/** 个人中心：修改密码、维护邮箱、查看余额流水。所有操作只作用于当前登录用户。 */
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/withdraw.php';
$me = require_login();
$navOn = 'profile';

$msg = '';
$msgType = 'ok';
$generated_key = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = $_POST['act'] ?? '';

    // ---- 修改密码 ----
    if ($act === 'passwd') {
        $old   = (string) ($_POST['old_password'] ?? '');
        $new   = (string) ($_POST['new_password'] ?? '');
        $new2  = (string) ($_POST['new_password2'] ?? '');

        if (!password_verify($old, $me['password_hash'])) {
            $msg = '当前密码不正确';
            $msgType = 'error';
        } elseif (mb_strlen($new) < 6) {
            $msg = '新密码至少 6 位';
            $msgType = 'error';
        } elseif ($new !== $new2) {
            $msg = '两次输入的新密码不一致';
            $msgType = 'error';
        } elseif ($new === $old) {
            $msg = '新密码与当前密码相同';
            $msgType = 'error';
        } else {
            db_exec('UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            // 改密后重建会话标识，降低会话固定风险
            session_regenerate_id(true);
            $msg = '密码已修改成功';
            $me = current_user() ?: $me;
        }
    }

    // ---- 修改邮箱 ----
    if ($act === 'email') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = '邮箱格式不正确';
            $msgType = 'error';
        } else {
            db_exec('UPDATE users SET email = ? WHERE id = ?', [mb_substr($email, 0, 120), $me['id']]);
            $msg = $email === '' ? '邮箱已清空' : '邮箱已更新';
            $me['email'] = $email;
        }
    }

    // ---- 修改昵称和 QQ 头像 ----
    if ($act === 'profile_info') {
        $nickname   = trim((string) ($_POST['nickname'] ?? ''));
        $avatar_qq  = trim((string) ($_POST['avatar_qq'] ?? ''));

        if ($nickname !== '' && mb_strlen($nickname) > 50) {
            $msg = '昵称最多 50 个字';
            $msgType = 'error';
        } elseif ($avatar_qq !== '' && !preg_match('/^[1-9]\d{4,11}$/', $avatar_qq)) {
            $msg = 'QQ 号格式不正确（5-12 位纯数字）';
            $msgType = 'error';
        } else {
            db_exec('UPDATE users SET nickname = ?, avatar_qq = ? WHERE id = ?',
                [mb_substr($nickname, 0, 50), mb_substr($avatar_qq, 0, 12), $me['id']]);
            $me['nickname']  = $nickname;
            $me['avatar_qq'] = $avatar_qq;
            $msg = $nickname === '' && $avatar_qq === '' ? '已清空昵称和头像' : '个人信息已更新';
        }
    }

    // ---- 提现申请 ----
    if ($act === 'withdraw') {
        start_session();
        $r = wd_submit((int) $me['id'], [
            'amount'       => $_POST['amount'] ?? '',
            'method'       => $_POST['method'] ?? '',
            'real_name'    => $_POST['real_name'] ?? '',
            'phone'        => $_POST['phone'] ?? '',
            'reason'       => $_POST['reason'] ?? '',
            'qr_upload_id' => $_POST['qr_upload_id'] ?? 0,
        ]);
        flash_set($r['ok'] ? 'ok' : 'error', $r['msg']);
        header('Location: /profile.php?tab=withdraw', true, 303);
        exit;
    }

    // ---- SK 卡密（中转用）：生成 / 删除 ----
    if ($act === 'sk_card_generate') {
        // 拉黑用户禁止生成卡密
        if ((int) ($me['sk_blacklisted'] ?? 0) === 1) {
            flash_set('error', '您的SK卡密功能已被禁用，如有疑问请联系管理员。');
            redirect_self();
        }
        start_session();
        $name        = trim((string) ($_POST['sk_name'] ?? ''));
        $balance     = (float) ($_POST['sk_balance'] ?? 0);
        $expireDays  = (int) ($_POST['sk_expire_days'] ?? 0);
        $ipWhitelist = trim((string) ($_POST['sk_ip_whitelist'] ?? ''));
        $ipBlacklist = trim((string) ($_POST['sk_ip_blacklist'] ?? ''));
        $allowedModels = trim((string) ($_POST['sk_allowed_models'] ?? ''));

        if ($name === '') {
            $name = '默认卡密';
        }
        if (mb_strlen($name) > 100) {
            $name = mb_substr($name, 0, 100);
        }
        if ($balance < 0) {
            $balance = 0;
        }
        // 0 = 永久不到期；>0 = N 天后过期
        $expiresAt = $expireDays > 0 ? date('Y-m-d H:i:s', time() + $expireDays * 86400) : null;

        // 从用户余额中扣除卡密分配的额度
        if ($balance > 0 && (float) $me['balance'] < $balance) {
            flash_set('error', '账户余额不足，无法分配 ' . $balance . ' 元给卡密');
            redirect_self();
        }
        if ($balance > 0) {
            db_exec('UPDATE users SET balance = balance - ? WHERE id = ?', [$balance, $me['id']]);
        }

        $tok = sk_card_create($me['id'], $name, $balance, $expiresAt, $ipWhitelist, $ipBlacklist, $allowedModels);
        if ($tok) {
            $_SESSION['new_sk_card'] = $tok;
            if ($balance > 0) {
                $余额后 = (float) db_val('SELECT balance FROM users WHERE id = ?', [$me['id']]);
                db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, created_at)
                         VALUES (?,?,?,?,?,NOW())',
                    [$me['id'], -$balance, $余额后, 'admin', '分配至 SK 卡密：' . $name]);
            }
            flash_set('ok', 'SK 卡密已生成');
        } else {
            // 生成失败，退回扣除的余额
            if ($balance > 0) {
                db_exec('UPDATE users SET balance = balance + ? WHERE id = ?', [$balance, $me['id']]);
            }
            flash_set('error', '卡密生成失败，请重试。');
        }
        redirect_self();
    }
    if ($act === 'sk_card_delete') {
        $kid = (int) ($_POST['sk_card_id'] ?? 0);
        $card = db_one('SELECT * FROM sk_cards WHERE id = ? AND user_id = ? AND status = 1',
            [$kid, $me['id']]);
        if ($card) {
            // 硬删除：彻底从数据库移除，不再用软删除
            db_exec('DELETE FROM sk_cards WHERE id = ? AND user_id = ?', [$kid, $me['id']]);
            // 剩余余额退回用户账户
            $remaining = (float) $card['balance'];
            if ($remaining > 0) {
                db_exec('UPDATE users SET balance = balance + ? WHERE id = ?', [$remaining, $me['id']]);
                $余额后 = (float) db_val('SELECT balance FROM users WHERE id = ?', [$me['id']]);
                db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, created_at)
                         VALUES (?,?,?,?,?,NOW())',
                    [$me['id'], $remaining, $余额后, 'admin', 'SK 卡密余额退回：' . $card['name']]);
            }
            flash_set('ok', '卡密已删除' . ($remaining > 0 ? '，剩余余额 ￥' . number_format($remaining, 2) . ' 已退回' : ''));
        } else {
            flash_set('error', '卡密不存在或已被删除');
        }
        redirect_self();
    }

    // ---- API 密钥：一律 POST 后 303 重定向，避免刷新重复提交 ----
    if ($act === 'api_key_generate') {
        start_session();
        $tok = api_key_create($me['id'], '默认密钥');
        if ($tok) {
            $_SESSION['new_api_key'] = $tok;   // 明文只暂存一次，取出即删
            flash_set('ok', '新密钥已生成');
        } else {
            flash_set('error', '密钥生成失败，请重试。');
        }
        redirect_self();
    }
    // 删除单个密钥：带 user_id 条件，防止越权删别人的
    if ($act === 'api_key_delete') {
        $kid = (int) ($_POST['key_id'] ?? 0);
        $n = db_exec('UPDATE user_api_keys SET status = 0 WHERE id = ? AND user_id = ? AND status = 1',
            [$kid, $me['id']]);
        flash_set($n > 0 ? 'ok' : 'error', $n > 0 ? '密钥已删除' : '密钥不存在或已被删除');
        redirect_self();
    }
    // 重置单个密钥：作废旧的，用原名字生成新的
    if ($act === 'api_key_reset') {
        start_session();
        $kid = (int) ($_POST['key_id'] ?? 0);
        $old = db_one('SELECT name FROM user_api_keys WHERE id = ? AND user_id = ? AND status = 1',
            [$kid, $me['id']]);
        if (!$old) {
            flash_set('error', '密钥不存在或已被删除');
            redirect_self();
        }
        db_exec('UPDATE user_api_keys SET status = 0 WHERE id = ? AND user_id = ?', [$kid, $me['id']]);
        $tok = api_key_create($me['id'], $old['name']);
        if ($tok) {
            $_SESSION['new_api_key'] = $tok;
            flash_set('ok', '密钥已重置，旧密钥立即失效');
        } else {
            flash_set('error', '重置失败，请重试。');
        }
        redirect_self();
    }
}
// 取出重定向前暂存的明文密钥，取一次就清掉
start_session();
$generated_key = $_SESSION['new_api_key'] ?? null;
unset($_SESSION['new_api_key']);
$generated_sk = $_SESSION['new_sk_card'] ?? null;
unset($_SESSION['new_sk_card']);
// 接上重定向带过来的提示（不覆盖改密、邮箱等本轮已有的提示）
[$fType, $fMsg] = flash_get();
if ($fMsg !== '' && $msg === '') {
    $msg = $fMsg;
    $msgType = $fType === 'error' ? 'error' : 'ok';
}
$csrf = csrf_token();
$apiKeys = db_all('SELECT id, name, created_at, last_used_at FROM user_api_keys WHERE user_id = ? AND status = 1 ORDER BY created_at DESC', [$me['id']]);
$skCards = db_all('SELECT id, name, balance, total_cost, expires_at, ip_whitelist, ip_blacklist, allowed_models, last_used_at, last_used_ip, created_at FROM sk_cards WHERE user_id = ? AND status = 1 ORDER BY created_at DESC', [$me['id']]);
$allModels = db_all('SELECT id, display_name FROM models WHERE status = 1 ORDER BY display_name');

// ---- 分页：个人中心 / 密钥管理 / AFF 推介 ----
require_once __DIR__ . '/inc/aff.php';
$tabs = ['profile' => '个人中心', 'keys' => '密钥管理'];
$tabs['favs'] = '我的收藏';
if (aff_enabled()) {
    $tabs['aff'] = 'AFF 推介';
}
if (wd_enabled()) {
    $tabs['withdraw'] = '余额提现';
}
$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'profile');
if (!isset($tabs[$tab])) {
    $tab = 'profile';
}

// 提现数据：只在提现页查，别的标签页用不上
$wdQuota = null;
$wdList = [];
if ($tab === 'withdraw') {
    $wdQuota = wd_quota_detail((int) $me['id']);
    $wdList = wd_my_list((int) $me['id'], 20);
}

// AFF 分页的数据只在需要时查，省掉另外两个分页的无用查询
$affStat = $affInvitees = $affComms = null;
$affCode = $affUrl = '';
$affPage = $affPages = 1;
if ($tab === 'aff') {
    $affCode = aff_user_code((int) $me['id']);
    $affUrl  = aff_invite_url($affCode);
    $affStat = aff_my_stat((int) $me['id']);
    $affPer  = 20;
    $affPage = max(1, (int) ($_GET['ap'] ?? 1));
    $affTotal = aff_my_invitee_count((int) $me['id']);
    $affPages = max(1, (int) ceil($affTotal / $affPer));
    if ($affPage > $affPages) {
        $affPage = $affPages;
    }
    $affInvitees = aff_my_invitees((int) $me['id'], $affPer, ($affPage - 1) * $affPer);
    $affComms = aff_my_commissions((int) $me['id'], 30);
}

// 收藏列表：只在收藏页查，别的标签页用不上
$favList = [];
if ($tab === 'favs') {
    $favList = db_all(
        'SELECT f.id, f.msg_id, f.conv_id, f.role, f.content, f.created_at,
                c.title AS conv_title
           FROM message_favorites f
           LEFT JOIN conversations c ON c.id = f.conv_id
          WHERE f.user_id = ?
          ORDER BY f.created_at DESC
          LIMIT 200',
        [$me['id']]
    );
    foreach ($favList as &$f) {
        $f['preview'] = mb_substr((string) $f['content'], 0, 500);
        $f['content_length'] = mb_strlen((string) $f['content']);
    }
    unset($f);
}

// 余额流水（仅本人）
$bpage = max(1, (int) ($_GET['p'] ?? 1));
$bper  = 15;
$boff  = ($bpage - 1) * $bper;
$btotal = (int) db_val('SELECT COUNT(*) FROM balance_logs WHERE user_id = ?', [$me['id']]);
$bpages = max(1, (int) ceil($btotal / $bper));
$blogs = db_all('SELECT * FROM balance_logs WHERE user_id = ?
                  ORDER BY id DESC LIMIT ' . $bper . ' OFFSET ' . $boff, [$me['id']]);

$convN = (int) db_val('SELECT COUNT(*) FROM conversations WHERE user_id = ?', [$me['id']]);
$callN = (int) db_val('SELECT COUNT(*) FROM usage_logs WHERE user_id = ?', [$me['id']]);
$siteName = app_name();

/** 流水类型中文名 */
function blog_type_name(string $t): string
{
    $map = [
        'register'        => '注册赠送',
        'admin'           => '管理员调整',
        'admin_gift'      => '管理员赠送',
        'consume'         => '对话消费',
        'refund'          => '退回',
        'est_recalc'      => '估算口径修正',
        'recharge'        => '在线充值',
        'aff'             => '推介返现',
        'withdraw'        => '提现扣款',
        'withdraw_refund' => '提现退回',
    ];
    return $map[$t] ?? $t;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var db=<?= (int)($me['dark_mode'] ?? 0) ?>;if(db)document.documentElement.classList.add('dark');})();</script>
<title>个人中心 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>
<div class="app">
<?php require __DIR__ . '/inc/topbar.php'; ?>
<div class="page">
  <div class="page-head"><h1 class="page-title"><?= h($tabs[$tab]) ?></h1></div>

  <div class="topbar-nav" style="margin:0 0 18px;flex-wrap:wrap">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="/profile.php?tab=<?= h($k) ?>" class="<?= $tab === $k ? 'on' : '' ?>" data-tab="<?= h($k) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <div id="profile-content">
  <?php if ($msg !== ''): ?>
    <div class="alert alert-<?= $msgType === 'ok' ? 'ok' : 'error' ?>"><?= h($msg) ?></div>
  <?php endif; ?>

  <?php if ($tab === 'profile'): ?>
  <div class="stat-grid">
    <div class="stat">
      <div class="stat-label">账户余额</div>
      <div class="stat-value">￥<?= money($me['balance']) ?></div>
      <div class="stat-sub">
        累计消费 ￥<?= money($me['total_cost']) ?>
        <?php if (wd_enabled()): ?>
          <a href="/profile.php?tab=withdraw" class="btn btn-sm" style="margin-left:8px">提现</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="stat">
      <div class="stat-label">账号</div>
      <div class="stat-value" style="font-size:19px"><?= h($me['username']) ?></div>
      <div class="stat-sub">
        <?= $me['role'] === 'admin'
            ? '<span class="badge badge-admin">管理员</span>'
            : '<span class="badge badge-blue">普通用户</span>' ?>
      </div>
    </div>
    <div class="stat">
      <div class="stat-label">使用情况</div>
      <div class="stat-value"><?= fmt_int($callN) ?></div>
      <div class="stat-sub"><?= fmt_int($convN) ?> 个会话 · 已用 <?= fmt_int($me['used_tokens']) ?> tokens</div>
    </div>
    <div class="stat">
      <div class="stat-label">上次登录</div>
      <div class="stat-value" style="font-size:17px">
        <?= $me['last_login_at'] ? h(substr($me['last_login_at'], 5, 11)) : '—' ?>
      </div>
      <div class="stat-sub">
        IP <?= h($me['last_login_ip'] !== '' ? $me['last_login_ip'] : '—') ?>
        · 注册 <?= h(substr($me['created_at'], 0, 10)) ?>
      </div>
    </div>
  </div>

  <div class="form-grid mb-16" style="align-items:start">
    <div class="card">
      <div class="card-head">昵称与头像</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="act" value="profile_info">
          <label class="field">
            <span class="field-label">昵称 <span class="muted">(可留空)</span></span>
            <input class="input" type="text" name="nickname" maxlength="50"
                   value="<?= h($me['nickname']) ?>" placeholder="显示在顶栏和对话中">
          </label>
          <label class="field mt-16">
            <span class="field-label">QQ 号 <span class="muted">(用于自动获取头像)</span></span>
            <input class="input" type="text" name="avatar_qq" maxlength="12" inputmode="numeric"
                   value="<?= h($me['avatar_qq']) ?>" placeholder="填入 QQ 号自动显示头像"
                   id="avatarQqInput">
          </label>
          <div class="field mt-16" id="avatarPreviewWrap" style="display:none">
            <span class="field-label">头像预览</span>
            <div style="display:flex;align-items:center;gap:12px">
              <img id="avatarPreviewImg" src="" alt="头像预览"
                   style="width:56px;height:56px;border-radius:50%;object-fit:cover">
              <span class="hint" style="margin:0">头像来自 QQ 官方接口，填写 QQ 号后自动显示</span>
            </div>
          </div>
          <div class="hint">昵称留空则显示用户名。头像通过 QQ 号从腾讯服务器获取，不存储在本站。</div>
          <div class="form-actions"><button class="btn btn-primary" type="submit">保存</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head">修改密码</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="act" value="passwd">
          <label class="field">
            <span class="field-label">当前密码</span>
            <input class="input" type="password" name="old_password" required autocomplete="current-password">
          </label>
          <label class="field mt-16">
            <span class="field-label">新密码</span>
            <input class="input" type="password" name="new_password" required minlength="6" autocomplete="new-password">
          </label>
          <label class="field mt-16">
            <span class="field-label">确认新密码</span>
            <input class="input" type="password" name="new_password2" required minlength="6" autocomplete="new-password">
          </label>
          <div class="hint">密码至少 6 位。修改成功后当前浏览器仍保持登录。</div>
          <div class="form-actions"><button class="btn btn-primary" type="submit">保存新密码</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head">联系邮箱</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="act" value="email">
          <label class="field">
            <span class="field-label">邮箱 <span class="muted">(可留空)</span></span>
            <input class="input" type="email" name="email" value="<?= h($me['email']) ?>" placeholder="用于接收通知">
          </label>
          <div class="hint">仅用于平台内联系，不会对外公开。</div>
          <div class="form-actions"><button class="btn btn-primary" type="submit">保存邮箱</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head">额度说明</div>
      <div class="card-body">
        <label class="field">
          <span class="field-label">Token 配额</span>
          <input class="input" type="text" readonly
                 value="<?= (int) $me['token_quota'] > 0
                     ? fmt_int($me['token_quota']) . ' tokens（剩余 ' . fmt_int(max(0, (int) $me['token_quota'] - (int) $me['used_tokens'])) . '）'
                     : '不限' ?>">
        </label>
        <div class="hint mt-16">
          余额与配额均由管理员分配。需要充值或调整额度请联系管理员。<br>
          想看每次调用的明细，去 <a href="/usage.php">我的用量</a>。
        </div>
        <div class="form-actions"><a class="btn" href="/usage.php">查看调用明细</a></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'keys'): ?>

  <?php /* SK 卡密生成成功的弹窗放在页面底部 */ ?>

  <div class="card mb-16" id="sk-cards">
    <div class="card-head">
      SK 卡密管理
      <span class="hint" style="margin:0">用于 /v1 中转 API 调用（OpenAI / Anthropic 兼容接口）</span>
    </div>
    <div class="card-body">
      <?php if ((int) ($me['sk_blacklisted'] ?? 0) === 1): ?>
        <div class="alert alert-error" style="margin-bottom:12px">您的 SK 卡密功能已被禁用，如有疑问请联系管理员。</div>
      <?php else: ?>
      <button class="btn btn-primary" type="button" onclick="document.getElementById('skModal').style.display='flex'">生成新卡密</button>
      <?php endif; ?>

      <?php if ($skCards): ?>
        <div class="table-wrap mt-16">
          <table class="tbl">
            <thead>
              <tr><th>名称</th><th>余额</th><th>累计消费</th><th>过期时间</th><th>模型限制</th><th>IP 限制</th><th>创建时间</th><th>最后使用</th><th style="width:80px">操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($skCards as $c): ?>
              <tr>
                <td><?= h($c['name']) ?></td>
                <td>￥<?= money($c['balance']) ?></td>
                <td>￥<?= money($c['total_cost']) ?></td>
                <td class="nowrap"><?= $c['expires_at']
                      ? h(substr($c['expires_at'], 0, 16))
                      : '<span class="hint" style="margin:0">永久</span>' ?></td>
                <td>
                  <?php
                    if (!empty($c['allowed_models'])) {
                      $models = array_filter(array_map('trim', explode(',', (string) $c['allowed_models'])));
                      $show = array_slice($models, 0, 3);
                      echo '<span style="font-size:12px">' . h(implode(', ', $show)) . '</span>';
                      if (count($models) > 3) {
                        echo '<br><span class="hint" style="margin:0">+' . (count($models) - 3) . ' 个</span>';
                      }
                    } else {
                      echo '<span class="hint" style="margin:0">不限制</span>';
                    }
                  ?>
                </td>
                <td>
                  <?php
                    $ipInfo = [];
                    if (!empty($c['ip_whitelist'])) $ipInfo[] = '白名单:' . h(substr($c['ip_whitelist'], 0, 30));
                    if (!empty($c['ip_blacklist'])) $ipInfo[] = '黑名单:' . h(substr($c['ip_blacklist'], 0, 30));
                    echo $ipInfo ? implode('<br>', $ipInfo) : '<span class="hint" style="margin:0">无限制</span>';
                  ?>
                </td>
                <td class="nowrap"><?= h(substr($c['created_at'], 0, 16)) ?></td>
                <td class="nowrap"><?= $c['last_used_at']
                      ? h(substr($c['last_used_at'], 0, 16)) . '<br><span class="hint" style="margin:0;font-size:11px">' . h($c['last_used_ip']) . '</span>'
                      : '<span class="hint" style="margin:0">从未使用</span>' ?></td>
                <td class="nowrap">
                  <form method="post" onsubmit="return confirm('删除后使用该卡密的调用会全部失败。剩余余额会退回账户。确定删除？')">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="tab" value="keys">
                    <input type="hidden" name="act" value="sk_card_delete">
                    <input type="hidden" name="sk_card_id" value="<?= (int) $c['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">删除</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="hint mt-16">卡密以 sk- 开头，明文仅在生成时显示一次。系统只保存哈希值，丢失只能删除重建。</div>
      <?php else: ?>
        <div class="hint mt-16">当前没有活跃的 SK 卡密，点上面的按钮生成一个。</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- SK 卡密生成弹窗 -->
  <div id="skModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
    <div style="background:var(--c-bg,#fff);border-radius:10px;padding:28px;max-width:460px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.2)">
      <h3 style="margin:0 0 20px;font-size:18px">生成 SK 卡密</h3>
      <form method="post" id="skForm">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="tab" value="keys">
        <input type="hidden" name="act" value="sk_card_generate">

        <label class="field">
          <span class="field-label">名称 <span class="muted">(可留空)</span></span>
          <input class="input" type="text" name="sk_name" maxlength="100" placeholder="如：测试卡密">
        </label>

        <label class="field mt-16">
          <span class="field-label">可用余额（元）</span>
          <input class="input" type="number" name="sk_balance" id="skBalance" step="0.01" min="0" value="0" placeholder="从账户余额分配">
          <span class="hint">当前账户余额 ￥<?= money($me['balance']) ?>，分配后会从账户扣除</span>
        </label>

        <label class="field mt-16">
          <span class="field-label">有效期（天）</span>
          <input class="input" type="number" name="sk_expire_days" id="skExpire" step="1" min="0" value="0" placeholder="0 = 永久不到期">
          <span class="hint">填 0 表示永久不到期，填 30 表示 30 天后过期</span>
        </label>

        <div class="field mt-16">
          <div onclick="const d=document.getElementById('skAdvanced');d.style.display=d.style.display==='none'?'block':'none';this.querySelector('.arrow').textContent=d.style.display==='none'?'▶':'▼'"
               style="cursor:pointer;font-weight:600;font-size:14px;padding:8px 0;border-top:1px solid var(--c-border,#ddd)">
            <span class="arrow">▶</span> 高级设置
          </div>
          <div id="skAdvanced" style="display:none;padding-top:12px">

            <div class="field" style="margin-bottom:16px">
              <span class="field-label">限制模型 <span class="muted">(可留空，留空=不限制)</span></span>
              <div id="skModelTags" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px;min-height:0"></div>
              <select class="input" id="skModelSelect" onchange="skToggleModel(this.value);this.selectedIndex=0">
                <option value="">+ 选择模型...</option>
                <?php foreach ($allModels as $m): ?>
                <option value="<?= h($m['display_name']) ?>"><?= h($m['display_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="sk_allowed_models" id="skAllowedModels" value="">
              <span class="hint">选择后该卡密只能调用选中的模型，留空表示不限制</span>
            </div>

            <label class="field">
              <span class="field-label">IP 白名单 <span class="muted">(可留空)</span></span>
              <input class="input" type="text" name="sk_ip_whitelist" placeholder="如 1.2.3.4,10.0.0.0/8">
              <span class="hint">留空表示不限制；多个用逗号分隔；支持 CIDR</span>
            </label>

            <label class="field mt-16">
              <span class="field-label">IP 黑名单 <span class="muted">(可留空)</span></span>
              <input class="input" type="text" name="sk_ip_blacklist" placeholder="如 5.6.7.8">
              <span class="hint">留空表示不限制；多个用逗号分隔；支持 CIDR</span>
            </label>

          </div>
        </div>

        <div class="form-actions mt-16" style="gap:8px">
          <button class="btn btn-primary" type="submit">生成卡密</button>
          <button class="btn" type="button" onclick="document.getElementById('skModal').style.display='none'">取消</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function() {
    window._skSelectedModels = [];
    window.skToggleModel = function(name) {
      if (!name) return;
      var idx = window._skSelectedModels.indexOf(name);
      if (idx >= 0) {
        window._skSelectedModels.splice(idx, 1);
      } else {
        window._skSelectedModels.push(name);
      }
      skRenderTags();
    };
    window.skRemoveModel = function(name) {
      var idx = window._skSelectedModels.indexOf(name);
      if (idx >= 0) {
        window._skSelectedModels.splice(idx, 1);
      }
      skRenderTags();
    };
    window.skRenderTags = function() {
      var box = document.getElementById('skModelTags');
      var hidden = document.getElementById('skAllowedModels');
      box.innerHTML = '';
      window._skSelectedModels.forEach(function(name) {
        var tag = document.createElement('span');
        tag.style.cssText = 'display:inline-flex;align-items:center;gap:3px;padding:2px 8px;background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;border-radius:4px;font-size:12px;cursor:pointer';
        tag.innerHTML = name + ' <span style="opacity:.6">✕</span>';
        if (document.documentElement.classList.contains('dark')) {
          tag.style.cssText = 'display:inline-flex;align-items:center;gap:3px;padding:2px 8px;background:#1e3a5f;color:#82b1ff;border:1px solid #1565c0;border-radius:4px;font-size:12px;cursor:pointer';
        }
        tag.onclick = function() { skRemoveModel(name); };
        box.appendChild(tag);
      });
      hidden.value = window._skSelectedModels.join(',');
    };
  })();
  </script>

  <div class="card mb-16" id="api-keys">
    <div class="card-head">
      API 密钥管理
      <span class="hint" style="margin:0">(内部用) 安卓端和客户端调用使用</span>
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="tab" value="keys">
        <input type="hidden" name="act" value="api_key_generate">
        <button class="btn btn-primary" type="submit">生成新密钥</button>
      </form>
      <?php if ($apiKeys): ?>
        <div class="table-wrap mt-16">
          <table class="tbl">
            <thead>
              <tr><th>名称</th><th>创建时间</th><th>最后使用</th><th style="width:150px">操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($apiKeys as $k): ?>
              <tr>
                <td><?= h($k['name']) ?></td>
                <td class="nowrap"><?= h($k['created_at']) ?></td>
                <td class="nowrap"><?= $k['last_used_at']
                      ? h($k['last_used_at'])
                      : '<span class="hint" style="margin:0">从未使用</span>' ?></td>
                <td class="nowrap">
                  <div style="display:flex;gap:6px">
                    <form method="post" onsubmit="return confirm('重置后这个密钥立即失效，需要改用新密钥。确定继续？')">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="tab" value="keys">
                      <input type="hidden" name="act" value="api_key_reset">
                      <input type="hidden" name="key_id" value="<?= (int) $k['id'] ?>">
                      <button class="btn btn-sm" type="submit">重置</button>
                    </form>
                    <form method="post" onsubmit="return confirm('删除后使用该密钥的调用会全部失败。确定删除？')">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="tab" value="keys">
                      <input type="hidden" name="act" value="api_key_delete">
                      <input type="hidden" name="key_id" value="<?= (int) $k['id'] ?>">
                      <button class="btn btn-sm btn-danger" type="submit">删除</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="hint mt-16">系统只保存密钥的哈希值，明文仅在生成的那一刻显示一次。忘记了就点「重置」换一个新的。</div>
      <?php else: ?>
        <div class="hint mt-16">当前没有活跃的 API 密钥，点上面的按钮生成一个。</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'withdraw' && $wdQuota !== null): ?>
  <div class="stat-grid">
    <div class="stat">
      <div class="stat-label">可提现额度</div>
      <div class="stat-value">￥<?= wd_money($wdQuota['quota']) ?></div>
      <div class="stat-sub">账户余额 ￥<?= money($wdQuota['balance']) ?></div>
    </div>
    <div class="stat">
      <div class="stat-label">不可提现部分</div>
      <div class="stat-value" style="font-size:19px">￥<?= wd_money($wdQuota['gift']) ?></div>
      <div class="stat-sub">注册赠送的余额只能用于消费</div>
    </div>
    <div class="stat">
      <div class="stat-label">今日已申请</div>
      <div class="stat-value" style="font-size:19px"><?= wd_today_count((int) $me['id']) ?><?= wd_daily_limit() > 0 ? ' / ' . wd_daily_limit() : '' ?> 次</div>
      <div class="stat-sub"><?= wd_daily_limit() > 0 ? '每日上限 ' . wd_daily_limit() . ' 次' : '不限次数' ?></div>
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-head">
      申请提现
      <span class="hint" style="margin:0">
        单笔 <?= wd_min() ?> ~ <?= wd_max() > 0 ? wd_max() : '不限' ?> 元，只能填整数
        <?php if (wd_fee_rate() > 0 || wd_fee_min() > 0): ?>
          ，手续费 <?= rtrim(rtrim(number_format(wd_fee_rate(), 2, '.', ''), '0'), '.') ?>%<?= wd_fee_min() > 0 ? '（最低 ' . wd_money(wd_fee_min()) . ' 元）' : '' ?>
        <?php else: ?>
          ，免手续费
        <?php endif; ?>
      </span>
    </div>
    <div class="card-body">
      <?php if ($wdQuota['quota'] < wd_min()): ?>
        <div class="alert alert-error" style="margin:0">
          当前可提现额度 ￥<?= wd_money($wdQuota['quota']) ?>，不足单笔最低 <?= wd_min() ?> 元。<br>
          <span class="hint">注册赠送的余额不参与提现，只有充值到账和推介返现的部分可以提。</span>
        </div>
      <?php else: ?>
      <form method="post" id="wdForm">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="tab" value="withdraw">
        <input type="hidden" name="act" value="withdraw">
        <input type="hidden" name="qr_upload_id" id="wdQrId" value="">

        <div class="form-grid">
          <label class="field"><span class="field-label">提现金额（元）</span>
            <input class="input" type="text" name="amount" id="wdAmount" inputmode="numeric"
                   placeholder="整数，最多 <?= $wdQuota['quota'] ?> 元" autocomplete="off" required>
            <span class="hint" id="wdCalc">可提现 <?= wd_money($wdQuota['quota']) ?> 元</span></label>

          <label class="field"><span class="field-label">收款方式</span>
            <select class="input" name="method" required>
              <option value="alipay">支付宝</option>
              <option value="wechat">微信</option>
            </select></label>

          <label class="field"><span class="field-label">收款人姓名</span>
            <input class="input" type="text" name="real_name" maxlength="50"
                   placeholder="与收款账号一致的真实姓名" required></label>

          <label class="field"><span class="field-label">手机号</span>
            <input class="input" type="text" name="phone" maxlength="11" inputmode="numeric"
                   placeholder="11 位手机号，便于联系" required></label>
        </div>

        <label class="field mt-16"><span class="field-label">提现理由</span>
          <textarea class="input" name="reason" rows="3" maxlength="500"
                    placeholder="简单说明提现原因" required></textarea></label>

        <label class="field mt-16"><span class="field-label">收款码</span>
          <input class="input" type="file" id="wdQrFile" accept="image/*"></label>
        <div class="hint" id="wdQrHint">上传支付宝或微信的收款二维码截图，仅管理员可见</div>
        <div id="wdQrPreview" style="margin-top:8px"></div>

        <div class="form-actions mt-16">
          <button class="btn btn-primary" type="submit" id="wdSubmit">提交申请</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      提现记录
      <span class="hint" style="margin:0">最近 20 条。驳回的金额会自动退回余额</span>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>时间</th><th>金额</th><th>手续费</th><th>到手</th><th>方式</th><th>状态</th><th>备注</th></tr>
        </thead>
        <tbody>
        <?php if (!$wdList): ?>
          <tr><td colspan="7" class="empty">还没有提现记录</td></tr>
        <?php else: ?>
          <?php foreach ($wdList as $w): ?>
            <tr>
              <td><?= h($w['created_at']) ?></td>
              <td>￥<?= wd_money($w['amount']) ?></td>
              <td><?= (float) $w['fee'] > 0 ? '￥' . wd_money($w['fee']) : '免' ?></td>
              <td>￥<?= wd_money($w['actual']) ?></td>
              <td><?= h(wd_method_text($w['method'])) ?></td>
              <td>
                <?php $st = (string) $w['status']; ?>
                <span class="badge badge-<?= $st === 'done' ? 'ok' : ($st === 'rejected' ? 'red' : 'off') ?>">
                  <?= h(wd_status_text($st)) ?>
                </span>
              </td>
              <td><?= $w['admin_note'] !== '' ? h($w['admin_note']) : '<span class="hint">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'profile'): ?>
  <div class="card">
    <div class="card-head">
      余额流水
      <span class="hint" style="margin:0">记录充值、赠送与对话消费；每次对话的 token 明细见「<a href="/usage.php">用量</a>」</span>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>时间</th><th>类型</th><th>变动金额</th><th>变动后余额</th><th>说明</th></tr>
        </thead>
        <tbody>
        <?php if (!$blogs): ?>
          <tr><td colspan="5" class="empty">暂无余额变动记录</td></tr>
        <?php else: foreach ($blogs as $b): ?>
          <tr>
            <td class="nowrap"><?= h($b['created_at']) ?></td>
            <td><?= h(blog_type_name((string) $b['type'])) ?></td>
            <td class="<?= (float) $b['amount'] >= 0 ? 'text-ok' : 'text-err' ?>">
              <?= (float) $b['amount'] >= 0 ? '+' : '' ?><?= money($b['amount']) ?>
            </td>
            <td>￥<?= money($b['balance_after']) ?></td>
            <td><?= h($b['note']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($bpages > 1): ?>
    <div class="pager">
      <?php for ($i = max(1, $bpage - 3); $i <= min($bpages, $bpage + 3); $i++): ?>
        <?php if ($i === $bpage): ?><span class="on"><?= $i ?></span>
        <?php else: ?><a href="?tab=profile&p=<?= $i ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'aff' && $affStat !== null): ?>
  <div class="stat-grid">
    <div class="stat">
      <div class="stat-label">已邀请人数</div>
      <div class="stat-value"><?= fmt_int($affStat['invited']) ?></div>
      <div class="stat-sub">通过我的链接或邀请码注册</div>
    </div>
    <div class="stat">
      <div class="stat-label">累计返现</div>
      <div class="stat-value">￥<?= money($affStat['commission_sum']) ?></div>
      <div class="stat-sub"><?= fmt_int($affStat['commission_n']) ?> 笔返现已入账</div>
    </div>
    <div class="stat">
      <div class="stat-label">下级充值总额</div>
      <div class="stat-value">￥<?= money($affStat['recharge_sum']) ?></div>
      <div class="stat-sub"><?= fmt_int($affStat['recharge_n']) ?> 笔已支付订单</div>
    </div>
    <div class="stat">
      <div class="stat-label">当前返现比例</div>
      <div class="stat-value"><?= rtrim(rtrim(number_format(aff_rate(), 2, '.', ''), '0'), '.') ?>%</div>
      <div class="stat-sub">按下级实付金额计算</div>
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-head">
      我的推介链接
      <span class="hint" style="margin:0">别人从这个链接注册就会自动绑定到你名下</span>
    </div>
    <div class="card-body">
      <div class="field field-inline">
        <span class="field-label">邀请码</span>
        <input class="input" type="text" id="affCode" value="<?= h($affCode) ?>" readonly
               style="font-family:monospace;font-size:18px;letter-spacing:3px;max-width:220px">
      </div>
      <div class="field field-inline">
        <span class="field-label">推介链接</span>
        <input class="input" type="text" id="affUrl" value="<?= h($affUrl) ?>" readonly
               onclick="this.select()">
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="button" data-copy="affUrl">复制链接</button>
        <button class="btn" type="button" data-copy="affCode">复制邀请码</button>
      </div>
      <div class="hint mt-16">
        下级每次充值成功，你都会按当前比例拿到返现，直接进你的账户余额。
        返现基数是下级的实付金额，不含站点赠送部分。
      </div>
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-head">
      我邀请的人
      <span class="hint" style="margin:0">共 <?= fmt_int($affStat['invited']) ?> 人</span>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>用户 ID</th><th>注册时间</th><th class="nowrap">充值次数</th>
              <th class="nowrap">累计充值</th><th class="nowrap">为我贡献返现</th><th>状态</th></tr>
        </thead>
        <tbody>
        <?php if (!$affInvitees): ?>
          <tr><td colspan="6" class="empty">还没有人通过你的链接注册，把链接分享出去试试</td></tr>
        <?php else: foreach ($affInvitees as $v): ?>
          <tr>
            <td><?= h($v['username']) ?></td>
            <td class="nowrap"><?= h(substr((string) ($v['referred_at'] ?: $v['created_at']), 0, 16)) ?></td>
            <td><?= fmt_int($v['recharge_n']) ?></td>
            <td>￥<?= money($v['recharge_sum']) ?></td>
            <td style="color:var(--ok,#16a34a)">￥<?= money($v['commission_sum']) ?></td>
            <td><?= (int) $v['status'] === 1
                  ? '<span class="badge badge-blue">正常</span>'
                  : '<span class="badge">已停用</span>' ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($affPages > 1): ?>
    <div class="pager">
      <?php for ($i = max(1, $affPage - 3); $i <= min($affPages, $affPage + 3); $i++): ?>
        <?php if ($i === $affPage): ?><span class="on"><?= $i ?></span>
        <?php else: ?><a href="?tab=aff&ap=<?= $i ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head">
      返现明细
      <span class="hint" style="margin:0">最近 30 条，返现同时会在「个人中心 - 余额流水」里出现</span>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>时间</th><th>来自用户 ID</th><th class="nowrap">对方实付</th>
              <th class="nowrap">比例</th><th class="nowrap">返现金额</th><th>订单号</th></tr>
        </thead>
        <tbody>
        <?php if (!$affComms): ?>
          <tr><td colspan="6" class="empty">暂无返现记录</td></tr>
        <?php else: foreach ($affComms as $c): ?>
          <tr>
            <td class="nowrap"><?= h($c['created_at']) ?></td>
            <td><?= h($c['from_username'] ?? ('#' . $c['from_user_id'])) ?></td>
            <td>￥<?= money($c['recharge_amount']) ?></td>
            <td><?= rtrim(rtrim(number_format((float) $c['rate'], 2, '.', ''), '0'), '.') ?>%</td>
            <td style="color:var(--ok,#16a34a)">+￥<?= money($c['amount']) ?></td>
            <td class="nowrap" style="font-family:monospace;font-size:12px"><?= h($c['order_no']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'favs'): ?>
  <div class="card">
    <div class="card-head">
      我的收藏
      <span class="hint" style="margin:0">收藏的 AI 回答和你的消息，最多 200 条</span>
    </div>
    <div class="card-body" id="favList">
      <?php if (!$favList): ?>
        <div class="empty" style="padding:40px 0;text-align:center;color:var(--c-muted)">
          还没有收藏任何消息<br>
          <span style="font-size:13px">在对话页面中，鼠标悬停到 AI 回答上，点击 ☆ 即可收藏</span>
        </div>
      <?php else: ?>
        <div class="fav-list">
          <?php foreach ($favList as $f): ?>
          <div class="fav-item" data-fav-id="<?= (int) $f['id'] ?>" data-msg-id="<?= (int) $f['msg_id'] ?>">
            <div class="fav-item-head">
              <span class="badge badge-<?= $f['role'] === 'user' ? 'blue' : 'ok' ?>">
                <?= $f['role'] === 'user' ? '我的消息' : 'AI 回答' ?>
              </span>
              <?php if ($f['conv_title']): ?>
              <a href="/chat.php?conv=<?= (int) $f['conv_id'] ?>" class="fav-conv-link">
                <?= h(mb_substr($f['conv_title'], 0, 40)) ?>
              </a>
              <?php endif; ?>
              <span class="fav-date"><?= h(substr($f['created_at'], 0, 16)) ?></span>
              <button class="btn btn-sm fav-del" type="button" data-fav-id="<?= (int) $f['id'] ?>">取消收藏</button>
            </div>
            <div class="fav-preview"><?= nl2br(h($f['preview'])) ?></div>
            <?php if ($f['content_length'] > 500): ?>
            <button class="btn btn-sm fav-expand" type="button">展开全部（<?= (int) $f['content_length'] ?> 字）</button>
            <?php endif; ?>
            <div class="fav-full" hidden><?= nl2br(h($f['content'])) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  </div><!-- /profile-content -->
</div>
</div>
<?php if ($generated_key): ?>
<div class="modal" id="keyModal">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="keyModalTitle">
    <div class="modal-head">
      <h3 id="keyModalTitle">保存好你的新密钥</h3>
      <button class="modal-x" type="button" data-key-close aria-label="关闭">×</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info" style="margin-bottom:14px">
        明文只显示这一次。关掉这个窗口就再也看不到了，请先复制保存。
      </div>
      <input type="text" id="keyText" class="input" readonly
             value="<?= h($generated_key) ?>"
             style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px"
             onclick="this.select()">
      <div class="modal-foot">
        <button class="btn" type="button" data-key-close>我已保存</button>
        <button class="btn btn-primary" type="button" id="keyCopy">复制密钥</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($generated_sk): ?>
<div class="modal" id="skCardModal">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="skCardModalTitle">
    <div class="modal-head">
      <h3 id="skCardModalTitle">保存好你的新 SK 卡密</h3>
      <button class="modal-x" type="button" data-sk-close aria-label="关闭">×</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info" style="margin-bottom:14px">
        明文只显示这一次。关掉这个窗口就再也看不到了，请先复制保存。
      </div>
      <input type="text" id="skCardText" class="input" readonly
             value="<?= h($generated_sk) ?>"
             style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px"
             onclick="this.select()">
      <div class="modal-foot">
        <button class="btn" type="button" data-sk-close>我已保存</button>
        <button class="btn btn-primary" type="button" id="skCardCopy">复制卡密</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<div id="profile-scripts">
<script>
(function () {
  var m = document.getElementById('keyModal');
  if (!m) return;
  var input = document.getElementById('keyText');
  var copyBtn = document.getElementById('keyCopy');
  // 关闭后明文就再也拿不到了，所以进页面直接弹出来
  input.focus();
  input.select();
  function close() { m.remove(); }
  copyBtn.addEventListener('click', function () {
    input.select();
    var done = function () {
      copyBtn.textContent = '已复制';
      setTimeout(function () { copyBtn.textContent = '复制密钥'; }, 1600);
    };
    // 优先用剪贴板 API，非 HTTPS 或旧浏览器下退回 execCommand
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(done, function () {
        try { document.execCommand('copy'); done(); } catch (e) {
          copyBtn.textContent = '请手动复制';
        }
      });
    } else {
      try { document.execCommand('copy'); done(); } catch (e) {
        copyBtn.textContent = '请手动复制';
      }
    }
  });
  m.addEventListener('click', function (e) {
    if (e.target === m || e.target.closest('[data-key-close]')) close();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });
})();

// SK 卡密生成成功弹窗
(function () {
  var m = document.getElementById('skCardModal');
  if (!m) return;
  var input = document.getElementById('skCardText');
  var copyBtn = document.getElementById('skCardCopy');
  input.focus();
  input.select();
  function close() { m.remove(); }
  copyBtn.addEventListener('click', function () {
    input.select();
    var done = function () {
      copyBtn.textContent = '已复制';
      setTimeout(function () { copyBtn.textContent = '复制卡密'; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(done, function () {
        try { document.execCommand('copy'); done(); } catch (e) {
          copyBtn.textContent = '请手动复制';
        }
      });
    } else {
      try { document.execCommand('copy'); done(); } catch (e) {
        copyBtn.textContent = '请手动复制';
      }
    }
  });
  m.addEventListener('click', function (e) {
    if (e.target === m || e.target.closest('[data-sk-close]')) close();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });
})();
</script>

<?php if ($tab === 'aff'): ?>
<script>
// 推介链接/邀请码的复制按钮
document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var el = document.getElementById(btn.getAttribute('data-copy'));
    if (!el) return;
    var old = btn.textContent;
    function done() {
      btn.textContent = '已复制';
      setTimeout(function () { btn.textContent = old; }, 1500);
    }
    // navigator.clipboard 只在 https 或 localhost 下可用，失败回退到 execCommand
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(el.value).then(done, function () {
        el.select();
        try { document.execCommand('copy'); done(); } catch (e) { btn.textContent = '请手动复制'; }
      });
    } else {
      el.select();
      try { document.execCommand('copy'); done(); } catch (e) { btn.textContent = '请手动复制'; }
    }
  });
});
</script>
<?php endif; ?>

<?php if ($tab === 'withdraw' && $wdQuota !== null && $wdQuota['quota'] >= wd_min()): ?>
<script>
(function () {
  var 配置 = {
    额度: <?= (int) $wdQuota['quota'] ?>,
    最低: <?= wd_min() ?>,
    最高: <?= wd_max() ?>,
    费率: <?= wd_fee_rate() ?>,
    费用下限: <?= wd_fee_min() ?>
  };
  var 金额框 = document.getElementById('wdAmount');
  var 试算区 = document.getElementById('wdCalc');
  var 文件框 = document.getElementById('wdQrFile');
  var 隐藏ID = document.getElementById('wdQrId');
  var 提示 = document.getElementById('wdQrHint');
  var 预览 = document.getElementById('wdQrPreview');
  var 表单 = document.getElementById('wdForm');
  var 提交钮 = document.getElementById('wdSubmit');
  var csrf = '<?= h($csrf) ?>';

  function 两位(n) { return (Math.round(n * 100) / 100).toFixed(2); }

  // 手续费试算，口径和服务端 wd_calc_fee 保持一致
  function 试算(额) {
    var 费 = Math.round(额 * 配置.费率) / 100;
    if (配置.费用下限 > 0 && 费 < 配置.费用下限) 费 = 配置.费用下限;
    if (费 >= 额) 费 = Math.max(0, 额 - 0.01);
    return { fee: 费, actual: 额 - 费 };
  }

  function 刷新() {
    var 原 = 金额框.value;
    // 只留数字，从根上杜绝小数点
    var 净 = 原.replace(/[^0-9]/g, '').replace(/^0+(?=\d)/, '');
    if (净 !== 原) 金额框.value = 净;

    if (净 === '') {
      试算区.textContent = '可提现 ' + 配置.额度 + ' 元';
      试算区.className = 'hint';
      return;
    }
    var 额 = parseInt(净, 10);
    if (额 < 配置.最低) {
      试算区.textContent = '单笔最低 ' + 配置.最低 + ' 元';
      试算区.className = 'hint error-text';
      return;
    }
    if (配置.最高 > 0 && 额 > 配置.最高) {
      试算区.textContent = '单笔最高 ' + 配置.最高 + ' 元';
      试算区.className = 'hint error-text';
      return;
    }
    if (额 > 配置.额度) {
      试算区.textContent = '超出可提现额度（' + 配置.额度 + ' 元），注册赠送的余额不可提现';
      试算区.className = 'hint error-text';
      return;
    }
    var r = 试算(额);
    试算区.textContent = '手续费 ' + 两位(r.fee) + ' 元，实际到手 ' + 两位(r.actual) + ' 元';
    试算区.className = 'hint';
  }

  金额框.addEventListener('input', 刷新);
  金额框.addEventListener('blur', 刷新);
  刷新();

  // 收款码上传，走通用上传接口，落盘在 webroot 之外
  文件框.addEventListener('change', function () {
    var f = 文件框.files && 文件框.files[0];
    if (!f) return;
    if (!/^image\//.test(f.type)) {
      提示.textContent = '只能上传图片';
      提示.className = 'hint error-text';
      return;
    }
    提示.textContent = '正在上传…';
    提示.className = 'hint';
    提交钮.disabled = true;

    var fd = new FormData();
    fd.append('file', f);
    fd.append('csrf', csrf);
    fetch('/api/upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        提交钮.disabled = false;
        if (!d || !d.id) {
          提示.textContent = (d && d.error) ? d.error : '上传失败，请重试';
          提示.className = 'hint error-text';
          return;
        }
        隐藏ID.value = d.id;
        提示.textContent = '收款码已上传';
        提示.className = 'hint';
        预览.innerHTML = '';
        var img = document.createElement('img');
        img.src = d.url;
        img.alt = '收款码';
        img.style.cssText = 'max-width:160px;border-radius:8px;border:1px solid var(--line,#333)';
        预览.appendChild(img);
      })
      .catch(function () {
        提交钮.disabled = false;
        提示.textContent = '上传失败，请检查网络后重试';
        提示.className = 'hint error-text';
      });
  });

  // 提交前兜一道，避免白跑一次请求
  表单.addEventListener('submit', function (e) {
    if (!/^\d+$/.test(金额框.value)) {
      e.preventDefault();
      提示.scrollIntoView({ block: 'center' });
      试算区.textContent = '提现金额必须是整数';
      试算区.className = 'hint error-text';
      return;
    }
    if (!隐藏ID.value) {
      e.preventDefault();
      提示.textContent = '请先上传收款码';
      提示.className = 'hint error-text';
      文件框.scrollIntoView({ block: 'center' });
    }
  });
})();
</script>
<?php endif; ?>
<script>
// QQ 头像实时预览：输入 QQ 号时立即显示头像
  var input = document.getElementById('avatarQqInput');
  var wrap  = document.getElementById('avatarPreviewWrap');
  var img   = document.getElementById('avatarPreviewImg');
  if (!input || !wrap || !img) return;
  function 刷新预览() {
    var qq = input.value.trim();
    if (/^[1-9]\d{4,11}$/.test(qq)) {
      img.src = 'https://q1.qlogo.cn/g?b=qq&nk=' + encodeURIComponent(qq) + '&s=100';
      wrap.style.display = '';
    } else {
      wrap.style.display = 'none';
    }
  }
  input.addEventListener('input', 刷新预览);
  刷新预览();
})();
</script>
<?php if ($tab === 'favs' && $favList): ?>
<script>
// 收藏列表交互：取消收藏 + 展开全文
  var box = document.getElementById('favList');
  if (!box) return;
  var csrf = <?= json_encode(csrf_token()) ?>;

  box.addEventListener('click', function (e) {
    // 取消收藏
    var delBtn = e.target.closest('.fav-del');
    if (delBtn) {
      var favId = delBtn.getAttribute('data-fav-id');
      if (!confirm('确定取消收藏？')) return;
      var fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('act', 'remove');
      fd.append('fav_id', favId);
      fetch('/api/favorite.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.ok) {
            var item = delBtn.closest('.fav-item');
            if (item) item.remove();
            if (!box.querySelector('.fav-item')) {
              box.innerHTML = '<div class="empty" style="padding:40px 0;text-align:center;color:var(--c-muted)">还没有收藏任何消息</div>';
            }
          }
        })
        .catch(function () {});
      return;
    }
    // 展开全文
    var expBtn = e.target.closest('.fav-expand');
    if (expBtn) {
      var item2 = expBtn.closest('.fav-item');
      var full = item2 ? item2.querySelector('.fav-full') : null;
      var prev = item2 ? item2.querySelector('.fav-preview') : null;
      if (full && prev) {
        if (full.hidden) {
          full.hidden = false;
          prev.hidden = true;
          expBtn.textContent = '收起';
        } else {
          full.hidden = true;
          prev.hidden = false;
          expBtn.textContent = '展开全部';
        }
      }
    }
  });
})();
</script>
<?php endif; ?>
</div><!-- /profile-scripts -->
<script>
(function () {
  var nav = document.querySelector('.topbar-nav');
  var content = document.getElementById('profile-content');
  var scriptsBox = document.getElementById('profile-scripts');
  if (!nav || !content || !scriptsBox) return;

  var isLoading = false;

  function updateActive(tab) {
    nav.querySelectorAll('a').forEach(function (a) {
      a.classList.toggle('on', a.getAttribute('data-tab') === tab);
    });
    var activeLink = nav.querySelector('a.on');
    if (activeLink) {
      var titleEl = document.querySelector('.page-title');
      if (titleEl) titleEl.textContent = activeLink.textContent;
    }
  }

  function switchTab(url, tab) {
    if (isLoading) return;
    isLoading = true;
    content.style.opacity = '0.5';

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var newContent = doc.getElementById('profile-content');
        var newScripts = doc.getElementById('profile-scripts');

        if (newContent) {
          content.innerHTML = newContent.innerHTML;
        }

        // 清空旧脚本，重新创建 script 元素使其能执行
        scriptsBox.innerHTML = '';
        if (newScripts) {
          newScripts.querySelectorAll('script').forEach(function (oldScript) {
            var s = document.createElement('script');
            if (oldScript.src) {
              s.src = oldScript.src;
            } else {
              s.textContent = oldScript.textContent;
            }
            scriptsBox.appendChild(s);
          });
        }

        content.style.opacity = '1';
        isLoading = false;
        if (tab) updateActive(tab);
      })
      .catch(function () {
        // 网络错误时回退到普通跳转
        content.style.opacity = '1';
        isLoading = false;
        window.location.href = url;
      });
  }

  // 拦截标签导航点击
  nav.addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (!a) return;
    e.preventDefault();
    var href = a.getAttribute('href');
    var tab = a.getAttribute('data-tab') || 'profile';
    switchTab(href, tab);
    history.pushState({ url: href, tab: tab }, '', href);
  });

  // 拦截内容区分页链接点击
  content.addEventListener('click', function (e) {
    var a = e.target.closest('.pager a');
    if (!a) return;
    e.preventDefault();
    var href = a.getAttribute('href');
    var url = new URL(href, window.location.href).href;
    switchTab(url, null);
    history.pushState({ url: url, tab: null }, '', url);
  });

  // 浏览器前进/后退
  window.addEventListener('popstate', function (e) {
    if (e.state && e.state.url) {
      switchTab(e.state.url, e.state.tab);
    }
  });

  // 记录初始状态
  var initialTab = new URLSearchParams(window.location.search).get('tab') || 'profile';
  history.replaceState({ url: window.location.href, tab: initialTab }, '', window.location.href);
})();
</script>
</body>
</html>
