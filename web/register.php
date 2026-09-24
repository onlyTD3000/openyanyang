<?php
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/email.php';
require_once __DIR__ . '/inc/aff.php';
start_session();

if (current_user()) {
    header('Location: /chat.php');
    exit;
}

$siteName = app_name();
$allow = setting_get('allow_register', '1') === '1';
$needVerify = setting_get('email_verify_enabled', '0') === '1';
$err = '';
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
// 邀请码：POST 优先（用户可能改过），GET 的 ref 用于从推介链接进来时自动填入
$inviteCode = strtoupper(trim((string) ($_POST['invite_code'] ?? $_GET['ref'] ?? '')));
$affOn = aff_enabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass   = (string) ($_POST['password'] ?? '');
    $pass2  = (string) ($_POST['password2'] ?? '');
    $token  = $_POST['csrf'] ?? '';
    $captcha = trim((string) ($_POST['captcha'] ?? ''));
    $emailCode = trim((string) ($_POST['email_code'] ?? ''));
    $agree = isset($_POST['agree']);

    if (!$allow) {
        $err = '当前已关闭注册';
    } elseif (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $token)) {
        $err = '页面已过期，请重试';
    } elseif (!captcha_check($captcha)) {
        $err = '图形验证码不正确';
    } elseif (!$agree) {
        $err = '请阅读并勾选同意《服务条款》与《隐私条款》';
    } elseif (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fff}]{2,50}$/u', $username)) {
        $err = '账号需 2-50 位字母、数字、下划线或中文，不能包含特殊符号';
    } elseif ($needVerify && $email === '') {
        $err = '当前要求邮箱验证，请输入邮箱地址';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = '邮箱格式不正确';
    } elseif ($needVerify && $emailCode === '') {
        $err = '请输入邮箱验证码，点击「发送验证码」获取';
    } elseif ($needVerify) {
        // 校验邮箱验证码（从 session 中读取）
        $sess = $_SESSION['email_verify'] ?? null;
        if (!$sess || ($sess['email'] ?? '') !== $email) {
            $err = '邮箱验证码与当前邮箱不匹配，请重新发送';
        } elseif (time() > ($sess['expires'] ?? 0)) {
            $err = '邮箱验证码已过期，请重新发送';
            unset($_SESSION['email_verify']);
        } elseif (!hash_equals((string) $sess['code'], $emailCode)) {
            $err = '邮箱验证码不正确';
        }
    }

    if ($err === '') {
        if ($needVerify && db_one('SELECT id FROM users WHERE email = ? AND status = 1', [$email])) {
            $err = '该邮箱已被注册';
        } else {
            [$passOk, $passErr] = password_strength_check($pass);
            if (!$passOk) {
                $err = $passErr;
            } elseif ($pass !== $pass2) {
                $err = '两次输入的密码不一致';
            } else {
                [$rateOk, $rateErr] = register_ratelimit_check();
                if (!$rateOk) {
                    $err = $rateErr;
                } elseif (db_one('SELECT id FROM users WHERE username = ?', [$username])) {
                    $err = '该账号已被注册';
                } elseif (($affChk = aff_check_code($inviteCode))[1] !== '') {
                    // 邀请码校验放在最后：前面的检查都过了才判断，
                    // 免得因为邀请码写错让用户重填整张表单
                    $err = $affChk[1];
                } else {
                    $referrer = $affChk[0];
                    $gift  = number_format((float) setting_get('register_balance', DEFAULT_BALANCE), 6, '.', '');
                    $quota = (int) setting_get('register_quota', 0);
                    // 邮箱验证通过则 status=1，否则 status=1（未开启验证时）
                    $uid = db_insert('INSERT INTO users (username, email, email_verified_at, password_hash, role, status, balance, token_quota, register_ip, invite_code, created_at)
                                      VALUES (?,?,?,?,?,1,?,?,?,?,NOW())',
                        [$username, $email, $needVerify ? date('Y-m-d H:i:s') : null, password_hash($pass, PASSWORD_DEFAULT), 'user', $gift, $quota, client_ip(), aff_gen_code()]);
                    if ((float) $gift > 0) {
                        db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, created_at)
                                 VALUES (?,?,?,?,?,NOW())',
                            [$uid, $gift, $gift, 'register', '注册赠送']);
                    }
                    // 绑定上级。失败不影响注册本身，只是没有推介关系
                    if ($referrer) {
                        try {
                            aff_bind($uid, (int) $referrer['id']);
                        } catch (Throwable $e) {
                            error_log('[aff] 绑定上级失败 uid=' . $uid . ' err=' . $e->getMessage());
                        }
                    }

                    // 清理 session 中的验证码
                    unset($_SESSION['email_verify'], $_SESSION['email_verify_sent']);

                    // 新用户注册邮件通知
                    if (setting_get('reg_notify') === '1') {
                        $通知邮箱 = trim(setting_get('reg_notify_email', ''));
                        if ($通知邮箱 !== '') {
                            $站名 = h(app_name());
                            $主题 = "【{$站名}】新用户注册通知";
                            $正文 = "<p>新用户 <strong>{$username}</strong>（邮箱：{$email}）于 " . date('Y-m-d H:i:s') . " 注册。</p>";
                            email_send($通知邮箱, $主题, $正文, '新用户注册通知');
                        }
                    }

                    session_regenerate_id(true);
                    $_SESSION['uid'] = $uid;
                    header('Location: /chat.php');
                    exit;
                }
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
<title>注册 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<style>
  .captcha-row { display: flex; gap: 10px; align-items: center; }
  .captcha-row .input { flex: 1; min-width: 0; }
  .captcha-img { height: 52px; width: 116px; flex: 0 0 116px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; background: #f9fafb; }
  .captcha-tip { font-size: 12px; color: #6b7280; margin-top: 0; line-height: 1.5; }
  .agree-row { display: flex; align-items: flex-start; gap: 8px; margin: 4px 0 14px; font-size: 13px; color: #6b7280; line-height: 1.6; cursor: pointer; }
  .agree-row input[type="checkbox"] { flex: 0 0 auto; width: 15px; height: 15px; margin-top: 2px; cursor: pointer; }
  .agree-row a { color: var(--c-primary, #2563eb); text-decoration: none; }
  .agree-row a:hover { text-decoration: underline; }
  .field-required { color: #ef4444; margin-left: 2px; }
  .email-code-row { display: flex; gap: 10px; align-items: center; }
  .email-code-row .input { flex: 1; min-width: 0; }
  .btn-send-code { white-space: nowrap; height: 52px; width: 116px; flex: 0 0 116px; padding: 0; font-size: 13px; }
  .btn-send-code:disabled { opacity: 0.6; cursor: not-allowed; }
  .send-code-tip { font-size: 12px; color: #6b7280; margin-top: 0; line-height: 1.5; }
  .send-code-tip:empty { display: none; }
  .send-code-tip.ok { color: #10b981; }
  .send-code-tip.err { color: #ef4444; }
</style>
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><span class="logo-dot"></span><?= h($siteName) ?></div>
  <h1 class="auth-title">创建账号</h1>
  <p class="auth-sub">注册后即可使用平台内的 AI 模型</p>

  <?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$allow): ?>
    <div class="alert alert-info">管理员已关闭注册通道。</div>
    <p class="auth-foot"><a href="/login.php">返回登录</a></p>
  <?php else: ?>
  <form method="post" class="form" id="regForm">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label class="field field-float">
      <input class="input" type="text" name="username" value="<?= h($username) ?>" placeholder=" " required autofocus>
      <span class="float-label">账号</span>
    </label>
    <label class="field field-float">
      <input class="input" type="email" name="email" id="emailInput" value="<?= h($email) ?>" placeholder=" " <?= $needVerify ? 'required' : '' ?>>
      <span class="float-label">邮箱<?php if ($needVerify): ?><span class="field-required">*</span><?php else: ?><span class="muted">（可选）</span><?php endif; ?></span>
    </label>
    <label class="field field-float">
      <input class="input" type="password" name="password" placeholder=" " required minlength="8" autocomplete="new-password">
      <span class="float-label">密码</span>
      <div class="captcha-tip">至少 8 位，需包含英文、数字、符号中的至少两种</div>
    </label>
    <label class="field field-float">
      <input class="input" type="password" name="password2" placeholder=" " required minlength="8" autocomplete="new-password">
      <span class="float-label">确认密码</span>
    </label>
    <?php if ($affOn): ?>
    <label class="field field-float">
      <input class="input" type="text" name="invite_code" value="<?= h($inviteCode) ?>"
             maxlength="16" placeholder=" " style="text-transform:uppercase"
             autocomplete="off">
      <span class="float-label">邀请码 <span class="muted">（可选）</span></span>
      <div class="captcha-tip">
        <?php if ($inviteCode !== '' && isset($_GET['ref'])): ?>
          已自动填入邀请码 <strong><?= h($inviteCode) ?></strong>
        <?php else: ?>
          没有可以留空，不影响注册
        <?php endif; ?>
      </div>
    </label>
    <?php endif; ?>
    <label class="field">
      <div class="captcha-row">
        <span class="field field-float" style="flex:1; min-width:0">
          <input class="input" type="text" name="captcha" required maxlength="4" placeholder=" " style="letter-spacing: 4px;">
          <span class="float-label" style="letter-spacing:normal">图中 4 位数字</span>
        </span>
        <img class="captcha-img" src="/captcha.php" alt="图形验证码" title="点击刷新" onclick="refreshCaptcha()" id="captchaImg">
      </div>
      <div class="captcha-tip">看不清？点击图片刷新</div>
    </label>
    <?php if ($needVerify): ?>
    <label class="field">
      <div class="email-code-row">
        <span class="field field-float" style="flex:1; min-width:0">
          <input class="input" type="text" name="email_code" id="emailCodeInput" required maxlength="6"
                 placeholder=" " style="letter-spacing: 4px; font-family: monospace;" autocomplete="one-time-code">
          <span class="float-label" style="letter-spacing:normal; font-family:inherit">6 位邮箱验证码 <span class="field-required">*</span></span>
        </span>
        <button type="button" class="btn btn-primary btn-send-code" id="sendCodeBtn" onclick="sendEmailCode()">发送验证码</button>
      </div>
      <div class="send-code-tip" id="sendCodeTip"></div>
    </label>
    <?php endif; ?>
    <label class="agree-row">
      <input type="checkbox" name="agree" value="1" required <?= (isset($agree) && $agree) ? "checked" : "" ?>>
      <span>我已阅读并同意<a href="/legal.php?t=service" target="_blank" rel="noopener">《服务条款》</a>和<a href="/legal.php?t=privacy" target="_blank" rel="noopener">《隐私条款》</a></span>
    </label>
    <button class="btn btn-primary btn-block" type="submit">注册</button>
  </form>
  <p class="auth-foot">已有账号？<a href="/login.php">去登录</a></p>
  <?php endif; ?>
</div>

<?php if ($needVerify): ?>
<script>
var sendTimer = 0;

function showTip(msg, cls) {
  var el = document.getElementById('sendCodeTip');
  el.textContent = msg;
  el.className = 'send-code-tip ' + (cls || '');
}

function refreshCaptcha() {
  document.getElementById('captchaImg').src = '/captcha.php?t=' + Date.now();
}

function updateSendBtn(left) {
  var btn = document.getElementById('sendCodeBtn');
  if (left > 0) {
    btn.disabled = true;
    btn.textContent = left + ' 秒后可重发';
    sendTimer = setTimeout(function() { updateSendBtn(left - 1); }, 1000);
  } else {
    btn.disabled = false;
    btn.textContent = '发送验证码';
    sendTimer = 0;
    showTip('', '');
  }
}

function sendEmailCode() {
  var email = document.getElementById('emailInput').value.trim();
  var captcha = document.querySelector('input[name="captcha"]').value.trim();

  if (email === '') {
    showTip('请先填写邮箱地址', 'err');
    return;
  }
  if (captcha === '') {
    showTip('请先填写图形验证码', 'err');
    return;
  }

  var btn = document.getElementById('sendCodeBtn');
  btn.disabled = true;
  btn.textContent = '发送中...';
  showTip('正在发送...', '');

  var formData = new FormData();
  formData.append('act', 'send_reg');
  formData.append('email', email);
  formData.append('captcha', captcha);

  fetch('/api/email_verify.php', {
    method: 'POST',
    body: formData
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (data.ok) {
      showTip('✓ ' + data.message, 'ok');
      document.getElementById('emailCodeInput').focus();
      refreshCaptcha();
      document.querySelector('input[name="captcha"]').value = '';
      updateSendBtn(60);
    } else {
      showTip(data.error || '发送失败', 'err');
      btn.disabled = false;
      btn.textContent = '发送验证码';
      refreshCaptcha();
    }
  })
  .catch(function() {
    showTip('网络错误，请重试', 'err');
    btn.disabled = false;
    btn.textContent = '发送验证码';
  });
}
</script>
<?php endif; ?>
</body>
</html>