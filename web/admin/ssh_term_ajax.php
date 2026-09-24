<?php
/**
 * 后台网页终端：命令执行接口（纯 JSON，不输出 HTML）
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/crypto.php';
require_once __DIR__ . '/../inc/ssh_guard.php';
require_once __DIR__ . '/../inc/ssh_run.php';

start_session();
$me = require_login_api();
if ($me['role'] !== 'admin') {
    json_out(['error' => '无权限']);
}

header('Content-Type: application/json; charset=utf-8');

$act = (string) ($_POST['act'] ?? $_GET['act'] ?? '');
$hostId = (int) ($_POST['host_id'] ?? $_GET['host_id'] ?? 0);

if ($hostId <= 0) { json_out(['error' => '主机ID无效']); }

$host = db_one('SELECT * FROM ssh_hosts WHERE id = ? LIMIT 1', [$hostId]);
if (!$host) { json_out(['error' => '主机不存在']); }

// 提前关闭 session，防止阻塞其他请求
session_write_close();

if ($act === 'connect') {
    try {
        $c = ssh_connect($host);
        if (!$c['ok']) { json_out(['error' => $c['error']]); }
        $r = ssh_exec($c['conn'], 'whoami; pwd; hostname');
        $lines = explode("
", trim($r['out']));
        $whoami = $lines[0] ?? '?';
        $pwd = $lines[1] ?? '/';
        $hostname = $lines[2] ?? '?';
        $banner = "连接成功！
用户: $whoami
主机: $hostname
目录: $pwd
";
        json_out([
            'ok' => 1,
            'prompt_user' => $whoami,
            'prompt_host' => $hostname,
            'cwd' => $pwd,
            'banner' => $banner
        ]);
    } catch (Throwable $e) {
        json_out(['error' => '连接异常: ' . $e->getMessage()]);
    }
}

if ($act === 'exec') {
    try {
        $cmd = (string) ($_POST['cmd'] ?? '');
        $cwd = (string) ($_POST['cwd'] ?? '/');
        if ($cmd === '') { json_out(['error' => '命令为空']); }
        $c = ssh_connect($host);
        if (!$c['ok']) { json_out(['error' => $c['error']]); }
        $safeCwd = escapeshellarg($cwd);
        $fullCmd = "cd $safeCwd 2>/dev/null; " . $cmd . '; echo "__CWD__$PWD"';
        $r = ssh_exec($c['conn'], $fullCmd);
        $out = $r['out'];
        $newCwd = $cwd;
        if (preg_match('/__CWD__(.+)s*$/', $out, $m)) {
            $newCwd = trim($m[1]);
            $out = preg_replace('/__CWD__.+s*$/', '', $out);
        }
        $out = rtrim($out);
        json_out(['ok' => 1, 'out' => $out, 'cwd' => $newCwd, 'exit' => $r['exit']]);
    } catch (Throwable $e) {
        json_out(['error' => '执行异常: ' . $e->getMessage()]);
    }
}

json_out(['error' => '未知操作']);
