<?php
/**
 * 支付宝异步通知（服务器对服务器）。这是入账的主路径。
 *
 * 几条硬规矩：
 * 1. 必须验签。不验签等于把加余额的接口公开给全网。
 * 2. 必须核对金额。签名只证明「这是支付宝发的」，不证明「金额是我要的那个」，
 *    要拿回调里的 total_amount 跟订单的 pay_amount 比，防止用户改金额下单。
 * 3. 必须核对 app_id。防止别人用自己的应用给我发通知。
 * 4. 处理成功要输出且只输出 success，否则支付宝会一直重发。
 * 5. 入账要幂等，因为重发是常态。幂等逻辑在 pay_settle() 里。
 */
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/pay.php';

// 回调是支付宝直接打过来的，没有会话也没有 CSRF token，这里不能做那些校验
$post = $_POST;
if (!$post) {
    http_response_code(400);
    echo 'fail';
    exit;
}

$raw = http_build_query($post);

// ---- 1. 验签 ----
if (!alipay_verify($post)) {
    pay_log('notify 验签失败: ' . $raw);
    echo 'fail';
    exit;
}

// ---- 2. 核对 app_id ----
if ((string) ($post['app_id'] ?? '') !== trim((string) setting_get('alipay_app_id', ''))) {
    pay_log('notify app_id 不匹配: ' . $raw);
    echo 'fail';
    exit;
}

$orderNo = (string) ($post['out_trade_no'] ?? '');
$status  = (string) ($post['trade_status'] ?? '');
$ord     = $orderNo !== ''
    ? db_one('SELECT * FROM recharge_orders WHERE order_no = ? LIMIT 1', [$orderNo])
    : null;

if (!$ord) {
    pay_log('notify 订单不存在: ' . $orderNo);
    echo 'fail';
    exit;
}

// ---- 3. 核对金额 ----
// 用分做比较，避免浮点误差把相等判成不等
$paidFen  = (int) round(((float) ($post['total_amount'] ?? 0)) * 100);
$orderFen = (int) round(((float) $ord['pay_amount']) * 100);
if ($paidFen !== $orderFen) {
    pay_log("notify 金额不符 订单{$orderNo} 应付{$orderFen}分 实收{$paidFen}分");
    echo 'fail';
    exit;
}

// ---- 4. 只有这两个状态算付款成功 ----
if (!in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
    // 交易关闭之类的，记下状态就行，不算失败（照样回 success 让支付宝别再发）
    if ($status === 'TRADE_CLOSED') {
        db_exec('UPDATE recharge_orders SET status = \'closed\', notify_raw = ?
                  WHERE order_no = ? AND status = \'pending\'',
            [mb_substr($raw, 0, 60000), $orderNo]);
    }
    echo 'success';
    exit;
}

// ---- 5. 幂等入账 ----
$done = pay_settle($orderNo, (string) ($post['trade_no'] ?? ''),
    (string) ($post['buyer_logon_id'] ?? ($post['buyer_id'] ?? '')), $raw);
pay_log('notify ' . $orderNo . ($done ? ' 入账成功' : ' 重复通知已忽略'));

echo 'success';
