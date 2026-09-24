<?php
/**
 * 客户端版本检查。
 *
 * GET/POST  ?cur=1.0.0[&plat=win]
 *
 * 故意不校验登录：客户端在登录页就要能查更新——密钥失效或者接口有不兼容
 * 改动时，用户恰恰是登录不进去才需要更新。这里只吐版本号和下载地址，
 * 不含任何用户数据，公开没有风险。
 */
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/version.php';
require_once __DIR__ . '/../inc/pay.php';   // site_base_url()，把相对下载地址补成完整 URL
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$当前 = ver_normalize((string) ($_REQUEST['cur'] ?? ''));
// 端别决定读哪套设置。安卓端传 side=android，不传就按电脑端处理，
// 老客户端不带这个参数，行为跟以前完全一致。
$端 = (string) ($_REQUEST['side'] ?? 'pc');
if ($端 !== 'android') { $端 = 'pc'; }
$前 = ver_prefix($端);
if ($端 === 'android') {
    $平台 = 'apk';                       // 安卓只有一个平台，不用挑
} else {
    $平台 = (string) ($_REQUEST['plat'] ?? 'win');
    if (!isset(client_platforms()['client_dl_' . $平台])) { $平台 = 'win'; }
}
$开 = (string) setting_get($前 . 'update_on', '1') === '1';
$最新 = ver_normalize((string) setting_get($前 . 'version', ''));
// 检测关闭、或者后台还没填版本号，一律回「不用更新」。
// 没填版本号时不能拿空串去比，否则所有客户端都会被判成需要更新。
if (!$开 || $最新 === '') {
    json_out(['ok' => 1, 'update' => 0, 'latest' => $最新]);
}
$地址 = trim((string) setting_get($前 . 'dl_' . $平台, ''));
// 相对路径补成完整地址：客户端不在浏览器里，没有「当前站点」这个概念，
// 拿到 /download/x.exe 是没法下载的。
if ($地址 !== '' && !preg_match('#^https?://#i', $地址)) {
    $地址 = rtrim(site_base_url(), '/') . '/' . ltrim($地址, '/');
}
// 客户端没报自己的版本时不做判断，只把最新版信息给它，让它自己决定。
$需更新 = $当前 !== '' && ver_cmp($最新, $当前) > 0;
// 强制更新有两个来源：总开关，或者当前版本低于最低可用版本
$最低 = ver_normalize((string) setting_get($前 . 'min_version', ''));
$强制 = (string) setting_get($前 . 'force', '0') === '1';
if (!$强制 && $最低 !== '' && $当前 !== '' && ver_cmp($最低, $当前) > 0) {
    $强制 = true;
}
json_out([
    'ok'     => 1,
    'update' => $需更新 ? 1 : 0,
    'latest' => $最新,
    'cur'    => $当前,
    'force'  => ($需更新 && $强制) ? 1 : 0,
    'url'    => $地址,
    'sha256' => (string) setting_get($前 . 'sha_' . $平台, ''),
    'size'   => (int) setting_get($前 . 'size_' . $平台, 0),
    'notes'  => (string) setting_get($前 . 'notes', ''),
    'side'   => $端,
]);
