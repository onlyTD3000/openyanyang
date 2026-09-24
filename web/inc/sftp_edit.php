<?php
/**
 * 直连 SFTP 读改存：不必先拉整个仓，也能改客户服务器上的单个文件。
 *
 * 和 repo_sync.php 的关系：
 *   - repo_sync 是「批量拉到本地副本 → 改副本 → 回传」，适合成套改动，有版本有 diff。
 *   - 本模块是「直接读远端文件 → 改 → 存回去」，适合改一两处配置这种轻量场景。
 * 两者共用同一条 SFTP 连接逻辑（同步开SFTP），因此主机指纹校验、
 * user_id 归属校验、凭据解密是同一套，安全保证一致。
 *
 * 为什么要有这个模块：命令行改文件（sed -i、heredoc、> 重定向）已在 ssh_guard 里
 * 全部拒绝。那些做法没备份、没版本，一个引号写错就能把客户文件写成空。
 * 这里每次写入前必定先把旧内容存成远端备份 + 本地留档，改坏了能原样还原。
 *
 * 安全要点：
 *   - 目标路径必须落在项目的部署目录（deploy_dir）之内，越界一律拒绝。
 *   - 写入前先读旧内容留档（sftp_edits 表 + 远端 .kiro_backup/），失败不写。
 *   - 只允许文本文件，二进制一律拒绝，避免把图片写坏。
 *   - 单文件体积上限，防止把内存打满。
 */

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/repo_sync.php';

/** 直连编辑的单文件体积上限（字节） */
const 直改体积上限 = 2097152;      // 2 MB
/** 列目录时一次最多返回多少条 */
const 直改列表上限 = 500;

/**
 * 校验目标路径是否落在允许的根目录内。
 *
 * 这是本模块的核心闸门。远端路径由 AI 给出，必须假定它可能带 .. 或绝对路径逃逸。
 * 判定只做字符串层面的规范化——远端软链接指向哪里在本地看不到，
 * 所以列目录时已经跳过所有软链接，不让它成为绕过手段。
 *
 * @param string $根   允许的根（项目部署目录，绝对路径）
 * @param string $路径 AI 给的路径，可以是相对根的相对路径，也可以是绝对路径
 * @return string 规范化后的绝对路径；越界或非法返回空串
 */
function 直改路径(string $根, string $路径): string
{
    $根 = '/' . trim(str_replace('\\', '/', $根), '/');
    $p  = trim(str_replace('\\', '/', $路径));
    if ($p === '' || $根 === '/') {
        return '';
    }
    if (strpos($p, "\0") !== false) {
        return '';
    }
    // 绝对路径必须本来就在根之下；相对路径拼到根后面
    $全 = ($p[0] === '/') ? $p : rtrim($根, '/') . '/' . $p;

    // 手工消解 . 与 ..，不能用 realpath（那是本地文件系统，远端路径在本地不存在）
    $段 = [];
    foreach (explode('/', $全) as $s) {
        if ($s === '' || $s === '.') {
            continue;
        }
        if ($s === '..') {
            if (!$段) {
                return '';          // 已经在根上还要往上，直接判越界
            }
            array_pop($段);
            continue;
        }
        $段[] = $s;
    }
    $规范 = '/' . implode('/', $段);

    // 必须仍在根之内。比较时给根补斜杠，防止 /www/site 匹配到 /www/site_bak
    $根带杠 = rtrim($根, '/') . '/';
    if ($规范 !== rtrim($根, '/') && strpos($规范 . '/', $根带杠) !== 0) {
        return '';
    }
    return $规范;
}

/**
 * 取项目绑定的主机与部署根目录，并校验归属。
 *
 * @return array{ok:bool, error:string, host:array, root:string}
 */
function 直改上下文(array $项目, int $用户id): array
{
    $空 = ['ok' => false, 'error' => '', 'host' => [], 'root' => ''];
    $主机id = (int) ($项目['host_id'] ?? 0);
    $根     = trim((string) ($项目['deploy_dir'] ?? ''));
    if ($主机id <= 0) {
        return array_merge($空, ['error' => '这个项目还没绑定服务器，请先在项目设置里绑定']);
    }
    if ($根 === '') {
        return array_merge($空, ['error' => '这个项目还没设置部署目录，请先在项目设置里填写']);
    }
    $err = 同步校验远程目录($根);
    if ($err !== '') {
        return array_merge($空, ['error' => $err]);
    }
    // host_id 必须配 user_id，否则等于越权连别人的机器
    $主机 = ssh_host_of($主机id, $用户id);
    if (!$主机) {
        return array_merge($空, ['error' => '绑定的服务器不存在或不属于你']);
    }
    return ['ok' => true, 'error' => '', 'host' => $主机, 'root' => rtrim($根, '/')];
}

/**
 * 列出远端某个目录（只列一层，不递归）。
 *
 * @return array{ok:bool, error:string, list:array, dir:string, truncated:bool}
 */
function 直改列目录(array $项目, int $用户id, string $子目录 = ''): array
{
    $空 = ['ok' => false, 'error' => '', 'list' => [], 'dir' => '', 'truncated' => false];
    $ctx = 直改上下文($项目, $用户id);
    if (!$ctx['ok']) {
        return array_merge($空, ['error' => $ctx['error']]);
    }
    $目标 = 直改路径($ctx['root'], $子目录 === '' ? $ctx['root'] : $子目录);
    if ($目标 === '') {
        return array_merge($空, ['error' => '目录超出项目部署目录范围，已拒绝']);
    }
    $sftp = 同步开SFTP($ctx['host']);
    if (!$sftp) {
        return array_merge($空, ['error' => '无法建立 SFTP 连接，请检查服务器凭据与网络']);
    }
    $表 = @$sftp->rawlist($目标);
    if (!is_array($表)) {
        return array_merge($空, ['error' => '目录不存在或没有权限：' . $目标]);
    }

    $出 = [];
    $截断 = false;
    foreach ($表 as $名 => $属) {
        if ($名 === '.' || $名 === '..' || $名 === '' || !is_array($属)) {
            continue;
        }
        if (count($出) >= 直改列表上限) {
            $截断 = true;
            break;
        }
        $类型 = (int) ($属['type'] ?? 0);
        // 软链接跳过：它可能指到根目录之外，是绕过路径闸门的常见手段
        if ($类型 !== 1 && $类型 !== 2) {
            continue;
        }
        $出[] = [
            'name'    => (string) $名,
            'is_dir'  => $类型 === 2 ? 1 : 0,
            'size'    => (int) ($属['size'] ?? 0),
            'mtime'   => (int) ($属['mtime'] ?? 0),
            'is_text' => $类型 === 1 && 仓是文本((string) $名) ? 1 : 0,
        ];
    }
    // 目录在前，同类按名字排
    usort($出, function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $b['is_dir'] - $a['is_dir'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return ['ok' => true, 'error' => '', 'list' => $出,
        'dir' => $目标, 'truncated' => $截断];
}

/**
 * 读远端文件正文。
 *
 * @return array{ok:bool, error:string, text:string, path:string, size:int}
 */
function 直改读文件(array $项目, int $用户id, string $路径): array
{
    $空 = ['ok' => false, 'error' => '', 'text' => '', 'path' => '', 'size' => 0];
    $ctx = 直改上下文($项目, $用户id);
    if (!$ctx['ok']) {
        return array_merge($空, ['error' => $ctx['error']]);
    }
    $全 = 直改路径($ctx['root'], $路径);
    if ($全 === '') {
        return array_merge($空, ['error' => '路径超出项目部署目录范围，已拒绝：' . $路径]);
    }
    if (!仓是文本(basename($全))) {
        return array_merge($空, ['error' => '这是二进制文件，不支持在线读改，请手动处理']);
    }
    $sftp = 同步开SFTP($ctx['host']);
    if (!$sftp) {
        return array_merge($空, ['error' => '无法建立 SFTP 连接，请检查服务器凭据与网络']);
    }
    $尺 = @$sftp->filesize($全);
    if ($尺 === false) {
        return array_merge($空, ['error' => '文件不存在或没有权限：' . $全]);
    }
    if ((int) $尺 > 直改体积上限) {
        return array_merge($空, ['error' => '文件超过 '
            . size_text(直改体积上限) . '，不适合在线读改：' . $全]);
    }
    $内容 = @$sftp->get($全);
    if (!is_string($内容)) {
        return array_merge($空, ['error' => '读取失败：' . $全]);
    }
    // 内容里有 NUL 说明实际是二进制，扩展名骗不了人
    if (strpos($内容, "\0") !== false) {
        return array_merge($空, ['error' => '文件内容是二进制，不支持在线读改']);
    }
    return ['ok' => true, 'error' => '', 'text' => $内容,
        'path' => $全, 'size' => strlen($内容)];
}

/**
 * 写远端文件。写前必定留档，留档失败就不写。
 *
 * @param string $动作 write（整文件覆写）或 patch（局部替换），只用于留档标记
 * @return array{ok:bool, error:string, path:string, add:int, del:int, backup:string, edit_id:int}
 */
function 直改写文件(array $项目, int $用户id, string $路径, string $内容,
                    string $动作 = 'write', int $对话id = 0, string $说明 = ''): array
{
    $空 = ['ok' => false, 'error' => '', 'path' => '', 'add' => 0, 'del' => 0,
        'backup' => '', 'edit_id' => 0];
    $ctx = 直改上下文($项目, $用户id);
    if (!$ctx['ok']) {
        return array_merge($空, ['error' => $ctx['error']]);
    }
    $全 = 直改路径($ctx['root'], $路径);
    if ($全 === '') {
        return array_merge($空, ['error' => '路径超出项目部署目录范围，已拒绝：' . $路径]);
    }
    if (!仓是文本(basename($全))) {
        return array_merge($空, ['error' => '这是二进制文件，不支持在线读改，请手动处理']);
    }
    if (strlen($内容) > 直改体积上限) {
        return array_merge($空, ['error' => '内容超过 ' . size_text(直改体积上限) . '，已拒绝']);
    }
    if (strpos($内容, "\0") !== false) {
        return array_merge($空, ['error' => '内容含 NUL 字符，拒绝写入']);
    }

    $sftp = 同步开SFTP($ctx['host']);
    if (!$sftp) {
        return array_merge($空, ['error' => '无法建立 SFTP 连接，请检查服务器凭据与网络']);
    }

    // ---- 留档：先取旧内容 ----
    $旧文 = '';
    $新建 = true;
    $尺 = @$sftp->filesize($全);
    if ($尺 !== false) {
        $新建 = false;
        if ((int) $尺 > 直改体积上限) {
            return array_merge($空, ['error' => '目标文件超过 '
                . size_text(直改体积上限) . '，不适合在线改写']);
        }
        $读 = @$sftp->get($全);
        if (!is_string($读)) {
            // 读不到就没法留档，宁可不写也不能盲改
            return array_merge($空, ['error' => '无法读取原文件内容，为安全起见不执行写入：' . $全]);
        }
        $旧文 = $读;
    }

    // ---- 远端备份：旧内容另存一份，改坏了能就地还原 ----
    $备份路径 = '';
    if (!$新建) {
        $备份目录 = $ctx['root'] . '/.kiro_backup';
        if (!@$sftp->is_dir($备份目录)) {
            @$sftp->mkdir($备份目录, 0755);
        }
        $备份路径 = $备份目录 . '/' . date('Ymd_His') . '_'
            . str_replace('/', '_', ltrim(substr($全, strlen($ctx['root'])), '/'));
        if (@$sftp->put($备份路径, $旧文) === false) {
            return array_merge($空, ['error' => '远端备份失败（目录可能不可写），'
                . '为安全起见已终止写入。']);
        }
    }

    // ---- 本地留档：远端备份可能被客户清掉，库里再存一份旧内容 ----
    $编辑id = 0;
    try {
        $编辑id = db_insert('INSERT INTO sftp_edits
            (user_id, project_id, host_id, conv_id, path, action,
             old_text, new_text, backup_path, note, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
            [$用户id, (int) $项目['id'], (int) $项目['host_id'], $对话id, $全, $动作,
             mb_substr($旧文, 0, 200000), mb_substr($内容, 0, 200000),
             $备份路径, mb_substr($说明, 0, 250)]);
    } catch (Throwable $e) {
        // 留档表不存在也不该挡住正常改文件，远端备份已经有了
        $编辑id = 0;
    }

    // ---- 父目录不存在时逐级创建（只在根之内）----
    $父 = dirname($全);
    if ($父 !== '' && $父 !== '/' && !@$sftp->is_dir($父)) {
        $累 = $ctx['root'];
        $相对父 = trim(substr($父, strlen($ctx['root'])), '/');
        if ($相对父 !== '') {
            foreach (explode('/', $相对父) as $段) {
                $累 .= '/' . $段;
                if (!@$sftp->is_dir($累)) {
                    @$sftp->mkdir($累, 0755);
                }
            }
        }
    }

    // ---- 真正写入 ----
    if (@$sftp->put($全, $内容) === false) {
        return array_merge($空, ['error' => '写入失败，可能是权限不足：' . $全,
            'backup' => $备份路径]);
    }
    // 复核远端体积，确认写全了。写一半比不写更糟，得让调用方知道。
    $新尺 = @$sftp->filesize($全);
    if ($新尺 !== false && (int) $新尺 !== strlen($内容)) {
        return array_merge($空, ['error' => '写入不完整（远端 ' . (int) $新尺
            . ' 字节，应为 ' . strlen($内容) . ' 字节）。'
            . ($备份路径 !== '' ? '原文件已备份在 ' . $备份路径 . '，可用它还原。' : ''),
            'backup' => $备份路径]);
    }

    $d = 仓差异($旧文, $内容);
    return ['ok' => true, 'error' => '', 'path' => $全,
        'add' => $d['add'], 'del' => $d['del'],
        'backup' => $备份路径, 'edit_id' => $编辑id, 'new' => $新建 ? 1 : 0];
}

/**
 * 把某次直连编辑还原回去。用库里存的 old_text 覆盖回远端。
 *
 * @return array{ok:bool, error:string, path:string}
 */
function 直改还原(int $编辑id, int $用户id): array
{
    $空 = ['ok' => false, 'error' => '', 'path' => ''];
    $行 = db_one('SELECT * FROM sftp_edits WHERE id=? AND user_id=?', [$编辑id, $用户id]);
    if (!$行) {
        return array_merge($空, ['error' => '找不到这条编辑记录']);
    }
    $项目 = db_one('SELECT * FROM projects WHERE id=? AND user_id=?',
        [(int) $行['project_id'], $用户id]);
    if (!$项目) {
        return array_merge($空, ['error' => '这条记录对应的项目已不存在']);
    }
    $动作   = (string) ($行['action'] ?? 'write');
    $旧文   = (string) $行['old_text'];
    $备份   = trim((string) $行['backup_path']);

    // 新建的文件没有旧内容，还原语义上应该是删除，但删远端文件风险高，交给用户手动做
    if ($动作 !== 'delete' && $旧文 === '' && $备份 === '') {
        return array_merge($空, ['error' => '这条记录是新建文件，没有可还原的旧内容。'
            . '如需撤销请手动删除该文件。']);
    }

    // 删除的还原＝把文件重新建回来。二进制文件当初没存正文（只存了远端备份），
    // 这时从 .kiro_backup 里把那份原样捞回来再写回去，否则会还原出一个空文件。
    if ($旧文 === '' && $备份 !== '') {
        $ctx = 直改上下文($项目, $用户id);
        if (!$ctx['ok']) {
            return array_merge($空, ['error' => $ctx['error']]);
        }
        $sftp = 同步开SFTP($ctx['host']);
        if (!$sftp) {
            return array_merge($空, ['error' => '无法建立 SFTP 连接，无法读取备份']);
        }
        $捞 = @$sftp->get($备份);
        if (!is_string($捞)) {
            return array_merge($空, ['error' => '远端备份已不存在，无法还原：' . $备份]);
        }
        // 二进制内容不走 直改写文件（它只收文本），直接写回并复核体积
        $目标 = 直改路径($ctx['root'], (string) $行['path']);
        if ($目标 === '') {
            return array_merge($空, ['error' => '记录里的路径已超出当前部署目录范围']);
        }
        if (@$sftp->put($目标, $捞) === false) {
            return array_merge($空, ['error' => '写回失败，可能是权限不足：' . $目标]);
        }
        if ((int) @$sftp->filesize($目标) !== strlen($捞)) {
            return array_merge($空, ['error' => '写回不完整，请检查远端文件：' . $目标]);
        }
        return ['ok' => true, 'error' => '', 'path' => $目标];
    }

    $r = 直改写文件($项目, $用户id, (string) $行['path'], $旧文,
        'restore', (int) $行['conv_id'], '还原第 ' . $编辑id . ' 次编辑');
    if (!$r['ok']) {
        return array_merge($空, ['error' => $r['error']]);
    }
    return ['ok' => true, 'error' => '', 'path' => $r['path']];
}

/**
 * 删除远端文件。
 *
 * 删除是不可逆动作，所以比写入更谨慎：
 *   - 只删单个文件，目录一律拒绝。要删目录得用户自己动手，
 *     避免 AI 一句 rmdir 把客户整个目录端掉。
 *   - 删之前必定先把内容读出来，存进 sftp_edits 的 old_text，
 *     并在远端 .kiro_backup/ 留一份。读不到内容就不删。
 *   - 二进制文件不读正文（可能很大），但仍要在远端备份成功后才删。
 *
 * @return array{ok:bool, error:string, path:string, backup:string, edit_id:int}
 */
function 直改删文件(array $项目, int $用户id, string $路径,
                    int $对话id = 0, string $说明 = ''): array
{
    $空 = ['ok' => false, 'error' => '', 'path' => '', 'backup' => '', 'edit_id' => 0];
    $ctx = 直改上下文($项目, $用户id);
    if (!$ctx['ok']) {
        return array_merge($空, ['error' => $ctx['error']]);
    }
    $全 = 直改路径($ctx['root'], $路径);
    if ($全 === '') {
        return array_merge($空, ['error' => '路径超出项目部署目录范围，已拒绝：' . $路径]);
    }
    // 不许删部署根本身，否则等于清空整个站点
    if ($全 === rtrim($ctx['root'], '/')) {
        return array_merge($空, ['error' => '不能删除项目部署根目录']);
    }
    // 备份目录本身不许删，否则历史备份一起没了
    if (strpos($全 . '/', rtrim($ctx['root'], '/') . '/.kiro_backup/') === 0
        || $全 === rtrim($ctx['root'], '/') . '/.kiro_backup') {
        return array_merge($空, ['error' => '备份目录不允许删除']);
    }

    $sftp = 同步开SFTP($ctx['host']);
    if (!$sftp) {
        return array_merge($空, ['error' => '无法建立 SFTP 连接，请检查服务器凭据与网络']);
    }
    if (@$sftp->is_dir($全)) {
        return array_merge($空, ['error' => '这是目录，不支持删除目录（风险过高）。'
            . '如确实要删整个目录，请手动操作。']);
    }
    $尺 = @$sftp->filesize($全);
    if ($尺 === false) {
        return array_merge($空, ['error' => '文件不存在：' . $全]);
    }

    // ---- 留档：先把内容捞出来 ----
    $旧文 = '';
    $可留正文 = 仓是文本(basename($全)) && (int) $尺 <= 直改体积上限;
    $读 = @$sftp->get($全);
    if (!is_string($读)) {
        return array_merge($空, ['error' => '无法读取文件内容，为安全起见不执行删除：' . $全]);
    }
    if ($可留正文 && strpos($读, "\0") === false) {
        $旧文 = $读;
    }

    // ---- 远端备份：删掉的东西必须能捞回来 ----
    $备份目录 = $ctx['root'] . '/.kiro_backup';
    if (!@$sftp->is_dir($备份目录)) {
        @$sftp->mkdir($备份目录, 0755);
    }
    $备份路径 = $备份目录 . '/' . date('Ymd_His') . '_删除_'
        . str_replace('/', '_', ltrim(substr($全, strlen($ctx['root'])), '/'));
    if (@$sftp->put($备份路径, $读) === false) {
        return array_merge($空, ['error' => '远端备份失败（备份目录可能不可写），'
            . '为安全起见已终止删除。']);
    }

    // ---- 本地留档 ----
    $编辑id = 0;
    try {
        $编辑id = db_insert('INSERT INTO sftp_edits
            (user_id, project_id, host_id, conv_id, path, action,
             old_text, new_text, backup_path, note, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
            [$用户id, (int) $项目['id'], (int) $项目['host_id'], $对话id, $全, 'delete',
             mb_substr($旧文, 0, 200000), '', $备份路径, mb_substr($说明, 0, 250)]);
    } catch (Throwable $e) {
        $编辑id = 0;
    }

    // ---- 真正删除 ----
    if (@$sftp->delete($全, false) === false) {
        return array_merge($空, ['error' => '删除失败，可能是权限不足：' . $全,
            'backup' => $备份路径]);
    }
    // 复核：确认真的没了
    if (@$sftp->filesize($全) !== false) {
        return array_merge($空, ['error' => '删除命令已执行但文件仍存在：' . $全,
            'backup' => $备份路径]);
    }

    return ['ok' => true, 'error' => '', 'path' => $全,
        'backup' => $备份路径, 'edit_id' => $编辑id,
        'size' => (int) $尺, 'text_kept' => $旧文 !== '' ? 1 : 0];
}
