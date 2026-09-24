<?php
/**
 * 个人资料接口：改密码、改邮箱、API 密钥管理、余额流水查询。
 * 逻辑对齐网页端 profile.php，供安卓端等客户端调用。
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();
$me = require_login_api();
$act = (string) ($_POST['act'] ?? $_GET['act'] ?? 'info');
/** 流水类型中文名，跟网页端 profile.php 保持一致 */
function blog_type_name_api(string $t): string
{
    $map = [
        'register'        => '注册赠送',
        'admin'           => '管理员调整',
        'admin_gift'      => '管理员赠送',
        'consume'         => '对话消费',
        'refund'          => '退回',
        'est_recalc'      => '估算口径修正',
        'recharge'        => '在线充值',
        'aff'             => '推介返现',
        'withdraw'        => '提现扣款',
        'withdraw_refund' => '提现退回',
    ];
    return $map[$t] ?? $t;
}
// ---------- 资料总览：密钥列表 + 最近流水 + 统计 ----------
if ($act === 'info') {
    $apiKeys = db_all('SELECT id, name, created_at, last_used_at FROM user_api_keys
                        WHERE user_id = ? AND status = 1 ORDER BY created_at DESC', [$me['id']]);
    $blogs = db_all('SELECT * FROM balance_logs WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$me['id']]);
    $convN = (int) db_val('SELECT COUNT(*) FROM conversations WHERE user_id = ?', [$me['id']]);
    $callN = (int) db_val('SELECT COUNT(*) FROM usage_logs WHERE user_id = ?', [$me['id']]);
    json_out([
        'ok'          => 1,
        'username'    => $me['username'],
        'email'       => $me['email'],
        'balance'     => money($me['balance']),
        'total_cost'  => money($me['total_cost']),
        'conv_count'  => $convN,
        'call_count'  => $callN,
        'tool_ssh_exec'      => (int) ($me['tool_ssh_exec'] ?? 1),
        'tool_sftp_read'     => (int) ($me['tool_sftp_read'] ?? 1),
        'tool_sftp_write'    => (int) ($me['tool_sftp_write'] ?? 1),
        'tool_sftp_list'     => (int) ($me['tool_sftp_list'] ?? 1),
        'tool_sftp_delete'   => (int) ($me['tool_sftp_delete'] ?? 1),
        'tool_sftp_patch'    => (int) ($me['tool_sftp_patch'] ?? 1),
        'tool_file_list'     => (int) ($me['tool_file_list'] ?? 1),
        'tool_file_read'     => (int) ($me['tool_file_read'] ?? 1),
        'tool_file_write'    => (int) ($me['tool_file_write'] ?? 1),
        'tool_file_delete'   => (int) ($me['tool_file_delete'] ?? 1),
        'tool_file_push'     => (int) ($me['tool_file_push'] ?? 1),
        'tool_file_patch'    => (int) ($me['tool_file_patch'] ?? 1),
        'tool_ws_list'       => (int) ($me['tool_ws_list'] ?? 1),
        'tool_ws_read'       => (int) ($me['tool_ws_read'] ?? 1),
        'tool_ws_write'      => (int) ($me['tool_ws_write'] ?? 1),
        'tool_ws_delete'     => (int) ($me['tool_ws_delete'] ?? 1),
        'tool_ws_patch'      => (int) ($me['tool_ws_patch'] ?? 1),
        'tool_ws_zip'        => (int) ($me['tool_ws_zip'] ?? 1),
        'tool_web_open'      => (int) ($me['tool_web_open'] ?? 1),
        'tool_web_search'    => (int) ($me['tool_web_search'] ?? 1),
        'tool_ppt_generate'  => (int) ($me['tool_ppt_generate'] ?? 1),
        'api_keys'    => array_map(fn($k) => [
            'id'           => (int) $k['id'],
            'name'         => $k['name'],
            'created_at'   => $k['created_at'],
            'last_used_at' => $k['last_used_at'],
        ], $apiKeys),
        'balance_logs' => array_map(fn($b) => [
            'id'            => (int) $b['id'],
            'amount'        => money($b['amount']),
            'balance_after' => money($b['balance_after']),
            'type'          => $b['type'],
            'type_name'     => blog_type_name_api((string) $b['type']),
            'note'          => $b['note'],
            'created_at'    => $b['created_at'],
        ], $blogs),
    ]);
}
// ---------- 余额流水分页 ----------
if ($act === 'balance_logs') {
    $page  = max(1, (int) ($_GET['p'] ?? $_POST['p'] ?? 1));
    $per   = 15;
    $off   = ($page - 1) * $per;
    $total = (int) db_val('SELECT COUNT(*) FROM balance_logs WHERE user_id = ?', [$me['id']]);
    $rows  = db_all('SELECT * FROM balance_logs WHERE user_id = ?
                      ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . $off, [$me['id']]);
    json_out([
        'ok'    => 1,
        'page'  => $page,
        'pages' => max(1, (int) ceil($total / $per)),
        'total' => $total,
        'list'  => array_map(fn($b) => [
            'id'            => (int) $b['id'],
            'amount'        => money($b['amount']),
            'balance_after' => money($b['balance_after']),
            'type'          => $b['type'],
            'type_name'     => blog_type_name_api((string) $b['type']),
            'note'          => $b['note'],
            'created_at'    => $b['created_at'],
        ], $rows),
    ]);
}
// ---------- 改密码 ----------
if ($act === 'change_password') {
    csrf_check();
    $old  = (string) ($_POST['old_password'] ?? '');
    $new  = (string) ($_POST['new_password'] ?? '');
    $new2 = (string) ($_POST['new_password2'] ?? '');
    if (!password_verify($old, $me['password_hash'])) {
        json_out(['error' => '当前密码不正确'], 400);
    }
    if (mb_strlen($new) < 6) {
        json_out(['error' => '新密码至少 6 位'], 400);
    }
    if ($new !== $new2) {
        json_out(['error' => '两次输入的新密码不一致'], 400);
    }
    if ($new === $old) {
        json_out(['error' => '新密码与当前密码相同'], 400);
    }
    db_exec('UPDATE users SET password_hash = ? WHERE id = ?',
        [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
    json_out(['ok' => 1, 'msg' => '密码已修改成功']);
}
// ---------- 改邮箱 ----------
if ($act === 'change_email') {
    csrf_check();
    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => '邮箱格式不正确'], 400);
    }
    db_exec('UPDATE users SET email = ? WHERE id = ?', [mb_substr($email, 0, 120), $me['id']]);
    json_out(['ok' => 1, 'email' => $email, 'msg' => $email === '' ? '邮箱已清空' : '邮箱已更新']);
}
// ---------- 新建密钥 ----------
if ($act === 'key_create') {
    csrf_check();
    $name = trim((string) ($_POST['name'] ?? '默认密钥'));
    $tok = api_key_create((int) $me['id'], $name !== '' ? mb_substr($name, 0, 100) : '默认密钥');
    if (!$tok) {
        json_out(['error' => '密钥生成失败，请重试'], 500);
    }
    json_out(['ok' => 1, 'token' => $tok, 'msg' => '新密钥已生成，请立即保存，仅显示一次']);
}
// ---------- 删除密钥 ----------
if ($act === 'key_delete') {
    csrf_check();
    $kid = (int) ($_POST['key_id'] ?? 0);
    $n = db_exec('UPDATE user_api_keys SET status = 0 WHERE id = ? AND user_id = ? AND status = 1',
        [$kid, $me['id']]);
    if ($n > 0) {
        json_out(['ok' => 1, 'msg' => '密钥已删除']);
    }
    json_out(['error' => '密钥不存在或已被删除'], 404);
}
// ---------- 重置密钥 ----------
if ($act === 'key_reset') {
    csrf_check();
    $kid = (int) ($_POST['key_id'] ?? 0);
    $old = db_one('SELECT name FROM user_api_keys WHERE id = ? AND user_id = ? AND status = 1',
        [$kid, $me['id']]);
    if (!$old) {
        json_out(['error' => '密钥不存在或已被删除'], 404);
    }
    db_exec('UPDATE user_api_keys SET status = 0 WHERE id = ? AND user_id = ?', [$kid, $me['id']]);
    $tok = api_key_create((int) $me['id'], $old['name']);
    if (!$tok) {
        json_out(['error' => '重置失败，请重试'], 500);
    }
    json_out(['ok' => 1, 'token' => $tok, 'msg' => '密钥已重置，旧密钥立即失效']);
}
json_out(['error' => '未知操作'], 400);
