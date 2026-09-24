<?php
/**
 * 找回密码：邮箱验证码 → 设置新密码。
 *
 * 分三步，都在这一个页面里切换：
 *   1 填邮箱 + 图形验证码，发邮箱验证码
 *   2 填 6 位验证码，通过后把邮箱写进 session
 *   3 输两次新密码，改完清掉标记
 *
 * 第 3 步凭 session 里的通过标记放行，不再重复校验验证码——码在第 2 步
 * 就烧掉了。标记带 15 分钟时限，避免有人开着页面过很久再来改。
 *
 * 安全上有一处是刻意的：不管邮箱是否注册过，第 1 步都回同样的提示。
 * 否则这个页面就成了账号探测器，能拿邮箱列表跑一遍看谁注册过本站。
 */
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/email.php';
// 已登录的直接去个人中心改密码，不用走这套
if (current_user()) {
    header('Location: /profile.php');
    exit;
}
start_session();
$csrf = csrf_token();
$siteName = app_name();
$step = (int) ($_POST['step'] ?? $_GET['step'] ?? 1);
$err = '';
$ok = '';
$email = trim((string) ($_POST['email'] ?? $_SESSION['forgot_email'] ?? ''));
/** 验证通过的标记还有效吗 */
function 重置许可有效(): bool
{
    $p = $_SESSION['forgot_pass'] ?? null;
    return is_array($p)
        && !empty($p['email'])
        && (int) ($p['at'] ?? 0) > time() - 900;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $动作 = (string) ($_POST['act'] ?? '');
    /* ---------- 第 2 步：校验邮箱验证码 ---------- */
    if ($动作 === 'verify_code') {
        $code = trim((string) ($_POST['code'] ?? ''));
        // 校验失败次数记在 session 里，防止对着一个邮箱穷举 6 位码
        $_SESSION['forgot_try'] = array_values(array_filter(
            $_SESSION['forgot_try'] ?? [],
            fn($t) => $t > time() - 600
        ));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = '请先填写邮箱';
            $step = 1;
        } elseif (count($_SESSION['forgot_try']) >= 10) {
            $err = '尝试次数过多，请 10 分钟后再试';
            $step = 2;
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $err = '验证码为 6 位数字';
            $step = 2;
        } else {
            $_SESSION['forgot_try'][] = time();
            // 只认 reset_pw 场景：登录码和注册码都不能拿来改密码
            $行 = db_one('SELECT * FROM email_verifications
                           WHERE email = ? AND scene = "reset_pw" AND used = 0
                           ORDER BY id DESC LIMIT 1', [$email]);
            if (!$行) {
                $err = '验证码无效或已过期，请重新获取';
                $step = 2;
            } elseif (strtotime($行['expires_at']) < time()) {
                $err = '验证码已过期，请重新获取';
                $step = 2;
            } elseif ((int) $行['attempts'] >= 5) {
                db_exec('UPDATE email_verifications SET used = 1 WHERE id = ?', [$行['id']]);
                $err = '错误次数过多，请重新获取验证码';
                $step = 2;
            } elseif (!hash_equals((string) $行['code'], $code)) {
                db_exec('UPDATE email_verifications SET attempts = attempts + 1 WHERE id = ?', [$行['id']]);
                $剩余 = 5 - ((int) $行['attempts'] + 1);
                $err = '验证码不正确' . ($剩余 > 0 ? "，还可尝试 {$剩余} 次" : '');
                $step = 2;
            } else {
                // 码一次性，先烧掉再放行
                db_exec('UPDATE email_verifications SET used = 1 WHERE id = ?', [$行['id']]);
                $_SESSION['forgot_pass'] = ['email' => $email, 'at' => time()];
                unset($_SESSION['forgot_try']);
                $step = 3;
                $ok = '验证通过，请设置新密码';
            }
        }
    }
    /* ---------- 第 3 步：写入新密码 ---------- */
    if ($动作 === 'set_password') {
        if (!重置许可有效()) {
            $err = '验证已过期，请重新走一遍找回流程';
            $step = 1;
            unset($_SESSION['forgot_pass']);
        } else {
            $许可邮箱 = (string) $_SESSION['forgot_pass']['email'];
            $新1 = (string) ($_POST['password'] ?? '');
            $新2 = (string) ($_POST['password2'] ?? '');
            if ($新1 === '' || $新2 === '') {
                $err = '请输入两次新密码';
                $step = 3;
            } elseif (mb_strlen($新1) < 6) {
                $err = '密码至少 6 位';
                $step = 3;
            } elseif (mb_strlen($新1) > 64) {
                $err = '密码最长 64 位';
                $step = 3;
            } elseif ($新1 !== $新2) {
                $err = '两次输入的密码不一致，请重新输入';
                $step = 3;
            } else {
                $u = db_one('SELECT id, username, status FROM users WHERE email = ? LIMIT 1', [$许可邮箱]);
                if (!$u) {
                    // 走到这步还找不到账号，说明期间账号被删或邮箱被改了
                    $err = '该邮箱对应的账号不存在，请联系管理员';
                    $step = 1;
                    unset($_SESSION['forgot_pass']);
                } elseif ((int) $u['status'] !== 1) {
                    $err = '该账号已被停用，无法重置密码';
                    $step = 1;
                    unset($_SESSION['forgot_pass']);
                } else {
                    db_exec('UPDATE users SET password_hash = ? WHERE id = ?',
                        [password_hash($新1, PASSWORD_DEFAULT), (int) $u['id']]);
                    // 改密后把这个账号所有未用的重置码作废，避免留后手
                    db_exec('UPDATE email_verifications SET used = 1
                              WHERE email = ? AND scene = "reset_pw" AND used = 0', [$许可邮箱]);
                    unset($_SESSION['forgot_pass'], $_SESSION['forgot_email'], $_SESSION['forgot_try']);
                    // 换个会话 ID，防止有人拿改密前的 session 继续用
                    session_regenerate_id(true);
                    $step = 4;
                    $ok = '密码已重置成功，现在可以用新密码登录了';
                }
            }
        }
    }
}
// 第 3 步只能凭有效许可进入，直接敲 ?step=3 会被打回第 1 步
if ($step === 3 && !重置许可有效()) {
    $step = 1;
    $err = $err !== '' ? $err : '请先完成邮箱验证';
}
if ($step === 2 && $email === '') {
    $step = 1;
}
if ($email !== '') {
    $_SESSION['forgot_email'] = $email;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>找回密码 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><span class="logo-dot"></span><?= h($siteName) ?></div>
  <h1 class="auth-title">找回密码</h1>
  <p class="auth-sub">
    <?php if ($step === 1): ?>输入注册时绑定的邮箱，我们会发一封验证码给你
    <?php elseif ($step === 2): ?>验证码已发往 <?= h($email) ?>
    <?php elseif ($step === 3): ?>为账号设置一个新密码
    <?php else: ?>重置完成
    <?php endif; ?>
  </p>
  <?php if ($err !== ''): ?>
    <div class="alert alert-error"><?= h($err) ?></div>
  <?php endif; ?>
  <?php if ($ok !== '' && $step !== 4): ?>
    <div class="alert alert-ok"><?= h($ok) ?></div>
  <?php endif; ?>
  <?php if ($step === 1 || $step === 2): ?>
  <form method="post" class="form" id="forgotForm">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="act" value="verify_code">
    <input type="hidden" name="step" value="2">
    <label class="field field-float">
      <input class="input" type="email" name="email" id="fgEmail" value="<?= h($email) ?>"
             placeholder=" " required<?= $step === 1 ? ' autofocus' : '' ?>>
      <span class="float-label">注册时绑定的邮箱</span>
    </label>
    <label class="field">
      <div class="captcha-row">
        <span class="field field-float" style="flex:1; min-width:0">
          <input class="input" type="text" id="fgCaptcha" maxlength="4" placeholder=" "
                 style="letter-spacing:4px">
          <span class="float-label" style="letter-spacing:normal">图中 4 位数字</span>
        </span>
        <img class="captcha-img" src="/captcha.php" alt="验证码" title="点击刷新" id="fgCaptchaImg"
             onclick="this.src='/captcha.php?t='+Date.now()">
      </div>
      <div class="captcha-tip">先填图形验证码，再获取邮箱验证码</div>
    </label>
    <label class="field">
      <div class="code-row">
        <span class="field field-float" style="flex:1; min-width:0">
          <input class="input" type="text" name="code" id="fgCode" maxlength="6" placeholder=" "
                 inputmode="numeric" style="letter-spacing:4px"<?= $step === 2 ? ' autofocus' : '' ?>>
          <span class="float-label" style="letter-spacing:normal">6 位邮箱验证码</span>
        </span>
        <button type="button" class="btn-code" id="fgSend">获取验证码</button>
      </div>
      <div class="code-msg" id="fgMsg"></div>
    </label>
    <button class="btn btn-primary btn-block" type="submit">下一步</button>
  </form>
  <?php endif; ?>
  <?php if ($step === 3): ?>
  <form method="post" class="form" id="pwForm">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="act" value="set_password">
    <input type="hidden" name="step" value="3">
    <div class="hint mb-16">正在为 <strong><?= h((string) $_SESSION['forgot_pass']['email']) ?></strong> 重置密码</div>
    <label class="field field-float">
      <input class="input" type="password" name="password" id="pw1" minlength="6" maxlength="64"
             placeholder=" " autocomplete="new-password" required autofocus>
      <span class="float-label">新密码（至少 6 位）</span>
    </label>
    <label class="field field-float">
      <input class="input" type="password" name="password2" id="pw2" minlength="6" maxlength="64"
             placeholder=" " autocomplete="new-password" required>
      <span class="float-label">确认新密码</span>
      <div class="code-msg" id="pwMsg"></div>
    </label>
    <button class="btn btn-primary btn-block" type="submit" id="pwSubmit">重置密码</button>
  </form>
  <?php endif; ?>
  <?php if ($step === 4): ?>
    <div class="alert alert-ok"><?= h($ok) ?></div>
    <a class="btn btn-primary btn-block" href="/login.php">去登录</a>
  <?php endif; ?>
  <p class="auth-foot">
    <?php if ($step !== 4): ?>想起密码了？<a href="/login.php">返回登录</a><?php endif; ?>
  </p>
</div>
<script>
(function () {
  var 邮箱 = document.getElementById('fgEmail');
  var 图形码 = document.getElementById('fgCaptcha');
  var 发送钮 = document.getElementById('fgSend');
  var 提示 = document.getElementById('fgMsg');
  if (发送钮) {
    var 倒计时 = 0, 计时器 = null;
    function 开始倒计时(秒) {
      倒计时 = 秒;
      发送钮.disabled = true;
      计时器 = setInterval(function () {
        倒计时--;
        发送钮.textContent = 倒计时 + ' 秒后重发';
        if (倒计时 <= 0) {
          clearInterval(计时器);
          发送钮.disabled = false;
          发送钮.textContent = '获取验证码';
        }
      }, 1000);
      发送钮.textContent = 倒计时 + ' 秒后重发';
    }
    发送钮.addEventListener('click', function () {
      var e = (邮箱.value || '').trim();
      var c = (图形码.value || '').trim();
      if (!e || e.indexOf('@') < 0) {
        提示.textContent = '请先填写有效的邮箱地址';
        提示.className = 'code-msg bad';
        邮箱.focus();
        return;
      }
      if (c.length !== 4) {
        提示.textContent = '请先填写 4 位图形验证码';
        提示.className = 'code-msg bad';
        图形码.focus();
        return;
      }
      发送钮.disabled = true;
      提示.textContent = '正在发送...';
      提示.className = 'code-msg';
      var fd = new FormData();
      fd.append('email', e);
      fd.append('captcha', c);
      fd.append('csrf', '<?= h($csrf) ?>');
      fetch('/api/email_verify.php?act=send_reset', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (d) {
          if (d && d.ok) {
            提示.textContent = d.message || '验证码已发送，请查收邮件';
            提示.className = 'code-msg ok';
            开始倒计时(60);
            var 码框 = document.getElementById('fgCode');
            if (码框) 码框.focus();
          } else {
            发送钮.disabled = false;
            提示.textContent = (d && d.error) ? d.error : '发送失败，请稍后重试';
            提示.className = 'code-msg bad';
            // 图形码错了要换一张，旧的已经在服务端销毁
            var img = document.getElementById('fgCaptchaImg');
            if (img) img.src = '/captcha.php?t=' + Date.now();
            图形码.value = '';
          }
        })
        .catch(function () {
          发送钮.disabled = false;
          提示.textContent = '网络异常，请稍后重试';
          提示.className = 'code-msg bad';
        });
    });
  }
  // 两次密码一致性即时提示，不用等提交
  var pw1 = document.getElementById('pw1');
  var pw2 = document.getElementById('pw2');
  var pwMsg = document.getElementById('pwMsg');
  if (pw1 && pw2 && pwMsg) {
    function 校验() {
      if (!pw2.value) { pwMsg.textContent = ''; return; }
      if (pw1.value === pw2.value) {
        pwMsg.textContent = '两次输入一致';
        pwMsg.className = 'code-msg ok';
      } else {
        pwMsg.textContent = '两次输入不一致';
        pwMsg.className = 'code-msg bad';
      }
    }
    pw1.addEventListener('input', 校验);
    pw2.addEventListener('input', 校验);
    document.getElementById('pwForm').addEventListener('submit', function (ev) {
      if (pw1.value !== pw2.value) {
        ev.preventDefault();
        校验();
        pw2.focus();
      }
    });
  }
})();
</script>
</body>
</html>
