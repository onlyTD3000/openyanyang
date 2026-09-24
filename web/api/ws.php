<?php
/**
 * 工作中心文件库接口：图片和文件同一套，严格按账号隔离。
 *
 * 动作：
 *   list    列文件（图片 + 文件，可按类型筛选、按名搜索）
 *   read    读文本文件正文
 *   write   写文本文件（不存在新建，存在覆盖，覆盖前留快照）
 *   patch   局部替换（读→按内容定位替换→写回）
 *   del     删除
 *   rename  重命名
 *   stat    空间用量与配额
 *   down    下载 / 在线查看原文件
 *   upload  上传任意文件（非图片走这里，图片仍走 api/upload.php）
 *   pvinfo  Office 文档逐页预览：转图并返回页数
 *   pvpage  取逐页预览的某一页 PNG
 *   preview Office 文档转 PDF（「用 PDF 打开」入口，浏览器设置可能拦内嵌渲染）
 *
 * 隔离：每个动作都用 $me['id'] 作为 user_id 条件，不接受前端传入的 user_id。
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();
require_once __DIR__ . '/../inc/ws_files.php';
require_once __DIR__ . '/../inc/repo.php';       // 复用 仓应用补丁 / 仓差异
require_once __DIR__ . '/../inc/office_preview.php';

$me  = require_login_api();
$uid = (int) $me['id'];
$act = (string) ($_POST['act'] ?? $_GET['act'] ?? 'list');

// 检查用户工作中心操作权限
$userCap = db_one('SELECT cap_ws_list, cap_ws_read, cap_ws_write FROM users WHERE id = ?', [$uid]);
$权限映射 = [
    'list' => 'cap_ws_list',
    'stat' => 'cap_ws_list',
    'read' => 'cap_ws_read',
    'down' => 'cap_ws_read',
    'preview' => 'cap_ws_read',
    'pvinfo' => 'cap_ws_read',
    'pvpage' => 'cap_ws_read',
    'write' => 'cap_ws_write',
    'patch' => 'cap_ws_write',
    'delete' => 'cap_ws_write',
    'rename' => 'cap_ws_write',
];
if (isset($权限映射[$act])) {
    $需要字段 = $权限映射[$act];
    if ((int) ($userCap[$需要字段] ?? 1) !== 1) {
        $操作名 = [
            'list' => '列表', 'stat' => '查看状态', 'read' => '读取', 'down' => '下载',
            'preview' => '预览', 'pvinfo' => '预览', 'pvpage' => '预览',
            'write' => '写入', 'patch' => '写入', 'delete' => '删除', 'rename' => '重命名'
        ][$act] ?? $act;
        json_out(['error' => "你没有工作中心{$操作名}权限"]);
    }
}

// 只读动作允许 GET，改动类一律 POST + CSRF
// preview 虽然会在服务端生成 PDF，但产出只是原文件的另一种表示、
// 不改动用户数据，所以归到只读这边，否则 iframe 里没法直接 GET 它。
$只读 = ['list', 'read', 'stat', 'down', 'preview', 'pvinfo', 'pvpage'];
if (!in_array($act, $只读, true)) {
    csrf_check();
}

// ---- 列文件 ----
if ($act === 'list') {
    $类 = (string) ($_GET['kind'] ?? 'all');
    $搜 = (string) ($_GET['q'] ?? '');
    $页 = (int) ($_GET['page'] ?? 1);
    $每 = (int) ($_GET['size'] ?? 30);
    $r  = 工作区列文件($uid, $类, $搜, $页, $每);
    // 可预览标记在这里补，不放进 工作区列文件()：
    // ws_files 是底层存储层，让它反向依赖 office_preview 会绕成环。
    foreach ($r['list'] as &$项) {
        $项['previewable'] = (string) $项['kind'] !== 'text'
            && (string) $项['kind'] !== 'image'
            && 预览可转((string) $项['name']) ? 1 : 0;
    }
    unset($项);
    $用 = 工作区用量($uid);
    $上 = 工作区配额($uid);
    json_out(['ok' => 1] + $r + [
        'used_bytes' => $用['bytes'], 'used_text' => size_text($用['bytes']),
        'quota_bytes' => $上, 'quota_text' => size_text($上),
        'percent' => $上 > 0 ? round($用['bytes'] / $上 * 100, 1) : 0,
    ]);
}

// ---- 空间用量 ----
if ($act === 'stat') {
    $用 = 工作区用量($uid);
    $上 = 工作区配额($uid);
    $分 = db_all('SELECT kind, COUNT(*) AS n, COALESCE(SUM(size),0) AS s
                  FROM uploads WHERE user_id=? GROUP BY kind', [$uid]);
    json_out(['ok' => 1, 'count' => $用['count'], 'bytes' => $用['bytes'],
        'bytes_text' => size_text($用['bytes']), 'quota_bytes' => $上,
        'quota_text' => size_text($上),
        'percent' => $上 > 0 ? round($用['bytes'] / $上 * 100, 1) : 0,
        'by_kind' => $分]);
}

// ---- 读文本 ----
if ($act === 'read') {
    $标 = (string) ($_GET['name'] ?? $_GET['id'] ?? $_POST['name'] ?? $_POST['id'] ?? '');
    if (trim($标) === '') {
        json_out(['error' => '缺少文件名或编号'], 400);
    }
    $r = 工作区读文件($uid, $标);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 404);
    }
    $文 = $r['text'];
    $截 = false;
    if (mb_strlen($文) > 仓正文上限) {
        $文 = mb_substr($文, 0, 仓正文上限);
        $截 = true;
    }
    json_out(['ok' => 1, 'id' => (int) $r['row']['id'], 'name' => (string) $r['row']['name'],
        'text' => $文, 'truncated' => $截 ? 1 : 0,
        'size' => (int) $r['row']['size'], 'ver' => (int) $r['row']['ver'],
        'lines' => substr_count($r['text'], "\n") + 1]);
}

// ---- 写文本 ----
if ($act === 'write') {
    $名 = (string) ($_POST['name'] ?? '');
    $文 = (string) ($_POST['text'] ?? '');
    $说 = (string) ($_POST['note'] ?? '');
    $源 = (string) ($_POST['source'] ?? 'ai');
    if (trim($名) === '') {
        json_out(['error' => '缺少文件名'], 400);
    }
    // 覆盖前留旧内容，用于返回 diff 让用户看清改了什么
    $旧 = 工作区读文件($uid, $名);
    $旧文 = $旧['ok'] ? $旧['text'] : '';

    $r = 工作区写文件($uid, $名, $文, $说, $源);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    $d = 仓差异($旧文, $文);
    $用 = 工作区用量($uid);
    $上 = 工作区配额($uid);
    json_out(['ok' => 1, 'id' => $r['id'], 'name' => $r['name'], 'size' => $r['size'],
        'size_text' => size_text($r['size']), 'new' => $r['new'] ? 1 : 0,
        'ver' => $r['ver'], 'add' => $d['add'], 'del' => $d['del'],
        'used_text' => size_text($用['bytes']), 'quota_text' => size_text($上),
        'msg' => ($r['new'] ? '已在工作中心新建 ' : '已更新工作中心文件 ') . $r['name']
            . '（' . size_text($r['size']) . '，+' . $d['add'] . ' -' . $d['del'] . ' 行）。'
            . '空间已用 ' . size_text($用['bytes']) . ' / ' . size_text($上) . '。']);
}

// ---- 局部补丁 ----
if ($act === 'patch') {
    $名 = (string) ($_POST['name'] ?? '');
    $查 = (string) ($_POST['find'] ?? '');
    $替 = (string) ($_POST['replace'] ?? '');
    $说 = (string) ($_POST['note'] ?? '');
    if (trim($名) === '') {
        json_out(['error' => '缺少文件名'], 400);
    }
    if ($查 === '') {
        json_out(['error' => '补丁缺少「原文」片段'], 400);
    }
    $读 = 工作区读文件($uid, $名);
    if (!$读['ok']) {
        json_out(['error' => $读['error']], 404);
    }
    $应 = 仓应用补丁($读['text'], $查, $替);
    if (!$应['ok']) {
        json_out(['error' => $应['error'], 'patch_failed' => 1], 400);
    }
    $r = 工作区写文件($uid, (string) $读['row']['name'], $应['text'], $说, 'ai');
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    $d  = 仓差异($读['text'], $应['text']);
    $档 = [1 => '精确匹配', 2 => '忽略行尾空白', 3 => '忽略缩进'][$应['档']] ?? '';
    json_out(['ok' => 1, 'id' => $r['id'], 'name' => $r['name'], 'ver' => $r['ver'],
        'add' => $d['add'], 'del' => $d['del'], 'mode' => $档,
        'msg' => '补丁已应用到工作中心文件 ' . $r['name'] . '（' . $档 . '，+'
            . $d['add'] . ' -' . $d['del'] . ' 行）。']);
}

// ---- 删除 ----
if ($act === 'del') {
    $id = (int) ($_POST['id'] ?? 0);
    $r  = 工作区删文件($uid, $id);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 404);
    }
    json_out(['ok' => 1, 'msg' => '已删除 ' . $r['name']]);
}

// ---- 重命名 ----
if ($act === 'rename') {
    $id = (int) ($_POST['id'] ?? 0);
    $新 = (string) ($_POST['name'] ?? '');
    $r  = 工作区改名($uid, $id, $新);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 400);
    }
    json_out(['ok' => 1, 'name' => $r['name'], 'msg' => '已改名为 ' . $r['name']]);
}

// ---- 上传任意文件 ----
if ($act === 'upload') {
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        json_out(['error' => '没有收到文件'], 400);
    }
    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_out(['error' => '上传失败（错误码 ' . (int) $f['error'] . '）'], 400);
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        json_out(['error' => '非法上传'], 400);
    }
    $大小 = (int) $f['size'];
    if ($大小 <= 0) {
        json_out(['error' => '文件是空的'], 400);
    }
    $单上限 = max(1, (int) setting_get('upload_max_mb', 5)) * 1048576;
    if ($大小 > $单上限) {
        json_out(['error' => '单个文件不能超过 ' . size_text($单上限)], 400);
    }
    $原名 = (string) ($_POST['name'] ?? $f['name']);
    $规范 = 工作区规范名(basename($原名));
    if ($规范 === '') {
        json_out(['error' => '文件名不合法'], 400);
    }
    $err = 工作区可写($uid, $大小, 0);
    if ($err !== '') {
        json_out(['error' => $err], 413);
    }
    // 文本类直接读进来走写文件那条路，这样它就是可编辑的
    if (工作区是文本($规范) && $大小 <= 工作区文本上限) {
        $内容 = (string) @file_get_contents($f['tmp_name']);
        if (strpos($内容, "\0") === false) {
            $r = 工作区写文件($uid, $规范, $内容, '用户上传', 'user');
            if (!$r['ok']) {
                json_out(['error' => $r['error']], 400);
            }
            json_out(['ok' => 1, 'id' => $r['id'], 'name' => $r['name'],
                'kind' => 'text', 'size' => $r['size'],
                'msg' => '已上传到工作中心：' . $r['name']]);
        }
    }
    // 其余当二进制存档，只能查看下载，不能在线编辑
    $相对 = 工作区落盘名($uid, $规范);
    $全   = 工作区绝对路径($相对);
    if ($全 === '') {
        json_out(['error' => '存储路径异常'], 500);
    }
    if (!is_dir(dirname($全)) && !@mkdir(dirname($全), 0750, true)) {
        json_out(['error' => '无法创建存储目录，请检查权限'], 500);
    }
    if (!@move_uploaded_file($f['tmp_name'], $全)) {
        json_out(['error' => '保存失败，请检查目录权限'], 500);
    }
    落盘收尾($全, 0640);
    $id = db_insert('INSERT INTO uploads
        (user_id, conv_id, path, name, kind, source, mime, size, width, height,
         used, note, ver, created_at, updated_at)
        VALUES (?,0,?,?,\'bin\',\'user\',?,?,0,0,1,?,1,NOW(),NOW())',
        [$uid, $相对, $规范, (string) ($f['type'] ?: 'application/octet-stream'),
         $大小, '用户上传']);
    json_out(['ok' => 1, 'id' => (int) $id, 'name' => $规范, 'kind' => 'bin',
        'size' => $大小, 'msg' => '已上传到工作中心：' . $规范]);
}

// ---- 下载 / 在线查看 ----
if ($act === 'down') {
    $id = (int) ($_GET['id'] ?? 0);
    $行 = 工作区取文件($uid, $id);
    if (!$行) {
        http_response_code(404);
        exit('文件不存在');
    }
    $全 = 工作区绝对路径((string) $行['path']);
    if ($全 === '' || !is_file($全)) {
        http_response_code(404);
        exit('文件实体已丢失');
    }
    $名 = (string) ($行['name'] !== '' ? $行['name'] : basename((string) $行['path']));
    $文本 = (string) $行['kind'] === 'text';
    // 二进制一律 attachment，杜绝在浏览器里被当页面执行
    $处置 = ((int) ($_GET['inline'] ?? 0) === 1 && $文本) ? 'inline' : 'attachment';
    header('Content-Type: ' . ($文本 ? 工作区MIME($名) . '; charset=utf-8'
        : 'application/octet-stream'));
    header('Content-Length: ' . filesize($全));
    header('Content-Disposition: ' . $处置 . '; filename*=UTF-8\'\''
        . rawurlencode(basename($名)));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0');
    readfile($全);
    exit;
}

// ---- 打包成 zip ----
// files 收「账号内虚拟路径」或 uploads.id 的数组；归属校验在 工作区打包() 里按 user_id 做。
if ($act === 'zip') {
    require_once __DIR__ . '/../inc/ws_zip.php';
    $入 = $_POST['files'] ?? [];
    // 前端可能传数组，AI 卡片也可能传 JSON 字符串，两种都收
    if (is_string($入)) {
        $解 = json_decode($入, true);
        $入 = is_array($解) ? $解 : preg_split('/[\r\n]+/', $入, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($入)) {
        json_out(['error' => 'files 需要是数组'], 400);
    }
    $r = 工作区打包($uid, $入, (string) ($_POST['name'] ?? ''), (string) ($_POST['note'] ?? ''));
    if (!$r['ok']) {
        json_out(['error' => $r['error'], 'skipped' => $r['skipped']], 400);
    }
    $用 = 工作区用量($uid);
    $上 = 工作区配额($uid);
    $提示 = '已打包 ' . $r['count'] . ' 个文件为 ' . $r['name']
        . '（' . size_text($r['size']) . '），客户可在工作中心下载。';
    if ($r['skipped']) {
        $提示 .= ' 跳过：' . implode('、', array_slice($r['skipped'], 0, 5))
            . (count($r['skipped']) > 5 ? ' 等' . count($r['skipped']) . ' 项' : '') . '。';
    }
    json_out(['ok' => 1, 'id' => $r['id'], 'name' => $r['name'], 'size' => $r['size'],
        'size_text' => size_text($r['size']), 'count' => $r['count'],
        'skipped' => $r['skipped'],
        'url' => '/api/ws.php?act=down&id=' . $r['id'],
        'used_text' => size_text($用['bytes']), 'quota_text' => size_text($上),
        'msg' => $提示 . ' 空间已用 ' . size_text($用['bytes']) . ' / ' . size_text($上) . '。']);
}

// ---- 逐页预览：先问页数 ----
// 转换在这一步完成，前端拿到页数后再逐张取图。
if ($act === 'pvinfo') {
    $id = (int) ($_GET['id'] ?? 0);
    $r  = 预览取页图($uid, $id);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], 422);
    }
    json_out(['ok' => 1, 'pages' => $r['pages'], 'cached' => $r['cached'] ? 1 : 0]);
}

// ---- 逐页预览：取某一页的图 ----
if ($act === 'pvpage') {
    $id = (int) ($_GET['id'] ?? 0);
    $页 = (int) ($_GET['p'] ?? 1);
    $行 = 工作区取文件($uid, $id);
    if (!$行) {
        http_response_code(404);
        exit('文件不存在');
    }
    $源 = 工作区绝对路径((string) $行['path']);
    if ($源 === '') {
        http_response_code(404);
        exit('路径异常');
    }
    // 页号只取正整数并卡上限，避免拼出 ../ 之类的路径
    if ($页 < 1 || $页 > 预览转图页数上限) {
        http_response_code(400);
        exit('页号超出范围');
    }
    $图 = 预览图目录($源) . '/p-' . $页 . '.png';
    if (!is_file($图)) {
        http_response_code(404);
        exit('这一页还没生成');
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($图));
    header('X-Content-Type-Options: nosniff');
    // 页图内容不会变（源文件改了会整目录重转），可以让浏览器多缓存一会
    header('Cache-Control: private, max-age=3600');
    readfile($图);
    exit;
}

// ---- Office 文档转 PDF 在线预览 ----
// 保留这条：逐页图是默认显示方式，这里给「用 PDF 打开」的入口用。
// 直接吐 PDF 字节流而不是返回 URL，不用把缓存文件暴露成可直接访问的路径。
if ($act === 'preview') {
    $id = (int) ($_GET['id'] ?? 0);
    $r  = 预览取PDF($uid, $id);
    if (!$r['ok']) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        exit($r['error']);
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($r['pdf']));
    // inline 才能在 iframe 里直接渲染。这里只可能是自己生成的 PDF，
    // 不是用户上传的任意内容，不存在被当页面执行的风险。
    header('Content-Disposition: inline; filename="preview.pdf"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($r['pdf']);
    exit;
}

json_out(['error' => '未知操作'], 400);
