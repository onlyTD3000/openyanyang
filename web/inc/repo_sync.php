<?php
/**
 * 代码仓与客户服务器之间的同步：拉取（pull）与回传（push）。
 *
 * 走 phpseclib 的 SFTP 通道，认证与指纹校验复用 inc/ssh_run.php 的同一套逻辑，
 * 因此主机指纹校验、user_id 归属校验、凭据解密这些安全保证是一致的。
 *
 * 安全要点：
 *   - 只连用户自己登记的主机；远程根目录锁定在项目的部署目录内。
 *   - 回传前先在服务器上打一份 tar.gz 备份，并把备份路径记进同步日志。
 *   - 回传只覆盖本地确实改过的文件（state=edited/new），不做整目录同步，
 *     绝不删除服务器上的文件。
 *   - 单次同步有文件数与体积上限，防止一次操作把内存或磁盘打满。
 */

require_once __DIR__ . '/repo.php';

/** 单次同步最多处理多少文件 */
const 同步文件上限 = 3000;
/** 递归目录的最大深度 */
const 同步深度上限 = 12;

/**
 * 开一条 SFTP 连接。
 *
 * SFTP 与 SSH2 在 phpseclib 里是两个类，不能从已有 SSH2 连接派生，
 * 所以这里复用 ssh_connect() 的认证与指纹校验流程，只把类换成 SFTP。
 *
 * @return \phpseclib3\Net\SFTP|null
 */
function 同步开SFTP(array $主机)
{
    $deny = ssh_host_deny_reason((string) $主机['host']);
    if ($deny !== '') {
        return null;
    }
    try {
        $sftp = new \phpseclib3\Net\SFTP(
            (string) $主机['host'], (int) $主机['port'], SSH_CONNECT_WAIT);
        // 与 ssh_connect() 保持同样的算法顺序，指纹才算得出一样的值
        $sftp->setPreferredAlgorithms([
            'hostkey' => ['ssh-rsa', 'ssh-dss', 'ecdsa-sha2-nistp256', 'ssh-ed25519'],
        ]);
        $fp = ssh_fingerprint_of($sftp);
        if ($fp === '') {
            return null;
        }
        // 指纹校验：和 SSH 通道同一条红线，变了就不连
        $saved = trim((string) $主机['fingerprint']);
        if ($saved !== '' && !hash_equals($saved, $fp)) {
            return null;
        }
        $user = (string) $主机['username'];
        if ($主机['auth_type'] === 'password') {
            $pwd = dec_secret((string) $主机['secret_enc']);
            if ($pwd === '' || !$sftp->login($user, $pwd)) {
                return null;
            }
        } else {
            $pem = dec_secret((string) $主机['secret_enc']);
            if ($pem === '') {
                return null;
            }
            $pass = dec_secret((string) $主机['key_pass_enc']);
            $key  = $pass !== ''
                ? \phpseclib3\Crypt\PublicKeyLoader::load($pem, $pass)
                : \phpseclib3\Crypt\PublicKeyLoader::load($pem);
            if (!$sftp->login($user, $key)) {
                return null;
            }
        }
        return $sftp;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * 校验远程目录形态。真正的越界防护在这里做一层，
 * 因为 SFTP 不经过 ssh_guard 的命令判定。
 */
function 同步校验远程目录(string $目录): string
{
    $d = trim($目录);
    if ($d === '' || $d[0] !== '/') {
        return '远程目录必须是绝对路径';
    }
    if (strpos($d, '..') !== false) {
        return '远程目录里不能出现 ..';
    }
    if (preg_match('/[\x00-\x1f]/', $d)) {
        return '远程目录含非法字符';
    }
    // 挡住几个明显不该整目录拉取的系统路径
    $禁 = ['/', '/etc', '/root', '/boot', '/dev', '/proc', '/sys', '/usr', '/var/lib'];
    if (in_array(rtrim($d, '/'), $禁, true)) {
        return '不允许同步系统目录：' . $d;
    }
    return '';
}

/**
 * 递归列出远程目录下的文件。
 *
 * @param \phpseclib3\Net\SFTP $sftp
 * @param string $根   远程根目录（绝对路径）
 * @return array{list:array, truncated:bool}
 */
function 同步列远程($sftp, string $根): array
{
    $出   = [];
    $截断 = false;
    $队   = [['', 0]];          // [相对路径, 深度]

    while ($队) {
        [$相对, $深] = array_shift($队);
        if ($深 > 同步深度上限) {
            continue;
        }
        $远程 = rtrim($根, '/') . ($相对 === '' ? '' : '/' . $相对);
        $表 = @$sftp->rawlist($远程);
        if (!is_array($表)) {
            continue;
        }
        foreach ($表 as $名 => $属) {
            if ($名 === '.' || $名 === '..' || $名 === '' || !is_array($属)) {
                continue;
            }
            $子相对 = $相对 === '' ? $名 : $相对 . '/' . $名;
            if (仓该忽略($子相对)) {
                continue;
            }
            // type: 1=文件 2=目录 3=软链接。软链接一律跳过，避免绕出目录。
            $类型 = (int) ($属['type'] ?? 0);
            if ($类型 === 2) {
                $队[] = [$子相对, $深 + 1];
                continue;
            }
            if ($类型 !== 1) {
                continue;
            }
            if (count($出) >= 同步文件上限) {
                $截断 = true;
                break;
            }
            $出[] = [
                'path'    => $子相对,
                'size'    => (int) ($属['size'] ?? 0),
                'is_text' => 仓是文本($子相对),
            ];
        }
        if ($截断) {
            break;
        }
    }
    return ['list' => $出, 'truncated' => $截断];
}

/**
 * 直连读一个远程文件，不经过本地副本、不需要 repo 记录。
 * 用于「只想看一两个文件」的场景，省掉整仓拉取。
 *
 * @return array{ok:bool, error:string, text:string, size:int, truncated:bool}
 */
function 远程读文件(array $主机, string $远程根, string $相对路径): array
{
    $空 = ['ok' => false, 'error' => '', 'text' => '', 'size' => 0, 'truncated' => false];
    $根 = rtrim($远程根, '/');
    $err = 同步校验远程目录($根);
    if ($err !== '') {
        return array_merge($空, ['error' => $err]);
    }
    $相对 = 仓规范路径($相对路径);
    if ($相对 === '') {
        return array_merge($空, ['error' => '路径不合法（需用部署目录内的相对路径）']);
    }
    if (仓该忽略($相对)) {
        return array_merge($空, ['error' => '这个路径在忽略名单里，不提供读取：' . $相对]);
    }
    $sftp = 同步开SFTP($主机);
    if (!$sftp) {
        return array_merge($空, ['error' => '无法打开 SFTP 通道']);
    }
    $全 = $根 . '/' . $相对;
    $属 = @$sftp->stat($全);
    if (!is_array($属)) {
        return array_merge($空, ['error' => '远端没有这个文件：' . $相对]);
    }
    $大小 = (int) ($属['size'] ?? 0);
    if ($大小 > 仓文件上限) {
        return array_merge($空, ['error' => '文件太大（' . size_text($大小) . '），超过单文件上限']);
    }
    if (!仓是文本($相对)) {
        return array_merge($空, ['error' => '这是二进制文件，不能按文本读取']);
    }
    $内容 = @$sftp->get($全);
    if ($内容 === false) {
        return array_merge($空, ['error' => '读取失败（可能没有权限）：' . $相对]);
    }
    $内容 = (string) $内容;
    $截断 = false;
    if (mb_strlen($内容) > 仓正文上限) {
        $内容 = mb_substr($内容, 0, 仓正文上限);
        $截断 = true;
    }
    return ['ok' => true, 'error' => '', 'text' => $内容,
            'size' => $大小, 'truncated' => $截断];
}
/**
 * 直连写一个远程文件。写前一定在服务器上留一份备份，备份失败就不写。
 *
 * @return array{ok:bool, error:string, size:int, 备份:string, 新建:bool}
 */
function 远程写文件(array $主机, string $远程根, string $相对路径, string $内容): array
{
    $空 = ['ok' => false, 'error' => '', 'size' => 0, '备份' => '', '新建' => false];
    $根 = rtrim($远程根, '/');
    $err = 同步校验远程目录($根);
    if ($err !== '') {
        return array_merge($空, ['error' => $err]);
    }
    $相对 = 仓规范路径($相对路径);
    if ($相对 === '') {
        return array_merge($空, ['error' => '路径不合法（需用部署目录内的相对路径）']);
    }
    if (仓该忽略($相对)) {
        return array_merge($空, ['error' => '这个路径在忽略名单里，不允许写入：' . $相对]);
    }
    if (!仓是文本($相对)) {
        return array_merge($空, ['error' => '只能写文本文件']);
    }
    if (strlen($内容) > 仓文件上限) {
        return array_merge($空, ['error' => '内容超过单文件上限']);
    }
    $c = ssh_connect($主机);
    if (!$c['ok']) {
        return array_merge($空, ['error' => $c['error']]);
    }
    $sftp = 同步开SFTP($主机);
    if (!$sftp) {
        return array_merge($空, ['error' => '无法打开 SFTP 通道']);
    }
    if (!@$sftp->is_dir($根)) {
        return array_merge($空, ['error' => '远程目录不存在：' . $根]);
    }
    $全   = $根 . '/' . $相对;
    $新建 = !is_array(@$sftp->stat($全));
    // 覆盖已有文件才需要备份；新建文件没有旧版可备
    $备份 = '';
    if (!$新建) {
        $备份 = 仓远程备份($c['conn'], $根, [$相对]);
        if ($备份 === '') {
            return array_merge($空, ['error' => '服务器端备份失败，已终止写入。'
                . '请检查远程目录是否可写、是否装了 tar。']);
        }
    }
    $父 = dirname($相对);
    if ($父 !== '' && $父 !== '.') {
        @$sftp->mkdir($根 . '/' . $父, -1, true);
    }
    if (@$sftp->put($全, $内容) === false) {
        return array_merge($空, ['error' => '写入失败（可能没有权限）：' . $相对]);
    }
    return ['ok' => true, 'error' => '', 'size' => strlen($内容),
            '备份' => $备份 ?: '(新建文件，无需备份)', '新建' => $新建];
}

function 仓拉取(array $仓, array $主机, bool $强制覆盖 = false): array
{
    $空 = ['ok' => false, 'error' => '', '新增' => 0, '更新' => 0, '跳过' => 0,
           '保护' => 0, '字节' => 0, '截断' => false];

    $远程根 = rtrim((string) $仓['remote_dir'], '/');
    $err = 同步校验远程目录($远程根);
    if ($err !== '') {
        return array_merge($空, ['error' => $err]);
    }

    $t0 = microtime(true);
    $c  = ssh_connect($主机);
    if (!$c['ok']) {
        return array_merge($空, ['error' => $c['error']]);
    }
    $sftp = 同步开SFTP($主机);
    if (!$sftp) {
        return array_merge($空, ['error' => '无法打开 SFTP 通道']);
    }

    // 远程根目录必须存在且是目录
    if (!@$sftp->is_dir($远程根)) {
        return array_merge($空, ['error' => '远程目录不存在或没有权限：' . $远程根]);
    }

    $列 = 同步列远程($sftp, $远程根);
    if (!$列['list']) {
        return array_merge($空, ['error' => '远程目录里没有可拉取的文件（或全部命中忽略规则）']);
    }

    $用户id = (int) $仓['user_id'];
    $本地根 = 仓根目录($用户id, (int) $仓['project_id']);
    $新增 = $更新 = $跳过 = $保护 = 0;
    $字节 = 0;
    $详情 = [];

    foreach ($列['list'] as $f) {
        if ($f['size'] > 仓文件上限) {
            $跳过++;
            $详情[] = '跳过（超过大小上限）：' . $f['path'];
            continue;
        }
        $全 = 仓内路径($本地根, $f['path']);
        if ($全 === '') {
            $跳过++;
            $详情[] = '跳过（路径非法）：' . $f['path'];
            continue;
        }

        $行 = db_one('SELECT * FROM repo_files WHERE repo_id = ? AND path = ? AND user_id = ? LIMIT 1',
            [(int) $仓['id'], $f['path'], $用户id]);

        // 本地已改过的文件，非强制模式下保护起来不覆盖
        if ($行 && !$强制覆盖 && in_array($行['state'], ['edited', 'new'], true)) {
            $保护++;
            $详情[] = '保留本地改动：' . $f['path'];
            continue;
        }

        $内容 = @$sftp->get($远程根 . '/' . $f['path']);
        if ($内容 === false) {
            $跳过++;
            $详情[] = '读取失败：' . $f['path'];
            continue;
        }

        $父 = dirname($全);
        if (!@is_dir($父) && !@mkdir($父, 0700, true)) {
            $跳过++;
            $详情[] = '建目录失败：' . $f['path'];
            continue;
        }
        if (@file_put_contents($全, $内容) === false) {
            $跳过++;
            $详情[] = '写入失败：' . $f['path'];
            continue;
        }
        落盘收尾($全, 0600);

        $哈希 = sha1($内容);
        $大小 = strlen($内容);
        $字节 += $大小;

        if ($行) {
            db_exec('UPDATE repo_files SET size=?, hash=?, remote_hash=?, is_text=?,
                            state=\'same\', updated_at=NOW()
                     WHERE id=? AND user_id=?',
                [$大小, $哈希, $哈希, $f['is_text'] ? 1 : 0, (int) $行['id'], $用户id]);
            $更新++;
        } else {
            db_insert('INSERT INTO repo_files
                (repo_id, user_id, path, size, hash, remote_hash, is_text, state, ver, updated_at)
                VALUES (?,?,?,?,?,?,?,\'same\',0,NOW())',
                [(int) $仓['id'], $用户id, $f['path'], $大小, $哈希, $哈希,
                 $f['is_text'] ? 1 : 0]);
            $新增++;
        }
    }

    // 远端已不存在、本地却还有记录的文件，标成 gone，但不删本地副本
    $远端集 = array_column($列['list'], 'path');
    if ($远端集 && !$列['truncated']) {
        $占位 = implode(',', array_fill(0, count($远端集), '?'));
        db_exec('UPDATE repo_files SET state = \'gone\', updated_at = NOW()
                  WHERE repo_id = ? AND user_id = ? AND state = \'same\'
                    AND path NOT IN (' . $占位 . ')',
            array_merge([(int) $仓['id'], $用户id], $远端集));
    }

    仓刷新统计((int) $仓['id'], $用户id);
    db_exec('UPDATE repos SET last_pull_at = NOW(), updated_at = NOW() WHERE id = ? AND user_id = ?',
        [(int) $仓['id'], $用户id]);

    $ms = (int) round((microtime(true) - $t0) * 1000);
    db_insert('INSERT INTO repo_syncs
        (repo_id, user_id, direction, ok, file_count, bytes, backup, detail, ms, client_ip, created_at)
        VALUES (?,?,\'pull\',1,?,?,\'\',?,?,?,NOW())',
        [(int) $仓['id'], $用户id, $新增 + $更新, $字节,
         mb_substr(implode("\n", $详情), 0, 60000), $ms,
         (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);

    return ['ok' => true, 'error' => '', '新增' => $新增, '更新' => $更新,
            '跳过' => $跳过, '保护' => $保护, '字节' => $字节, '截断' => $列['truncated']];
}

/**
 * 列出待回传的文件：只有本地确实改过的（edited 已改 / new 新增）。
 * state=same 的不推，gone（远端已删）的也不推，避免把客户删掉的文件又送回去。
 */
function 仓待回传(array $仓, array $限定路径 = []): array
{
    $参 = [(int) $仓['id'], (int) $仓['user_id']];
    $sql = 'SELECT * FROM repo_files
             WHERE repo_id = ? AND user_id = ? AND state IN (\'edited\',\'new\')';
    if ($限定路径) {
        $限定路径 = array_slice(array_values(array_unique($限定路径)), 0, 200);
        $sql .= ' AND path IN (' . implode(',', array_fill(0, count($限定路径), '?')) . ')';
        $参 = array_merge($参, $限定路径);
    }
    $sql .= ' ORDER BY path LIMIT ' . 同步文件上限;
    return db_all($sql, $参);
}

/**
 * 挑出回传清单里需要人工确认的文件。
 *
 * 两类：
 *   1. 敏感配置。这类文件线上那份通常和本地不一样（数据库密码、密钥、
 *      域名），一旦被本地版本覆盖，站点当场连不上库。这是完整入仓
 *      放开忽略规则之后新出现的风险，之前这些路径根本进不了仓。
 *   2. 压缩包。回传后落在部署目录里，也就是网站根目录下，
 *      任何人拿到 URL 就能下载整套源码。
 *
 * 只负责识别和说明，拦不拦由调用方决定。
 *
 * @return array 每项 ['path'=>..., 'why'=>...]
 */
function 仓回传风险(array $待): array
{
    // 敏感文件名：不分大小写，按 basename 比
    $敏感名 = ['.env', '.env.local', '.env.production', '.env.prod',
               'config.local.php', 'config.prod.php', 'database.php',
               '.htpasswd', 'id_rsa', 'id_ed25519', '.npmrc', '.pypirc'];
    // 敏感路径片段
    $敏感段 = ['/.env', '/.git/', '/.ssh/'];
    // 压缩包扩展名
    $包扩展 = ['zip', 'tar', 'gz', 'tgz', 'bz2', 'rar', '7z'];

    $出 = [];
    foreach ($待 as $行) {
        $路径 = (string) $行['path'];
        $名 = strtolower(basename($路径));
        $扩 = strtolower((string) pathinfo($路径, PATHINFO_EXTENSION));
        $低 = strtolower('/' . $路径);

        if (in_array($名, $敏感名, true)) {
            $出[] = ['path' => $路径, 'why' => '敏感配置，线上那份可能含不同的密码或密钥，覆盖后站点可能起不来'];
            continue;
        }
        foreach ($敏感段 as $段) {
            if (strpos($低, $段) !== false) {
                $出[] = ['path' => $路径, 'why' => '敏感目录或文件，覆盖线上版本风险高'];
                continue 2;
            }
        }
        if (in_array($扩, $包扩展, true)) {
            $出[] = ['path' => $路径, 'why' => '压缩包会落在网站根目录下，可能被公网直接下载，导致源码泄露'];
            continue;
        }
        // 密钥类：按扩展名兜一层
        if (in_array($扩, ['pem', 'key', 'p12', 'pfx', 'jks', 'keystore'], true)) {
            $出[] = ['path' => $路径, 'why' => '证书或私钥文件，不该覆盖线上版本'];
        }
    }
    return $出;
}

/**
 * 在服务器上给即将被覆盖的文件打一份备份。
 *
 * 用 tar 打包到远程根目录同级的隐藏目录里，只打本次要改的文件，
 * 这样体积小、恢复也快。返回备份文件的绝对路径，失败返回空串。
 */
function 仓远程备份($conn, string $远程根, array $路径表): string
{
    if (!$路径表) {
        return '';
    }
    $根 = rtrim($远程根, '/');
    $备份目录 = $根 . '/.kiro_backup';
    $名 = 'bak_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.tar.gz';

    // 只备份远端已存在的文件，新增文件没有可备份的旧版
    $清单 = '';
    foreach ($路径表 as $p) {
        $清单 .= $p . "\n";
    }
    $列表文件 = $备份目录 . '/.list_' . bin2hex(random_bytes(4));

    // 分步执行：建目录 → 写清单 → 过滤出已存在的文件 → 打包 → 删清单
    $cmd = 'mkdir -p ' . escapeshellarg($备份目录)
         . ' && cat > ' . escapeshellarg($列表文件) . " << 'KIROLIST'\n" . $清单 . "KIROLIST\n"
         . ' && cd ' . escapeshellarg($根)
         . ' && : > ' . escapeshellarg($列表文件 . '.ok')
         . ' && while IFS= read -r f; do [ -f "$f" ] && printf "%s\\n" "$f" >> '
         . escapeshellarg($列表文件 . '.ok') . '; done < ' . escapeshellarg($列表文件)
         . ' && if [ -s ' . escapeshellarg($列表文件 . '.ok') . ' ]; then tar -czf '
         . escapeshellarg($备份目录 . '/' . $名) . ' -T ' . escapeshellarg($列表文件 . '.ok')
         . ' 2>/dev/null && echo BAKOK; else echo NOFILE; fi;'
         . ' rm -f ' . escapeshellarg($列表文件) . ' ' . escapeshellarg($列表文件 . '.ok');

    $r = ssh_exec($conn, $cmd);
    if (!$r['ok']) {
        return '';
    }
    if (strpos($r['out'], 'BAKOK') !== false) {
        return $备份目录 . '/' . $名;
    }
    if (strpos($r['out'], 'NOFILE') !== false) {
        return '(本次全是新增文件，无需备份)';
    }
    return '';
}

/**
 * 把本地改动回传到服务器。
 *
 * @param array $限定路径 只回传这些路径；空数组表示回传全部改动
 * @return array{ok:bool, error:string, 成功:int, 失败:int, 备份:string, 明细:array}
 */
function 仓回传(array $仓, array $主机, array $限定路径 = []): array
{
    $空 = ['ok' => false, 'error' => '', '成功' => 0, '失败' => 0, '备份' => '', '明细' => []];

    $远程根 = rtrim((string) $仓['remote_dir'], '/');
    $err = 同步校验远程目录($远程根);
    if ($err !== '') {
        return array_merge($空, ['error' => $err]);
    }

    $待 = 仓待回传($仓, $限定路径);
    if (!$待) {
        return array_merge($空, ['error' => '没有需要回传的改动']);
    }

    $t0 = microtime(true);
    $c  = ssh_connect($主机);
    if (!$c['ok']) {
        return array_merge($空, ['error' => $c['error']]);
    }
    $sftp = 同步开SFTP($主机);
    if (!$sftp) {
        return array_merge($空, ['error' => '无法打开 SFTP 通道']);
    }

    // 远程根目录必须已存在，不替客户凭空创建整个站点目录
    if (!@$sftp->is_dir($远程根)) {
        return array_merge($空, ['error' => '远程目录不存在：' . $远程根]);
    }

    // 先备份，失败就不推。宁可不改，也不能改坏了没有退路。
    $备份 = 仓远程备份($c['conn'], $远程根, array_column($待, 'path'));
    if ($备份 === '') {
        return array_merge($空, ['error' => '服务器端备份失败，为安全起见已终止回传。'
            . '请检查远程目录是否可写、是否装了 tar。']);
    }

    $用户id = (int) $仓['user_id'];
    $本地根 = 仓根目录($用户id, (int) $仓['project_id']);
    $成功 = $失败 = 0;
    $明细 = [];

    foreach ($待 as $行) {
        $全 = 仓内路径($本地根, (string) $行['path']);
        if ($全 === '' || !@is_file($全)) {
            $失败++;
            $明细[] = ['path' => $行['path'], 'ok' => 0, 'msg' => '本地文件不存在'];
            continue;
        }
        $内容 = @file_get_contents($全);
        if ($内容 === false) {
            $失败++;
            $明细[] = ['path' => $行['path'], 'ok' => 0, 'msg' => '本地读取失败'];
            continue;
        }

        // 远端子目录不存在时逐级创建（只在远程根之内）
        $子目录 = trim(dirname((string) $行['path']), '.');
        if ($子目录 !== '' && $子目录 !== '/') {
            $累 = $远程根;
            foreach (explode('/', trim($子目录, '/')) as $段) {
                $累 .= '/' . $段;
                if (!@$sftp->is_dir($累)) {
                    @$sftp->mkdir($累, 0755);
                }
            }
        }

        $远程文件 = $远程根 . '/' . $行['path'];
        if (@$sftp->put($远程文件, $内容) === false) {
            $失败++;
            $明细[] = ['path' => $行['path'], 'ok' => 0, 'msg' => '远端文件无法写入（权限不足？）'];
            continue;
        }
        // 复核远端大小，确认写全了
        $远尺 = @$sftp->filesize($远程文件);
        if ($远尺 !== false && (int) $远尺 !== strlen($内容)) {
            $失败++;
            $明细[] = ['path' => $行['path'], 'ok' => 0, 'msg' => '写入不完整'];
            continue;
        }

        // 回传成功，本地状态归位为 same，remote_hash 更新成刚推上去的内容
        $哈希 = sha1($内容);
        db_exec('UPDATE repo_files SET state = \'same\', remote_hash = ?, updated_at = NOW()
                 WHERE id = ? AND user_id = ?',
            [$哈希, (int) $行['id'], $用户id]);
        $成功++;
        $明细[] = ['path' => $行['path'], 'ok' => 1, 'msg' => '已回传 ' . size_text(strlen($内容))];
    }

    db_exec('UPDATE repos SET last_push_at = NOW(), updated_at = NOW() WHERE id = ? AND user_id = ?',
        [(int) $仓['id'], $用户id]);

    $ms = (int) round((microtime(true) - $t0) * 1000);
    $文 = [];
    foreach ($明细 as $d) {
        $文[] = ($d['ok'] ? '✓ ' : '✗ ') . $d['path'] . ' — ' . $d['msg'];
    }
    db_insert('INSERT INTO repo_syncs
        (repo_id, user_id, direction, ok, file_count, bytes, backup, detail, ms, client_ip, created_at)
        VALUES (?,?,\'push\',?,?,?,?,?,?,?,NOW())',
        [(int) $仓['id'], $用户id, $失败 === 0 ? 1 : 0, $成功,
         array_sum(array_map(fn($r) => (int) $r['size'], $待)), $备份,
         mb_substr(implode("\n", $文), 0, 60000), $ms,
         (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);

    return ['ok' => $成功 > 0, 'error' => $失败 > 0 ? ($失败 . ' 个文件回传失败') : '',
            '成功' => $成功, '失败' => $失败, '备份' => $备份, '明细' => $明细];
}
