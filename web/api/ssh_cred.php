<?php
/**
 * 凭据下发接口。只给桌面客户端用。
 *
 * 客户端直连模式下，SSH 连接由客户端本地建立，服务端不再代连。
 * 客户端需要拿到目标主机的明文密码或私钥才能自己认证，这个接口负责下发。
 *
 * 安全边界（改动这个文件前务必读完）：
 *   1. 必须登录，且只下发属于自己的主机，靠 ssh_host_of() 的 user_id 过滤。
 *   2. 一次只下发一台主机的凭据，不提供批量导出。避免接口被当成
 *      「把我所有服务器密码打包给我」的通道，万一 token 泄露损失也小一些。
 *   3. 每次下发都记审计日志。用户凭据离开服务器这件事必须留痕。
 *   4. 只走 HTTPS。明文凭据在网络上传输，降级到 HTTP 等于裸奔。
 *
 * 这个接口是客户端直连方案的安全代价所在：私钥不再「永不出服务器」。
 * 网页端不用这个接口，仍走服务端代连的老路。
 */
require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
api_error_guard();
require __DIR__ . '/../inc/crypto.php';
require __DIR__ . '/../inc/ssh_run.php';
start_session();
$me = require_login_api();
csrf_check();
$uid = (int) $me['id'];
session_write_close();
/* 只允许 HTTPS。反代场景下 $_SERVER['HTTPS'] 可能没设，
   一并看 X-Forwarded-Proto。两个都判不出来才拒。 */
$是HTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443');
if (!$是HTTPS) {
    json_out(['error' => '凭据只能通过 HTTPS 下发，当前连接不安全']);
}
$hostId = (int) ($_POST['host_id'] ?? 0);
$host = ssh_host_of($hostId, $uid);
if (!$host) {
    json_out(['error' => '主机不存在或不属于你']);
}
/* 解密。密钥在服务端的 DATA_DIR/secure/master.key，客户端拿不到，
   所以解密只能在这里做。 */
$secret = '';
$keyPass = '';
try {
    $secret = dec_secret((string) $host['secret_enc']);
    if (trim((string) $host['key_pass_enc']) !== '') {
        $keyPass = dec_secret((string) $host['key_pass_enc']);
    }
} catch (\Throwable $e) {
    json_out(['error' => '凭据解密失败，请在主机管理里重新保存一次密码或私钥']);
}
if ($secret === '') {
    json_out(['error' => '这台主机没有存凭据，请先在主机管理里补上']);
}
/* 审计：凭据离开服务器要留痕。退出码用 -4 表示「凭据下发」这类事件，
   与命令执行的 0/-1/-3 区分开，日志页面按这个值单独显示。 */
ssh_log($uid, $hostId, 0, '[客户端取凭据]', 'safe', '', -4,
    '下发给桌面客户端用于本地直连', 0);
json_out([
    'ok'          => 1,
    'host'        => (string) $host['host'],
    'port'        => (int) $host['port'],
    'username'    => (string) $host['username'],
    'auth_type'   => (string) $host['auth_type'],
    'secret'      => $secret,
    'key_pass'    => $keyPass,
    // 客户端拿它比对，防中间人。空串表示首次连接，由客户端记下来回传
    'fingerprint' => (string) $host['fingerprint'],
    'name'        => (string) $host['name'],
]);
