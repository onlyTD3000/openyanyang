<?php
/**
 * 充值接口：下单、查单。
 *
 * 下单时金额必须由服务端按后台配置重新算一遍，绝不能信前端传来的实付金额，
 * 否则改一下请求体就能一分钱充一百块。
 */
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/pay.php';
api_error_guard();

$me = require_login_api();
csrf_check();

$act = (string) ($_POST['act'] ?? $_GET['act'] ?? 'create');

// ---------- 支付方式与面额 ----------
// 桌面端点余额时先打这里，拿到可用渠道和面额再画选择界面。
// 每个面额都带上服务端算好的实付金额，客户端不自己算折扣，避免两边算法不一致。
if ($act === 'options') {
    $channels = pay_channels();
    $list = [];
    foreach (pay_amount_options() as $credit) {
        $c = pay_calc((float) $credit);
        $list[] = [
            'credit' => $c['credit'],
            'pay'    => $c['pay'],
            'rate'   => $c['rate'],
        ];
    }
    // 桌面端优先用扫码：客户端里没法跳浏览器完成支付宝页面流程
    $qrOn = (string) setting_get('alipay_scene_qr', '0') === '1';
    json_out([
        'ok'         => 1,
        'channels'   => $channels,
        'amounts'    => $list,
        'qr_on'      => $qrOn ? 1 : 0,
        'custom_on'  => (string) setting_get('pay_custom_on', '0') === '1' ? 1 : 0,
        'custom_min' => (float) setting_get('pay_custom_min', 1),
        'custom_max' => (float) setting_get('pay_custom_max', 10000),
    ]);
}
// ---------- 下单 ----------
if ($act === 'create') {
    $channels = pay_channels();
    if (!$channels) {
        json_out(['error' => '管理员还没有配置支付渠道'], 400);
    }
    $ch = (string) ($_POST['channel'] ?? array_key_first($channels));
    if (!isset($channels[$ch])) {
        json_out(['error' => '支付方式不可用'], 400);
    }

    // 到账金额：必须是后台配的面额之一，或者在允许的自定义区间内
    $credit  = round((float) ($_POST['credit'] ?? 0), 2);
    $options = pay_amount_options();
    $ok      = in_array($credit, $options, true);
    if (!$ok && (string) setting_get('pay_custom_on', '0') === '1') {
        $min = (float) setting_get('pay_custom_min', 1);
        $max = (float) setting_get('pay_custom_max', 10000);
        $ok  = $credit >= $min && $credit <= $max;
    }
    if (!$ok) {
        json_out(['error' => '充值金额不在允许范围内'], 400);
    }

    // 实付金额服务端算，前端传什么都不看
    $calc = pay_calc($credit);

    // 支付形态：按设备选，同时受后台开关限制
    $isMobile = (bool) preg_match('/(iPhone|iPad|Android|Mobile)/i',
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $wantQr = (string) ($_POST['scene'] ?? '') === 'qr';
    $scene  = '';
    if ($wantQr && (string) setting_get('alipay_scene_qr', '0') === '1') {
        $scene = 'qr';
    } elseif ($isMobile && (string) setting_get('alipay_scene_wap', '1') === '1') {
        $scene = 'wap';
    } elseif ((string) setting_get('alipay_scene_page', '1') === '1') {
        $scene = 'page';
    } elseif ((string) setting_get('alipay_scene_wap', '1') === '1') {
        $scene = 'wap';
    } elseif ((string) setting_get('alipay_scene_qr', '0') === '1') {
        $scene = 'qr';
    }
    if ($scene === '') {
        json_out(['error' => '管理员没有启用任何支付形态'], 400);
    }

    $orderNo = pay_make_order_no((int) $me['id']);
    db_insert('INSERT INTO recharge_orders
                 (order_no, user_id, channel, pay_scene, credit_amount, pay_amount,
                  discount_rate, status, client_ip, created_at)
               VALUES (?,?,?,?,?,?,?,\'pending\',?,NOW())',
        [$orderNo, (int) $me['id'], $ch, $scene, $calc['credit'], $calc['pay'],
         $calc['rate'], mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);

    $r = alipay_create([
        'order_no'   => $orderNo,
        'pay_amount' => $calc['pay'],
        'pay_scene'  => $scene,
    ]);
    if (!$r['ok']) {
        db_exec('UPDATE recharge_orders SET status = \'failed\' WHERE order_no = ?', [$orderNo]);
        json_out(['error' => $r['error']], 400);
    }

    // 扫码形态不把码串返给前端，图片由 /api/qrcode.php 服务端渲染
    json_out(['ok' => 1, 'order_no' => $orderNo, 'type' => $r['type'],
              'url' => $r['url'] ?? '',
              'credit' => $calc['credit'], 'pay' => $calc['pay']]);
}

// ---------- 查单 ----------
// 前端轮询和用户点「我已支付」都走这里。
// 除了读本地状态，还会主动问一次支付宝：回调可能没打进来。
if ($act === 'query') {
    $no  = (string) ($_POST['order_no'] ?? $_GET['order_no'] ?? '');
    $ord = db_one('SELECT * FROM recharge_orders WHERE order_no = ? AND user_id = ? LIMIT 1',
        [$no, $me['id']]);
    if (!$ord) {
        json_out(['error' => '订单不存在'], 404);
    }
    if ((string) $ord['status'] === 'paid') {
        json_out(['ok' => 1, 'status' => 'paid',
                  'balance' => (float) db_val('SELECT balance FROM users WHERE id = ?', [$me['id']])]);
    }

    $q = alipay_query($no);
    if ($q['ok'] && $q['paid']) {
        pay_settle($no, (string) $q['trade_no'], (string) $q['buyer_id'],
            'query:' . json_encode($q, JSON_UNESCAPED_UNICODE));
        json_out(['ok' => 1, 'status' => 'paid',
                  'balance' => (float) db_val('SELECT balance FROM users WHERE id = ?', [$me['id']])]);
    }
    json_out(['ok' => 1, 'status' => 'pending', 'msg' => $q['error'] ?? '']);
}

json_out(['error' => '未知操作'], 400);
