<?php
/**
 * 直连 SFTP 接口：不经过本地副本，直接读改客户服务器上的文件。
 *
 * 动作：
 *   list     列远端目录（只列一层）
 *   read     读远端文件正文
 *   write    整文件覆写远端文件
 *   patch    按内容定位做局部替换（读→替换→写回，全程 SFTP）
 *   delete   删远端文件（删前必定留备份，只删单文件不删目录）
 *   history  本项目的直连编辑记录
 *   restore  把某次编辑还原回去
 *
 * 与 api/repo.php 的分工：
 *   repo.php  操作工作中心里的本地副本，file-push 才回传，适合成套改动。
 *   本文件    直接落到远端，适合改一两处配置。写前必定留备份。
 *
 * 所有查询一律带 user_id，用户之间互不可见。
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
require __DIR__ . '/../inc/sftp_edit.php';

start_session();
$me  = require_login_api();
$act = (string) ($_POST['act'] ?? $_GET['act'] ?? '');
$uid = (int) $me['id'];

// 检查用户 SFTP 操作权限
$userCap = db_one('SELECT cap_sftp_read, cap_sftp_write, cap_sftp_list, cap_sftp_delete FROM users WHERE id = ?', [$uid]);
$权限映射 = [
    'list' => 'cap_sftp_list',
    'read' => 'cap_sftp_read',
    'write' => 'cap_sftp_write',
    'patch' => 'cap_sftp_write',
    'delete' => 'cap_sftp_delete',
];
if (isset($权限映射[$act])) {
    $需要字段 = $权限映射[$act];
    if ((int) ($userCap[$需要字段] ?? 1) !== 1) {
        $操作名 = ['list' => '列表', 'read' => '读取', 'write' => '写入', 'patch' => '写入', 'delete' => '删除'][$act] ?? $act;
        json_out(['error' => "你没有 SFTP {$操作名}权限"]);
    }
}

// 只读动作允许 GET，凡是会落到客户服务器的一律 POST + CSRF
$只读 = ['list', 'read', 'history'];
if (!in_array($act, $只读, true)) {
    csrf_check();
}

/** 取当前项目，并确认归属。项目不属于本人时一律当不存在。 */
function 直改取项目(int $uid): array
{
    $pid = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
    if ($pid <= 0) {
        json_out(['error' => '缺少项目编号。直连改文件要先知道改哪台服务器的哪个目录，'
            . '请把这条对话挂到一个项目下。'], 400);
    }
    $项目 = project_of($pid, $uid);
    if (!$项目) {
        json_out(['error' => '项目不存在或不属于你'], 404);
    }
    return $项目;
}

// ---- 列目录 ----
if ($act === 'list') {
    $项目 = 直改取项目($uid);
    $子   = (string) ($_GET['dir'] ?? $_POST['dir'] ?? '');
    $r = 直改列目录($项目, $uid, $子);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    json_out(['ok' => 1, 'dir' => $r['dir'], 'truncated' => $r['truncated'] ? 1 : 0,
        'count' => count($r['list']), 'list' => $r['list']]);
}

// ---- 读文件 ----
if ($act === 'read') {
    $项目 = 直改取项目($uid);
    $路径 = (string) ($_GET['path'] ?? $_POST['path'] ?? '');
    if (trim($路径) === '') {
        json_out(['error' => '缺少文件路径'], 400);
    }
    $r = 直改读文件($项目, $uid, $路径);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    $文 = $r['text'];
    $截断 = false;
    if (mb_strlen($文) > 仓正文上限) {
        $文  = mb_substr($文, 0, 仓正文上限);
        $截断 = true;
    }
    json_out(['ok' => 1, 'path' => $r['path'], 'text' => $文,
        'truncated' => $截断 ? 1 : 0, 'size' => $r['size'],
        'size_text' => size_text($r['size']),
        'lines' => substr_count($r['text'], "\n") + 1]);
}

// ---- 整文件覆写 ----
if ($act === 'write') {
    $项目 = 直改取项目($uid);
    $路径 = (string) ($_POST['path'] ?? '');
    $内容 = (string) ($_POST['text'] ?? '');
    $说明 = (string) ($_POST['note'] ?? '');
    $对话 = (int) ($_POST['conv_id'] ?? 0);
    if (trim($路径) === '') {
        json_out(['error' => '缺少文件路径'], 400);
    }
    $r = 直改写文件($项目, $uid, $路径, $内容, 'write', $对话, $说明);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    $消息 = '已通过 SFTP 写入客户服务器：' . $r['path']
        . '（+' . $r['add'] . ' -' . $r['del'] . ' 行）。'
        . (empty($r['new'])
            ? '改动前的原文件已备份到 ' . $r['backup'] . '。'
            : '这是新建文件，服务器上原先没有它。')
        . '这次改动已经生效，不需要再回传。';
    json_out(['ok' => 1, 'path' => $r['path'], 'add' => $r['add'], 'del' => $r['del'],
        'backup' => $r['backup'], 'edit_id' => $r['edit_id'],
        'new' => empty($r['new']) ? 0 : 1, 'msg' => $消息]);
}

// ---- 局部补丁：读远端 → 内容定位替换 → 写回远端 ----
if ($act === 'patch') {
    $项目 = 直改取项目($uid);
    $路径 = (string) ($_POST['path'] ?? '');
    $查找 = (string) ($_POST['find'] ?? '');
    $替换 = (string) ($_POST['replace'] ?? '');
    $说明 = (string) ($_POST['note'] ?? '');
    $对话 = (int) ($_POST['conv_id'] ?? 0);
    if (trim($路径) === '') {
        json_out(['error' => '缺少文件路径'], 400);
    }
    if ($查找 === '') {
        json_out(['error' => '补丁缺少「原文」片段'], 400);
    }
    $读 = 直改读文件($项目, $uid, $路径);
    if (!$读['ok']) {
        json_out(['error' => $读['error']], 400);
    }
    $应用 = 仓应用补丁($读['text'], $查找, $替换);
    if (!$应用['ok']) {
        // 失败原因要说清楚，AI 才知道怎么补上下文，而不是原样重试
        json_out(['error' => $应用['error'], 'patch_failed' => 1], 400);
    }
    $r = 直改写文件($项目, $uid, $路径, $应用['text'], 'patch', $对话, $说明);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    $档 = [1 => '精确匹配', 2 => '忽略行尾空白', 3 => '忽略缩进'][$应用['档']] ?? '';
    json_out(['ok' => 1, 'path' => $r['path'], 'add' => $r['add'], 'del' => $r['del'],
        'mode' => $档, 'backup' => $r['backup'], 'edit_id' => $r['edit_id'],
        'msg' => '补丁已通过 SFTP 写入客户服务器（' . $档 . '）：' . $r['path']
            . '（+' . $r['add'] . ' -' . $r['del'] . ' 行）。原文件已备份到 '
            . $r['backup'] . '。这次改动已经生效。']);
}

// ---- 删除文件 ----
// 不可逆动作，所以要求 POST + CSRF（已由上面的 $只读 判定覆盖），
// 且底层会先把内容备份到远端 .kiro_backup 与库里，再执行删除。
if ($act === 'delete') {
    $项目 = 直改取项目($uid);
    $路径 = (string) ($_POST['path'] ?? '');
    $说明 = (string) ($_POST['note'] ?? '');
    $对话 = (int) ($_POST['conv_id'] ?? 0);
    if (trim($路径) === '') {
        json_out(['error' => '缺少文件路径'], 400);
    }
    $r = 直改删文件($项目, $uid, $路径, $对话, $说明);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    json_out(['ok' => 1, 'path' => $r['path'], 'backup' => $r['backup'],
        'edit_id' => $r['edit_id'],
        'msg' => '已通过 SFTP 删除客户服务器上的文件：' . $r['path']
            . '（' . size_text((int) $r['size']) . '）。'
            . '删除前的内容已备份到 ' . $r['backup']
            . '，需要时可以用编辑记录还原。']);
}

// ---- 编辑记录 ----
if ($act === 'history') {
    $pid = (int) ($_GET['project_id'] ?? 0);
    $条件 = 'user_id=?';
    $参 = [$uid];
    if ($pid > 0) {
        $条件 .= ' AND project_id=?';
        $参[] = $pid;
    }
    try {
        $行 = db_all('SELECT id, project_id, path, action, backup_path, note, created_at,
                        CHAR_LENGTH(old_text) AS old_len, CHAR_LENGTH(new_text) AS new_len
                      FROM sftp_edits WHERE ' . $条件 . '
                      ORDER BY id DESC LIMIT 100', $参);
    } catch (Throwable $e) {
        json_out(['error' => '编辑记录表还没建，请先执行 sql/06_直连编辑.sql'], 500);
    }
    json_out(['ok' => 1, 'list' => $行]);
}

// ---- 还原某次编辑 ----
if ($act === 'restore') {
    $id = (int) ($_POST['edit_id'] ?? 0);
    if ($id <= 0) {
        json_out(['error' => '缺少编辑记录编号'], 400);
    }
    $r = 直改还原($id, $uid);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    json_out(['ok' => 1, 'path' => $r['path'],
        'msg' => '已把 ' . $r['path'] . ' 还原到这次编辑之前的内容。']);
}

json_out(['error' => '未知动作'], 400);
