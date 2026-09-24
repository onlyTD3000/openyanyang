<?php
/**
 * 邮箱验证码 API
 * ?act=send_reg   - 注册时发送验证码（只需图形验证码 + 邮箱，无需登录）
 * ?act=send       - 已登录用户发送验证码
 * ?act=verify     - 校验验证码（POST code）
 * ?act=resend     - 已登录用户重新发送验证码
 */
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/email.php';

$act = trim((string) ($_GET['act'] ?? $_POST['act'] ?? ''));

/* ========== 注册时发送验证码（无需登录） ========== */
if ($act === 'send_reg') {
    start_session();

    // 先检验图形验证码
    $gCaptcha = trim((string) ($_POST['captcha'] ?? ''));
    if ($gCaptcha === '') {
        json_out(['error' => '请输入图形验证码'], 400);
    }
    if (empty($_SESSION['captcha']) || !hash_equals($_SESSION['captcha'], $gCaptcha)) {
        unset($_SESSION['captcha']);
        json_out(['error' => '图形验证码不正确'], 400);
    }
    unset($_SESSION['captcha']);

    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => '请输入有效的邮箱地址'], 400);
    }

    // 频率限制改用数据库记录，不再依赖 session：
    // 之前靠 session 记时间戳，攻击者不带 cookie 每次都是"新会话"，
    // 60 秒限制形同虚设，曾经 1 小时内被刷了 1300+ 封邮件导致 163 账号被拦截。
    // 同邮箱 60 秒一次
    $上次 = db_val('SELECT created_at FROM email_verifications
                     WHERE email = ? AND scene = "register_anon" ORDER BY id DESC LIMIT 1', [$email]);
    if ($上次 && time() - strtotime($上次) < 60) {
        $left = 60 - (time() - strtotime($上次));
        json_out(['error' => "发送过于频繁，请 {$left} 秒后再试"], 429);
    }
    // 同 IP 一小时最多 10 条，防止拿不同邮箱轮着发
    $本IP = client_ip();
    $IP条数 = (int) db_val('SELECT COUNT(*) FROM email_verifications
                            WHERE ip = ? AND scene = "register_anon"
                              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$本IP]);
    if ($IP条数 >= 10) {
        json_out(['error' => '当前网络请求过于频繁，请稍后再试'], 429);
    }

    // 生成 6 位验证码，10 分钟有效
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['email_verify'] = [
        'email'   => $email,
        'code'    => $code,
        'expires' => time() + 600,
    ];
    // 注册尚未创建账号，user_id 用 0 占位，靠 scene=register_anon 区分
    db_exec('INSERT INTO email_verifications (user_id, email, code, scene, expires_at, used, attempts, ip, created_at)
             VALUES (0, ?, ?, "register_anon", DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, 0, ?, NOW())',
        [$email, $code, $本IP]);

    $siteName = setting_get('site_name', '云智 AI');
    $body = email_verify_template($siteName, '', $code);
    $subject = '[' . $siteName . '] 注册验证码：' . $code;

    [$ok, $err] = email_send($email, $subject, $body, '注册验证');

    if ($ok) {
        json_out(['ok' => true, 'message' => '验证码已发送至 ' . $email]);
    } else {
        json_out(['error' => '邮件发送失败：' . $err], 500);
    }

/* ========== 已登录用户发送验证码 ========== */
} elseif ($act === 'send') {
    $me = require_login_api();
    if ((int) $me['status'] === 1) {
        json_out(['error' => '您已完成邮箱验证，无需重复发送'], 400);
    }
    if (empty($me['email'])) {
        json_out(['error' => '您的账号未绑定邮箱，无法发送验证码'], 400);
    }

    $last = db_val(
        'SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$me['id']]
    );
    if ($last && time() - strtotime($last) < 60) {
        json_out(['error' => '发送过于频繁，请 60 秒后再试'], 429);
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db_exec('INSERT INTO email_verifications (user_id, email, code, expires_at, created_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())',
        [$me['id'], $me['email'], $code]);

    $siteName = setting_get('site_name', '云智 AI');
    $body = email_verify_template($siteName, $me['username'], $code);
    $subject = '[' . $siteName . '] 邮箱验证码：' . $code;

    [$ok, $err] = email_send($me['email'], $subject, $body, '注册验证', (int) $me['id']);

    if ($ok) {
        json_out(['ok' => true, 'message' => '验证码已发送至您的邮箱']);
    } else {
        json_out(['error' => '邮件发送失败：' . $err], 500);
    }

/* ========== 校验验证码 ========== */
} elseif ($act === 'verify') {
    $me = require_login_api();
    if ((int) $me['status'] === 1) {
        json_out(['error' => '您已完成邮箱验证'], 400);
    }

    $code = trim((string) ($_POST['code'] ?? ''));
    if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
        json_out(['error' => '请输入 6 位验证码'], 400);
    }

    $row = db_one(
        'SELECT * FROM email_verifications WHERE user_id = ? AND email = ? AND code = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
        [$me['id'], $me['email'], $code]
    );

    if (!$row) {
        json_out(['error' => '验证码无效或已过期，请重新获取'], 400);
    }

    db_exec('UPDATE users SET status = 1 WHERE id = ?', [$me['id']]);
    db_exec('DELETE FROM email_verifications WHERE user_id = ?', [$me['id']]);
    $_SESSION['uid'] = (int) $me['id'];

    json_out(['ok' => true, 'message' => '邮箱验证成功！']);

/* ========== 重新发送验证码 ========== */
} elseif ($act === 'resend') {
    $me = require_login_api();
    if ((int) $me['status'] === 1) {
        json_out(['error' => '您已完成邮箱验证'], 400);
    }
    if (empty($me['email'])) {
        json_out(['error' => '您的账号未绑定邮箱'], 400);
    }

    $last = db_val(
        'SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$me['id']]
    );
    if ($last && time() - strtotime($last) < 60) {
        json_out(['error' => '发送过于频繁，请 ' . (60 - (time() - strtotime($last))) . ' 秒后再试'], 429);
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db_exec('INSERT INTO email_verifications (user_id, email, code, expires_at, created_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())',
        [$me['id'], $me['email'], $code]);

    $siteName = setting_get('site_name', '云智 AI');
    $body = email_verify_template($siteName, $me['username'], $code);
    $subject = '[' . $siteName . '] 邮箱验证码：' . $code;

    [$ok, $err] = email_send($me['email'], $subject, $body, '注册验证(重发)', (int) $me['id']);

    if ($ok) {
        json_out(['ok' => true, 'message' => '新验证码已发送至您的邮箱']);
    } else {
        json_out(['error' => '邮件发送失败：' . $err], 500);
    }

/* ========== 登录验证码：发送（无需登录） ========== */
} elseif ($act === 'send_login') {
    start_session();
    // 图形验证码先过一道，挡住脚本批量薅邮件额度
    $gCaptcha = trim((string) ($_POST['captcha'] ?? ''));
    if ($gCaptcha === '') {
        json_out(['error' => '请输入图形验证码'], 400);
    }
    if (!captcha_check($gCaptcha)) {
        json_out(['error' => '图形验证码不正确'], 400);
    }
    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => '请输入有效的邮箱地址'], 400);
    }
    // 同邮箱 60 秒一次（按库里时间算，不靠 session，换浏览器也绕不过）
    $上次 = db_val('SELECT created_at FROM email_verifications
                     WHERE email = ? AND scene = "login" ORDER BY id DESC LIMIT 1', [$email]);
    if ($上次 && time() - strtotime($上次) < 60) {
        json_out(['error' => '发送过于频繁，请 ' . (60 - (time() - strtotime($上次))) . ' 秒后再试'], 429);
    }
    // 同 IP 一小时最多 10 条，防止拿不同邮箱轮着发
    $本IP = client_ip();
    $IP条数 = (int) db_val('SELECT COUNT(*) FROM email_verifications
                            WHERE ip = ? AND scene = "login"
                              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$本IP]);
    if ($IP条数 >= 10) {
        json_out(['error' => '当前网络请求过于频繁，请稍后再试'], 429);
    }
    $u = db_one('SELECT id, username, status FROM users WHERE email = ? LIMIT 1', [$email]);
    // 邮箱不存在也回成功。否则这个接口就成了账号探测器，
    // 谁都能拿它枚举出哪些邮箱注册过。
    if (!$u || (int) $u['status'] !== 1) {
        json_out(['ok' => true, 'message' => '验证码已发送，请查收邮件']);
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    // 同邮箱旧的登录码一律作废，避免多码并存
    db_exec('UPDATE email_verifications SET used = 1
              WHERE email = ? AND scene = "login" AND used = 0', [$email]);
    db_exec('INSERT INTO email_verifications (user_id, email, code, scene, expires_at, used, attempts, ip, created_at)
             VALUES (?, ?, ?, "login", DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 0, ?, NOW())',
        [$u['id'], $email, $code, $本IP]);
    $siteName = setting_get('site_name', '云智 AI');
    $body = email_verify_template($siteName, (string) $u['username'], $code);
    $subject = '[' . $siteName . '] 登录验证码：' . $code;
    [$ok, $err] = email_send($email, $subject, $body, '登录验证', (int) $u['id']);
    if ($ok) {
        json_out(['ok' => true, 'message' => '验证码已发送，请查收邮件']);
    }
    json_out(['error' => '邮件发送失败：' . $err], 500);
/* ========== 找回密码：发送重置验证码（无需登录） ========== */
} elseif ($act === 'send_reset') {
    start_session();

    // 图形验证码先过一道，挡住脚本直接刷接口
    $gCaptcha = trim((string) ($_POST['captcha'] ?? ''));
    if ($gCaptcha === '') {
        json_out(['error' => '请输入图形验证码'], 400);
    }
    if (empty($_SESSION['captcha']) || !hash_equals($_SESSION['captcha'], $gCaptcha)) {
        unset($_SESSION['captcha']);
        json_out(['error' => '图形验证码不正确'], 400);
    }
    unset($_SESSION['captcha']);

    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => '请输入有效的邮箱地址'], 400);
    }

    // 限流跟注册那套一样，走数据库不走 session
    $上次 = db_val('SELECT created_at FROM email_verifications
                     WHERE email = ? AND scene = "reset_pw" ORDER BY id DESC LIMIT 1', [$email]);
    if ($上次 && time() - strtotime($上次) < 60) {
        $left = 60 - (time() - strtotime($上次));
        json_out(['error' => "发送过于频繁，请 {$left} 秒后再试"], 429);
    }
    $本IP = client_ip();
    $IP条数 = (int) db_val('SELECT COUNT(*) FROM email_verifications
                            WHERE ip = ? AND scene = "reset_pw"
                              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$本IP]);
    if ($IP条数 >= 10) {
        json_out(['error' => '当前网络请求过于频繁，请稍后再试'], 429);
    }

    $u = db_one('SELECT id, username, status FROM users WHERE email = ? LIMIT 1', [$email]);

    // 这里刻意不区分「邮箱没注册」和「已发送」：两者回同样的话。
    // 否则谁都能拿一批邮箱来试，看哪个回「未注册」，等于送出用户名单。
    // 停用的账号同理，不告诉对方这个邮箱存在但被封了。
    $统一回复 = ['ok' => true, 'message' => '验证码已发送至 ' . $email . '，10 分钟内有效'];

    if (!$u || (int) $u['status'] !== 1) {
        // 不发信也留一条记录，让上面的 60 秒限流对不存在的邮箱同样生效，
        // 否则可以拿同一个邮箱无限次请求，从响应快慢上推断出账号存不存在。
        db_exec('INSERT INTO email_verifications (user_id, email, code, scene, expires_at, used, attempts, ip, created_at)
                 VALUES (0, ?, "000000", "reset_pw", DATE_ADD(NOW(), INTERVAL 10 MINUTE), 1, 9, ?, NOW())',
            [$email, $本IP]);
        json_out($统一回复);
    }

    // 同一邮箱之前没用掉的重置码全部作废，只留最新这个
    db_exec('UPDATE email_verifications SET used = 1
              WHERE email = ? AND scene = "reset_pw" AND used = 0', [$email]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db_exec('INSERT INTO email_verifications (user_id, email, code, scene, expires_at, used, attempts, ip, created_at)
             VALUES (?, ?, ?, "reset_pw", DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, 0, ?, NOW())',
        [(int) $u['id'], $email, $code, $本IP]);

    $siteName = setting_get('site_name', '云智 AI');
    $body = email_verify_template($siteName, (string) $u['username'], $code);
    $subject = '[' . $siteName . '] 重置密码验证码：' . $code;

    [$发送成功, $发送错误] = email_send($email, $subject, $body, '重置密码', (int) $u['id']);
    if ($发送成功) {
        json_out($统一回复);
    }
    json_out(['error' => '邮件发送失败：' . $发送错误], 500);
} else {
    json_out(['error' => '未知操作'], 400);
}