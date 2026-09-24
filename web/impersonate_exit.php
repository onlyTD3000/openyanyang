<?php
/**
 * 结束模拟登录，切回管理员身份。
 *
 * 放在站点根目录而不是 /admin/ 下，是因为执行到这里时当前身份已经是普通用户，
 * 放 admin 目录会被 require_admin() 直接 403，切都切不回来。
 *
 * 这里只要求「已登录」，真正的权限判断在 impersonate_stop() 里：
 * 它只认 session 中暂存的管理员 uid，普通用户自己访问这个页面拿不到任何东西。
 */
require_once __DIR__ . '/inc/helpers.php';

$me = require_login();

// 只接受 POST + CSRF，避免被人用 <img src> 之类的方式诱导切换身份
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php', true, 303);
    exit;
}
csrf_check_page();

$err = impersonate_stop();

if ($err !== '') {
    // 原管理员账号已不可用时 session 已被销毁，只能回登录页
    if (mb_strpos($err, '重新登录') !== false) {
        header('Location: /login.php', true, 303);
        exit;
    }
    flash_set('error', $err);
    header('Location: /index.php', true, 303);
    exit;
}

flash_set('ok', '已切回管理员账号');
header('Location: /admin/users.php', true, 303);
exit;
