<?php
/** 保存用户暗黑模式偏好到数据库 */
require __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

// 仅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// 验证登录
$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '未登录']);
    exit;
}

// CSRF 校验
$csrf = $_POST['csrf'] ?? '';
if (!hash_equals(csrf_token(), $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF 校验失败']);
    exit;
}

$dark = !empty($_POST['dark_mode']) ? 1 : 0;

try {
    db_exec('UPDATE users SET dark_mode = ? WHERE id = ?', [$dark, (int)$me['id']]);
    echo json_encode(['ok' => true, 'dark_mode' => $dark]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '保存失败']);
}
