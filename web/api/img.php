<?php
/**
 * 图片读取接口。文件存在 webroot 之外，只能从这里拿。
 * 归属校验：普通用户只能读自己上传的图，管理员可读全部（便于后台排查）。
 */
require_once __DIR__ . '/../inc/helpers.php';

$me = current_user();
if (!$me) {
    http_response_code(401);
    exit('未登录');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('参数错误');
}

$row = db_one('SELECT * FROM uploads WHERE id = ? LIMIT 1', [$id]);
if (!$row) {
    http_response_code(404);
    exit('图片不存在');
}
// 关键隔离点：非本人且非管理员，一律 404，不暴露「存在但无权」的信息
if ((int) $row['user_id'] !== (int) $me['id'] && $me['role'] !== 'admin') {
    http_response_code(404);
    exit('图片不存在');
}

// 防目录穿越：拼好路径后必须仍在 UPLOAD_DIR 之内
$base = realpath(rtrim(UPLOAD_DIR, '/'));
$file = realpath($base . '/' . $row['path']);
if ($base === false || $file === false || strncmp($file, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
    http_response_code(404);
    exit('图片不存在');
}
if (!is_file($file)) {
    http_response_code(404);
    exit('文件已丢失');
}

$mime = (string) $row['mime'];
if (!preg_match('#^image/(jpeg|png|gif|webp)$#', $mime)) {
    $mime = 'application/octet-stream';
}

$etag = '"' . md5($row['id'] . '-' . filemtime($file)) . '"';
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="img' . (int) $row['id'] . '"');
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
// 图片自身不应被当成页面渲染
header("Content-Security-Policy: default-src 'none'; img-src 'self'");

readfile($file);
