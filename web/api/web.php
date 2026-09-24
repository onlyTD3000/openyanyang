<?php
/**
 * 网页抓取接口。
 *
 * 只有一个动作：传 url，回来是清理过的正文文本。
 * 真正的抓取和 SSRF 防护都在 inc/web_fetch.php，这里只做鉴权、参数校验、出参整形。
 *
 * 为什么要鉴权：这个接口会以服务器的身份对外发请求，不登录就能调等于开了个
 * 公开代理，别人能拿它刷别的站点、也能拿它探测本机网络。
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();
require_once __DIR__ . '/../inc/web_fetch.php';

$me  = require_login_api();
$uid = (int) $me['id'];

// 检查用户网页访问权限
$userCap = db_one('SELECT cap_web_open FROM users WHERE id = ?', [$uid]);
if ((int) ($userCap['cap_web_open'] ?? 1) !== 1) {
    json_out(['error' => '你没有网页访问权限']);
}

csrf_check();

$网址 = trim((string) ($_POST['url'] ?? ''));
if ($网址 === '') {
    json_out(['error' => '没收到网址']);
}
if (mb_strlen($网址) > 2048) {
    json_out(['error' => '网址太长了（超过 2048 字符）']);
}

$选项 = [];
// 正文上限：AI 可以按需要调小（只想看个大概时省 token），
// 但调大有天花板，具体在 web_fetch 里按后台设置卡住。
if (isset($_POST['limit']) && trim((string) $_POST['limit']) !== '') {
    $选项['limit'] = (int) $_POST['limit'];
}
if (isset($_POST['links']) && trim((string) $_POST['links']) !== '') {
    $选项['links'] = (int) $_POST['links'];
}

$开始 = microtime(true);
$r = web_fetch($网址, $选项);
$耗时 = round((microtime(true) - $开始) * 1000);

if (empty($r['ok'])) {
    json_out(['error' => (string) ($r['error'] ?? '抓取失败'), 'ms' => $耗时]);
}

json_out([
    'ok'        => 1,
    'url'       => (string) $r['url'],
    'status'    => (int) $r['status'],
    'type'      => (string) $r['type'],
    'title'     => (string) $r['title'],
    'text'      => (string) $r['text'],
    'links'     => $r['links'],
    'bytes'     => (int) $r['bytes'],
    'size_text' => size_text((int) $r['bytes']),
    'chars'     => (int) $r['chars'],
    'truncated' => !empty($r['truncated']),
    'oversize'  => !empty($r['oversize']),
    'hops'      => $r['hops'],
    'ms'        => $耗时,
]);
