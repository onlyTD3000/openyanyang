<?php
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/email.php';
start_session();

$me = current_user();
if (!$me) {
    header('Location: /login.php');
    exit;
}

// 已完成验证直接跳转
if ((int) $me['status'] === 1) {
    header('Location: /chat.php');
    exit;
}

// 没绑定邮箱
if (empty($me['email'])) {
    die('你的账号未绑定邮箱，无法进行邮箱验证。请联系管理员。');
}

$siteName = app_name();
$needVerify = setting_get('email_verify_enabled', '0') === '1';
if (!$needVerify) {
    header('Location: /chat.php');
    exit;
}

$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $token = $_POST['csrf'] ?? '';

    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $token)) {
        $err = '页面已过期，请重试';
    } elseif ($act === 'verify') {
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
            $err = '请输入 6 位验证码';
        } else {
            $row = db_one(
                'SELECT * FROM email_verifications WHERE user_id = ? AND email = ? AND code = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
                [$me['id'], $me['email'], $code]
            );
            if (!$row) {
                $err = '验证码无效或已过期，请重新获取';
            } else {
                db_exec('UPDATE users SET status = 1 WHERE id = ?', [$me['id']]);
                db_exec('DELETE FROM email_verifications WHERE user_id = ?', [$me['id']]);
                // 更新会话
                $_SESSION['uid'] = (int) $me['id'];
                header('Location: /chat.php');
                exit;
            }
        }
    } elseif ($act === 'resend') {
        // 频率限制
        $last = db_val(
            'SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$me['id']]
        );
        if ($last && time() - strtotime($last) < 60) {
            $left = 60 - (time() - strtotime($last));
            $err = "发送过于频繁，请 {$left} 秒后再试";
        } else {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            db_exec('INSERT INTO email_verifications (user_id, email, code, expires_at, created_at)
                     VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())',
                [$me['id'], $me['email'], $code]);

            $body = email_verify_template($siteName, $me['username'], $code);
            $subject = '[' . $siteName . '] 邮箱验证码：' . $code;
            [$ok, $sendErr] = email_send($me['email'], $subject, $body, '注册验证(重发)', (int) $me['id']);

            if ($ok) {
                $msg = '新验证码已发送至您的邮箱 ' . h($me['email']);
            } else {
                $err = '邮件发送失败：' . $sendErr;
            }
        }
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>邮箱验证 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<style>
  .verify-box { text-align: center; }
  .verify-icon { font-size: 48px; margin-bottom: 12px; }
  .verify-title { font-size: 20px; font-weight: 600; color: #1f2937; margin-bottom: 8px; }
  .verify-sub { font-size: 14px; color: #6b7280; margin-bottom: 24px; line-height: 1.6; }
  .verify-email { font-weight: 600; color: #6366f1; }
  .code-input { text-align: center; font-size: 24px; letter-spacing: 8px; font-family: monospace; max-width: 220px; margin: 0 auto; }
  .resend-link { color: #6366f1; text-decoration: none; font-size: 14px; }
  .resend-link:hover { text-decoration: underline; }
  .timer { color: #9ca3af; font-size: 14px; }
</style>
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><span class="logo-dot"></span><?= h($siteName) ?></div>

  <div class="verify-box">
    <div class="verify-icon">📧</div>
    <h1 class="verify-title">验证你的邮箱</h1>
    <p class="verify-sub">
      验证码已发送至 <span class="verify-email"><?= h($me['email']) ?></span><br>
      请在下方输入 6 位验证码完成验证
    </p>
  </div>

  <?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>

  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="act" value="verify">
    <label class="field">
      <span class="field-label" style="text-align:center">验证码</span>
      <input class="input code-input" type="text" name="code" required maxlength="6"
             placeholder="000000" autocomplete="one-time-code" autofocus>
    </label>
    <button class="btn btn-primary btn-block" type="submit" style="margin-top:8px">验证邮箱</button>
  </form>

  <div style="text-align:center;margin-top:16px">
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="resend">
      <button type="submit" class="resend-link" style="background:none;border:none;cursor:pointer"
              id="resendBtn">重新发送验证码</button>
    </form>
    <span class="timer" id="timer"></span>
  </div>

  <p class="auth-foot" style="margin-top:20px">
    <a href="/logout.php">退出登录</a>
  </p>
</div>

<script>
// 60 秒倒计时
(function() {
  var btn = document.getElementById('resendBtn');
  var timer = document.getElementById('timer');
  var left = 60;
  function tick() {
    if (left <= 0) {
      timer.textContent = '';
      btn.disabled = false;
      return;
    }
    timer.textContent = '(' + left + ' 秒后可重发)';
    btn.disabled = true;
    left--;
    setTimeout(tick, 1000);
  }
  tick();
})();
</script>
</body>
</html>