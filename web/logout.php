<?php
require_once __DIR__ . '/inc/helpers.php';
start_session();

// 模拟登录期间点退出，先退回管理员而不是真的登出。
// 否则下面的「记住我_清除」会删掉管理员自己的免密令牌（那张 cookie 始终是管理员的），
// 管理员被彻底登出，也失去了切回自己账号的机会。
if (impersonating()) {
    $err = impersonate_stop();
    if ($err === '') {
        flash_set('ok', '已退出模拟登录，回到管理员账号');
        header('Location: /admin/users.php', true, 303);
        exit;
    }
    // 切回失败（原管理员账号已被禁用/删除）时 session 已销毁，落到登录页
    header('Location: /login.php', true, 303);
    exit;
}

// 手动退出必须把自动登录令牌一起废掉，否则下一个请求又被令牌顶回登录态，
// 用户会觉得「点了退出还是登录着的」。这也是需求里「只要不手动退出」的那个边界。
记住我_清除(true);

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) ($p['secure'] ?? false), true);
}
session_destroy();
header('Location: /login.php');
exit;
