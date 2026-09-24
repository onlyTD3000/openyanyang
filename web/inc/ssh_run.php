<?php
/**
 * SSH 连接与命令执行。基于 phpseclib 3（纯 PHP，不依赖 ssh2 扩展）。
 *
 * 为什么不用 ssh2 扩展：宝塔环境普遍没装，且 PHP 8 下 pecl 版本维护滞后。
 * phpseclib 无编译依赖，换服务器不会再卡在扩展上。
 *
 * 安全要点：
 *   - 只连用户自己登记的主机，host_id 必须配 user_id
 *   - 首次连接记录主机指纹，之后指纹变更即拒连（防中间人）
 *   - 单命令超时，输出截断，防止长时间占用与内存打爆
 *   - 命令原文与结果全部落审计日志
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/crypto.php';

/* 超时上限受 PHP-FPM 的 request_terminate_timeout 与 nginx 的
   fastcgi_read_timeout 双重约束，两者当前都是 300 秒。SSH_TIMEOUT 必须留出
   建连、认证与回包的余量，所以取 240，再往上加会变成 502 而不是超时提示。 */
const SSH_TIMEOUT      = 240;    // 单条命令最长执行秒数
const SSH_OUT_LIMIT    = 40000;  // 输出最多保留字符数
const SSH_CONNECT_WAIT = 20;     // 建连超时秒数

/**
 * 取用户自己的主机记录。取不到说明不存在或不属于该用户。
 */
function ssh_host_of(int $hostId, int $userId): ?array
{
    $row = db_one('SELECT * FROM ssh_hosts WHERE id=? AND user_id=? AND status=1',
        [$hostId, $userId]);
    return $row ?: null;
}

/**
 * 建立连接并认证。
 *
 * @return array{ok:bool, conn:mixed, error:string, fingerprint:string}
 */
function ssh_connect(array $host): array
{
    $deny = ssh_host_deny_reason((string) $host['host']);
    if ($deny !== '') {
        return ['ok' => false, 'conn' => null, 'error' => $deny, 'fingerprint' => ''];
    }

    try {
        $conn = new \phpseclib3\Net\SSH2(
            (string) $host['host'], (int) $host['port'], SSH_CONNECT_WAIT);
        // 主机密钥算法顺序必须与历史保持一致，否则同一台机器会算出不同指纹，
        // 已登记的主机会被误判为中间人攻击。
        $conn->setPreferredAlgorithms([
            'hostkey' => ['ssh-rsa', 'ssh-dss', 'ecdsa-sha2-nistp256', 'ssh-ed25519'],
        ]);
        $fp = ssh_fingerprint_of($conn);
    } catch (\Throwable $e) {
        return ['ok' => false, 'conn' => null,
            'error' => '无法连接到主机，请检查地址、端口与防火墙', 'fingerprint' => ''];
    }
    if ($fp === '') {
        return ['ok' => false, 'conn' => null,
            'error' => '无法读取主机密钥，连接中断', 'fingerprint' => ''];
    }

    // 指纹校验：首次记录，之后不允许变更
    $saved = trim((string) $host['fingerprint']);
    if ($saved !== '' && !hash_equals($saved, $fp)) {
        return ['ok' => false, 'conn' => null,
            'error' => '主机密钥指纹与首次连接时不一致，已中断（可能存在中间人攻击）。如果确实更换过服务器，请在「我的服务器」里删除后重新登记。',
            'fingerprint' => $fp];
    }

    $user = (string) $host['username'];
    $ok   = false;
    try {
        if ($host['auth_type'] === 'password') {
            $pwd = dec_secret((string) $host['secret_enc']);
            if ($pwd === '') {
                return ['ok' => false, 'conn' => null,
                    'error' => '凭据解密失败，请重新登记该主机', 'fingerprint' => $fp];
            }
            $ok = $conn->login($user, $pwd);
        } else {
            $pem = dec_secret((string) $host['secret_enc']);
            if ($pem === '') {
                return ['ok' => false, 'conn' => null,
                    'error' => '凭据解密失败，请重新登记该主机', 'fingerprint' => $fp];
            }
            $pass = dec_secret((string) $host['key_pass_enc']);
            // phpseclib 直接吃私钥内容，不必落盘，也不用再从私钥导出公钥
            try {
                $key = $pass !== ''
                    ? \phpseclib3\Crypt\PublicKeyLoader::load($pem, $pass)
                    : \phpseclib3\Crypt\PublicKeyLoader::load($pem);
            } catch (\Throwable $e) {
                return ['ok' => false, 'conn' => null,
                    'error' => '私钥格式无法识别，或口令不正确', 'fingerprint' => $fp];
            }
            $ok = $conn->login($user, $key);
        }
    } catch (\Throwable $e) {
        return ['ok' => false, 'conn' => null,
            'error' => '认证过程出错，请检查用户名与密码/私钥', 'fingerprint' => $fp];
    }
    if (!$ok) {
        return ['ok' => false, 'conn' => null,
            'error' => '认证失败，请检查用户名与密码/私钥', 'fingerprint' => $fp];
    }
    return ['ok' => true, 'conn' => $conn, 'error' => '', 'fingerprint' => $fp];
}

/**
 * 算主机密钥指纹：SHA1(hex, 大写)。
 * 与旧 ssh2_fingerprint(SHA1|HEX) 的输出格式保持一致，老记录才不会失效。
 */
function ssh_fingerprint_of(\phpseclib3\Net\SSH2 $conn): string
{
    $k = $conn->getServerPublicHostKey();
    if (!is_string($k) || $k === '') {
        return '';
    }
    $sp = strpos($k, ' ');
    if ($sp === false) {
        return '';
    }
    $blob = base64_decode(substr($k, $sp + 1), true);
    return $blob === false ? '' : strtoupper(sha1($blob));
}

/**
 * 由私钥导出 OpenSSH 格式公钥。
 * ssh2_auth_pubkey_file 需要公钥文件，但用户通常只提供私钥。
 */
function ssh_derive_pubkey(string $privPem, string $pass = ''): string
{
    $res = $pass !== ''
        ? @openssl_pkey_get_private($privPem, $pass)
        : @openssl_pkey_get_private($privPem);
    if ($res === false) {
        return '';
    }
    $det = @openssl_pkey_get_details($res);
    if (!$det || ($det['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) {
        // 仅支持 RSA 导出；其他类型返回空，由调用方提示
        return '';
    }
    $e = $det['rsa']['e'];
    $n = $det['rsa']['n'];
    $pack = function (string $s): string {
        // 最高位为 1 时补 0x00，符合 SSH mpint 规范
        if (strlen($s) > 0 && (ord($s[0]) & 0x80)) {
            $s = "\x00" . $s;
        }
        return pack('N', strlen($s)) . $s;
    };
    $blob = $pack('ssh-rsa') . $pack($e) . $pack($n);
    return 'ssh-rsa ' . base64_encode($blob) . " kiro\n";
}

/**
 * 在已认证连接上执行一条命令。
 *
 * @return array{ok:bool, out:string, exit:int, ms:int, error:string}
 */
function ssh_exec($conn, string $cmd): array
{
    $t0 = microtime(true);
    if (!$conn instanceof \phpseclib3\Net\SSH2) {
        return ['ok' => false, 'out' => '', 'exit' => -1, 'ms' => 0, 'error' => '连接无效'];
    }
    // 包一层：远端加超时保护，stderr 合并进 stdout，末尾带出退出码
    $wrapped = 'timeout ' . SSH_TIMEOUT . ' bash -lc '
        . escapeshellarg($cmd) . ' 2>&1; echo "__RC__$?"';
    $conn->setTimeout(SSH_TIMEOUT + 5);
    try {
        $out = $conn->exec($wrapped);
    } catch (\Throwable $e) {
        return ['ok' => false, 'out' => '', 'exit' => -1,
            'ms' => (int) round((microtime(true) - $t0) * 1000), 'error' => '命令下发失败'];
    }
    if (!is_string($out)) {
        return ['ok' => false, 'out' => '', 'exit' => -1,
            'ms' => (int) round((microtime(true) - $t0) * 1000), 'error' => '命令下发失败'];
    }
    if ($conn->isTimeout()) {
        $out .= "\n[已达超时上限，连接中断]";
    }
    // 超长先截一刀，避免后面的正则和入库吃掉大量内存
    if (strlen($out) > SSH_OUT_LIMIT * 4) {
        /* substr 按字节切，切点很容易落在一个多字节字符中间。
           留着半个字符，写审计日志时 MySQL 会直接拒收整条记录，
           请求 500 —— 命令明明跑成功了，用户却什么都看不到。
           所以截断后必须把结尾的残字节裁掉。 */
        $out = ssh_utf8_trim_tail(substr($out, 0, SSH_OUT_LIMIT * 4))
            . "\n[输出过长，已截断]";
    }

    // 取出退出码
    $exit = -1;
    if (preg_match('/__RC__(\d+)\s*$/', $out, $mm)) {
        $exit = (int) $mm[1];
        $out  = (string) preg_replace('/__RC__\d+\s*$/', '', $out);
    }
    $out = rtrim($out);
    if (mb_strlen($out) > SSH_OUT_LIMIT) {
        $out = mb_substr($out, 0, SSH_OUT_LIMIT) . "\n[输出已截断，共 " . mb_strlen($out) . " 字符]";
    }
    return ['ok' => true, 'out' => $out, 'exit' => $exit,
        'ms' => (int) round((microtime(true) - $t0) * 1000), 'error' => ''];
}

/* ---------- 后台任务模式 ----------
   同步执行有个绕不过去的天花板：nginx 的 fastcgi_read_timeout 和 FPM 的
   request_terminate_timeout 都是 300 秒，请求再久就是 502/504。可下载、编译、
   装依赖这类活儿本来就可能跑十几分钟。
   所以命令不再由 PHP 阻塞等待，而是一律用 nohup 丢到远端后台，输出重定向到
   日志文件，PHP 立刻返回任务号。前端隔一会儿拉一次进度：短命令第一次拉就拿到
   全部输出，看起来跟同步没差别；长命令就一直拉到它自己跑完。
   任务状态全放远端文件里，不进平台数据库 —— 平台这边不需要维护任何生命周期，
   远端文件在就是在、没了就是没了，不会出现两边状态不一致。
   每个任务三个文件（都在 SSH_JOB_DIR 下，以任务号命名）：
     <id>.log   合并了 stderr 的输出，命令边跑边往里写
     <id>.pid   包装进程的 PID，用来判断还活着没有、以及要杀的时候杀谁
     <id>.rc    退出码，命令结束才出现 —— 所以「这个文件存在」就等于「跑完了」
*/
const SSH_JOB_DIR = '/tmp/.kiro_jobs';
/** 任务号：时间戳加随机串。带时间是为了在服务器上 ls 一眼能看出先后。 */
function ssh_job_new_id(): string
{
    return date('Ymd_His') . '_' . bin2hex(random_bytes(4));
}
/** 任务号格式校验。这个值会拼进 shell 命令，必须严格限死字符集。 */
function ssh_job_id_ok(string $id): bool
{
    return (bool) preg_match('/^[0-9]{8}_[0-9]{6}_[0-9a-f]{8}$/', $id);
}
/**
 * 把命令丢到远端后台跑，立刻返回。
 *
 * setsid 让包装进程脱离当前 SSH 会话自己组队 —— 不脱离的话，PHP 这边连接一断，
 * SIGHUP 会把整棵进程树带走，后台就白后台了。
 *
 * @return array{ok:bool, job:string, error:string}
 */
function ssh_job_start($conn, string $cmd): array
{
    if (!$conn instanceof \phpseclib3\Net\SSH2) {
        return ['ok' => false, 'job' => '', 'error' => '连接无效'];
    }
    $job = ssh_job_new_id();
    $dir = SSH_JOB_DIR;
    $log = $dir . '/' . $job . '.log';
    $pid = $dir . '/' . $job . '.pid';
    $rc  = $dir . '/' . $job . '.rc';
    /* 内层：跑用户的命令，输出进 log，结束把退出码写进 rc。
       rc 必须最后写，前端靠它判断跑完没有。
       stdin 接 /dev/null：交互式命令（apt 不带 -y 之类）会立刻收到 EOF 而不是
       悄悄挂在那里等输入，等于永不结束的任务。 */
    $inner = 'exec </dev/null >' . escapeshellarg($log) . ' 2>&1; '
        . 'bash -lc ' . escapeshellarg($cmd) . '; '
        . 'echo $? > ' . escapeshellarg($rc);
    /* 外层：建目录、setsid 起后台、记下 PID。
       mkdir -m 700 是因为日志里可能有敏感输出，同机其他用户不该看到。 */
    $launch = 'mkdir -p -m 700 ' . escapeshellarg($dir) . ' && '
        . 'setsid bash -c ' . escapeshellarg($inner) . ' & '
        . 'echo $! > ' . escapeshellarg($pid) . '; '
        . 'sleep 0.2; echo __JOB_OK__';
    $conn->setTimeout(30);
    try {
        $out = $conn->exec($launch);
    } catch (\Throwable $e) {
        return ['ok' => false, 'job' => '', 'error' => '任务启动失败：命令下发出错'];
    }
    if (!is_string($out) || strpos($out, '__JOB_OK__') === false) {
        $提示 = is_string($out) ? trim($out) : '';
        return ['ok' => false, 'job' => '',
            'error' => '任务启动失败' . ($提示 !== '' ? '：' . mb_substr($提示, 0, 200) : '')];
    }
    return ['ok' => true, 'job' => $job, 'error' => ''];
}
/**
 * 切掉字符串末尾不完整的 UTF-8 字节序列。
 *
 * 日志是按字节截取的，边界很可能落在一个多字节字符中间。
 * 残字节会让 json_encode 直接失败（返回 false），所以宁可少给几个字节，
 * 反正下一轮轮询会从正确的偏移把它完整取回来。
 */
function ssh_utf8_trim_tail(string $s): string
{
    if ($s === '') { return ''; }
    // 最多回退 3 字节：UTF-8 单字符最长 4 字节
    for ($i = 0; $i < 4; $i++) {
        if ($s === '') { return ''; }
        if (preg_match('//u', $s)) { return $s; }   // 整串合法就收工
        $s = substr($s, 0, -1);
    }
    return $s;
}
/**
 * 把字符串里所有非法 UTF-8 字节替换掉，保证能安全入库和 json_encode。
 *
 * 和 ssh_utf8_trim_tail 的分工：trim_tail 只管结尾被切断的那几个字节，
 * 用于「内容本身是好的，只是切歪了」的场景，不损失可读内容；
 * 这个函数管整串任意位置的坏字节，用于入库前兜底 —— 命令输出可能压根
 * 就不是 UTF-8（二进制文件、GBK 日志），那种情况只能替换。
 */
function ssh_utf8_clean(string $s): string
{
    if ($s === '' || preg_match('//u', $s)) {
        return $s;                                  // 本来就合法，原样返回
    }
    // substChar 设成 ? 而不是默认的 U+FFFD，避免日志里满屏问号方块
    $old = mb_substitute_character();
    mb_substitute_character(0x3F);
    $r = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    mb_substitute_character($old);
    return is_string($r) ? $r : '';
}
/**
 * 读任务进度。
 *
 * $from 是已经读到的字节偏移，只取后面新增的部分，前端拿去往输出框末尾追加。
 * 这样长任务反复轮询也不会每次重传整份日志。
 *
 * 判断是否结束只看 .rc 文件在不在，不看进程 —— 进程可能刚退出而 rc 已写好，
 * 也可能被 kill 掉而 rc 永远不出现，后者靠 alive 字段区分。
 *
 * @return array{ok:bool, done:bool, exit:int, out:string, size:int, alive:bool, error:string}
 */
function ssh_job_tail($conn, string $job, int $from = 0): array
{
    $空 = ['ok' => false, 'done' => false, 'exit' => -1, 'out' => '',
           'size' => 0, 'alive' => false, 'error' => ''];
    if (!$conn instanceof \phpseclib3\Net\SSH2) {
        return array_merge($空, ['error' => '连接无效']);
    }
    if (!ssh_job_id_ok($job)) {
        return array_merge($空, ['error' => '任务号格式不正确']);
    }
    $dir = SSH_JOB_DIR;
    $log = $dir . '/' . $job . '.log';
    $pid = $dir . '/' . $job . '.pid';
    $rc  = $dir . '/' . $job . '.rc';
    if ($from < 0) { $from = 0; }
    /* 一次问清四件事，用固定分隔符隔开，省得来回几趟：
       日志总大小、退出码、进程还在不在、从 $from 开始的新增内容。
       字段顺序固定，下面按序解析。 */
    $q = 'S=$(stat -c %s ' . escapeshellarg($log) . ' 2>/dev/null || echo 0); '
        . 'echo "__SIZE__$S"; '
        . 'if [ -f ' . escapeshellarg($rc) . ' ]; then echo "__RC__$(cat '
        . escapeshellarg($rc) . ' 2>/dev/null)"; else echo "__RC__none"; fi; '
        . 'P=$(cat ' . escapeshellarg($pid) . ' 2>/dev/null); '
        . 'if [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then echo "__ALIVE__1"; '
        . 'else echo "__ALIVE__0"; fi; '
        . 'echo "__OUT__"; '
        . 'tail -c +' . ($from + 1) . ' ' . escapeshellarg($log) . ' 2>/dev/null | head -c '
        . SSH_OUT_LIMIT;
    $conn->setTimeout(45);
    try {
        $raw = $conn->exec($q);
    } catch (\Throwable $e) {
        return array_merge($空, ['error' => '读取进度失败']);
    }
    if (!is_string($raw)) {
        return array_merge($空, ['error' => '读取进度失败']);
    }
    $size  = 0;
    $exit  = -1;
    $done  = false;
    $alive = false;
    if (preg_match('/__SIZE__(\d+)/', $raw, $m))  { $size = (int) $m[1]; }
    if (preg_match('/__RC__(\d+|none)/', $raw, $m)) {
        if ($m[1] !== 'none') { $done = true; $exit = (int) $m[1]; }
    }
    if (preg_match('/__ALIVE__(\d)/', $raw, $m)) { $alive = $m[1] === '1'; }
    $新增 = '';
    $pos = strpos($raw, "__OUT__\n");
    if ($pos !== false) {
        $新增 = substr($raw, $pos + 8);
    }
    /* 进程没了、rc 也没写出来：被 kill 了，或者机器重启了。
       这种任务永远等不到结束，必须就地判死，否则前端会一直轮询下去。 */
    if (!$done && !$alive) {
        /* 有个时间差：进程刚退出、rc 还没落盘的一瞬间也会走到这里。
           所以再确认一次 rc，避免把正常结束的任务误判成中断。 */
        try {
            $再 = $conn->exec('if [ -f ' . escapeshellarg($rc) . ' ]; then cat '
                . escapeshellarg($rc) . '; else echo none; fi');
        } catch (\Throwable $e) {
            $再 = 'none';
        }
        $再 = is_string($再) ? trim($再) : 'none';
        if ($再 !== 'none' && ctype_digit($再)) {
            $done = true;
            $exit = (int) $再;
        } else {
            $done = true;
            $exit = -2;   // 约定值：进程已不在但没有退出码，属于被中断
        }
    }
    /* 偏移必须按「实际读到的字节数」推进，在裁剪之前先算好。
       head -c 是按字节切的，很可能把一个多字节字符劈成两半：
       末尾留着半个字符会让 json_encode 返回 false，前端收到空响应。
       所以这里先记住真实长度，再把结尾的残字节切掉 —— 下一轮从 next
       开始读，那半个字符的前半段会被重新取一次，拼起来仍然完整。 */
    $next = $from + strlen($新增);
    /* 先裁掉结尾被切断的半个字符（这部分下轮会重新取回，不丢内容），
       再洗掉中间可能存在的坏字节 —— 日志里混进二进制或 GBK 内容时，
       json_encode 会直接返回 false，前端就收到一个空响应，
       表现是「进度突然不动了」，比丢几个字符难查得多。 */
    $新增 = ssh_utf8_clean(ssh_utf8_trim_tail($新增));
    return ['ok' => true, 'done' => $done, 'exit' => $exit, 'out' => $新增,
        'size' => $size, 'next' => $next, 'alive' => $alive, 'error' => ''];
}
/**
 * 终止任务。杀整个进程组（PID 取负），否则只杀掉包装进程，
 * 真正在干活的子进程（wget、make 之类）会变成孤儿继续跑。
 */
function ssh_job_kill($conn, string $job): array
{
    if (!$conn instanceof \phpseclib3\Net\SSH2) {
        return ['ok' => false, 'error' => '连接无效'];
    }
    if (!ssh_job_id_ok($job)) {
        return ['ok' => false, 'error' => '任务号格式不正确'];
    }
    $dir = SSH_JOB_DIR;
    $pid = $dir . '/' . $job . '.pid';
    $rc  = $dir . '/' . $job . '.rc';
    /* 先 TERM 给个体面退出的机会，两秒后还在就 KILL。
       最后补写 rc，让轮询端立刻看到「结束了」而不用等判死逻辑。 */
    $c = 'P=$(cat ' . escapeshellarg($pid) . ' 2>/dev/null); '
        . 'if [ -n "$P" ]; then kill -TERM -"$P" 2>/dev/null || kill -TERM "$P" 2>/dev/null; '
        . 'sleep 2; '
        . 'if kill -0 "$P" 2>/dev/null; then kill -KILL -"$P" 2>/dev/null || kill -KILL "$P" 2>/dev/null; fi; fi; '
        . 'if [ ! -f ' . escapeshellarg($rc) . ' ]; then echo 143 > ' . escapeshellarg($rc) . '; fi; '
        . 'echo __KILLED__';
    $conn->setTimeout(30);
    try {
        $out = $conn->exec($c);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => '终止失败'];
    }
    $ok = is_string($out) && strpos($out, '__KILLED__') !== false;
    return ['ok' => $ok, 'error' => $ok ? '' : '终止失败'];
}
/**
 * 清掉超过一天的任务残留文件，避免 /tmp 里越积越多。
 * 顺手在启动新任务时调一次就够，不值得单独跑定时任务。
 */
function ssh_job_gc($conn): void
{
    if (!$conn instanceof \phpseclib3\Net\SSH2) { return; }
    try {
        $conn->setTimeout(15);
        $conn->exec('find ' . escapeshellarg(SSH_JOB_DIR)
            . ' -maxdepth 1 -type f -mtime +1 -delete 2>/dev/null; true');
    } catch (\Throwable $e) { /* 清理失败无所谓，不影响主流程 */ }
}
/** 写审计日志。只增不改，用于事后追溯。 */
function ssh_log(int $userId, int $hostId, int $convId, string $cmd,
                 string $risk, string $denyReason, int $exitCode,
                 string $output, int $ms): void
{
    /* 入库前把非 UTF-8 字节洗掉。
       命令输出是完全不受控的：cat 一个二进制文件、程序吐 GBK 日志、
       或者上面按字节截断留下半个字符，都会带进非法序列。
       MySQL 的 utf8mb4 列遇到非法字节会整条拒收（错误 1366），
       结果是审计日志没写上、请求 500、用户丢掉本次命令的全部输出。
       宁可把坏字节换成 ?，也不能让它把整个请求带崩。 */
    $cmd    = ssh_utf8_clean($cmd);
    $output = ssh_utf8_clean($output);
    db_insert('INSERT INTO ssh_logs
        (user_id, host_id, conv_id, command, risk, deny_reason,
         exit_code, output, duration_ms, client_ip, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
        [$userId, $hostId, $convId, mb_substr($cmd, 0, 4000), $risk,
         mb_substr(ssh_utf8_clean($denyReason), 0, 250), $exitCode,
         mb_substr($output, 0, 60000), $ms,
         (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);
}
