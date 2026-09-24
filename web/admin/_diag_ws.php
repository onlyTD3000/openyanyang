<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP_SAPI: " . PHP_SAPI . "\n";
echo "运行用户: " . (function_exists('posix_geteuid') ? posix_geteuid() : 'n/a') . "\n";
echo "disable_functions: " . ini_get('disable_functions') . "\n";
echo "open_basedir: " . ini_get('open_basedir') . "\n";
echo "---\n";

// 1. 测 exec 是否可用
$out = []; $ret = -1;
@exec('echo EXEC_OK', $out, $ret);
echo "exec echo: ret=$ret out=" . implode(',', $out) . "\n";

// 2. 测 sudo systemctl show 拿 PID
$out2 = []; $ret2 = -1;
@exec('sudo -n /usr/bin/systemctl show -p MainPID --value wsssh-8801 2>&1', $out2, $ret2);
echo "sudo systemctl: ret=$ret2 out=" . trim(implode('', $out2)) . "\n";

// 3. 测 sudo -n 是否被允许
$out3 = []; $ret3 = -1;
@exec('sudo -n /usr/bin/systemctl is-active wsssh-8801 2>&1', $out3, $ret3);
echo "sudo is-active: ret=$ret3 out=" . trim(implode('', $out3)) . "\n";

// 4. 测 fsockopen 8801 端口
$conn = @fsockopen('127.0.0.1', 8801, $errno, $errstr, 1);
if ($conn) { echo "port 8801: 可连接\n"; fclose($conn); }
else { echo "port 8801: 连接失败 errno=$errno errstr=$errstr\n"; }

// 5. 直接读 /proc 拿 PID（不依赖 sudo）
$pidFromProc = 0;
$files = glob('/proc/[0-9]*');
foreach ($files as $f) {
    $cmdline = @file_get_contents($f . '/cmdline');
    if ($cmdline && strpos($cmdline, 'wsssh_server.php') !== false) {
        $pidFromProc = (int) basename($f);
        break;
    }
}
echo "proc 找 PID: " . ($pidFromProc ? $pidFromProc : '未找到') . "\n";

// 6. 测 shell_exec 是否被禁（备选方案）
if (function_exists('shell_exec')) {
    $o = @shell_exec('sudo -n /usr/bin/systemctl show -p MainPID --value wsssh-8801 2>&1');
    echo "shell_exec sudo: " . trim((string)$o) . "\n";
} else {
    echo "shell_exec 不可用\n";
}