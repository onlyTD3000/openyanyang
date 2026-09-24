<?php
/**
 * 命令执行接口。按客户要求改为免确认直接执行。
 *
 * 动作：
 *   run      提交并立即执行，一次请求走完（当前前端用这个）
 *   propose  仅登记待执行项并返回令牌，保留给需要人工确认的场景
 *   confirm  凭令牌执行
 *   cancel   放弃执行
 *   logs     查看自己的执行记录
 *
 * 命令层面已无任何安全策略拦截，客户服务器上想执行什么都可以。
 * 仍保留的限制只有 ssh_connect() 里的目标地址校验（不许连内网/保留地址），
 * 那是防止平台被当跳板打自己内网，与客户的操作自由无关。
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

// 检查用户是否有 SSH 执行权限
$userCap = db_one('SELECT cap_ssh_exec FROM users WHERE id = ?', [$me['id']]);
if ((int) ($userCap['cap_ssh_exec'] ?? 1) !== 1) {
    json_out(['error' => '你没有 SSH 命令执行权限']);
}

// 身份和 CSRF 都验完了，后面只读写数据库和 SSH，不再碰 $_SESSION。
// 这里放掉会话锁：远程命令可能跑很久（装包、编译、网络卡顿），
// 锁不放会把同一用户的其他请求全堵在 session_start() 上。
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$act = (string) ($_POST['act'] ?? $_GET['act'] ?? '');
$uid = (int) $me['id'];

// ---- 提交并立即执行，不需要确认 ----
if ($act === 'run') {
    $hostId = (int) ($_POST['host_id'] ?? 0);
    $convId = (int) ($_POST['conv_id'] ?? 0);
    $cmd    = trim((string) ($_POST['command'] ?? ''));

    $host = ssh_host_of($hostId, $uid);
    if (!$host) {
        json_out(['error' => '主机不存在或不属于你']);
    }
    if ($cmd === '') {
        json_out(['error' => '命令为空']);
    }

    $c = ssh_connect($host);
    if (!$c['ok']) {
        ssh_log($uid, $hostId, $convId, $cmd, 'safe', $c['error'], -1, '', 0);
        db_exec('UPDATE ssh_hosts SET last_error=? WHERE id=? AND user_id=?',
            [mb_substr($c['error'], 0, 250), $hostId, $uid]);
        json_out(['error' => $c['error']]);
    }
    if (trim((string) $host['fingerprint']) === '' && $c['fingerprint'] !== '') {
        db_exec('UPDATE ssh_hosts SET fingerprint=? WHERE id=? AND user_id=?',
            [$c['fingerprint'], $hostId, $uid]);
    }

    $r = ssh_exec($c['conn'], $cmd);
    db_exec('UPDATE ssh_hosts SET last_ok_at=NOW(), last_error=\'\' WHERE id=? AND user_id=?',
        [$hostId, $uid]);
    // 执行记录照旧写，出了问题要能查是哪条命令干的
    ssh_log($uid, $hostId, $convId, $cmd, 'safe', '', $r['exit'], $r['out'], $r['ms']);

    json_out(['ok' => 1, 'out' => $r['out'], 'exit' => $r['exit'], 'ms' => $r['ms']]);
}

/* ---- 后台任务：启动 ----
   前端现在默认走这条路，不再用同步的 run。
   命令丢到远端后台，这里立刻返回任务号，之后靠 tail 轮询进度。
   好处是彻底绕开 nginx/FPM 的 300 秒天花板：请求本身只有零点几秒，
   命令跑多久都跟 HTTP 请求的生命周期无关了。 */
if ($act === 'start') {
    $hostId = (int) ($_POST['host_id'] ?? 0);
    $convId = (int) ($_POST['conv_id'] ?? 0);
    $cmd    = trim((string) ($_POST['command'] ?? ''));
    $host = ssh_host_of($hostId, $uid);
    if (!$host) {
        json_out(['error' => '主机不存在或不属于你']);
    }
    if ($cmd === '') {
        json_out(['error' => '命令为空']);
    }
    $c = ssh_connect($host);
    if (!$c['ok']) {
        ssh_log($uid, $hostId, $convId, $cmd, 'safe', $c['error'], -1, '', 0);
        db_exec('UPDATE ssh_hosts SET last_error=? WHERE id=? AND user_id=?',
            [mb_substr($c['error'], 0, 250), $hostId, $uid]);
        json_out(['error' => $c['error']]);
    }
    if (trim((string) $host['fingerprint']) === '' && $c['fingerprint'] !== '') {
        db_exec('UPDATE ssh_hosts SET fingerprint=? WHERE id=? AND user_id=?',
            [$c['fingerprint'], $hostId, $uid]);
    }
    ssh_job_gc($c['conn']);                       // 顺手清掉一天前的残留
    $r = ssh_job_start($c['conn'], $cmd);
    if (!$r['ok']) {
        ssh_log($uid, $hostId, $convId, $cmd, 'safe', $r['error'], -1, '', 0);
        json_out(['error' => $r['error']]);
    }
    db_exec('UPDATE ssh_hosts SET last_ok_at=NOW(), last_error=\'\' WHERE id=? AND user_id=?',
        [$hostId, $uid]);
    /* 审计日志在启动时先记一条，退出码留 -3 表示「已启动、结果未知」。
       任务结束时由 tail 那边补一条完整记录。这样即使用户关掉页面、
       轮询再没发生过，日志里也留得下「这条命令确实被执行了」。 */
    ssh_log($uid, $hostId, $convId, $cmd, 'safe', '', -3, '[后台任务已启动：' . $r['job'] . ']', 0);
    json_out(['ok' => 1, 'job' => $r['job'], 'host_id' => $hostId]);
}
/* ---- 后台任务：读进度 ----
   from 是前端已经收到的字节数，只返回后面新增的部分。
   长任务反复轮询也不会每次重传整份日志。 */
if ($act === 'tail') {
    $hostId = (int) ($_POST['host_id'] ?? 0);
    $convId = (int) ($_POST['conv_id'] ?? 0);
    $job    = (string) ($_POST['job'] ?? '');
    $from   = (int) ($_POST['from'] ?? 0);
    $cmd    = trim((string) ($_POST['command'] ?? ''));   // 只为结束时补审计日志
    $host = ssh_host_of($hostId, $uid);
    if (!$host) {
        json_out(['error' => '主机不存在或不属于你']);
    }
    if (!ssh_job_id_ok($job)) {
        json_out(['error' => '任务号格式不正确']);
    }
    $c = ssh_connect($host);
    if (!$c['ok']) {
        json_out(['error' => $c['error']]);
    }
    $r = ssh_job_tail($c['conn'], $job, $from);
    if (!$r['ok']) {
        json_out(['error' => $r['error']]);
    }
    // 跑完了补一条审计日志，带真实退出码
    if ($r['done'] && $cmd !== '') {
        ssh_log($uid, $hostId, $convId, $cmd, 'safe', '', (int) $r['exit'],
            '[后台任务 ' . $job . ' 结束]', 0);
    }
    json_out(['ok' => 1, 'done' => $r['done'] ? 1 : 0, 'exit' => $r['exit'],
        'out' => $r['out'], 'size' => $r['size'], 'next' => $r['next'],
        'alive' => $r['alive'] ? 1 : 0]);
}
/* ---- 后台任务：终止 ----
   用户点「停止」时调。杀整个进程组，孤儿进程不会留下继续跑。 */
if ($act === 'kill') {
    $hostId = (int) ($_POST['host_id'] ?? 0);
    $job    = (string) ($_POST['job'] ?? '');
    $host = ssh_host_of($hostId, $uid);
    if (!$host) {
        json_out(['error' => '主机不存在或不属于你']);
    }
    if (!ssh_job_id_ok($job)) {
        json_out(['error' => '任务号格式不正确']);
    }
    $c = ssh_connect($host);
    if (!$c['ok']) {
        json_out(['error' => $c['error']]);
    }
    $r = ssh_job_kill($c['conn'], $job);
    json_out($r['ok'] ? ['ok' => 1] : ['error' => $r['error']]);
}
// ---- 登记待执行项，返回令牌（不执行） ----
if ($act === 'propose') {
    $hostId = (int) ($_POST['host_id'] ?? 0);
    $convId = (int) ($_POST['conv_id'] ?? 0);
    $cmd    = trim((string) ($_POST['command'] ?? ''));

    $host = ssh_host_of($hostId, $uid);
    if (!$host) {
        json_out(['error' => '主机不存在或不属于你']);
    }
    if ($cmd === '') {
        json_out(['error' => '命令为空']);
    }

    $cls = ssh_classify($cmd);

    // 清理该用户的过期待确认项
    db_exec('UPDATE ssh_pending SET state=\'expired\'
             WHERE user_id=? AND state=\'wait\' AND expires_at < NOW()', [$uid]);

    $token = bin2hex(random_bytes(16));
    db_insert('INSERT INTO ssh_pending
        (token, user_id, host_id, conv_id, command, risk, created_at, expires_at)
        VALUES (?,?,?,?,?,?,NOW(), DATE_ADD(NOW(), INTERVAL 5 MINUTE))',
        [$token, $uid, $hostId, $convId, $cmd, $cls['risk']]);

    json_out(['ok' => 1, 'token' => $token, 'risk' => $cls['risk'],
        'command' => $cmd, 'host' => $host['name'] . '（' . $host['host'] . '）',
        'need_confirm' => 1]);
}

// ---- 确认执行 ----
if ($act === 'confirm') {
    $token = (string) ($_POST['token'] ?? '');
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        json_out(['error' => '令牌格式不正确']);
    }
    // 令牌必须属于当前用户且仍在等待状态
    $p = db_one('SELECT * FROM ssh_pending WHERE token=? AND user_id=? AND state=\'wait\'',
        [$token, $uid]);
    if (!$p) {
        json_out(['error' => '该命令已执行、已取消或不存在']);
    }
    if (strtotime((string) $p['expires_at']) < time()) {
        db_exec('UPDATE ssh_pending SET state=\'expired\' WHERE id=?', [$p['id']]);
        json_out(['error' => '确认已超时（超过 5 分钟），请重新发起']);
    }

    // 立刻标记为已用，防止同一令牌被重复提交
    $n = db_exec('UPDATE ssh_pending SET state=\'done\' WHERE id=? AND state=\'wait\'',
        [$p['id']]);
    if ($n < 1) {
        json_out(['error' => '该命令已被处理']);
    }

    $hostId = (int) $p['host_id'];
    $host   = ssh_host_of($hostId, $uid);
    if (!$host) {
        json_out(['error' => '主机不存在']);
    }
    $cmd = (string) $p['command'];

    $cls = ssh_classify($cmd);

    $c = ssh_connect($host);
    if (!$c['ok']) {
        ssh_log($uid, $hostId, (int) $p['conv_id'], $cmd, $cls['risk'], $c['error'], -1, '', 0);
        db_exec('UPDATE ssh_hosts SET last_error=? WHERE id=? AND user_id=?',
            [mb_substr($c['error'], 0, 250), $hostId, $uid]);
        json_out(['error' => $c['error']]);
    }
    if (trim((string) $host['fingerprint']) === '' && $c['fingerprint'] !== '') {
        db_exec('UPDATE ssh_hosts SET fingerprint=? WHERE id=? AND user_id=?',
            [$c['fingerprint'], $hostId, $uid]);
    }

    $r = ssh_exec($c['conn'], $cmd);
    db_exec('UPDATE ssh_hosts SET last_ok_at=NOW(), last_error=\'\' WHERE id=? AND user_id=?',
        [$hostId, $uid]);
    ssh_log($uid, $hostId, (int) $p['conv_id'], $cmd, $cls['risk'], '',
        $r['exit'], $r['out'], $r['ms']);

    json_out(['ok' => 1, 'out' => $r['out'], 'exit' => $r['exit'], 'ms' => $r['ms']]);
}

// ---- 取消 ----
if ($act === 'cancel') {
    $token = (string) ($_POST['token'] ?? '');
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        json_out(['error' => '令牌格式不正确']);
    }
    db_exec('UPDATE ssh_pending SET state=\'cancel\'
             WHERE token=? AND user_id=? AND state=\'wait\'', [$token, $uid]);
    json_out(['ok' => 1]);
}

// ---- 自己的执行记录 ----
if ($act === 'logs') {
    $rows = db_all('SELECT l.id, l.command, l.risk, l.deny_reason, l.exit_code,
                           l.duration_ms, l.created_at, h.name host_name, h.host
                    FROM ssh_logs l LEFT JOIN ssh_hosts h ON h.id=l.host_id
                    WHERE l.user_id=? ORDER BY l.id DESC LIMIT 100', [$uid]);
    json_out(['ok' => 1, 'logs' => $rows]);
}

json_out(['error' => '未知操作']);
