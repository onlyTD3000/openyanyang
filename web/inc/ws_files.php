<?php
/**
 * 工作中心文件库：每个账号一份，图片和文件同库同列表。
 *
 * 设计要点：
 *  1. 严格按账号隔离。所有函数第一个参数就是 用户id，所有 SQL 都带 user_id 条件，
 *     落盘目录也按 用户id/年月 分开。账号 A 的 AI 拿不到账号 B 的任何东西——
 *     不是靠界面不显示，而是查询层面就查不出来。
 *  2. 库里 name 是「账号内的虚拟相对路径」，如 报价单.md、src/app.py。
 *     真实落盘名是随机串，扩展名由服务端决定，杜绝 .php 之类可执行后缀。
 *  3. 空间上限双层：账号单独配额（users.space_quota_mb）优先，
 *     为 0 时用后台全局默认（settings.upload_quota_mb）。
 *  4. 文本文件可以在线编辑，改写时留旧版本快照（同目录 .ver/），改坏能回退。
 */

require_once __DIR__ . '/helpers.php';

/** 单个文本文件体积上限 */
const 工作区文本上限 = 2097152;         // 2 MB
/** 一个账号最多存多少个文件，防止用碎文件把 inode 打满 */
const 工作区文件数上限 = 3000;
/** 可在线编辑的文本扩展名 */
const 工作区文本扩展 = 'txt,md,markdown,json,xml,yml,yaml,ini,conf,cfg,env,log,csv,tsv,sql,'
    . 'html,htm,css,scss,less,js,mjs,ts,jsx,tsx,vue,php,py,rb,go,java,kt,c,h,cpp,hpp,cs,'
    . 'sh,bash,bat,ps1,lua,pl,rs,swift,toml,properties,gitignore,htaccess,dockerfile,makefile';

/**
 * 账号的空间上限（字节）。账号配额优先，为 0 用全局默认。
 */
function 工作区配额(int $用户id): int
{
    $个人 = 0;
    try {
        $个人 = (int) db_val('SELECT space_quota_mb FROM users WHERE id=?', [$用户id]);
    } catch (Throwable $e) {
        $个人 = 0;          // 字段还没加，退回全局
    }
    if ($个人 > 0) {
        return $个人 * 1048576;
    }
    return max(10, (int) setting_get('upload_quota_mb', 200)) * 1048576;
}

/** 账号已用空间（字节）与文件数 */
function 工作区用量(int $用户id): array
{
    $r = db_one('SELECT COUNT(*) AS n, COALESCE(SUM(size),0) AS s FROM uploads WHERE user_id=?',
        [$用户id]);
    return ['count' => (int) ($r['n'] ?? 0), 'bytes' => (int) ($r['s'] ?? 0)];
}

/**
 * 配额检查。
 *
 * @param int $新增字节 本次要新增的字节数
 * @param int $抵扣字节 覆盖写时旧文件会被释放，这部分不该重复计算
 * @return string 空串表示允许，否则为拒绝原因
 */
function 工作区可写(int $用户id, int $新增字节, int $抵扣字节 = 0): string
{
    $用 = 工作区用量($用户id);
    $上 = 工作区配额($用户id);
    $后 = $用['bytes'] - $抵扣字节 + $新增字节;
    if ($后 > $上) {
        return '工作中心空间不足：上限 ' . size_text($上) . '，已用 ' . size_text($用['bytes'])
            . '，本次需要 ' . size_text($新增字节)
            . '。请先在工作中心删掉一些文件，或联系管理员提额。';
    }
    if ($抵扣字节 === 0 && $用['count'] >= 工作区文件数上限) {
        return '工作中心文件数已达上限（' . 工作区文件数上限 . ' 个），请先清理。';
    }
    return '';
}

/**
 * 规范化账号内的虚拟路径。
 *
 * 这是防越权的关键闸门之一：路径由 AI 给出，必须假定它可能带 ..、绝对路径、
 * 或者控制字符。规范化后的结果一定是「不以 / 开头、不含 .. 的相对路径」。
 *
 * @return string 规范路径；非法返回空串
 */
function 工作区规范名(string $名): string
{
    $s = str_replace('\\', '/', trim($名));
    if ($s === '' || strpos($s, "\0") !== false) {
        return '';
    }
    if (preg_match('/[\x00-\x1f]/', $s)) {
        return '';
    }
    $段 = [];
    foreach (explode('/', $s) as $x) {
        $x = trim($x);
        if ($x === '' || $x === '.') {
            continue;
        }
        if ($x === '..') {
            return '';                  // 一律拒绝，不做消解，避免歧义
        }
        // Windows 保留字符与首尾点，防止在不同系统上落盘出怪文件
        if (preg_match('~[:*?"<>|]~', $x)) {
            return '';
        }
        $段[] = $x;
    }
    if (!$段) {
        return '';
    }
    $出 = implode('/', $段);
    if (mb_strlen($出) > 200) {
        return '';
    }
    // 目录层级别太深，纯属防御
    if (count($段) > 8) {
        return '';
    }
    return $出;
}

/** 按扩展名判断是不是可在线编辑的文本 */
function 工作区是文本(string $名): bool
{
    $ext = strtolower(pathinfo($名, PATHINFO_EXTENSION));
    if ($ext === '') {
        // 没扩展名的按名字判断几个常见的
        return in_array(strtolower(basename($名)),
            ['dockerfile', 'makefile', 'readme', 'license', '.env', '.gitignore'], true);
    }
    return in_array($ext, explode(',', 工作区文本扩展), true);
}

/** 落盘用的真实相对路径：用户id/年月/随机名.ext */
function 工作区落盘名(int $用户id, string $虚拟名): string
{
    $ext = strtolower(pathinfo($虚拟名, PATHINFO_EXTENSION));
    // 扩展名只留字母数字，且不允许 php/phtml 之类可执行后缀落盘
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    if ($ext === '' || strlen($ext) > 12
        || in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'cgi', 'pl'], true)) {
        $ext = 'dat';               // 内容照存，只是落盘后缀无害化
    }
    return $用户id . '/' . date('Ym') . '/' . date('YmdHis') . '_'
        . bin2hex(random_bytes(8)) . '.' . $ext;
}

/** 相对路径转绝对路径，并确认没跑出 UPLOAD_DIR */
function 工作区绝对路径(string $相对): string
{
    $根 = rtrim(UPLOAD_DIR, '/');
    $全 = $根 . '/' . ltrim($相对, '/');
    // 目录可能还不存在，realpath 会返回 false，所以只做字符串校验
    if (strpos($全, '..') !== false) {
        return '';
    }
    if (strncmp($全, $根 . '/', strlen($根) + 1) !== 0) {
        return '';
    }
    return $全;
}

/**
 * 取一条文件记录。带 user_id 查，别人的文件查不出来。
 *
 * @param int|string $标识 数字视作 id，字符串视作账号内虚拟路径
 */
function 工作区取文件(int $用户id, $标识): ?array
{
    if (is_numeric($标识)) {
        $r = db_one('SELECT * FROM uploads WHERE id=? AND user_id=?',
            [(int) $标识, $用户id]);
        return $r ?: null;
    }
    $名 = 工作区规范名((string) $标识);
    if ($名 === '') {
        return null;
    }
    $r = db_one('SELECT * FROM uploads WHERE user_id=? AND name=? ORDER BY id DESC LIMIT 1',
        [$用户id, $名]);
    return $r ?: null;
}

/**
 * 读文本文件正文。
 *
 * @return array{ok:bool, error:string, text:string, row:array}
 */
function 工作区读文件(int $用户id, $标识): array
{
    $空 = ['ok' => false, 'error' => '', 'text' => '', 'row' => []];
    $行 = 工作区取文件($用户id, $标识);
    if (!$行) {
        return array_merge($空, ['error' => '工作中心里没有这个文件：' . (string) $标识]);
    }
    if ((string) $行['kind'] === 'image') {
        return array_merge($空, ['error' => '这是图片，不能按文本读取。'
            . '要让我看图请在对话里直接发送这张图片。', 'row' => $行]);
    }
    if ((string) $行['kind'] !== 'text') {
        return array_merge($空, ['error' => '这是二进制文件，不能按文本读取', 'row' => $行]);
    }
    $全 = 工作区绝对路径((string) $行['path']);
    if ($全 === '' || !is_file($全)) {
        return array_merge($空, ['error' => '文件记录存在但实体已丢失，请重新上传', 'row' => $行]);
    }
    $文 = (string) @file_get_contents($全);
    return ['ok' => true, 'error' => '', 'text' => $文, 'row' => $行];
}

/**
 * 写文本文件：不存在就新建，存在就覆盖（覆盖前留旧版本快照）。
 *
 * @param string $来源 user 或 ai
 * @return array{ok:bool, error:string, id:int, name:string, size:int, new:bool}
 */
function 工作区写文件(int $用户id, string $名, string $内容,
                      string $说明 = '', string $来源 = 'ai'): array
{
    $空 = ['ok' => false, 'error' => '', 'id' => 0, 'name' => '', 'size' => 0, 'new' => false];
    $规范 = 工作区规范名($名);
    if ($规范 === '') {
        return array_merge($空, ['error' => '文件名不合法。只能用账号内的相对路径，'
            . '不能带 .. 或以 / 开头，层级不超过 8 层。']);
    }
    if (!工作区是文本($规范)) {
        return array_merge($空, ['error' => '这个扩展名不在可编辑文本白名单里：' . $规范
            . '。二进制文件请用上传功能。']);
    }
    $长 = strlen($内容);
    if ($长 > 工作区文本上限) {
        return array_merge($空, ['error' => '内容超过 ' . size_text(工作区文本上限) . '，已拒绝']);
    }
    if (strpos($内容, "\0") !== false) {
        return array_merge($空, ['error' => '内容含 NUL 字符，不是文本，已拒绝']);
    }

    $旧 = 工作区取文件($用户id, $规范);
    if ($旧 && (string) $旧['kind'] === 'image') {
        return array_merge($空, ['error' => '同名的是一张图片，换个文件名吧：' . $规范]);
    }
    $旧字节 = $旧 ? (int) $旧['size'] : 0;

    $err = 工作区可写($用户id, $长, $旧字节);
    if ($err !== '') {
        return array_merge($空, ['error' => $err]);
    }

    // ---- 覆盖已有文件 ----
    if ($旧) {
        $全 = 工作区绝对路径((string) $旧['path']);
        if ($全 === '') {
            return array_merge($空, ['error' => '文件路径异常，已拒绝写入']);
        }
        // 旧内容留一份快照，改坏了能回退
        if (is_file($全)) {
            $快照目录 = dirname($全) . '/.ver';
            if (!is_dir($快照目录)) {
                @mkdir($快照目录, 0750, true);
            }
            @copy($全, $快照目录 . '/' . basename($全) . '.' . date('YmdHis'));
        }
        if (!is_dir(dirname($全))) {
            @mkdir(dirname($全), 0750, true);
        }
        if (@file_put_contents($全, $内容) === false) {
            return array_merge($空, ['error' => '写入失败，请检查存储目录权限']);
        }
        落盘收尾($全, 0640);
        db_exec('UPDATE uploads SET size=?, note=?, ver=ver+1, updated_at=NOW(),
                 kind=\'text\', mime=? WHERE id=? AND user_id=?',
            [$长, mb_substr($说明, 0, 250), 工作区MIME($规范), (int) $旧['id'], $用户id]);
        return ['ok' => true, 'error' => '', 'id' => (int) $旧['id'], 'name' => $规范,
            'size' => $长, 'new' => false, 'ver' => (int) $旧['ver'] + 1];
    }

    // ---- 新建 ----
    $相对 = 工作区落盘名($用户id, $规范);
    $全   = 工作区绝对路径($相对);
    if ($全 === '') {
        return array_merge($空, ['error' => '存储路径异常，已拒绝写入']);
    }
    if (!is_dir(dirname($全)) && !@mkdir(dirname($全), 0750, true)) {
        return array_merge($空, ['error' => '无法创建存储目录，请检查权限']);
    }
    if (@file_put_contents($全, $内容) === false) {
        return array_merge($空, ['error' => '写入失败，请检查存储目录权限']);
    }
    落盘收尾($全, 0640);
    $id = db_insert('INSERT INTO uploads
        (user_id, conv_id, path, name, kind, source, mime, size, width, height,
         used, note, ver, created_at, updated_at)
        VALUES (?,0,?,?,\'text\',?,?,?,0,0,1,?,1,NOW(),NOW())',
        [$用户id, $相对, $规范, $来源 === 'user' ? 'user' : 'ai',
         工作区MIME($规范), $长, mb_substr($说明, 0, 250)]);
    return ['ok' => true, 'error' => '', 'id' => (int) $id, 'name' => $规范,
        'size' => $长, 'new' => true, 'ver' => 1];
}

/** 按扩展名猜个 MIME，仅用于下载时的响应头 */
function 工作区MIME(string $名): string
{
    $ext = strtolower(pathinfo($名, PATHINFO_EXTENSION));
    $表 = [
        'txt' => 'text/plain', 'md' => 'text/markdown', 'json' => 'application/json',
        'xml' => 'text/xml', 'csv' => 'text/csv', 'html' => 'text/html', 'htm' => 'text/html',
        'css' => 'text/css', 'js' => 'text/javascript', 'sql' => 'text/plain',
        'yml' => 'text/yaml', 'yaml' => 'text/yaml', 'log' => 'text/plain',
    ];
    return $表[$ext] ?? 'text/plain';
}

/**
 * 删除一个文件（连实体一起删）。
 */
function 工作区删文件(int $用户id, int $id): array
{
    $行 = 工作区取文件($用户id, $id);
    if (!$行) {
        return ['ok' => false, 'error' => '文件不存在'];
    }
    $全 = 工作区绝对路径((string) $行['path']);
    if ($全 !== '' && is_file($全)) {
        @unlink($全);
        // 顺手删掉预览缓存（PDF + 逐页图），不然源文件没了缓存还留着，
        // 占空间又没人认领。这里直接拼路径而不调 office_preview 的函数，
        // 避免底层存储层反向依赖上层模块。
        $缓存根 = dirname($全) . '/.pdf/' . basename($全);
        @unlink($缓存根 . '.pdf');
        foreach ((array) glob($缓存根 . '.png/p-*.png') as $页图) {
            @unlink($页图);
        }
        @rmdir($缓存根 . '.png');
    }
    db_exec('DELETE FROM uploads WHERE id=? AND user_id=?', [$id, $用户id]);
    return ['ok' => true, 'error' => '', 'name' => (string) $行['name']];
}

/** 重命名（只改虚拟名，不动落盘文件） */
function 工作区改名(int $用户id, int $id, string $新名): array
{
    $行 = 工作区取文件($用户id, $id);
    if (!$行) {
        return ['ok' => false, 'error' => '文件不存在'];
    }
    $规范 = 工作区规范名($新名);
    if ($规范 === '') {
        return ['ok' => false, 'error' => '新文件名不合法'];
    }
    $占 = db_one('SELECT id FROM uploads WHERE user_id=? AND name=? AND id<>?',
        [$用户id, $规范, $id]);
    if ($占) {
        return ['ok' => false, 'error' => '这个名字已经被占用了：' . $规范];
    }
    db_exec('UPDATE uploads SET name=?, updated_at=NOW() WHERE id=? AND user_id=?',
        [$规范, $id, $用户id]);
    return ['ok' => true, 'error' => '', 'name' => $规范];
}

/**
 * 列文件。图片和文件一起列，按需筛选。
 *
 * @param string $类 全部 all / image / text / bin
 */
function 工作区列文件(int $用户id, string $类 = 'all', string $搜 = '',
                      int $页 = 1, int $每页 = 30): array
{
    $条件 = 'user_id=?';
    $参   = [$用户id];
    if (in_array($类, ['image', 'text', 'bin'], true)) {
        $条件 .= ' AND kind=?';
        $参[]  = $类;
    }
    $关键 = trim($搜);
    if ($关键 !== '') {
        $条件 .= ' AND name LIKE ?';
        $参[]  = '%' . str_replace(['%', '_'], ['\%', '\_'], $关键) . '%';
    }
    $总 = (int) db_val('SELECT COUNT(*) FROM uploads WHERE ' . $条件, $参);
    $每页 = min(100, max(6, $每页));
    $页   = max(1, $页);
    $行 = db_all('SELECT id, name, path, kind, source, mime, size, width, height,
                    used, note, ver, conv_id, created_at, updated_at
                  FROM uploads WHERE ' . $条件 . '
                  ORDER BY id DESC LIMIT ' . $每页 . ' OFFSET ' . (($页 - 1) * $每页), $参);
    $表 = [];
    foreach ($行 as $r) {
        $名 = (string) ($r['name'] !== '' ? $r['name'] : basename((string) $r['path']));
        $表[] = [
            'id'        => (int) $r['id'],
            'name'      => $名,
            'kind'      => (string) $r['kind'],
            'source'    => (string) $r['source'],
            'size'      => (int) $r['size'],
            'size_text' => size_text((int) $r['size']),
            'width'     => (int) $r['width'],
            'height'    => (int) $r['height'],
            'used'      => (int) $r['used'],
            'note'      => (string) $r['note'],
            'ver'       => (int) $r['ver'],
            'conv_id'   => (int) $r['conv_id'],
            'editable'  => (string) $r['kind'] === 'text' ? 1 : 0,
            'url'       => (string) $r['kind'] === 'image'
                            ? '/api/img.php?id=' . (int) $r['id']
                            : '/api/ws.php?act=down&id=' . (int) $r['id'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) ($r['updated_at'] ?? $r['created_at']),
        ];
    }
    return ['list' => $表, 'total' => $总, 'page' => $页,
        'pages' => max(1, (int) ceil($总 / $每页))];
}

// ============================================================
// 英文包装函数，供 tool_execute.php 调用
// ============================================================

/**
 * 列出工作中心文件
 */
function ws_list(int $userId, string $keyword = ''): array
{
    $result = 工作区列文件($userId, 'all', $keyword, 1, 100);
    return ['ok' => true, 'error' => '', 'list' => $result['list']];
}

/**
 * 读取工作中心文件
 */
function ws_read(int $userId, string $name): array
{
    return 工作区读文件($userId, $name);
}

/**
 * 写入工作中心文件
 */
function ws_write(int $userId, string $name, string $content, string $note = ''): array
{
    return 工作区写文件($userId, $name, $content, $note, 'ai');
}

/**
 * 补丁方式修改工作中心文件
 */
function ws_patch(int $userId, string $name, string $original, string $replacement, string $note = ''): array
{
    // 先读取当前内容
    $readResult = 工作区读文件($userId, $name);
    if (!$readResult['ok']) {
        return $readResult;
    }
    
    $content = $readResult['text'];
    
    // 检查原片段是否存在
    $count = substr_count($content, $original);
    if ($count === 0) {
        return [
            'ok' => false,
            'error' => "补丁失败：在文件中找不到指定的原片段。\n\n请检查：\n"
                     . "1. 原片段是否完全匹配（包括空格、换行）\n"
                     . "2. 文件内容是否已被修改\n\n"
                     . "原片段：\n" . mb_substr($original, 0, 200) . ($content ? '...' : ''),
            'name' => $name,
            'size' => 0
        ];
    }
    
    if ($count > 1) {
        return [
            'ok' => false,
            'error' => "补丁失败：原片段在文件中出现了 {$count} 次，无法确定要替换哪一处。\n\n"
                     . "解决方法：\n"
                     . "1. 在原片段中多带几行上下文，让它在文件中唯一\n"
                     . "2. 或改用 ws_write 整文件覆写\n\n"
                     . "原片段：\n" . mb_substr($original, 0, 200) . '...',
            'name' => $name,
            'size' => 0
        ];
    }
    
    // 执行替换
    $newContent = str_replace($original, $replacement, $content);
    
    // 写入
    return 工作区写文件($userId, $name, $newContent, $note, 'ai');
}

/**
 * Delete workspace file (wrapper for 工作区删文件).
 */
function ws_delete(int $userId, $identifier, string $note = ''): array
{
    // 如果传入的是文件名，先查出 ID
    if (!is_numeric($identifier)) {
        $file = 工作区取文件($userId, $identifier);
        if (!$file) {
            return ['ok' => false, 'error' => '文件不存在：' . $identifier];
        }
        $identifier = (int) $file['id'];
    }
    
    return 工作区删文件($userId, (int) $identifier);
}