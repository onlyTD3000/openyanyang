<?php
require_once __DIR__ . '/inc/helpers.php';
start_session();
if (current_user()) {
    header('Location: /chat.php');
    exit;
}
$err = '';
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
// 登录方式：pass 密码登录 / code 邮箱验证码登录。出错重渲染时要停在原来那个页签。
$方式 = ($_POST['login_mode'] ?? 'pass') === 'code' ? 'code' : 'pass';
/**
 * 登录成功后的收尾：重置失败计数、换 session id、发记住我、记登录信息、跳转。
 * 密码登录和验证码登录都走这里，避免两套逻辑各写一遍导致行为不一致。
 */
function 登录成功(array $u): void
{
    login_attempt_reset((int) $u['id']);
    session_regenerate_id(true);          // 防会话固定攻击
    $_SESSION['uid'] = (int) $u['id'];
    unset($_SESSION['try']);
    if (!empty($_POST['remember'])) {
        记住我_签发((int) $u['id']);
    }
    db_exec('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
        [client_ip(), $u['id']]);
    header('Location: /chat.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf'] ?? '';
    $banMsg = (string) setting_get('login_ban_msg', '账号已被封禁，请联系管理员解封');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $token)) {
        $err = '页面已过期，请重试';
    } else {
        // 两种方式共用一个滚动窗口计数，防止换个方式接着试
        $now = time();
        $_SESSION['try'] = array_values(array_filter($_SESSION['try'] ?? [], fn($t) => $t > $now - 60));
        if (count($_SESSION['try']) >= 10) {
            $err = '尝试过于频繁，请稍后再试';
        } elseif ($方式 === 'code') {
            /* ---------- 邮箱验证码登录 ---------- */
            $code = trim((string) ($_POST['email_code'] ?? ''));
            if ($email === '' || $code === '') {
                $err = '请输入邮箱和验证码';
            } elseif (!preg_match('/^\d{6}$/', $code)) {
                $err = '验证码为 6 位数字';
            } else {
                $_SESSION['try'][] = $now;
                // 只认 scene=login 的码：注册码不能拿来登录，否则等于绕过密码
                $行 = db_one('SELECT * FROM email_verifications
                               WHERE email = ? AND scene = "login" AND used = 0
                               ORDER BY id DESC LIMIT 1', [$email]);
                if (!$行) {
                    $err = '验证码无效或已过期，请重新获取';
                } elseif (strtotime($行['expires_at']) < time()) {
                    $err = '验证码已过期，请重新获取';
                } elseif ((int) $行['attempts'] >= 5) {
                    // 错 5 次直接烧掉这个码，逼对方重新走发码流程（受 60 秒限流）
                    db_exec('UPDATE email_verifications SET used = 1 WHERE id = ?', [$行['id']]);
                    $err = '错误次数过多，请重新获取验证码';
                } elseif (!hash_equals((string) $行['code'], $code)) {
                    db_exec('UPDATE email_verifications SET attempts = attempts + 1 WHERE id = ?', [$行['id']]);
                    $剩余 = 5 - ((int) $行['attempts'] + 1);
                    $err = '验证码不正确' . ($剩余 > 0 ? "，还可尝试 {$剩余} 次" : '');
                } else {
                    $u = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $行['user_id']]);
                    if (!$u) {
                        $err = '账号不存在';
                    } elseif ((int) $u['status'] !== 1) {
                        $err = $banMsg;
                    } else {
                        // 先标已用再放行：验证码一次性，避免同一个码被重复提交
                        db_exec('UPDATE email_verifications SET used = 1 WHERE id = ?', [$行['id']]);
                        登录成功($u);
                    }
                }
            }
        } else {
            /* ---------- 密码登录 ---------- */
            $pass    = (string) ($_POST['password'] ?? '');
            $captcha = trim((string) ($_POST['captcha'] ?? ''));
            if ($username === '' || $pass === '') {
                $err = '请输入账号和密码';
            } elseif (!captcha_check($captcha)) {
                $err = '验证码不正确';
            } else {
                $_SESSION['try'][] = $now;
                $u = db_one('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
                if (!$u || !password_verify($pass, $u['password_hash'])) {
                    if ($u) {
                        // 剩余次数由 login_attempt_record 回传。别再拿 $u 里的旧计数自己算，
                        // 那份数据是本次失败入库前读出来的，滚动窗口重置后会算错。
                        [$已封禁, $剩余] = login_attempt_record($username);
                        $err = $已封禁 ? $banMsg
                            : '账号或密码错误，本小时内还可尝试 ' . $剩余 . ' 次';
                    } else {
                        $err = '账号或密码错误';
                    }
                } elseif ((int) $u['status'] !== 1) {
                    $err = $banMsg;
                } else {
                    登录成功($u);
                }
            }
        }
    }
}
$csrf = csrf_token();
$siteName = app_name();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<style>
  .captcha-row { display: flex; gap: 10px; align-items: center; }
  .captcha-row .input { flex: 1; min-width: 0; }
  .captcha-img { height: 52px; width: 116px; flex: 0 0 116px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; background: #f9fafb; }
  .captcha-tip { font-size: 12px; color: #6b7280; margin-top: 0; line-height: 1.5; }
  .remember-row { display: flex; align-items: center; justify-content: space-between;
    gap: 10px; margin: 14px 0 4px; font-size: 14px; color: #374151; }
  .remember-label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
  .remember-row input { width: 15px; height: 15px; cursor: pointer; }
  .forgot-link { flex: 0 0 auto; font-size: 13px; color: #2563eb; text-decoration: none; }
  .forgot-link:hover { text-decoration: underline; }
  .remember-tip { font-size: 12px; color: #9ca3af; margin-bottom: 14px; }
  /* 登录方式切换页签 */
  .mode-tabs { display: flex; gap: 0; margin-bottom: 18px; border-bottom: 1px solid #e5e7eb; }
  .mode-tab { flex: 1; padding: 10px 0; text-align: center; font-size: 14px; color: #6b7280;
    cursor: pointer; background: none; border: none; border-bottom: 2px solid transparent;
    transition: color .15s, border-color .15s; }
  .mode-tab:hover { color: #374151; }
  .mode-tab.on { color: #2563eb; border-bottom-color: #2563eb; font-weight: 500; }
  /* 发码按钮和输入框同排 */
  .code-row { display: flex; gap: 10px; align-items: center; }
  .code-row .input { flex: 1; min-width: 0; }
  .btn-code { flex: 0 0 auto; padding: 0 14px; height: 52px; font-size: 13px; white-space: nowrap;
    border: 1px solid #2563eb; border-radius: 6px; background: #fff; color: #2563eb; cursor: pointer; }
  .btn-code:hover:not(:disabled) { background: #eff6ff; }
  .btn-code:disabled { border-color: #d1d5db; color: #9ca3af; cursor: not-allowed; background: #f9fafb; }
  .code-msg { font-size: 12px; margin-top: 6px; line-height: 1.5; display: none; }
  .code-msg.ok { color: #059669; display: block; }
  .code-msg.bad { color: #dc2626; display: block; }
</style>
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><span class="logo-dot"></span><?= h($siteName) ?></div>
  <h1 class="auth-title">登录</h1>
  <p class="auth-sub">使用你的账号继续</p>
  <?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>
  <div class="mode-tabs">
    <button type="button" class="mode-tab<?= $方式 === 'pass' ? ' on' : '' ?>" data-mode="pass">密码登录</button>
    <button type="button" class="mode-tab<?= $方式 === 'code' ? ' on' : '' ?>" data-mode="code">验证码登录</button>
  </div>
  <form method="post" class="form" autocomplete="on" id="loginForm">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="login_mode" id="loginMode" value="<?= h($方式) ?>">
    <!-- 密码登录 -->
    <div id="panePass"<?= $方式 === 'code' ? ' hidden' : '' ?>>
      <label class="field field-float">
        <input class="input" type="text" name="username" value="<?= h($username) ?>" placeholder=" "<?= $方式 === 'pass' ? ' autofocus' : '' ?>>
        <span class="float-label">账号或邮箱</span>
      </label>
      <label class="field field-float">
        <input class="input" type="password" name="password" placeholder=" " autocomplete="current-password">
        <span class="float-label">密码</span>
      </label>
      <label class="field">
        <div class="captcha-row">
          <span class="field field-float" style="flex:1; min-width:0">
            <input class="input" type="text" name="captcha" maxlength="4" placeholder=" " style="letter-spacing: 4px;">
            <span class="float-label" style="letter-spacing:normal">图中 4 位数字</span>
          </span>
          <img class="captcha-img" src="/captcha.php" alt="验证码" title="点击刷新" onclick="this.src='/captcha.php?t='+Date.now()">
        </div>
        <div class="captcha-tip">看不清？点击图片刷新</div>
      </label>
    </div>
    <!-- 验证码登录 -->
    <div id="paneCode"<?= $方式 === 'pass' ? ' hidden' : '' ?>>
      <label class="field field-float">
        <input class="input" type="email" name="email" id="emailInput" value="<?= h($email) ?>" placeholder=" "<?= $方式 === 'code' ? ' autofocus' : '' ?>>
        <span class="float-label">注册时使用的邮箱</span>
      </label>
      <label class="field">
        <div class="captcha-row">
          <span class="field field-float" style="flex:1; min-width:0">
            <input class="input" type="text" name="captcha_code" id="captchaCode" maxlength="4" placeholder=" " style="letter-spacing: 4px;">
            <span class="float-label" style="letter-spacing:normal">图中 4 位数字</span>
          </span>
          <img class="captcha-img" src="/captcha.php" alt="验证码" title="点击刷新" id="captchaImg2" onclick="this.src='/captcha.php?t='+Date.now()">
        </div>
        <div class="captcha-tip">先填图形验证码，再获取邮箱验证码</div>
      </label>
      <label class="field">
        <div class="code-row">
          <span class="field field-float" style="flex:1; min-width:0">
            <input class="input" type="text" name="email_code" maxlength="6" placeholder=" " inputmode="numeric" style="letter-spacing: 4px;">
            <span class="float-label" style="letter-spacing:normal">6 位邮箱验证码</span>
          </span>
          <button type="button" class="btn-code" id="btnSendCode">获取验证码</button>
        </div>
        <div class="code-msg" id="codeMsg"></div>
      </label>
    </div>
    <div class="remember-row">
      <label class="remember-label">
        <input type="checkbox" name="remember" value="1" checked>
        <span><?= 记住我天数 ?> 天内自动登录</span>
      </label>
      <a class="forgot-link" href="/forgot.php">忘记密码？</a>
    </div>
    <div class="remember-tip">公用电脑上建议取消勾选。手动退出登录会立即失效。</div>
    <button class="btn btn-primary btn-block" type="submit">登录</button>
  </form>
  <?php if (setting_get('allow_register', '1') === '1'): ?>
  <p class="auth-foot">还没有账号？<a href="/register.php">立即注册</a></p>
  <?php endif; ?>
</div>
<script>
(function () {
  var 页签 = document.querySelectorAll('.mode-tab');
  var 方式框 = document.getElementById('loginMode');
  var 面板 = { pass: document.getElementById('panePass'), code: document.getElementById('paneCode') };
  // 切页签只切显示，不清已填内容 —— 用户来回点两下不该丢输入
  页签.forEach(function (t) {
    t.addEventListener('click', function () {
      var m = t.dataset.mode;
      方式框.value = m;
      页签.forEach(function (x) { x.classList.toggle('on', x === t); });
      面板.pass.hidden = (m !== 'pass');
      面板.code.hidden = (m !== 'code');
      var 首个 = 面板[m].querySelector('input:not([type=hidden])');
      if (首个) { 首个.focus(); }
    });
  });
  var 按钮 = document.getElementById('btnSendCode');
  var 提示 = document.getElementById('codeMsg');
  var 倒计时 = 0, 计时器 = null;
  function 显示(文字, 好坏) {
    提示.textContent = 文字;
    提示.className = 'code-msg ' + (好坏 ? 'ok' : 'bad');
  }
  function 开始倒计时(秒) {
    倒计时 = 秒;
    按钮.disabled = true;
    按钮.textContent = 倒计时 + ' 秒后重发';
    clearInterval(计时器);
    计时器 = setInterval(function () {
      倒计时--;
      if (倒计时 <= 0) {
        clearInterval(计时器);
        按钮.disabled = false;
        按钮.textContent = '重新获取';
      } else {
        按钮.textContent = 倒计时 + ' 秒后重发';
      }
    }, 1000);
  }
  按钮.addEventListener('click', function () {
    var 邮箱 = document.getElementById('emailInput').value.trim();
    var 图码 = document.getElementById('captchaCode').value.trim();
    if (!邮箱) { 显示('请先填写邮箱', false); return; }
    if (!图码) { 显示('请先填写图形验证码', false); return; }
    按钮.disabled = true;
    按钮.textContent = '发送中…';
    var fd = new FormData();
    fd.append('email', 邮箱);
    fd.append('captcha', 图码);
    fetch('/api/email_verify.php?act=send_login', { method: 'POST', body: fd })
      .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (res) {
        if (res.j && res.j.ok) {
          显示(res.j.message || '验证码已发送，5 分钟内有效', true);
          开始倒计时(60);
        } else {
          显示((res.j && res.j.error) || '发送失败，请稍后再试', false);
          按钮.disabled = false;
          按钮.textContent = '获取验证码';
          // 图形验证码用一次就废，失败后必须换一张，否则重试一定还是错
          document.getElementById('captchaImg2').src = '/captcha.php?t=' + Date.now();
          document.getElementById('captchaCode').value = '';
        }
      })
      .catch(function () {
        显示('网络异常，请稍后再试', false);
        按钮.disabled = false;
        按钮.textContent = '获取验证码';
      });
  });
})();
</script>
</body>
</html>
