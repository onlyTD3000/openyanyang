<?php
/**
 * 主机登记管理接口。用户只能操作自己的记录。
 * 动作：list 列表 / save 新增或修改 / del 删除 / test 连通性测试
 */
require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
api_error_guard();   // 让未捕获异常返回可读 JSON，而不是空的 500
require __DIR__ . '/../inc/crypto.php';
require __DIR__ . '/../inc/ssh_guard.php';
require __DIR__ . '/../inc/ssh_run.php';

start_session();
$me = require_login_api();
csrf_check();

$act = (string) ($_POST['act'] ?? $_GET['act'] ?? 'list');
$uid = (int) $me['id'];

// ---- 列表：不返回任何凭据内容 ----
if ($act === 'list') {
    $rows = db_all('SELECT id, name, host, port, username, auth_type, status,
                           fingerprint, last_ok_at, last_error, created_at
                    FROM ssh_hosts WHERE user_id=? ORDER BY id DESC', [$uid]);
    json_out(['ok' => 1, 'hosts' => $rows]);
}

// ---- 新增 / 修改 ----
if ($act === 'save') {
    $id    = (int) ($_POST['id'] ?? 0);
    $name  = trim((string) ($_POST['name'] ?? ''));
    $host  = trim((string) ($_POST['host'] ?? ''));
    $port  = (int) ($_POST['port'] ?? 22);
    $user  = trim((string) ($_POST['username'] ?? ''));
    $auth  = ($_POST['auth_type'] ?? 'key') === 'password' ? 'password' : 'key';
    $secret= (string) ($_POST['secret'] ?? '');
    $kpass = (string) ($_POST['key_pass'] ?? '');

    if ($name === '' || $host === '' || $user === '') {
        json_out(['error' => '备注名、主机地址、登录用户都不能为空']);
    }
    if ($port < 1 || $port > 65535) {
        json_out(['error' => '端口不合法']);
    }
    if (mb_strlen($name) > 40) {
        json_out(['error' => '备注名请控制在 40 字以内']);
    }
    // 目标地址合法性与内网限制
    $deny = ssh_host_deny_reason($host);
    if ($deny !== '') {
        json_out(['error' => $deny]);
    }
    // 单用户主机数量上限，防止刷库
    if ($id === 0) {
        $n = (int) (db_one('SELECT COUNT(*) n FROM ssh_hosts WHERE user_id=?', [$uid])['n'] ?? 0);
        if ($n >= 20) {
            json_out(['error' => '最多登记 20 台主机']);
        }
    }

    if ($id > 0) {
        // 修改：先确认归属
        $old = db_one('SELECT * FROM ssh_hosts WHERE id=? AND user_id=?', [$id, $uid]);
        if (!$old) {
            json_out(['error' => '主机不存在']);
        }
        // 凭据留空表示不修改，沿用原值
        $encSecret = $secret !== '' ? enc_secret($secret) : (string) $old['secret_enc'];
        $encKpass  = $secret !== '' ? enc_secret($kpass)  : (string) $old['key_pass_enc'];
        // 地址或端口变了，指纹要清空重新记录
        $fp = ((string) $old['host'] !== $host || (int) $old['port'] !== $port)
            ? '' : (string) $old['fingerprint'];
        db_exec('UPDATE ssh_hosts SET name=?, host=?, port=?, username=?, auth_type=?,
                        secret_enc=?, key_pass_enc=?, fingerprint=? WHERE id=? AND user_id=?',
            [$name, $host, $port, $user, $auth, $encSecret, $encKpass, $fp, $id, $uid]);
        json_out(['ok' => 1, 'id' => $id]);
    }

    if ($secret === '') {
        json_out(['error' => $auth === 'password' ? '请填写登录密码' : '请粘贴私钥内容']);
    }
    $newId = db_insert('INSERT INTO ssh_hosts
        (user_id, name, host, port, username, auth_type, secret_enc, key_pass_enc, created_at)
        VALUES (?,?,?,?,?,?,?,?,NOW())',
        [$uid, $name, $host, $port, $user, $auth, enc_secret($secret), enc_secret($kpass)]);
    json_out(['ok' => 1, 'id' => $newId]);
}

// ---- 删除 ----
if ($act === 'del') {
    $id = (int) ($_POST['id'] ?? 0);
    $n  = db_exec('DELETE FROM ssh_hosts WHERE id=? AND user_id=?', [$id, $uid]);
    if ($n < 1) {
        json_out(['error' => '主机不存在']);
    }
    json_out(['ok' => 1]);
}

// ---- 连通性测试：只跑一条固定的只读命令 ----
if ($act === 'test') {
    $id   = (int) ($_POST['id'] ?? 0);
    $host = ssh_host_of($id, $uid);
    if (!$host) {
        json_out(['error' => '主机不存在']);
    }
    $c = ssh_connect($host);
    if (!$c['ok']) {
        db_exec('UPDATE ssh_hosts SET last_error=? WHERE id=? AND user_id=?',
            [mb_substr($c['error'], 0, 250), $id, $uid]);
        ssh_log($uid, $id, 0, '[连通性测试]', 'safe', $c['error'], -1, '', 0);
        json_out(['error' => $c['error']]);
    }
    $r = ssh_exec($c['conn'], 'whoami; hostname; uptime');
    // 首次连接成功，记录指纹
    if (trim((string) $host['fingerprint']) === '' && $c['fingerprint'] !== '') {
        db_exec('UPDATE ssh_hosts SET fingerprint=? WHERE id=? AND user_id=?',
            [$c['fingerprint'], $id, $uid]);
    }
    db_exec('UPDATE ssh_hosts SET last_ok_at=NOW(), last_error=\'\' WHERE id=? AND user_id=?',
        [$id, $uid]);
    ssh_log($uid, $id, 0, 'whoami; hostname; uptime', 'safe', '', $r['exit'], $r['out'], $r['ms']);
    json_out(['ok' => 1, 'out' => $r['out'], 'ms' => $r['ms']]);
}

json_out(['error' => '未知操作']);
