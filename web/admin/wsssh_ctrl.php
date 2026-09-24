<?php
/**
 * 维护备注：
 * PID 获取曾显示“未知”的原因：isRunning() 通过 sudo 执行
 * systemctl show -p MainPID --value wsssh-8801 获取主进程号；
 * 如果 www 用户的 sudoers 未放行这条 show 命令，exec() 会返回空，
 * PID 会被转换为 0，虽然端口已监听，页面仍会显示“未知”。
 * 当前 sudoers 已放行该命令，实测可正常返回 PID。
 */

/**
 * WebSocket SSH 服务控制器
 * 提供状态查询、启动、停止等功能
 */

require_once __DIR__ . '/../inc/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if (!$u || $u['role'] !== 'admin') {
    echo json_encode(['ok' => false, 'error' => '无权限']);
    exit;
}

$port = 8801;
$logFile = __DIR__ . '/../assets/wsssh/server.log';
$sessionDir = __DIR__ . '/../assets/wsssh';

if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0755, true);
}

/**
 * 服务由 systemd 单元 wsssh-8801 托管（/etc/systemd/system/wsssh-8801.service）。
 *
 * 为什么不再用 nohup 自己拉进程：从 PHP-FPM 里 nohup 出来的子进程挂在 FPM worker 名下，
 * worker 被回收（php-fpm reload、达到 max_requests）时会把它一起带走，
 * 表现就是终端「用一阵子就连不上了」。systemd 托管后进程独立于 FPM，
 * 还能 Restart=always 自愈、开机自启。
 */
const WSSSH_UNIT = 'wsssh-8801';

/** 跑一条 systemctl 子命令，返回 [退出码, 输出] */
function sysctl(string $sub): array {
    $out = [];
    $ret = 1;
    // systemctl 需要 root；php-fpm 是 www，靠 sudoers 白名单授权这三条子命令
    @exec('sudo -n /usr/bin/systemctl ' . escapeshellarg($sub) . ' ' . WSSSH_UNIT . ' 2>&1',
          $out, $ret);
    return [$ret, trim(implode("\n", $out))];
}

function isRunning($port) {
    // 以端口能否连通为准：systemd 说 active 但端口没起来（比如刚启动还在初始化）
    // 对用户没意义，用户关心的是「现在能不能连」。
    $pid  = 0;
    $o    = [];
    @exec('sudo -n /usr/bin/systemctl show -p MainPID --value ' . WSSSH_UNIT . ' 2>/dev/null',
          $o);
    $pid  = (int) trim(implode('', $o));
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if ($conn) {
        fclose($conn);
        return ['running' => true, 'pid' => $pid];
    }
    return ['running' => false, 'pid' => 0];
}

function getSessions($sessionDir) {
    $file = $sessionDir . '/sessions.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

if ($action === 'status') {
    $status = isRunning($port);
    $sessions = getSessions($sessionDir);
    echo json_encode([
        'ok' => true,
        'running' => $status['running'],
        'pid' => $status['pid'],
        'port' => $port,
        'sessions' => $sessions,
        'session_count' => count($sessions),
    ]);
    exit;
}

if ($action === 'start') {
    $status = isRunning($port);
    if ($status['running']) {
        echo json_encode(['ok' => false, 'error' => '服务已在运行中', 'pid' => $status['pid']]);
        exit;
    }
    [$ret, $out] = sysctl('start');
    // 启动是异步的，等一下再看端口，不然刚 start 完必然报「还没起来」
    usleep(800000);
    $check = isRunning($port);
    if ($check['running']) {
        echo json_encode(['ok' => true, 'pid' => $check['pid'], 'msg' => '服务启动成功']);
    } else {
        $log = is_file($logFile) ? mb_substr((string) @file_get_contents($logFile), -2000) : '无日志';
        echo json_encode(['ok' => false,
            'error' => $out !== '' ? $out : '服务启动失败', 'log' => $log]);
    }
    exit;
}

if ($action === 'stop') {
    $status = isRunning($port);
    if (!$status['running']) {
        echo json_encode(['ok' => false, 'error' => '服务未运行']);
        exit;
    }
    // 必须走 systemctl stop：单元里配了 Restart=always，
    // 直接 kill 掉 systemd 会立刻再拉起来，看起来就像「停不掉」。
    [$ret, $out] = sysctl('stop');
    usleep(500000);
    $check = isRunning($port);
    echo json_encode($check['running']
        ? ['ok' => false, 'error' => $out !== '' ? $out : '停止失败']
        : ['ok' => true, 'msg' => '服务已停止']);
    exit;
}

if ($action === 'restart') {
    // 改完 wsssh_server.php 后必须重启才能生效：老进程一直跑在内存里的旧代码，
    // 这也是之前「代码已经修好了但终端还是连不上」的原因。
    [$ret, $out] = sysctl('restart');
    usleep(1000000);
    $check = isRunning($port);
    if ($check['running']) {
        echo json_encode(['ok' => true, 'pid' => $check['pid'], 'msg' => '服务已重启']);
    } else {
        $log = is_file($logFile) ? mb_substr((string) @file_get_contents($logFile), -2000) : '无日志';
        echo json_encode(['ok' => false,
            'error' => $out !== '' ? $out : '重启后端口未监听', 'log' => $log]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => '未知操作']);
