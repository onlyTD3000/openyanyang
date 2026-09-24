<?php
/**
 * 工作中心代码仓：本地副本的存取、版本与回滚。
 *
 * 设计要点：
 *   - 文件正文落盘在 DATA_DIR/projects/<用户id>/<项目id>/，不进数据库。
 *     该目录在网站根目录之外，URL 拿不到，一律走鉴权接口读。
 *   - 所有读写都必须先过 仓内路径()，把 ../ 一类越界路径挡在门外。
 *   - 每次改动前先存旧版本，可 diff、可回滚。
 *   - 二进制文件只记元信息，不给 AI 看正文，也不允许 AI 覆写。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** 单文件大小上限：超过就不收，防止把整个数据目录塞满 */
const 仓文件上限 = 2097152;          // 2 MB
/** 单仓文件数上限 */
const 仓文件数上限 = 3000;
/** 单仓总体积上限 */
const 仓体积上限 = 209715200;        // 200 MB
/** 给 AI 看的正文最大字符数，超了要求它改用补丁方式 */
const 仓正文上限 = 30000;

/** 不拉取的目录与文件：体积大、无意义、或含敏感信息 */
function 仓忽略规则(): array
{
    return [
        '.git', '.svn', '.hg', 'node_modules', '.idea', '.vscode',
        '__pycache__', '.DS_Store', 'Thumbs.db', '.env',
        // 第三方依赖：体积大、不该由 AI 改，整个目录排除
        'vendor', 'composer.phar',
        // 分发包与上传物：APK、安装包、数据库导出
        'download', 'uploads', 'backup', 'backups',
        // 历史备份目录（本项目习惯用中文目录名存旧版本）
        '.sk_bak', '.kiro_backup', '.trae', '.备份', '.备份归档',
        // 敏感配置：含数据库账号密码，绝不进本地副本
        'config.local.php',
    ];
}

/** 按通配符排除的文件名规则，补 仓忽略规则() 整段匹配之不足 */
function 仓忽略通配(): array
{
    return [
        '*.bak', '*.apk', '*.ipa', '*.log', '*.zip', '*.tar', '*.tar.gz', '*.tgz',
        '*.gz', '*.rar', '*.7z', '*.sql', '*.pptx', '*.docx', '*.xlsx',
        '*.jar', '*.war', '*.exe', '*.dmg', '*.iso', '*.img',
        '*.mp4', '*.mov', '*.avi', '*.mkv', '*.psd',
    ];
}

/** 视为文本的扩展名。不在列表里的按二进制处理，只记元信息。 */
function 仓文本扩展(): array
{
    return [
        'php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'css', 'scss', 'less', 'html', 'htm',
        'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'sql', 'md', 'txt', 'sh', 'bash',
        'py', 'rb', 'go', 'java', 'c', 'h', 'cpp', 'cs', 'rs', 'lua', 'pl', 'env',
        'htaccess', 'gitignore', 'lock', 'log', 'csv', 'tpl', 'twig', 'blade',
        // Kotlin / 安卓工程：不加这些，安卓端源码在 SFTP 通道里会被当成二进制拒掉
        'kt', 'kts', 'gradle', 'properties', 'pro', 'cfg',
        // 其余常见文本类型，一并补齐
        'mjs', 'cjs', 'toml', 'swift', 'hpp', 'cc', 'm', 'mm',
        'bat', 'cmd', 'ps1', 'ftl', 'jsp', 'srt', 'vtt', 'diff', 'patch',
    ];
}

/** 按扩展名判断是否文本文件 */
function 仓是文本(string $路径): bool
{
    $扩 = strtolower((string) pathinfo($路径, PATHINFO_EXTENSION));
    if ($扩 === '') {
        // 无扩展名的常见文本文件
        $名 = strtolower(basename($路径));
        return in_array($名, ['dockerfile', 'makefile', 'readme', 'license'], true);
    }
    return in_array($扩, 仓文本扩展(), true);
}

/** 路径是否命中忽略规则 */
function 仓该忽略(string $相对路径): bool
{
    $段 = explode('/', str_replace('\\', '/', $相对路径));
    foreach (仓忽略规则() as $规则) {
        if (in_array($规则, $段, true)) {
            return true;
        }
        if (strpos($相对路径, $规则) === 0) {
            return true;
        }
    }
    // 旁挂备份：原名后面接「.改xxx前_月日_时分」或「.bak.时间戳」这类尾巴
    if (preg_match('/\.(bak|old|orig)[._-]?\d*$/iu', $相对路径)
        || preg_match('/前_\d{4,8}_\d{4,6}$/u', $相对路径)
        || preg_match('/\.\d{9,}$/u', $相对路径)) {
        return true;
    }
    // 再按通配符比对文件名，命中 *.bak / *.apk / *.log 这类也排除
    $文件名 = basename($相对路径);
    foreach (仓忽略通配() as $通配) {
        if (fnmatch($通配, $文件名, FNM_CASEFOLD)) {
            return true;
        }
    }
    return false;
}

/**
 * 仓在磁盘上的根目录。不存在就建。
 */
function 仓根目录(int $用户id, int $项目id): string
{
    $dir = rtrim(DATA_DIR, '/') . '/projects/' . $用户id . '/' . $项目id;
    if (!@is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/** 版本文件的存放根目录，与代码文件分开放，避免被当成项目文件回传 */
function 仓版本目录(int $用户id, int $项目id): string
{
    $dir = rtrim(DATA_DIR, '/') . '/versions/' . $用户id . '/' . $项目id;
    if (!@is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * 把相对路径解析成仓内绝对路径，并确保没有越界。
 *
 * 这是整个模块的安全闸门：拒绝绝对路径、拒绝 ..、拒绝软链接跳出仓外。
 * 返回空串表示路径非法，调用方必须当失败处理。
 */
/**
 * 把外部传入的路径规范成「仓内相对路径」。不合法返回空串。
 * 读、写、查库都走这一个函数，避免各处 ltrim 规则不一致，
 * 导致校验放行的路径和 DB 里存的 path 对不上。
 */
function 仓规范路径(string $相对路径): string
{
    $p = str_replace('\\', '/', trim($相对路径));
    if ($p === '' || $p[0] === '/' || preg_match('/^[A-Za-z]:/', $p)) {
        return '';
    }
    if (strlen($p) > 500) {
        return '';
    }
    $段 = [];
    foreach (explode('/', $p) as $s) {
        if ($s === '' || $s === '.') {
            continue;
        }
        if ($s === '..' || preg_match('/[\x00-\x1f]/', $s)) {
            return '';
        }
        $段[] = $s;
    }
    return $段 ? implode('/', $段) : '';
}

function 仓内路径(string $仓根, string $相对路径): string
{
    $相对 = 仓规范路径($相对路径);
    if ($相对 === '') {
        return '';
    }
    $全 = rtrim($仓根, '/') . '/' . $相对;

    // 再用 realpath 复核一遍：文件已存在时，确保它真的落在仓内（挡软链接）
    $根真 = realpath($仓根);
    if ($根真 === false) {
        return '';
    }
    $真 = realpath($全);
    if ($真 !== false
        && strncmp($真, $根真 . '/', strlen($根真) + 1) !== 0
        && $真 !== $根真) {
        return '';
    }
    return $全;
}

/**
 * 取项目对应的仓记录，没有就创建一条。
 * 仓与项目一对一，project_id 上有唯一键。
 */
function 仓取或建(int $项目id, int $用户id, int $主机id = 0, string $远程目录 = ''): array
{
    $仓 = db_one('SELECT * FROM repos WHERE project_id = ? AND user_id = ? LIMIT 1',
        [$项目id, $用户id]);
    if ($仓) {
        // 主机或目录变了就同步过来，保证与项目档案一致
        if (($主机id > 0 && (int) $仓['host_id'] !== $主机id)
            || ($远程目录 !== '' && $仓['remote_dir'] !== $远程目录)) {
            db_exec('UPDATE repos SET host_id = ?, remote_dir = ?, updated_at = NOW()
                     WHERE id = ? AND user_id = ?',
                [$主机id > 0 ? $主机id : (int) $仓['host_id'],
                 $远程目录 !== '' ? $远程目录 : $仓['remote_dir'],
                 (int) $仓['id'], $用户id]);
            $仓 = db_one('SELECT * FROM repos WHERE id = ?', [(int) $仓['id']]);
        }
        return $仓;
    }
    $id = db_insert('INSERT INTO repos
        (user_id, project_id, host_id, remote_dir, created_at, updated_at)
        VALUES (?,?,?,?,NOW(),NOW())',
        [$用户id, $项目id, $主机id, $远程目录]);
    return db_one('SELECT * FROM repos WHERE id = ?', [$id]);
}

/** 按 id 取仓，必须属于该用户 */
function 仓取(int $仓id, int $用户id): ?array
{
    $r = db_one('SELECT * FROM repos WHERE id = ? AND user_id = ? LIMIT 1', [$仓id, $用户id]);
    return $r ?: null;
}

/** 重算仓的文件数与体积 */
function 仓刷新统计(int $仓id, int $用户id): void
{
    db_exec('UPDATE repos SET
                file_count  = (SELECT COUNT(*) FROM repo_files WHERE repo_id = ?),
                total_bytes = (SELECT COALESCE(SUM(size),0) FROM repo_files WHERE repo_id = ?),
                updated_at  = NOW()
             WHERE id = ? AND user_id = ?',
        [$仓id, $仓id, $仓id, $用户id]);
}

/**
 * 读取仓内一个文件的正文。
 *
 * @return array{ok:bool, text:string, error:string, row:?array}
 */
function 仓读文件(array $仓, string $相对路径): array
{
    $相对 = 仓规范路径($相对路径);
    if ($相对 === '') {
        return ['ok' => false, 'text' => '', 'error' => '路径不合法（需用仓内相对路径，不能用绝对路径或 ..）', 'row' => null];
    }
    $行 = db_one('SELECT * FROM repo_files WHERE repo_id = ? AND path = ? AND user_id = ? LIMIT 1',
        [(int) $仓['id'], $相对, (int) $仓['user_id']]);
    if (!$行) {
        return ['ok' => false, 'text' => '', 'error' => '仓里没有这个文件：' . $相对路径, 'row' => null];
    }
    if ((int) $行['is_text'] !== 1) {
        return ['ok' => false, 'text' => '', 'error' => '这是二进制文件，不能按文本读取', 'row' => $行];
    }
    $根 = 仓根目录((int) $仓['user_id'], (int) $仓['project_id']);
    $全 = 仓内路径($根, $相对路径);
    if ($全 === '' || !@is_file($全)) {
        return ['ok' => false, 'text' => '', 'error' => '文件在磁盘上不存在，建议重新拉取', 'row' => $行];
    }
    $内容 = @file_get_contents($全);
    if ($内容 === false) {
        return ['ok' => false, 'text' => '', 'error' => '文件读取失败', 'row' => $行];
    }
    return ['ok' => true, 'text' => $内容, 'error' => '', 'row' => $行];
}

/**
 * 存一份旧版本，供 diff 与回滚。文件首次入库（拉取）时也存，作为基线。
 */
function 仓存版本(array $仓, array $文件行, string $旧内容, string $动作,
                  int $对话id = 0, string $说明 = ''): int
{
    $新版 = (int) $文件行['ver'] + 1;
    $目录 = 仓版本目录((int) $仓['user_id'], (int) $仓['project_id']) . '/' . (int) $文件行['id'];
    if (!@is_dir($目录)) {
        @mkdir($目录, 0700, true);
    }
    @file_put_contents($目录 . '/' . $新版, $旧内容);
    落盘收尾($目录 . '/' . $新版, 0600);

    db_insert('INSERT INTO repo_versions
        (file_id, repo_id, user_id, ver, size, hash, action, conv_id, note, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,NOW())',
        [(int) $文件行['id'], (int) $仓['id'], (int) $仓['user_id'], $新版,
         strlen($旧内容), sha1($旧内容), $动作, $对话id, mb_substr($说明, 0, 250)]);
    return $新版;
}

/**
 * 写入（覆写）仓内一个文件。写前自动存旧版本。
 *
 * @param string $动作 write 整文件覆写 / patch 补丁 / rollback 回滚
 * @return array{ok:bool, error:string, ver:int, size:int, new:bool}
 */
function 仓写文件(array $仓, string $相对路径, string $新内容, string $动作 = 'write',
                  int $对话id = 0, string $说明 = ''): array
{
    $用户id = (int) $仓['user_id'];
    $路径   = 仓规范路径($相对路径);
    if ($路径 === '') {
        return ['ok' => false, 'error' => '路径不合法（需用仓内相对路径，不能用绝对路径或 ..）',
                'ver' => 0, 'size' => 0, 'new' => false];
    }

    if (仓该忽略($路径)) {
        return ['ok' => false, 'error' => '这个路径在忽略列表里，不允许写入', 'ver' => 0, 'size' => 0, 'new' => false];
    }
    if (!仓是文本($路径)) {
        return ['ok' => false, 'error' => '只允许写文本文件，二进制文件请手动处理', 'ver' => 0, 'size' => 0, 'new' => false];
    }
    if (strlen($新内容) > 仓文件上限) {
        return ['ok' => false, 'error' => '内容超过 ' . round(仓文件上限 / 1048576, 1) . ' MB 上限',
                'ver' => 0, 'size' => 0, 'new' => false];
    }
    $根 = 仓根目录($用户id, (int) $仓['project_id']);
    $全 = 仓内路径($根, $路径);
    if ($全 === '') {
        return ['ok' => false, 'error' => '路径非法（不能用绝对路径或 ..）', 'ver' => 0, 'size' => 0, 'new' => false];
    }

    $行 = db_one('SELECT * FROM repo_files WHERE repo_id = ? AND path = ? AND user_id = ? LIMIT 1',
        [(int) $仓['id'], $路径, $用户id]);

    // 新增文件时检查配额
    if (!$行) {
        $数 = (int) db_val('SELECT COUNT(*) FROM repo_files WHERE repo_id = ?', [(int) $仓['id']]);
        if ($数 >= 仓文件数上限) {
            return ['ok' => false, 'error' => '仓内文件数已达上限 ' . 仓文件数上限,
                    'ver' => 0, 'size' => 0, 'new' => false];
        }
        $体 = (int) db_val('SELECT COALESCE(SUM(size),0) FROM repo_files WHERE repo_id = ?', [(int) $仓['id']]);
        if ($体 + strlen($新内容) > 仓体积上限) {
            return ['ok' => false, 'error' => '仓体积已达上限 ' . round(仓体积上限 / 1048576) . ' MB',
                    'ver' => 0, 'size' => 0, 'new' => false];
        }
    }

    $父 = dirname($全);
    if (!@is_dir($父) && !@mkdir($父, 0700, true)) {
        return ['ok' => false, 'error' => '无法创建目录，请检查数据目录权限', 'ver' => 0, 'size' => 0, 'new' => false];
    }

    // 先存旧版本，再写新内容
    $新版 = 0;
    if ($行) {
        $旧 = @is_file($全) ? (string) @file_get_contents($全) : '';
        $新版 = 仓存版本($仓, $行, $旧, $动作, $对话id, $说明);
    }

    if (@file_put_contents($全, $新内容) === false) {
        return ['ok' => false, 'error' => '写入磁盘失败', 'ver' => 0, 'size' => 0, 'new' => false];
    }
    落盘收尾($全, 0600);

    $哈希 = sha1($新内容);
    if ($行) {
        // 内容回到与远端一致时，状态改回 same，避免无意义回传
        $状态 = ($哈希 === $行['remote_hash'] && $行['remote_hash'] !== '') ? 'same' : 'edited';
        db_exec('UPDATE repo_files SET size = ?, hash = ?, state = ?, ver = ?, updated_at = NOW()
                 WHERE id = ? AND user_id = ?',
            [strlen($新内容), $哈希, $状态, $新版, (int) $行['id'], $用户id]);
    } else {
        $新id = db_insert('INSERT INTO repo_files
            (repo_id, user_id, path, size, hash, remote_hash, is_text, state, ver, updated_at)
            VALUES (?,?,?,?,?,?,1,\'new\',0,NOW())',
            [(int) $仓['id'], $用户id, $路径, strlen($新内容), $哈希, '']);
        $行 = ['id' => $新id, 'ver' => 0];
    }
    仓刷新统计((int) $仓['id'], $用户id);

    return ['ok' => true, 'error' => '', 'ver' => $新版,
            'size' => strlen($新内容), 'new' => !isset($行['path'])];
}

/**
 * 原样写入仓内文件：不按忽略规则筛，也不要求必须是文本。
 *
 * 用途只有一个：用户主动上传时选了「完整入仓」，那就按他说的收全，
 * 包括 vendor、图片、压缩包这类平时拉取会排除的东西。
 * 放宽的只是「收不收」这一层，路径安全（不许绝对路径与 ..）、
 * 文件数上限、仓体积上限一律照旧，避免把数据目录塞爆。
 *
 * @param int $单文件上限 传 0 用默认的 仓文件上限；压缩包原样收下时可放大
 * @return array{ok:bool, error:string, ver:int, size:int, new:bool}
 */
function 仓写原始文件(array $仓, string $相对路径, string $新内容,
                      string $说明 = '上传', int $单文件上限 = 0): array
{
    $用户id = (int) $仓['user_id'];
    $路径   = 仓规范路径($相对路径);
    if ($路径 === '') {
        return ['ok' => false, 'error' => '路径不合法（需用仓内相对路径，不能用绝对路径或 ..）',
                'ver' => 0, 'size' => 0, 'new' => false];
    }
    $上限 = $单文件上限 > 0 ? min($单文件上限, 仓体积上限) : 仓文件上限;
    if (strlen($新内容) > $上限) {
        return ['ok' => false, 'error' => '文件超过 ' . size_text($上限) . ' 上限',
                'ver' => 0, 'size' => 0, 'new' => false];
    }

    $根 = 仓根目录($用户id, (int) $仓['project_id']);
    $全 = 仓内路径($根, $路径);
    if ($全 === '') {
        return ['ok' => false, 'error' => '路径非法（不能用绝对路径或 ..）',
                'ver' => 0, 'size' => 0, 'new' => false];
    }

    $行 = db_one('SELECT * FROM repo_files WHERE repo_id = ? AND path = ? AND user_id = ? LIMIT 1',
        [(int) $仓['id'], $路径, $用户id]);

    if (!$行) {
        $数 = (int) db_val('SELECT COUNT(*) FROM repo_files WHERE repo_id = ?', [(int) $仓['id']]);
        if ($数 >= 仓文件数上限) {
            return ['ok' => false, 'error' => '仓内文件数已达上限 ' . 仓文件数上限,
                    'ver' => 0, 'size' => 0, 'new' => false];
        }
        $体 = (int) db_val('SELECT COALESCE(SUM(size),0) FROM repo_files WHERE repo_id = ?', [(int) $仓['id']]);
        if ($体 + strlen($新内容) > 仓体积上限) {
            return ['ok' => false, 'error' => '仓体积已达上限 ' . round(仓体积上限 / 1048576) . ' MB',
                    'ver' => 0, 'size' => 0, 'new' => false];
        }
    }

    $父 = dirname($全);
    if (!@is_dir($父) && !@mkdir($父, 0700, true)) {
        return ['ok' => false, 'error' => '无法创建目录，请检查数据目录权限',
                'ver' => 0, 'size' => 0, 'new' => false];
    }

    // 覆盖已有文件时先留一份旧版本，方便回滚
    $新版 = 0;
    if ($行) {
        $旧 = @is_file($全) ? (string) @file_get_contents($全) : '';
        $新版 = 仓存版本($仓, $行, $旧, 'write', 0, $说明);
    }

    if (@file_put_contents($全, $新内容) === false) {
        return ['ok' => false, 'error' => '写入磁盘失败', 'ver' => 0, 'size' => 0, 'new' => false];
    }
    落盘收尾($全, 0600);

    // 二进制标记按扩展名加空字节双重判断，标错会让在线编辑读出乱码
    $是文本 = 仓是文本($路径) && strpos($新内容, "\0") === false ? 1 : 0;
    $哈希   = sha1($新内容);

    if ($行) {
        $状态 = ($哈希 === $行['remote_hash'] && $行['remote_hash'] !== '') ? 'same' : 'edited';
        db_exec('UPDATE repo_files SET size = ?, hash = ?, is_text = ?, state = ?, ver = ?, updated_at = NOW()
                 WHERE id = ? AND user_id = ?',
            [strlen($新内容), $哈希, $是文本, $状态, $新版, (int) $行['id'], $用户id]);
        $新增 = false;
    } else {
        db_insert('INSERT INTO repo_files
            (repo_id, user_id, path, size, hash, remote_hash, is_text, state, ver, updated_at)
            VALUES (?,?,?,?,?,?,?,\'new\',0,NOW())',
            [(int) $仓['id'], $用户id, $路径, strlen($新内容), $哈希, '', $是文本]);
        $新增 = true;
    }
    仓刷新统计((int) $仓['id'], $用户id);

    return ['ok' => true, 'error' => '', 'ver' => $新版,
            'size' => strlen($新内容), 'new' => $新增];
}

/**
 * 删除仓内一个文件（只动本地副本，不碰客户服务器上的文件）。
 *
 * 删之前先把当前内容存成一个版本，所以误删可以从版本历史里翻回来。
 *
 * 两种情况分开处理：
 *   - state=new：这文件本来就只存在于本地副本，服务器上没有，直接从库里删干净；
 *   - 其它状态：服务器上有对应文件。这里只删本地记录，不产生「要去服务器删它」的
 *     指令——回传逻辑只推 edited/new，不做整目录同步，所以删掉本地记录后
 *     服务器上那份原封不动。想删服务器上的文件请直接在服务器上操作。
 *
 * @return array{ok:bool, error:string, ver:int, was:string, path:string, size:int}
 */
function 仓删文件(array $仓, string $相对路径, int $对话id = 0, string $说明 = ''): array
{
    $用户id = (int) $仓['user_id'];
    $路径   = 仓规范路径($相对路径);
    if ($路径 === '') {
        return ['ok' => false, 'error' => '路径不合法', 'ver' => 0, 'was' => '',
                'path' => $相对路径, 'size' => 0];
    }
    $行 = db_one('SELECT * FROM repo_files WHERE repo_id = ? AND path = ? AND user_id = ? LIMIT 1',
        [(int) $仓['id'], $路径, $用户id]);
    if (!$行) {
        return ['ok' => false, 'error' => '仓里没有这个文件：' . $路径, 'ver' => 0, 'was' => '',
                'path' => $路径, 'size' => 0];
    }
    // 先存版本再删。二进制文件（如 zip）也一并存，回滚才有东西可用
    $读 = 仓读文件($仓, $路径);
    $旧内容 = $读['ok'] ? (string) $读['text'] : '';
    $新版 = 仓存版本($仓, $行, $旧内容, 'delete', $对话id,
        $说明 !== '' ? $说明 : '删除文件');
    $实体 = 仓内路径(仓根目录($用户id, (int) $仓['project_id']), $路径);
    if ($实体 !== '' && @is_file($实体)) {
        @unlink($实体);
    }
    db_exec('DELETE FROM repo_files WHERE id = ? AND user_id = ?',
        [(int) $行['id'], $用户id]);
    仓刷新统计((int) $仓['id'], $用户id);
    return ['ok' => true, 'error' => '', 'ver' => $新版, 'was' => (string) $行['state'],
            'path' => $路径, 'size' => (int) $行['size']];
}

/**
 * 对文件做「查找 → 替换」式补丁。
 *
 * 为什么不用标准 diff：模型生成的 unified diff 行号常常是错的，应用成功率低。
 * 改成让模型给出「原片段 + 新片段」，按内容定位，稳得多。
 *
 * 匹配策略分三档，逐档放宽，任一档命中即用：
 *   1. 原样精确匹配；
 *   2. 行尾空白无视；
 *   3. 每行首尾空白全部无视（只比对内容骨架），命中后按原文缩进替换。
 * 三档都不中，或命中多处无法确定改哪个，一律失败并说明原因，绝不猜。
 *
 * @return array{ok:bool, error:string, text:string, 档:int}
 */
function 仓应用补丁(string $原文, string $查找, string $替换): array
{
    if ($查找 === '') {
        return ['ok' => false, 'error' => '查找片段为空', 'text' => '', '档' => 0];
    }

    // ---- 第 1 档：精确匹配 ----
    $次数 = substr_count($原文, $查找);
    if ($次数 === 1) {
        return ['ok' => true, 'error' => '',
                'text' => str_replace($查找, $替换, $原文), '档' => 1];
    }
    if ($次数 > 1) {
        return ['ok' => false, '档' => 1, 'text' => '',
                'error' => '查找片段在文件里出现了 ' . $次数 . ' 处，无法确定改哪一处。'
                         . '请把片段写长一些（多带几行上下文）使其唯一。'];
    }

    // 统一换行后再试，很多失败只是 \r\n 与 \n 的差别
    $原文n = str_replace(["\r\n", "\r"], "\n", $原文);
    $查找n = str_replace(["\r\n", "\r"], "\n", $查找);
    $替换n = str_replace(["\r\n", "\r"], "\n", $替换);
    $次数 = substr_count($原文n, $查找n);
    if ($次数 === 1) {
        return ['ok' => true, 'error' => '',
                'text' => str_replace($查找n, $替换n, $原文n), '档' => 1];
    }
    if ($次数 > 1) {
        return ['ok' => false, '档' => 1, 'text' => '',
                'error' => '查找片段出现 ' . $次数 . ' 处，无法定位。请多带几行上下文使其唯一。'];
    }

    // ---- 第 2、3 档：按行比对 ----
    $原行 = explode("\n", $原文n);
    $查行 = explode("\n", $查找n);
    $替行 = explode("\n", $替换n);
    $查数 = count($查行);
    if ($查数 === 0 || $查数 > count($原行)) {
        return ['ok' => false, 'error' => '查找片段与文件内容对不上（片段比文件还长）',
                'text' => '', '档' => 0];
    }

    // 第 2 档：忽略行尾空白
    $命中 = [];
    for ($i = 0; $i + $查数 <= count($原行); $i++) {
        $同 = true;
        for ($j = 0; $j < $查数; $j++) {
            if (rtrim($原行[$i + $j]) !== rtrim($查行[$j])) {
                $同 = false;
                break;
            }
        }
        if ($同) {
            $命中[] = $i;
        }
    }
    if (count($命中) === 1) {
        array_splice($原行, $命中[0], $查数, $替行);
        return ['ok' => true, 'error' => '', 'text' => implode("\n", $原行), '档' => 2];
    }
    if (count($命中) > 1) {
        return ['ok' => false, '档' => 2, 'text' => '',
                'error' => '忽略行尾空白后仍匹配到 ' . count($命中) . ' 处，请多带几行上下文使片段唯一。'];
    }

    // 第 3 档：忽略每行首尾空白，只比内容骨架
    $骨 = fn(string $s): string => trim($s);
    $命中 = [];
    for ($i = 0; $i + $查数 <= count($原行); $i++) {
        $同 = true;
        for ($j = 0; $j < $查数; $j++) {
            if ($骨($原行[$i + $j]) !== $骨($查行[$j])) {
                $同 = false;
                break;
            }
        }
        if ($同) {
            $命中[] = $i;
        }
    }
    if (count($命中) === 1) {
        $起 = $命中[0];
        // 用原文该处的缩进重新缩排新片段，避免把缩进搞乱
        $原缩进 = '';
        if (preg_match('/^[ \t]*/', $原行[$起], $mm)) {
            $原缩进 = $mm[0];
        }
        // 先算出新片段自身的公共缩进：整块剥掉它，再统一补上原文缩进。
        // 不能逐行 ltrim——那样会把块内的相对层次（if 里的语句、方法体）全压平，
        // 客户文件的缩进就被改乱了。
        $公共 = null;
        foreach ($替行 as $行文) {
            if (trim($行文) === '') {
                continue;               // 空行不参与公共缩进的计算
            }
            preg_match('/^[ \t]*/', $行文, $m3);
            if ($公共 === null || strlen($m3[0]) < strlen($公共)) {
                $公共 = $m3[0];
            }
        }
        $公共 = $公共 ?? '';
        $重排 = [];
        foreach ($替行 as $行文) {
            if (trim($行文) === '') {
                $重排[] = '';
                continue;
            }
            // 剥掉整块公共缩进，保留该行相对块首的额外缩进。
            // 公共缩进为空表示块首已顶格，此时不该动任何缩进。
            if ($公共 === '') {
                $去 = $行文;
            } elseif (strncmp($行文, $公共, strlen($公共)) === 0) {
                $去 = substr($行文, strlen($公共));
            } else {
                $去 = ltrim($行文, " \t");
            }
            $重排[] = $原缩进 . $去;
        }
        array_splice($原行, $起, $查数, $重排);
        return ['ok' => true, 'error' => '', 'text' => implode("\n", $原行), '档' => 3];
    }
    if (count($命中) > 1) {
        return ['ok' => false, '档' => 3, 'text' => '',
                'error' => '忽略缩进后仍匹配到 ' . count($命中) . ' 处，请多带几行上下文使片段唯一。'];
    }

    return ['ok' => false, '档' => 0, 'text' => '',
            'error' => '在文件里找不到这段内容。请先读一遍文件确认原文，'
                     . '或改用整文件覆写（file-write）。'];
}

/**
 * 回滚文件到指定版本。
 * 回滚本身也算一次改动，会把当前内容再存一版，所以回滚可以再撤销。
 *
 * @return array{ok:bool, error:string}
 */
function 仓回滚(array $仓, string $相对路径, int $目标版本, int $对话id = 0): array
{
    $读 = 仓读文件($仓, $相对路径);
    if (!$读['ok'] && $读['row'] === null) {
        return ['ok' => false, 'error' => $读['error']];
    }
    $行 = $读['row'];
    $版 = db_one('SELECT * FROM repo_versions WHERE file_id = ? AND ver = ? AND user_id = ? LIMIT 1',
        [(int) $行['id'], $目标版本, (int) $仓['user_id']]);
    if (!$版) {
        return ['ok' => false, 'error' => '找不到第 ' . $目标版本 . ' 版'];
    }
    $文件 = 仓版本目录((int) $仓['user_id'], (int) $仓['project_id'])
          . '/' . (int) $行['id'] . '/' . $目标版本;
    if (!@is_file($文件)) {
        return ['ok' => false, 'error' => '该版本的内容文件已丢失'];
    }
    $内容 = (string) @file_get_contents($文件);
    $r = 仓写文件($仓, $相对路径, $内容, 'rollback', $对话id, '回滚到第 ' . $目标版本 . ' 版');
    return ['ok' => $r['ok'], 'error' => $r['error']];
}

/**
 * 生成简易行级差异，供页面展示。
 * 只标出增删行，不做最优对齐，够看清改了什么即可。
 */
function 仓差异(string $旧, string $新, int $最多行 = 400): array
{
    $旧行 = explode("\n", str_replace(["\r\n", "\r"], "\n", $旧));
    $新行 = explode("\n", str_replace(["\r\n", "\r"], "\n", $新));

    // 掐头去尾，只对中间真正变化的区间做标注
    $头 = 0;
    while ($头 < count($旧行) && $头 < count($新行) && $旧行[$头] === $新行[$头]) {
        $头++;
    }
    $尾 = 0;
    while ($尾 < count($旧行) - $头 && $尾 < count($新行) - $头
           && $旧行[count($旧行) - 1 - $尾] === $新行[count($新行) - 1 - $尾]) {
        $尾++;
    }
    $删 = array_slice($旧行, $头, count($旧行) - $头 - $尾);
    $增 = array_slice($新行, $头, count($新行) - $头 - $尾);

    $出 = [];
    // 前文留 3 行上下文
    for ($i = max(0, $头 - 3); $i < $头; $i++) {
        $出[] = ['t' => ' ', 'n' => $i + 1, 's' => $旧行[$i]];
    }
    foreach ($删 as $k => $l) {
        $出[] = ['t' => '-', 'n' => $头 + $k + 1, 's' => $l];
    }
    foreach ($增 as $k => $l) {
        $出[] = ['t' => '+', 'n' => $头 + $k + 1, 's' => $l];
    }
    for ($i = 0; $i < 3 && ($头 + count($删) + $i) < count($旧行); $i++) {
        $出[] = ['t' => ' ', 'n' => $头 + count($删) + $i + 1,
                 's' => $旧行[$头 + count($删) + $i]];
    }
    // 截断：省出最后一格放提示行，保证总行数不超过 $最多行
    $截断 = false;
    if (count($出) > $最多行) {
        $截断 = true;
        $出 = array_slice($出, 0, max(1, $最多行 - 1));
        $出[] = ['t' => ' ', 'n' => 0, 's' => '…（差异过长，已截断）'];
    }
    // add/del 仍是真实的全量增删行数，不受显示截断影响
    return ['lines' => $出, 'add' => count($增), 'del' => count($删),
            'truncated' => $截断 ? 1 : 0];
}

/**
 * ---- AI 调用的英文包装函数 ----
 * 工具执行入口（tool_execute.php）通过 Function Calling 拿到的参数都是英文键名，
 * 为了不在入口层做一堆映射，这里给每个核心函数包一层英文名的壳。
 */

/**
 * Delete file from repository (wrapper for 仓删文件).
 */
function file_delete(array $仓, string $path, int $convId = 0, string $note = ''): array
{
    return 仓删文件($仓, $path, $convId, $note);
}
