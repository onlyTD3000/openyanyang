<?php
/**
 * 后台 - 安装包上传接口（JSON）。
 *
 * 单独开一个接口而不复用 software.php 的 POST 分支：那条分支走完要渲染整页 HTML，
 * XHR 拿到一大坨页面没法用。这里只回 JSON，前端好判断成败。
 *
 * 上传进度由浏览器端的 XMLHttpRequest.upload.onprogress 提供，服务端不用管——
 * 进度是「已发出多少字节」，浏览器自己就知道，不需要服务端配合上报。
 */
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();
require_once __DIR__ . '/../inc/version.php';
header('Content-Type: application/json; charset=utf-8');
/** 统一出口，避免各分支重复写 json_encode。 */
function 回(array $体, int $码 = 200): void {
    http_response_code($码);
    echo json_encode($体, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 回(['ok' => 0, 'err' => '只接受 POST'], 405); }
csrf_check_page();
$包目录 = dirname(__DIR__) . '/download';
$平台 = (string) ($_POST['plat'] ?? 'win');
$表 = client_platforms();
$键 = 'client_dl_' . $平台;
if (!isset($表[$键])) { 回(['ok' => 0, 'err' => '未知平台']); }
if (!isset($_FILES['pkg']) || $_FILES['pkg']['error'] !== UPLOAD_ERR_OK) {
    // 分清是超限还是别的错，超限最常见，单独给一句能对症的提示。
    $码 = $_FILES['pkg']['error'] ?? -1;
    $提示 = ($码 === UPLOAD_ERR_INI_SIZE || $码 === UPLOAD_ERR_FORM_SIZE)
        ? '文件超过上限 ' . ini_get('upload_max_filesize')
        : '没收到文件（错误码 ' . $码 . '）';
    回(['ok' => 0, 'err' => $提示]);
}
$原名 = (string) $_FILES['pkg']['name'];
$后缀 = strtolower((string) pathinfo($原名, PATHINFO_EXTENSION));
if (!in_array($后缀, ['exe', 'zip', 'dmg', 'appimage', 'deb'], true)) {
    回(['ok' => 0, 'err' => '只允许 exe / zip / dmg / AppImage / deb']);
}
if (!is_dir($包目录)) { @mkdir($包目录, 0755, true); }
$版 = ver_normalize((string) setting_get('client_version', '1.0.0'));
$存名 = 'yanyang-' . $平台 . '-' . ($版 ?: '1.0.0') . '.' . $后缀;
$目标 = $包目录 . '/' . $存名;
if (!move_uploaded_file($_FILES['pkg']['tmp_name'], $目标)) {
    回(['ok' => 0, 'err' => '写入失败，检查 download 目录是否可写']);
}
@chmod($目标, 0644);
$地址 = '/download/' . $存名;
$哈希 = hash_file('sha256', $目标);
$尺寸 = (int) filesize($目标);
setting_set($键, $地址);
setting_set('client_sha_' . $平台, $哈希);
setting_set('client_size_' . $平台, (string) $尺寸);
回([
    'ok'   => 1,
    'name' => $存名,
    'url'  => $地址,
    'sha'  => $哈希,
    'size' => $尺寸,
    'plat' => $平台,
    'key'  => $键,
]);
