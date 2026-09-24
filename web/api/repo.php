<?php
/**
 * 代码仓接口：拉取、浏览、读写、打补丁、回传、版本回滚。
 *
 * 动作：
 *   info      仓概况（含项目与主机信息）
 *   pull      从客户服务器拉取代码到工作中心
 *   tree      文件清单
 *   read      读一个文件的正文
 *   write     整文件覆写（AI 与页面共用）
 *   patch     局部补丁替换
 *   diff      某文件当前内容与某个历史版本的差异
 *   vers      某文件的版本列表
 *   rollback  回滚到指定版本
 *   pending   待回传清单
 *   push      回传到客户服务器
 *   logs      同步日志
 *
 * 所有查询一律带 user_id 条件，用户之间互不可见。
 */
require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
api_error_guard();
require __DIR__ . '/../inc/crypto.php';
require __DIR__ . '/../inc/ssh_guard.php';
require __DIR__ . '/../inc/ssh_run.php';
require __DIR__ . '/../inc/project.php';
require __DIR__ . '/../inc/repo.php';
require __DIR__ . '/../inc/repo_sync.php';

start_session();
$me  = require_login_api();
$act = (string) ($_POST['act'] ?? $_GET['act'] ?? '');
$uid = (int) $me['id'];

// 检查用户代码仓操作权限
$userCap = db_one('SELECT cap_file_list, cap_file_read, cap_file_write, cap_file_push FROM users WHERE id = ?', [$uid]);
$权限映射 = [
    'info' => 'cap_file_list',
    'tree' => 'cap_file_list',
    'read' => 'cap_file_read',
    'write' => 'cap_file_write',
    'patch' => 'cap_file_write',
    'delete' => 'cap_file_write',
    'push' => 'cap_file_push',
    'pull' => 'cap_file_list',
];
if (isset($权限映射[$act])) {
    $需要字段 = $权限映射[$act];
    if ((int) ($userCap[$需要字段] ?? 1) !== 1) {
        $操作名 = [
            'info' => '查看信息', 'tree' => '列表', 'read' => '读取',
            'write' => '写入', 'patch' => '写入', 'delete' => '删除',
            'push' => '推送', 'pull' => '拉取'
        ][$act] ?? $act;
        json_out(['error' => "你没有代码仓{$操作名}权限"]);
    }
}

// 只读动作允许 GET，改动类一律要 POST + CSRF
$只读 = ['info', 'tree', 'read', 'diff', 'vers', 'pending', 'logs'];
if (!in_array($act, $只读, true)) {
    csrf_check();
}

/**
 * 取当前操作的项目与仓。项目不属于本人时一律当不存在。
 */
function 取仓上下文(int $uid): array
{
    $项目id = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
    $项目 = project_of($项目id, $uid);
    if (!$项目) {
        json_out(['error' => '项目不存在或不属于你'], 404);
    }
    $仓 = 仓取或建($项目id, $uid, (int) $项目['host_id'], (string) $项目['deploy_dir']);

    // 仓记录存在但从未拉取过代码，自动触发一次初始化拉取
    if ((int) $仓['file_count'] === 0 && trim((string) $仓['last_pull_at']) === ''
        && (int) $仓['host_id'] > 0 && trim((string) $仓['remote_dir']) !== '') {
        $主机 = ssh_host_of((int) $仓['host_id'], $uid);
        if ($主机) {
            // 拉取失败不阻塞本次请求，失败信息记进日志，下次操作时再试
            @仓拉取($仓, $主机, false);
            // 重新读一次仓记录，拿到拉取后的最新状态
            $仓 = db_one('SELECT * FROM repos WHERE id=? AND user_id=? LIMIT 1',
                [(int) $仓['id'], $uid]);
        }
    }

    return [$项目, $仓];
}

// ---- 仓概况 ----
if ($act === 'info') {
    [$项目, $仓] = 取仓上下文($uid);
    $主机 = (int) $仓['host_id'] > 0
        ? db_one('SELECT id, name, host, username FROM ssh_hosts WHERE id=? AND user_id=?',
            [(int) $仓['host_id'], $uid])
        : null;
    $改动数 = (int) db_val('SELECT COUNT(*) FROM repo_files
                            WHERE repo_id=? AND state IN (\'edited\',\'new\')', [(int) $仓['id']]);
    json_out(['ok' => 1, 'repo' => [
        'id'          => (int) $仓['id'],
        'project_id'  => (int) $仓['project_id'],
        'project'     => $项目['name'],
        'remote_dir'  => $仓['remote_dir'],
        'host'        => $主机 ? ($主机['name'] . '（' . $主机['host'] . '）') : '',
        'host_id'     => (int) $仓['host_id'],
        'file_count'  => (int) $仓['file_count'],
        'bytes'       => (int) $仓['total_bytes'],
        'bytes_text'  => size_text((int) $仓['total_bytes']),
        'dirty'       => $改动数,
        'last_pull'   => $仓['last_pull_at'],
        'last_push'   => $仓['last_push_at'],
    ]]);
}

// ---- 拉取 ----
if ($act === 'pull') {
    [$项目, $仓] = 取仓上下文($uid);
    if ((int) $仓['host_id'] <= 0) {
        json_out(['error' => '这个项目还没绑定服务器，请先在项目设置里绑定'], 400);
    }
    if (trim((string) $仓['remote_dir']) === '') {
        json_out(['error' => '这个项目还没设置部署目录，请先在项目设置里填写'], 400);
    }
    $主机 = ssh_host_of((int) $仓['host_id'], $uid);
    if (!$主机) {
        json_out(['error' => '绑定的服务器不存在或已停用'], 400);
    }
    $强制 = (int) ($_POST['force'] ?? 0) === 1;
    $r = 仓拉取($仓, $主机, $强制);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    $msg = '拉取完成：新增 ' . $r['新增'] . '，更新 ' . $r['更新'];
    if ($r['保护'] > 0) { $msg .= '，保留本地改动 ' . $r['保护']; }
    if ($r['跳过'] > 0) { $msg .= '，跳过 ' . $r['跳过']; }
    if ($r['截断'])     { $msg .= '（文件数达上限，未拉全）'; }
    json_out(['ok' => 1, 'msg' => $msg] + $r);
}

// ---- 文件清单 ----
if ($act === 'tree') {
    [$项目, $仓] = 取仓上下文($uid);
    $仅改动 = (int) ($_GET['dirty'] ?? 0) === 1;
    $条件 = $仅改动 ? ' AND state IN (\'edited\',\'new\')' : '';
    $行 = db_all('SELECT id, path, size, hash, remote_hash, is_text, state, ver, updated_at
                   FROM repo_files WHERE repo_id = ? AND user_id = ?' . $条件 . '
                  ORDER BY path LIMIT 3000',
        [(int) $仓['id'], $uid]);
    $出 = [];
    foreach ($行 as $r) {
        $出[] = [
            'id'        => (int) $r['id'],
            'path'      => $r['path'],
            'size'      => (int) $r['size'],
            'size_text' => size_text((int) $r['size']),
            'is_text'   => (int) $r['is_text'],
            'state'     => $r['state'],
            'ver'       => (int) $r['ver'],
            'updated_at'=> $r['updated_at'],
        ];
    }
    json_out(['ok' => 1, 'list' => $出, 'total' => count($出)]);
}

// ---- 读文件 ----
if ($act === 'read') {
    [$项目, $仓] = 取仓上下文($uid);
    $路径 = (string) ($_GET['path'] ?? $_POST['path'] ?? '');
    $r = 仓读文件($仓, $路径);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 404);
    }
    $文 = $r['text'];
    $截断 = false;
    if (mb_strlen($文) > 仓正文上限) {
        $文 = mb_substr($文, 0, 仓正文上限);
        $截断 = true;
    }
    json_out(['ok' => 1, 'path' => $r['row']['path'], 'text' => $文,
        'truncated' => $截断 ? 1 : 0, 'size' => (int) $r['row']['size'],
        'state' => $r['row']['state'], 'ver' => (int) $r['row']['ver'],
        'lines' => substr_count($r['text'], "\n") + 1]);
}

// ---- 整文件覆写 ----
if ($act === 'write') {
    [$项目, $仓] = 取仓上下文($uid);
    $路径 = (string) ($_POST['path'] ?? '');
    $内容 = (string) ($_POST['text'] ?? '');
    $说明 = (string) ($_POST['note'] ?? '');
    $对话 = (int) ($_POST['conv_id'] ?? 0);
    if (trim($路径) === '') {
        json_out(['error' => '缺少文件路径'], 400);
    }
    // 覆写前留一份旧内容，用于返回 diff 让用户看清改了什么
    $旧 = 仓读文件($仓, $路径);
    $旧文 = $旧['ok'] ? $旧['text'] : '';

    $r = 仓写文件($仓, $路径, $内容, 'write', $对话, $说明);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    $d = 仓差异($旧文, $内容);
    json_out(['ok' => 1, 'path' => 仓规范路径($路径), 'ver' => $r['ver'],
        'size' => $r['size'], 'size_text' => size_text($r['size']),
        'add' => $d['add'], 'del' => $d['del'],
        'msg' => '已写入本地副本：+' . $d['add'] . ' -' . $d['del'] . ' 行。改动还未回传到服务器。']);
}

// ---- 局部补丁 ----
if ($act === 'patch') {
    [$项目, $仓] = 取仓上下文($uid);
    $路径 = (string) ($_POST['path'] ?? '');
    $查找 = (string) ($_POST['find'] ?? '');
    $替换 = (string) ($_POST['replace'] ?? '');
    $说明 = (string) ($_POST['note'] ?? '');
    $对话 = (int) ($_POST['conv_id'] ?? 0);

    $读 = 仓读文件($仓, $路径);
    if (!$读['ok']) {
        json_out(['error' => $读['error']], 404);
    }
    $应用 = 仓应用补丁($读['text'], $查找, $替换);
    if (!$应用['ok']) {
        // 补丁失败要把原因说清楚，AI 才知道该怎么改，而不是原样重试
        json_out(['error' => $应用['error'], 'patch_failed' => 1], 400);
    }
    $r = 仓写文件($仓, $路径, $应用['text'], 'patch', $对话, $说明);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    $d = 仓差异($读['text'], $应用['text']);
    $档说明 = [1 => '精确匹配', 2 => '忽略行尾空白', 3 => '忽略缩进'][$应用['档']] ?? '';
    json_out(['ok' => 1, 'path' => 仓规范路径($路径), 'ver' => $r['ver'],
        'add' => $d['add'], 'del' => $d['del'], 'mode' => $档说明,
        'msg' => '补丁已应用（' . $档说明 . '）：+' . $d['add'] . ' -' . $d['del']
               . ' 行。改动还未回传到服务器。']);
}

// ---- 版本列表 ----
// ---- 删除本地副本里的文件（单个或批量）----
if ($act === 'del') {
    [$项目, $仓] = 取仓上下文($uid);

    // paths 传 JSON 数组走批量，path 传单个；两者取到一起统一处理
    $表 = [];
    $原始 = (string) ($_POST['paths'] ?? '');
    if ($原始 !== '') {
        $解 = json_decode($原始, true);
        if (is_array($解)) {
            foreach ($解 as $p) {
                if (is_string($p) && $p !== '') {
                    $表[] = $p;
                }
            }
        }
    }
    $单 = (string) ($_POST['path'] ?? '');
    if ($单 !== '') {
        $表[] = $单;
    }
    $表 = array_values(array_unique($表));

    if (!$表) {
        json_out(['error' => '没有指定要删的文件'], 400);
    }
    if (count($表) > 500) {
        json_out(['error' => '一次最多删 500 个文件，请分批'], 400);
    }

    $成功 = 0;
    $字节 = 0;
    $失败 = [];
    foreach ($表 as $p) {
        $r = 仓删文件($仓, $p);
        if ($r['ok']) {
            $成功++;
            $字节 += (int) $r['size'];
        } else {
            $失败[] = $p . '：' . $r['error'];
        }
    }

    if ($成功 === 0) {
        json_out(['error' => '一个也没删掉。' . implode('；', array_slice($失败, 0, 5))], 400);
    }

    $msg = '已从本地副本删掉 ' . $成功 . ' 个文件（' . size_text($字节) . '）';
    if ($失败) {
        $msg .= '，' . count($失败) . ' 个没删成';
    }
    $msg .= '。服务器上那份还在，回传不会删远端文件。';
    json_out(['ok' => 1, '成功' => $成功, '失败' => count($失败),
        'failed' => array_slice($失败, 0, 30), 'bytes' => $字节,
        'size_text' => size_text($字节), 'msg' => $msg]);
}

if ($act === 'vers') {
    [$项目, $仓] = 取仓上下文($uid);
    $路径 = (string) ($_GET['path'] ?? '');
    $规范 = 仓规范路径($路径);
    if ($规范 === '') {
        json_out(['error' => '路径不合法（需用仓内相对路径）'], 400);
    }
    $行 = db_one('SELECT id FROM repo_files WHERE repo_id=? AND path=? AND user_id=? LIMIT 1',
        [(int) $仓['id'], $规范, $uid]);
    if (!$行) {
        json_out(['error' => '仓里没有这个文件'], 404);
    }
    $vs = db_all('SELECT ver, size, action, note, conv_id, created_at
                   FROM repo_versions WHERE file_id=? AND user_id=?
                  ORDER BY ver DESC LIMIT 50', [(int) $行['id'], $uid]);
    json_out(['ok' => 1, 'list' => $vs]);
}

// ---- 与历史版本的差异 ----
if ($act === 'diff') {
    [$项目, $仓] = 取仓上下文($uid);
    $路径 = (string) ($_GET['path'] ?? '');
    $版本 = (int) ($_GET['ver'] ?? 0);
    $读 = 仓读文件($仓, $路径);
    if (!$读['ok']) {
        json_out(['error' => $读['error']], 404);
    }
    $行 = $读['row'];
    if ($版本 <= 0) {
        $版本 = (int) $行['ver'];
    }
    $文件 = 仓版本目录($uid, (int) $仓['project_id']) . '/' . (int) $行['id'] . '/' . $版本;
    $旧 = @is_file($文件) ? (string) @file_get_contents($文件) : '';
    if ($旧 === '' && !@is_file($文件)) {
        json_out(['error' => '找不到第 ' . $版本 . ' 版的内容'], 404);
    }
    $d = 仓差异($旧, $读['text']);
    json_out(['ok' => 1, 'path' => $行['path'], 'ver' => $版本,
        'add' => $d['add'], 'del' => $d['del'], 'lines' => $d['lines']]);
}

// ---- 回滚 ----
if ($act === 'rollback') {
    [$项目, $仓] = 取仓上下文($uid);
    $路径 = (string) ($_POST['path'] ?? '');
    $版本 = (int) ($_POST['ver'] ?? 0);
    $r = 仓回滚($仓, $路径, $版本, (int) ($_POST['conv_id'] ?? 0));
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    json_out(['ok' => 1, 'msg' => '已回滚到第 ' . $版本 . ' 版（本地副本，未回传）']);
}

// ---- 待回传清单 ----
if ($act === 'pending') {
    [$项目, $仓] = 取仓上下文($uid);
    $待 = 仓待回传($仓);
    $出 = [];
    foreach ($待 as $r) {
        $出[] = ['path' => $r['path'], 'state' => $r['state'],
                 'size' => (int) $r['size'], 'size_text' => size_text((int) $r['size']),
                 'ver' => (int) $r['ver']];
    }
    json_out(['ok' => 1, 'list' => $出, 'total' => count($出)]);
}

// ---- 回传 ----
if ($act === 'push') {
    [$项目, $仓] = 取仓上下文($uid);
    if ((int) $仓['host_id'] <= 0 || trim((string) $仓['remote_dir']) === '') {
        json_out(['error' => '项目还没绑定服务器或部署目录'], 400);
    }
    $主机 = ssh_host_of((int) $仓['host_id'], $uid);
    if (!$主机) {
        json_out(['error' => '绑定的服务器不存在或已停用'], 400);
    }
    // paths 可选：JSON 数组，只回传指定文件
    $限定 = [];
    $原 = (string) ($_POST['paths'] ?? '');
    if (trim($原) !== '') {
        $j = json_decode($原, true);
        if (is_array($j)) {
            foreach ($j as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $规 = 仓规范路径((string) $p);
                    if ($规 !== '') { $限定[] = $规; }
                }
            }
        }
    }
    // 回传前先看清单里有没有高风险文件。没带 confirm 就停下来问，不动服务器。
    $已确认 = (string) ($_POST['confirm'] ?? '') === '1';
    if (!$已确认) {
        $待 = 仓待回传($仓, $限定);
        $险 = 仓回传风险($待);
        if ($险) {
            $行 = [];
            foreach ($险 as $x) {
                $行[] = '· ' . $x['path'] . ' — ' . $x['why'];
            }
            json_out([
                'need_confirm' => 1,
                'risks'        => $险,
                'total'        => count($待),
                'msg'          => '这批回传里有 ' . count($险) . ' 个文件需要确认（共 ' . count($待) . ' 个待回传）：' . "\n"
                                . implode("\n", $行),
            ]);
        }
    }

    $r = 仓回传($仓, $主机, $限定);
    if (!$r['ok'] && $r['成功'] === 0) {
        json_out(['error' => $r['error'] ?: '回传失败'], 400);
    }
    $msg = '回传完成：成功 ' . $r['成功'] . ' 个'
         . ($r['失败'] > 0 ? ('，失败 ' . $r['失败'] . ' 个') : '')
         . '。服务器端备份：' . $r['备份'];
    json_out(['ok' => 1, 'msg' => $msg, 'ok_count' => $r['成功'],
        'fail_count' => $r['失败'], 'backup' => $r['备份'], 'detail' => $r['明细']]);
}

// ---- 上传：单个代码文件或压缩包 ----
// ---- 解压仓内已有的压缩包 ----
// 上传一律 keep（整包完整进仓），要展开时由用户在代码仓里点「解压」走到这里。
// 和 upload 里的解压循环是同一套过滤与安全闸门，区别只有两点：
//   1. 包不在 $_FILES 里，得先从 repo_files 把 content 取出来；
//   2. ZipArchive 只能开磁盘上的文件，所以要先落一个临时文件，用完即删。
if ($act === 'extract') {
    [$项目, $仓] = 取仓上下文($uid);
    $包路径 = 仓规范路径((string) ($_POST['path'] ?? ''));
    if ($包路径 === '') {
        json_out(['error' => '没有指定要解压的压缩包'], 400);
    }
    $扩名 = strtolower((string) pathinfo($包路径, PATHINFO_EXTENSION));
    if ($扩名 !== 'zip') {
        json_out(['error' => '目前只能解压 .zip。' . ($扩名 === '' ? '这个文件没有扩展名' : '.' . $扩名 . ' 格式请在本地解开后再上传')], 400);
    }
    if (!class_exists('ZipArchive')) {
        json_out(['error' => '服务器未启用 zip 扩展，无法解压'], 500);
    }
    $包行 = db_one('SELECT * FROM repo_files WHERE repo_id = ? AND path = ? AND user_id = ? LIMIT 1',
        [(int) $仓['id'], $包路径, $uid]);
    if (!$包行) {
        json_out(['error' => '仓里没有这个文件：' . $包路径], 404);
    }
    // 解到哪：默认解到包所在的同级目录，跟本地双击解压的直觉一致。
    // 传 dir 可以指定别处，传 dir=. 表示解到仓根。
    if (isset($_POST['dir'])) {
        $原样 = trim((string) $_POST['dir']);
        $前缀 = ($原样 === '.' || $原样 === '') ? '' : 仓规范路径($原样);
    } else {
        $父 = str_contains($包路径, '/') ? substr($包路径, 0, strrpos($包路径, '/')) : '';
        $前缀 = $父;
    }
    // 完整入仓：不按忽略规则筛、二进制也收。用户主动解的包以他的意思为准
    $完整 = ((string) ($_POST['full'] ?? '1')) !== '0';
    // 原包一律留在仓里。代码仓目前没有删除动作（repo_files 靠 state 做软删，
    // 回传时要靠它判断服务器上该删哪些文件），加删除是另一件事，不在这里顺手做。
    // 包留着也没坏处：占的是本地副本的空间，回传时它和其它文件一样传上去，
    // 用户觉得多余可以自己在服务器上清掉。
    $临时 = tempnam(sys_get_temp_dir(), 'repozip');
    if ($临时 === false || @file_put_contents($临时, (string) $包行['content']) === false) {
        if ($临时 !== false) { @unlink($临时); }
        json_out(['error' => '服务器临时目录写入失败，无法解压'], 500);
    }
    $zip = new ZipArchive();
    if ($zip->open($临时) !== true) {
        @unlink($临时);
        json_out(['error' => '压缩包打不开，可能已损坏或不是 zip 格式'], 400);
    }
    $成功 = 0;
    $跳过 = [];
    $字节 = 0;
    $达上限 = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if (!is_array($st)) {
            continue;
        }
        $条目名 = (string) $st['name'];
        if ($条目名 === '' || substr($条目名, -1) === '/') {
            continue;
        }
        $条目大小 = (int) ($st['size'] ?? 0);
        // 路径规范化即安全闸门：绝对路径、.. 越界一律被挡掉
        $相对 = 仓规范路径($前缀 === '' ? $条目名 : $前缀 . '/' . $条目名);
        if ($相对 === '') {
            $跳过[] = $条目名 . '（路径不合法）';
            continue;
        }
        // 别把自己覆盖掉：包里恰好有个同名同路径的文件时跳过，
        // 否则边读边写同一条记录，解出来的东西是什么谁也说不清
        if ($相对 === $包路径) {
            $跳过[] = $相对 . '（与压缩包自身同路径）';
            continue;
        }
        if (!$完整) {
            if (仓该忽略($相对)) {
                $跳过[] = $相对 . '（在忽略列表里）';
                continue;
            }
            if (!仓是文本($相对)) {
                $跳过[] = $相对 . '（非文本文件）';
                continue;
            }
        }
        if ($条目大小 > 仓文件上限) {
            $跳过[] = $相对 . '（超过 ' . size_text(仓文件上限) . '）';
            continue;
        }
        $内容 = $zip->getFromIndex($i);
        if ($内容 === false) {
            $跳过[] = $相对 . '（读取失败）';
            continue;
        }
        if ($完整) {
            $r = 仓写原始文件($仓, $相对, (string) $内容, '解压 ' . $包路径);
        } else {
            if (strpos($内容, "\0") !== false) {
                $跳过[] = $相对 . '（内容是二进制）';
                continue;
            }
            $r = 仓写文件($仓, $相对, (string) $内容, 'write', 0, '解压 ' . $包路径);
        }
        if (!$r['ok']) {
            $跳过[] = $相对 . '（' . $r['error'] . '）';
            if (strpos($r['error'], '上限') !== false) {
                $达上限 = true;
                break;
            }
            continue;
        }
        $成功++;
        $字节 += $r['size'];
    }
    $zip->close();
    @unlink($临时);
    if ($成功 === 0) {
        仓刷新统计((int) $仓['id'], $uid);
        json_out(['error' => '这个包里没有可收下的文件'
            . ($跳过 ? '。跳过：' . implode('；', array_slice($跳过, 0, 8)) : ''),
            'skipped' => array_slice($跳过, 0, 30)], 400);
    }
    仓刷新统计((int) $仓['id'], $uid);
    $msg = '已解出 ' . $成功 . ' 个文件（' . size_text($字节) . '）到 '
         . ($前缀 === '' ? '仓根' : $前缀);
    if ($跳过) {
        $msg .= '，跳过 ' . count($跳过) . ' 个';
    }
    if ($达上限) {
        $msg .= '（仓已达上限，未解完）';
    }
    $msg .= '。改动还未回传到服务器。';
    json_out(['ok' => 1, 'mode' => 'extract', 'path' => $包路径,
        'dir' => $前缀, '成功' => $成功, '跳过' => count($跳过),
        'skipped' => array_slice($跳过, 0, 30), 'bytes' => $字节,
        'size_text' => size_text($字节),
        'limit' => $达上限 ? 1 : 0, 'msg' => $msg]);
}
if ($act === 'upload') {
    [$项目, $仓] = 取仓上下文($uid);
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        json_out(['error' => '没有收到文件'], 400);
    }
    $f = $_FILES['file'];
    if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_out(['error' => '上传失败（错误码 ' . (int) ($f['error'] ?? -1) . '）'], 400);
    }
    if (!is_uploaded_file((string) $f['tmp_name'])) {
        json_out(['error' => '非法上传'], 400);
    }
    $大小 = (int) $f['size'];
    if ($大小 <= 0) {
        json_out(['error' => '文件是空的'], 400);
    }
    // 压缩包整体可以比单文件大些，按后台上限的 10 倍收，最少 50 MB
    $单上限 = max(1, (int) setting_get('upload_max_mb', 5)) * 1048576;
    $包上限 = max($单上限 * 10, 52428800);

    // 子目录前缀：允许把包解到仓内某个子目录下，留空则解到仓根
    $前缀 = 仓规范路径((string) ($_POST['dir'] ?? ''));

    $原名 = basename((string) ($_POST['name'] ?? $f['name']));
    $扩名 = strtolower((string) pathinfo($原名, PATHINFO_EXTENSION));
    $是压缩包 = in_array($扩名, ['zip', 'tar', 'gz', 'tgz', 'bz2', 'rar', '7z'], true);

    // 压缩包怎么处理：keep 整个包原样收下（默认） / extract 解压后逐个入仓
    // 默认改成 keep：上传时一律完整入仓，要不要展开由用户在代码仓里点「解压」决定。
    // 原来默认 extract 有个实际麻烦——包里几百个文件一次涌进仓，用户还没看清就已经
    // 混进一堆 node_modules、.git 之类的东西，想撤只能一个个删。
    $方式 = ((string) ($_POST['mode'] ?? 'keep')) === 'extract' ? 'extract' : 'keep';
    // 完整入仓：不按忽略规则筛、二进制也收。用户主动上传的东西以他的意思为准
    $完整 = ((string) ($_POST['full'] ?? '1')) !== '0';

    if (!$是压缩包) {
        // ---- 单个代码文件 ----
        if ($大小 > $单上限) {
            json_out(['error' => '单个文件不能超过 ' . size_text($单上限)], 400);
        }
        if ($大小 > 仓文件上限) {
            json_out(['error' => '单个文件不能超过 ' . size_text(仓文件上限)], 400);
        }
        $目标 = 仓规范路径($前缀 === '' ? $原名 : $前缀 . '/' . $原名);
        if ($目标 === '') {
            json_out(['error' => '文件名不合法'], 400);
        }
        $内容 = (string) @file_get_contents((string) $f['tmp_name']);
        if ($完整) {
            // 完整入仓：类型不筛，只守大小与路径
            $r = 仓写原始文件($仓, $目标, $内容, '用户上传', $单上限);
        } else {
            if (!仓是文本($目标)) {
                json_out(['error' => '代码仓只收文本文件，二进制文件请勾选「完整入仓」或压成 zip'], 400);
            }
            if (仓该忽略($目标)) {
                json_out(['error' => '这个路径在忽略列表里，不允许写入：' . $目标], 400);
            }
            if (strpos($内容, "\0") !== false) {
                json_out(['error' => '这个文件看起来是二进制，不能作为代码文件收下'], 400);
            }
            $r = 仓写文件($仓, $目标, $内容, 'write', 0, '用户上传');
        }
        if (!$r['ok']) {
            json_out(['error' => $r['error']], 400);
        }
        仓刷新统计((int) $仓['id'], $uid);
        json_out(['ok' => 1, 'mode' => 'file', 'path' => $目标,
            'size' => $r['size'], 'size_text' => size_text($r['size']),
            '成功' => 1, '跳过' => 0, 'skipped' => [],
            'msg' => '已上传到本地副本：' . $目标 . '（' . size_text($r['size'])
                   . '）。改动还未回传到服务器。']);
    }

    // ---- 压缩包 ----
    if ($大小 > $包上限) {
        json_out(['error' => '压缩包不能超过 ' . size_text($包上限)], 400);
    }

    // 原样收下：不解压，整个包作为一个文件进仓。tar.gz、rar、7z 这类也能走这条路
    if ($方式 === 'keep') {
        $目标 = 仓规范路径($前缀 === '' ? $原名 : $前缀 . '/' . $原名);
        if ($目标 === '') {
            json_out(['error' => '文件名不合法'], 400);
        }
        $内容 = (string) @file_get_contents((string) $f['tmp_name']);
        $r = 仓写原始文件($仓, $目标, $内容, '压缩包原样上传', $包上限);
        if (!$r['ok']) {
            json_out(['error' => $r['error']], 400);
        }
        仓刷新统计((int) $仓['id'], $uid);
        json_out(['ok' => 1, 'mode' => 'keep', 'path' => $目标,
            'size' => $r['size'], 'size_text' => size_text($r['size']),
            '成功' => 1, '跳过' => 0, 'skipped' => [],
            'warn' => '压缩包回传后会落在网站根目录下，可能被公网直接下载。'
                    . '只是拿它入仓、不打算上线的话，回传前记得先删掉。',
            'msg' => '压缩包已原样收进本地副本：' . $目标 . '（' . size_text($r['size'])
                   . '）。改动还未回传到服务器。'
                   . "\n注意：压缩包回传后会落在网站根目录下，可能被公网直接下载。"
                   . '只是拿它入仓、不打算上线的话，回传前记得先删掉。']);
    }

    if ($扩名 !== 'zip') {
        json_out(['error' => '要解压的话目前只支持 .zip。' . $扩名
            . ' 格式请改选「原样收下不解压」，或先转成 zip'], 400);
    }
    if (!class_exists('ZipArchive')) {
        json_out(['error' => '服务器未启用 zip 扩展，无法解压。请单个上传代码文件'], 500);
    }
    $zip = new ZipArchive();
    if ($zip->open((string) $f['tmp_name']) !== true) {
        json_out(['error' => '压缩包打不开，可能已损坏或不是 zip 格式'], 400);
    }

    $成功 = 0;
    $跳过 = [];
    $字节 = 0;
    $达上限 = false;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if (!is_array($st)) {
            continue;
        }
        $条目名 = (string) $st['name'];
        // 目录项跳过，文件会带着自己的完整相对路径进来
        if ($条目名 === '' || substr($条目名, -1) === '/') {
            continue;
        }
        $条目大小 = (int) ($st['size'] ?? 0);

        // 路径规范化即安全闸门：绝对路径、.. 越界一律被挡掉
        $相对 = 仓规范路径($前缀 === '' ? $条目名 : $前缀 . '/' . $条目名);
        if ($相对 === '') {
            $跳过[] = $条目名 . '（路径不合法）';
            continue;
        }
        if (!$完整) {
            if (仓该忽略($相对)) {
                $跳过[] = $相对 . '（在忽略列表里）';
                continue;
            }
            if (!仓是文本($相对)) {
                $跳过[] = $相对 . '（非文本文件）';
                continue;
            }
        }
        if ($条目大小 > 仓文件上限) {
            $跳过[] = $相对 . '（超过 ' . size_text(仓文件上限) . '）';
            continue;
        }
        $内容 = $zip->getFromIndex($i);
        if ($内容 === false) {
            $跳过[] = $相对 . '（读取失败）';
            continue;
        }
        if ($完整) {
            $r = 仓写原始文件($仓, $相对, (string) $内容, '压缩包上传');
        } else {
            if (strpos($内容, "\0") !== false) {
                $跳过[] = $相对 . '（内容是二进制）';
                continue;
            }
            $r = 仓写文件($仓, $相对, (string) $内容, 'write', 0, '压缩包上传');
        }
        if (!$r['ok']) {
            $跳过[] = $相对 . '（' . $r['error'] . '）';
            // 仓满了就别继续硬塞，直接收尾告诉用户
            if (strpos($r['error'], '上限') !== false) {
                $达上限 = true;
                break;
            }
            continue;
        }
        $成功++;
        $字节 += $r['size'];
    }
    $zip->close();

    仓刷新统计((int) $仓['id'], $uid);

    if ($成功 === 0) {
        json_out(['error' => '压缩包里没有可收下的代码文件'
            . ($跳过 ? '。跳过：' . implode('；', array_slice($跳过, 0, 8)) : ''),
            'skipped' => $跳过], 400);
    }
    $msg = '已从压缩包收下 ' . $成功 . ' 个文件（' . size_text($字节) . '）';
    if ($跳过) {
        $msg .= '，跳过 ' . count($跳过) . ' 个';
    }
    if ($达上限) {
        $msg .= '（仓已达上限，未收全）';
    }
    $msg .= '。改动还未回传到服务器。';
    json_out(['ok' => 1, 'mode' => 'zip', '成功' => $成功, '跳过' => count($跳过),
        'skipped' => array_slice($跳过, 0, 30), 'bytes' => $字节,
        'size_text' => size_text($字节), 'msg' => $msg]);
}

// ---- 同步日志 ----
if ($act === 'logs') {
    [$项目, $仓] = 取仓上下文($uid);
    $行 = db_all('SELECT id, direction, ok, file_count, bytes, backup, ms, created_at
                   FROM repo_syncs WHERE repo_id=? AND user_id=?
                  ORDER BY id DESC LIMIT 30', [(int) $仓['id'], $uid]);
    json_out(['ok' => 1, 'list' => $行]);
}

// ---- 直连：列出远程目录 ----
if ($act === 'rlist') {
    $项目id = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
    $项目 = project_of($项目id, $uid);
    if (!$项目) { json_out(['error' => '项目不存在或不属于你'], 404); }
    if ((int) $项目['host_id'] <= 0 || trim((string) $项目['deploy_dir']) === '') {
        json_out(['error' => '项目未绑定服务器或未填部署目录'], 400);
    }
    $主机 = ssh_host_of((int) $项目['host_id'], $uid);
    if (!$主机) { json_out(['error' => '绑定的服务器不存在或已停用'], 400); }
    $sftp = 同步开SFTP($主机);
    if (!$sftp) { json_out(['error' => '无法打开 SFTP 通道'], 500); }
    $根 = rtrim((string) $项目['deploy_dir'], '/');
    $目录 = (string) ($_GET['dir'] ?? $_POST['dir'] ?? '');
    $远程 = $目录 === '' ? $根 : $根 . '/' . 仓规范路径($目录);
    $表 = @$sftp->rawlist($远程);
    if (!is_array($表)) { json_out(['error' => '目录不存在或无权限：' . $远程], 404); }
    $出 = [];
    foreach ($表 as $名 => $属) {
        if ($名 === '.' || $名 === '..' || !is_array($属)) { continue; }
        $出[] = ['name' => $名, 'type' => (int) ($属['type'] ?? 0), 'size' => (int) ($属['size'] ?? 0),
                 'size_text' => size_text((int) ($属['size'] ?? 0))];
    }
    usort($出, fn($a, $b) => $b['type'] <=> $a['type'] ?: strcmp($a['name'], $b['name']));
    json_out(['ok' => 1, 'dir' => $远程, 'list' => $出]);
}

// ---- 直连：读一个远程文件（不经本地副本）----
if ($act === 'rread') {
    $项目id = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
    $项目 = project_of($项目id, $uid);
    if (!$项目) { json_out(['error' => '项目不存在或不属于你'], 404); }
    if ((int) $项目['host_id'] <= 0 || trim((string) $项目['deploy_dir']) === '') {
        json_out(['error' => '项目未绑定服务器或未填部署目录'], 400);
    }
    $主机 = ssh_host_of((int) $项目['host_id'], $uid);
    if (!$主机) { json_out(['error' => '绑定的服务器不存在或已停用'], 400); }
    $路径 = (string) ($_GET['path'] ?? $_POST['path'] ?? '');
    if (trim($路径) === '') { json_out(['error' => '缺少 path 参数'], 400); }
    $r = 远程读文件($主机, (string) $项目['deploy_dir'], $路径);
    if (!$r['ok']) { json_out(['error' => $r['error']], 400); }
    $文 = $r['text'];
    $截断 = $r['truncated'] ? true : false;
    if (!$截断 && mb_strlen($文) > 仓正文上限) {
        $文 = mb_substr($文, 0, 仓正文上限);
        $截断 = true;
    }
    json_out(['ok' => 1, 'path' => $路径, 'text' => $文,
              'size' => $r['size'], 'truncated' => $截断 ? 1 : 0,
              'note' => '直连读取，无本地副本，不支持回滚']);
}

// ---- 直连：写一个远程文件（不经本地副本，写前自动备份）----
if ($act === 'rwrite') {
    csrf_check();
    $项目id = (int) ($_POST['project_id'] ?? 0);
    $项目 = project_of($项目id, $uid);
    if (!$项目) { json_out(['error' => '项目不存在或不属于你'], 404); }
    if ((int) $项目['host_id'] <= 0 || trim((string) $项目['deploy_dir']) === '') {
        json_out(['error' => '项目未绑定服务器或未填部署目录'], 400);
    }
    $主机 = ssh_host_of((int) $项目['host_id'], $uid);
    if (!$主机) { json_out(['error' => '绑定的服务器不存在或已停用'], 400); }
    $路径 = (string) ($_POST['path'] ?? '');
    $内容 = (string) ($_POST['text'] ?? '');
    if (trim($路径) === '') { json_out(['error' => '缺少 path 参数'], 400); }
    $r = 远程写文件($主机, (string) $项目['deploy_dir'], $路径, $内容);
    if (!$r['ok']) { json_out(['error' => $r['error']], 400); }
    json_out(['ok' => 1, 'path' => $路径, 'size' => $r['size'],
              'size_text' => size_text($r['size']),
              '备份' => $r['备份'], '新建' => $r['新建'] ? 1 : 0,
              'note' => '直连写入，无本地副本版本历史，修改立即生效']);
}

json_out(['error' => '未知操作'], 400);
