<?php
/**
 * 项目管理接口。
 * 动作：list / get / save（新增/修改）/ del / archive / pin
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();
require_once __DIR__ . '/../inc/project.php';

$me  = require_login_api();
$uid = (int) $me['id'];
$act = (string) ($_POST['act'] ?? $_GET['act'] ?? 'list');

// ---------- 读操作 ----------
if ($act === 'list') {
    $含归档 = !empty($_GET['archived']);
    $rows = project_list($uid, $含归档);
    json_out(['ok' => 1, 'list' => $rows]);
}

if ($act === 'get') {
    $pid = (int) ($_GET['id'] ?? 0);
    $p = project_of($pid, $uid);
    if (!$p) {
        json_out(['error' => '项目不存在']);
    }
    json_out(['ok' => 1, 'project' => $p]);
}

// ---------- 写操作需要 CSRF ----------
csrf_check();

if ($act === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $入 = [
        'name'       => trim((string) ($_POST['name'] ?? '')),
        'intro'      => trim((string) ($_POST['intro'] ?? '')),
        'stack'      => trim((string) ($_POST['stack'] ?? '')),
        'host_id'    => (int) ($_POST['host_id'] ?? 0),
        'deploy_dir' => trim((string) ($_POST['deploy_dir'] ?? '')),
        'site_url'   => trim((string) ($_POST['site_url'] ?? '')),
        'color'        => preg_replace('/[^a-z0-9#]/i', '', (string) ($_POST['color'] ?? '')),
        'hidden'       => !empty($_POST['hidden']) ? 1 : 0,
        'project_type' => (($_POST['project_type'] ?? 'cloud') === 'local') ? 'local' : 'cloud',
    ];

    $err = project_validate($入);
    if ($err !== '') {
        json_out(['error' => $err]);
    }

    // 绑定服务器须属于本人
    if ($入['host_id'] > 0) {
        if (!db_one('SELECT id FROM ssh_hosts WHERE id = ? AND user_id = ? AND status = 1',
            [$入['host_id'], $uid])) {
            json_out(['error' => '所选服务器不存在或不属于你']);
        }
    }

    if ($id > 0) {
        // 修改
        $p = project_of($id, $uid);
        if (!$p) {
            json_out(['error' => '项目不存在']);
        }
        db_exec(
            'UPDATE projects
                SET name=?, intro=?, stack=?, host_id=?, deploy_dir=?, site_url=?,
                    color=?, updated_at=NOW()
              WHERE id=? AND user_id=?',
            [$入['name'], $入['intro'], $入['stack'], $入['host_id'],
             $入['deploy_dir'], $入['site_url'], $入['color'], $id, $uid]
        );
        json_out(['ok' => 1, 'id' => $id]);
    }

    // 新建：单用户最多 100 个活跃项目
    $n = (int) db_val('SELECT COUNT(*) FROM projects WHERE user_id=? AND archived=0', [$uid]);
    if ($n >= 100) {
        json_out(['error' => '活跃项目超过 100 个，请先归档一些']);
    }
    $newId = db_insert(
        'INSERT INTO projects (user_id, name, intro, stack, host_id, deploy_dir, site_url,
                               color, hidden, project_type, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [$uid, $入['name'], $入['intro'], $入['stack'],
         $入['host_id'], $入['deploy_dir'], $入['site_url'], $入['color'],
         $入['hidden'], $入['project_type']]
    );
    json_out(['ok' => 1, 'id' => $newId]);
}

if ($act === 'del') {
    $id = (int) ($_POST['id'] ?? 0);
    $p = project_of($id, $uid);
    if (!$p) {
        json_out(['error' => '项目不存在']);
    }
    // 有对话的项目不直接删，先要求用户归档或迁移
    $count = (int) db_val('SELECT COUNT(*) FROM conversations WHERE project_id=?', [$id]);
    if ($count > 0) {
        json_out(['error' => "项目下还有 {$count} 条对话，请先删除对话再删除项目，或选择归档项目。"]);
    }
    db_exec('DELETE FROM projects WHERE id=? AND user_id=?', [$id, $uid]);
    json_out(['ok' => 1]);
}

if ($act === 'hide') {
    $id  = (int) ($_POST['id'] ?? 0);
    $val = (int) ($_POST['hidden'] ?? 1);
    $n = db_exec('UPDATE projects SET hidden=?, updated_at=updated_at WHERE id=? AND user_id=?',
        [$val ? 1 : 0, $id, $uid]);
    json_out($n ? ['ok' => 1] : ['error' => '项目不存在']);
}

if ($act === 'archive') {
    $id  = (int) ($_POST['id'] ?? 0);
    $val = (int) ($_POST['archived'] ?? 1);
    $n = db_exec('UPDATE projects SET archived=?, updated_at=NOW() WHERE id=? AND user_id=?',
        [$val ? 1 : 0, $id, $uid]);
    json_out($n ? ['ok' => 1] : ['error' => '项目不存在']);
}

if ($act === 'pin') {
    $id  = (int) ($_POST['id'] ?? 0);
    $val = (int) ($_POST['pinned'] ?? 1);
    $n = db_exec('UPDATE projects SET pinned=?, updated_at=NOW() WHERE id=? AND user_id=?',
        [$val ? 1 : 0, $id, $uid]);
    json_out($n ? ['ok' => 1] : ['error' => '项目不存在']);
}

json_out(['error' => '未知操作'], 400);
