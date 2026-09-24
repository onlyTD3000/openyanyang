<?php
/**
 * 支付二维码图片输出。
 *
 * 只接受订单号，码串由服务端自己去支付宝取，不接受前端传任意文本。
 * 否则这就成了一个「传什么就画什么」的公共画图接口，别人可以拿它生成
 * 任意内容的二维码挂在本站域名下，做钓鱼引流。
 *
 * 用 <img src> 直接引用，所以只能靠会话鉴权，没法带 CSRF token。
 * 这是读取操作，不改数据，可以接受。
 */
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/pay.php';
require_once __DIR__ . '/../inc/qrcode.php';

// 桌面客户端没有浏览器会话，只有 API Key，所以两种鉴权都收：先试会话，再试 Bearer。
// 客户端那边由主进程把图片抓回来转成 data URL 用，渲染层的 <img src> 带不了 Authorization 头。
$me = current_user() ?: api_key_validate();
if (!$me) {
    http_response_code(401);
    exit;
}

$no  = (string) ($_GET['order_no'] ?? '');
$ord = db_one('SELECT * FROM recharge_orders WHERE order_no = ? AND user_id = ? LIMIT 1',
    [$no, $me['id']]);
if (!$ord) {
    http_response_code(404);
    exit;
}

// 码串没缓存下来，重新 precreate 一次。支付宝对同一 out_trade_no
// 重复预下单会返回同一个二维码，不会产生重复订单。
$r = alipay_create([
    'order_no'   => $ord['order_no'],
    'pay_amount' => (float) $ord['pay_amount'],
    'pay_scene'  => 'qr',
]);
if (empty($r['ok']) || empty($r['qr'])) {
    http_response_code(400);
    exit;
}

$png = qr_png((string) $r['qr'], 6, 3);
if ($png === null) {
    http_response_code(500);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: private, max-age=60');
header('Content-Length: ' . strlen($png));
echo $png;
