<?php
/**
 * PPT 生成接口。
 *
 * 只有一个动作：make —— 收结构化大纲，生成 pptx，存进工作中心，返下载地址。
 * 文件本身的下载复用 api/ws.php?act=down，那边已经做好了账号隔离和响应头。
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();
require_once __DIR__ . '/../inc/ppt.php';

$me  = require_login_api();
$uid = (int) $me['id'];

// 检查用户 PPT 生成权限
$userCap = db_one('SELECT cap_ppt_generate FROM users WHERE id = ?', [$uid]);
if ((int) ($userCap['cap_ppt_generate'] ?? 1) !== 1) {
    json_out(['error' => '你没有 PPT 生成权限']);
}

csrf_check();

$原始 = (string) ($_POST['outline'] ?? '');
if (trim($原始) === '') {
    json_out(['error' => '没收到大纲内容']);
}

$大纲 = json_decode($原始, true);
if (!is_array($大纲)) {
    json_out(['error' => '大纲不是合法 JSON：' . json_last_error_msg()]);
}

$校 = ppt_check_outline($大纲);
if (!$校['ok']) {
    json_out(['error' => $校['error']]);
}

// 文件名优先取单独传的 name，没传就用大纲 JSON 里的 name 字段。
// 提示词里教 AI 把 name 写在 JSON 里，这里必须认，不能只依赖调用方额外传参。
$名 = trim((string) ($_POST['name'] ?? ''));
if ($名 === '') {
    $名 = trim((string) ($大纲['name'] ?? ''));
}
$r = ppt_generate($uid, $校['data'], $名);
if (!$r['ok']) {
    json_out(['error' => $r['error']]);
}

json_out([
    'ok'     => 1,
    'id'     => $r['id'],
    'name'   => $r['name'],
    'size'   => $r['size'],
    'size_text' => size_text($r['size']),
    'slides' => $r['slides'],
    'new'    => !empty($r['new']),
    'url'    => '/api/ws.php?act=down&id=' . $r['id'],
]);
