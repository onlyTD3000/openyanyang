<?php
// 当前用户信息接口：供桌面端校验 API 密钥并取余额。
// 桌面端登录时先打这里，密钥无效就拿到 401 和 error 字段；
// 有效则返回身份和余额，登录后刷余额也复用这个接口，省一次请求。
require_once __DIR__ . "/../inc/helpers.php";
api_error_guard();

$me = require_login_api();
$act = (string) ($_POST['act'] ?? $_GET['act'] ?? '');

// 保存工具开关配置
if ($act === 'set_tools') {
    csrf_check();
    
    $tools = [
        'tool_ssh_exec', 'tool_sftp_read', 'tool_sftp_write', 'tool_sftp_list', 'tool_sftp_delete', 'tool_sftp_patch',
        'tool_file_list', 'tool_file_read', 'tool_file_write', 'tool_file_delete', 'tool_file_push', 'tool_file_patch',
        'tool_ws_list', 'tool_ws_read', 'tool_ws_write', 'tool_ws_delete', 'tool_ws_patch', 'tool_ws_zip',
        'tool_web_open', 'tool_web_search', 'tool_ppt_generate'
    ];
    
    $updates = [];
    $params = [];
    
    foreach ($tools as $key) {
        $val = isset($_POST[$key]) ? (int)(bool)$_POST[$key] : 1; // 未传视为开启
        $updates[] = "`{$key}` = ?";
        $params[] = $val;
    }
    
    $params[] = (int)$me['id'];
    
    $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $n = db_exec($sql, $params);
    
    json_out($n !== false ? ['ok' => 1] : ['error' => '保存失败'], 200);
}

// 默认动作：返回用户信息（原有逻辑）
// balance 用 money() 格式化，跟网页端顶栏显示的位数一致（4 位小数）。
// 数据库里是 decimal(14,6)，直接回原值客户端会显示成「余额 10.000000」。
// 只回客户端要用的和无害的身份字段，password_hash 这类绝不外传。
json_out([
    "ok"          => 1,
    "id"          => (int) $me["id"],
    "username"    => $me["username"],
    "email"       => $me["email"],
    "role"        => $me["role"],
    "balance"     => money($me["balance"]),
    "token_quota" => (int) $me["token_quota"],
    "used_tokens" => (int) $me["used_tokens"],
    "total_cost"  => money($me["total_cost"]),
    // 站点公告，跟网页端聊天页空态显示的是同一个设置项。
    // 挂在这个接口上而不是单开一个：客户端登录时本来就要打 me.php，
    // 顺路带回来省一次请求，公告本身也不是敏感信息。
    "notice"      => trim((string) setting_get("site_notice", "")),
    // 图片上传体积上限（KB），跟 api/upload.php 用的是同一个后台设置项。
    // 客户端拿到后在选图阶段就能判断要不要压缩，不必先传一遍再被服务端拒。
    // 回 0 表示后台没设上限，客户端此时不做任何拦截。
    "upload_max_kb" => max(0, (int) setting_get("upload_max_image_size", 300)),
    // 工具开关状态
    "tool_ssh_exec" => (int)($me["tool_ssh_exec"] ?? 1),
    "tool_sftp_read" => (int)($me["tool_sftp_read"] ?? 1),
    "tool_sftp_write" => (int)($me["tool_sftp_write"] ?? 1),
    "tool_sftp_list" => (int)($me["tool_sftp_list"] ?? 1),
    "tool_sftp_delete" => (int)($me["tool_sftp_delete"] ?? 1),
    "tool_sftp_patch" => (int)($me["tool_sftp_patch"] ?? 1),
    "tool_file_list" => (int)($me["tool_file_list"] ?? 1),
    "tool_file_read" => (int)($me["tool_file_read"] ?? 1),
    "tool_file_write" => (int)($me["tool_file_write"] ?? 1),
    "tool_file_delete" => (int)($me["tool_file_delete"] ?? 1),
    "tool_file_push" => (int)($me["tool_file_push"] ?? 1),
    "tool_file_patch" => (int)($me["tool_file_patch"] ?? 1),
    "tool_ws_list" => (int)($me["tool_ws_list"] ?? 1),
    "tool_ws_read" => (int)($me["tool_ws_read"] ?? 1),
    "tool_ws_write" => (int)($me["tool_ws_write"] ?? 1),
    "tool_ws_delete" => (int)($me["tool_ws_delete"] ?? 1),
    "tool_ws_patch" => (int)($me["tool_ws_patch"] ?? 1),
    "tool_ws_zip" => (int)($me["tool_ws_zip"] ?? 1),
    "tool_web_open" => (int)($me["tool_web_open"] ?? 1),
    "tool_web_search" => (int)($me["tool_web_search"] ?? 1),
    "tool_ppt_generate" => (int)($me["tool_ppt_generate"] ?? 1),
]);