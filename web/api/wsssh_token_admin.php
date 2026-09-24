<?php
/**
 * 后台版握手令牌。
 *
 * 和 api/wsssh_token.php 的区别：那个只发自己名下的主机，这个允许管理员开任意主机
 * ——后台服务器管理本来就是跨账号运维的入口。
 *
 * 令牌里的 user_id 填主机的真实归属者，不是管理员自己。因为 ws 服务端要用它做
 * 归属校验（ssh_hosts WHERE id=? AND user_id=?），填管理员会查不到那台机器。
 * 谁开了谁的机器另外记进审计日志，不靠令牌本身留痕。
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();

$me = require_login_api();
// 必须是管理员。这个接口能开任意用户的机器，是全站最敏感的入口之一。
if (($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    json_out(['error' => '需要管理员权限']);
}

csrf_check();

$hostId = (int) ($_POST['host_id'] ?? 0);
if ($hostId <= 0) {
    json_out(['error' => '没指定主机']);
}

$主机 = db_one('SELECT id, user_id, name, host, port, username, status FROM ssh_hosts
                WHERE id = ? LIMIT 1', [$hostId]);
if (!$主机) {
    json_out(['error' => '主机不存在']);
}
if ((int) $主机['status'] !== 1) {
    json_out(['error' => '这台主机已停用']);
}

db_exec('DELETE FROM wsssh_tokens WHERE expires_at < NOW() OR used = 1');

$明文 = bin2hex(random_bytes(32));
db_exec('INSERT INTO wsssh_tokens (token, user_id, host_id, used, ip, expires_at, created_at)
         VALUES (?,?,?,0,?, DATE_ADD(NOW(), INTERVAL 60 SECOND), NOW())',
    [hash('sha256', $明文), (int) $主机['user_id'], $hostId, client_ip()]);

// 管理员开了谁的机器，留一条审计。出事时这条记录是唯一线索。
audit_log((int) $me['id'], 'open_web_terminal', (int) $主机['user_id'], 0,
    '网页终端: ' . $主机['name'] . ' (' . $主机['host'] . ')');

json_out([
    'ok'    => 1,
    'token' => $明文,
    'host'  => [
        'id'   => (int) $主机['id'],
        'name' => (string) $主机['name'],
        'addr' => $主机['username'] . '@' . $主机['host'] . ':' . $主机['port'],
    ],
]);
