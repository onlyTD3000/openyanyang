<?php
/**
 * 后台：单台服务器连通性测试（JSON 接口）。
 *
 * 「批量测试」不在这里循环，而是由前端逐台调用本接口。
 * 原因：单台建连最长 20 秒，几十台串起来必然超过 PHP 的执行时限，
 * 请求会被掐断，前端也看不到进度。拆成一台一次，前端能边测边显示。
 *
 * 与前台 api/ssh_hosts.php 的 test 动作逻辑一致，区别是管理员不限 user_id，
 * 可以测任意用户的主机。
 */
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/crypto.php';    // dec_secret，解主机凭据
require_once __DIR__ . '/../inc/ssh_guard.php'; // ssh_host_deny_reason
require_once __DIR__ . '/../inc/ssh_run.php';   // ssh_connect / ssh_exec

header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if (!$u || $u['role'] !== 'admin') {
    echo json_encode(['ok' => false, 'error' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 写操作要带 CSRF。这个接口会改 ssh_hosts 的状态字段，算写操作。
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
// 不带 status=1 条件：后台要能测到停用的主机，否则停用的永远停在「未测试」
$host = db_one('SELECT * FROM ssh_hosts WHERE id = ? LIMIT 1', [$id]);
if (!$host) {
    echo json_encode(['ok' => false, 'error' => '主机不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deny = ssh_host_deny_reason((string) $host['host']);
if ($deny !== '') {
    db_exec('UPDATE ssh_hosts SET last_error = ? WHERE id = ?', [mb_substr($deny, 0, 250), $id]);
    echo json_encode(['ok' => false, 'error' => $deny, 'state' => 'bad'], JSON_UNESCAPED_UNICODE);
    exit;
}

$c = ssh_connect($host);
if (!$c['ok']) {
    db_exec('UPDATE ssh_hosts SET last_error = ? WHERE id = ?',
        [mb_substr((string) $c['error'], 0, 250), $id]);
    audit_log($u['id'], 'test_server', (int) $host['user_id'], 0,
        '测试失败: ' . $host['name'] . ' - ' . $c['error']);
    echo json_encode([
        'ok'    => false,
        'error' => (string) $c['error'],
        'state' => 'bad',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$r = ssh_exec($c['conn'], 'whoami; hostname');

// 首次连通记录指纹，后续可用于察觉主机被换掉
if (trim((string) $host['fingerprint']) === '' && (string) $c['fingerprint'] !== '') {
    db_exec('UPDATE ssh_hosts SET fingerprint = ? WHERE id = ?', [$c['fingerprint'], $id]);
}
db_exec('UPDATE ssh_hosts SET last_ok_at = NOW(), last_error = \'\' WHERE id = ?', [$id]);
audit_log($u['id'], 'test_server', (int) $host['user_id'], 0, '测试成功: ' . $host['name']);

echo json_encode([
    'ok'     => true,
    'state'  => 'good',
    'ms'     => (int) $r['ms'],
    'out'    => mb_substr(trim((string) $r['out']), 0, 200),
    'ok_at'  => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
