<?php
/**
 * 网页终端握手令牌。
 *
 * 为什么要这一层：浏览器的 WebSocket API 不允许自定义请求头，跨端口时 Cookie
 * 也带不过去，ws 服务端没法直接复用 PHP 会话。所以流程拆成两步——
 * 先由这个已登录的 HTTP 接口发一张一次性令牌，前端再拿令牌去连 ws。
 *
 * 令牌的三重约束：绑定用户、绑定单台主机、60 秒内用一次即废。
 * 这样即使令牌在 URL 里泄漏（比如被日志记下），也开不了别人的机器、过期就废。
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();

$me  = require_login_api();
$uid = (int) $me['id'];

csrf_check();

$hostId = (int) ($_POST['host_id'] ?? 0);
if ($hostId <= 0) {
    json_out(['error' => '没指定主机']);
}

// 关键：查询必须带 user_id。只能给自己绑定的、启用中的主机发令牌。
$主机 = db_one('SELECT id, name, host, port, username FROM ssh_hosts
                WHERE id = ? AND user_id = ? AND status = 1',
    [$hostId, $uid]);
if (!$主机) {
    // 不区分「不存在」和「不属于你」，避免被拿去枚举别人有哪些主机
    json_out(['error' => '主机不存在或不属于你']);
}

// 顺手清掉过期令牌，不用另开定时任务
db_exec('DELETE FROM wsssh_tokens WHERE expires_at < NOW() OR used = 1');

// 明文只回给前端一次，库里只存哈希。
// 这样即使数据库被读到，也拿不到能直接用的令牌。
$明文 = bin2hex(random_bytes(32));
db_exec('INSERT INTO wsssh_tokens (token, user_id, host_id, used, ip, expires_at, created_at)
         VALUES (?,?,?,0,?, DATE_ADD(NOW(), INTERVAL 60 SECOND), NOW())',
    [hash('sha256', $明文), $uid, $hostId, client_ip()]);

json_out([
    'ok'    => 1,
    'token' => $明文,
    'host'  => [
        'id'   => (int) $主机['id'],
        'name' => (string) $主机['name'],
        'addr' => $主机['username'] . '@' . $主机['host'] . ':' . $主机['port'],
    ],
]);
