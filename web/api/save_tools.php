<?php
// 保存工具开关接口
require_once __DIR__ . "/../inc/helpers.php";
api_error_guard();

$me = require_login_api();

$tools = [
    'tool_ssh_exec', 'tool_sftp_read', 'tool_sftp_write', 'tool_sftp_list', 'tool_sftp_delete', 'tool_sftp_patch',
    'tool_file_list', 'tool_file_read', 'tool_file_write', 'tool_file_delete', 'tool_file_push', 'tool_file_patch',
    'tool_ws_list', 'tool_ws_read', 'tool_ws_write', 'tool_ws_delete', 'tool_ws_patch', 'tool_ws_zip',
    'tool_web_open', 'tool_web_search', 'tool_ppt_generate'
];

$updates = [];
$params = [];

foreach ($tools as $key) {
    if (isset($_POST[$key])) {
        $val = (int)$_POST[$key];
        $updates[] = "`{$key}` = ?";
        $params[] = $val;
    }
}

if (empty($updates)) {
    json_out(['error' => '没有需要保存的工具开关'], 400);
}

$params[] = (int)$me['id'];

$sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
$n = db_exec($sql, $params);

json_out($n !== false ? ['ok' => 1] : ['error' => '保存失败'], 200);