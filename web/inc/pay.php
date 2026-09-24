<?php
/**
 * 支付渠道抽象 + 支付宝官方支付实现。
 *
 * 设计成「渠道注册表」的形式，是为了后面加微信、易支付时不用改充值页和回调页：
 * 新渠道只要往 pay_channels() 里加一条、再实现自己的下单函数就行。
 * 目前只落地了支付宝，但后台的渠道开关已经是多选结构。
 *
 * 支付宝这边用普通公钥方式（应用私钥 + 支付宝公钥），签名算法 RSA2。
 * 三种形态共用一套签名/验签逻辑，只是 method 和跳转方式不同：
 *   page = alipay.trade.page.pay      电脑网站支付，表单自动提交跳过去
 *   wap  = alipay.trade.wap.pay       手机网站支付，同样跳转，会唤起 App
 *   qr   = alipay.trade.precreate     当面付预下单，返回二维码串自己渲染
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crypto.php';   // enc_secret / dec_secret，应用私钥加密落库

/** 支付宝网关。沙箱环境请在后台把「支付宝网关」改成 openapi.alipaydev.com 那个地址。 */
function alipay_gateway(): string
{
    $g = trim((string) setting_get('alipay_gateway', ''));
    return $g !== '' ? $g : 'https://openapi.alipay.com/gateway.do';
}

/**
 * 已启用的支付渠道列表。
 * 返回 [渠道标识 => 显示名]，只含后台勾选启用且参数配齐的。
 */
function pay_channels(): array
{
    $all = [
        'alipay' => '支付宝',
    ];
    $on   = array_filter(array_map('trim', explode(',', (string) setting_get('pay_channels_on', 'alipay'))));
    $out  = [];
    foreach ($all as $k => $name) {
        if (!in_array($k, $on, true)) {
            continue;
        }
        // 参数没配齐的渠道不要暴露给用户，否则点下去只能看到报错
        if ($k === 'alipay' && !alipay_ready()) {
            continue;
        }
        $out[$k] = $name;
    }
    return $out;
}

/** 支付宝参数是否配齐 */
function alipay_ready(): bool
{
    return trim((string) setting_get('alipay_app_id', '')) !== ''
        && trim((string) setting_get('alipay_private_key', '')) !== ''
        && trim((string) setting_get('alipay_public_key', '')) !== '';
}

/** 取应用私钥明文（后台是加密存的） */
function alipay_private_key(): string
{
    $raw = (string) setting_get('alipay_private_key', '');
    if ($raw === '') {
        return '';
    }
    $plain = dec_secret($raw);
    return $plain !== '' ? $plain : $raw;   // 兼容早期明文存的情况
}

/**
 * 把裸密钥串补成 PEM 格式。
 * 后台粘贴时常常只有一长串 base64（支付宝开放平台给的就是这样），
 * openssl 认不了，必须补上头尾和换行。已经是 PEM 的原样返回。
 */
function pem_wrap(string $key, bool $isPublic): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }
    if (strpos($key, '-----BEGIN') !== false) {
        return $key;
    }
    $raw  = preg_replace('/\s+/', '', $key);
    $body = chunk_split($raw, 64, "\n");
    if ($isPublic) {
        return "-----BEGIN PUBLIC KEY-----\n{$body}-----END PUBLIC KEY-----";
    }
    // 私钥有 PKCS#1 和 PKCS#8 两种，头不一样，套错了 openssl 认不出来。
    // 开放平台密钥工具默认给 PKCS#8，但也有人用 PKCS#1 的工具生成，
    // 光看 base64 前缀猜不可靠，直接拿 openssl 试一把、哪个能加载用哪个。
    // 之前一律按 PKCS#1 套头，PKCS#8 的密钥虽然靠 fallback 仍能加载，
    // 但会留一串 asn1 报错，且新版 openssl 收紧后可能直接失败。
    $pkcs8 = "-----BEGIN PRIVATE KEY-----\n{$body}-----END PRIVATE KEY-----";
    $pkcs1 = "-----BEGIN RSA PRIVATE KEY-----\n{$body}-----END RSA PRIVATE KEY-----";
    if (openssl_pkey_get_private($pkcs8)) {
        return $pkcs8;
    }
    while (openssl_error_string()) {
        // 清掉试探留下的错误，免得污染后面真正签名时的错误信息
    }
    return $pkcs1;
}

/**
 * 生成待签名字符串：参数按键名字典序升序，用 & 拼 k=v，跳过空值和 sign。
 * 这是支付宝规定的规则，顺序错一位签名就过不了。
 *
 * sign_type 要不要参与签名，两个方向的规则是相反的，这也是最容易踩的坑：
 *   我们发起请求（签名）——sign_type 必须参与。网关收到后就是带着它验的，
 *     少一个参数就会报「验签出错，建议检查签名字符串或签名私钥与应用公钥是否匹配」。
 *   支付宝回调过来（验签）——sign_type 必须剔除。它是和 sign 并列的签名元数据，
 *     不属于业务参数，留着反而验不过。
 * 所以用 $dropSignType 区分，别图省事两边都删。
 */
function alipay_sign_content(array $params, bool $dropSignType = false): string
{
    unset($params['sign']);
    if ($dropSignType) {
        unset($params['sign_type']);
    }
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null || is_array($v)) {
            continue;
        }
        $parts[] = $k . '=' . $v;
    }
    return implode('&', $parts);
}

/** RSA2 签名，返回 base64 */
function alipay_sign(array $params): string
{
    $pem = pem_wrap(alipay_private_key(), false);
    $key = openssl_pkey_get_private($pem);
    if (!$key) {
        return '';
    }
    $sign = '';
    openssl_sign(alipay_sign_content($params), $sign, $key, OPENSSL_ALGO_SHA256);
    return base64_encode($sign);
}

/**
 * 验证支付宝回调/返回的签名。
 *
 * 这一步是整个充值流程的安全底线：不验签的话，任何人构造一个
 * trade_status=TRADE_SUCCESS 的请求打到回调地址就能白拿余额。
 */
function alipay_verify(array $params): bool
{
    $sign = (string) ($params['sign'] ?? '');
    if ($sign === '') {
        return false;
    }
    $pem = pem_wrap((string) setting_get('alipay_public_key', ''), true);
    $key = openssl_pkey_get_public($pem);
    if (!$key) {
        return false;
    }
    // 验回调：sign_type 要剔除，见 alipay_sign_content 的说明
    return openssl_verify(alipay_sign_content($params, true), base64_decode($sign), $key,
        OPENSSL_ALGO_SHA256) === 1;
}

/** 本站对外地址，拼回调用。优先用后台配的，没配就按当前请求推断。 */
function site_base_url(): string
{
    $u = trim((string) setting_get('site_url', ''));
    if ($u !== '') {
        return rtrim($u, '/');
    }
    $proto = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
    return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * 后台配的可选面额，返回升序去重的正数数组。
 * 后台是一行一个或逗号分隔地填，这里都兼容。
 */
function pay_amount_options(): array
{
    $raw  = (string) setting_get('pay_amounts', '10,50,100,200');
    $nums = preg_split('/[\s,，、]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $out  = [];
    foreach ($nums as $n) {
        $v = round((float) $n, 2);
        if ($v > 0) {
            $out[(string) $v] = $v;
        }
    }
    $out = array_values($out);
    sort($out, SORT_NUMERIC);
    return $out;
}

/**
 * 阶梯优惠配置。返回按门槛升序排列的 [['min'=>门槛元, 'rate'=>比例%], ...]。
 *
 * 存储格式是每行「门槛:比例」，例如：
 *   30:1
 *   50:2
 *   100:3
 * 表示满 30 优惠 1%、满 50 优惠 2%、满 100 优惠 3%。
 *
 * 兼容旧的单档配置：新键没值时，用老的 pay_discount / pay_discount_min 折算成一档，
 * 这样升级上来的站点不用重新配就能继续按原规则收费。
 */
function pay_discount_tiers(): array
{
    $raw = trim((string) setting_get('pay_discount_tiers', ''));

    if ($raw === '') {
        // 回落到旧配置
        $rate = max(0.0, min(90.0, (float) setting_get('pay_discount', 0)));
        if ($rate <= 0) {
            return [];
        }
        return [['min' => max(0.0, (float) setting_get('pay_discount_min', 0)), 'rate' => $rate]];
    }

    $tiers = [];
    foreach (preg_split('/[\r\n;；]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        // 门槛和比例之间允许用中英文冒号、逗号、空格分隔，用户怎么填都认
        $kv = preg_split('/[\s:：,，、]+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY);
        if (count($kv) < 2) {
            continue;
        }
        $min  = round((float) $kv[0], 2);
        $rate = (float) $kv[1];
        if ($min < 0 || $rate <= 0) {
            continue;   // 比例为 0 的档等于没优惠，留着只会让预览看着困惑
        }
        // 同一门槛填了多次，后面的覆盖前面的
        $tiers[(string) $min] = ['min' => $min, 'rate' => max(0.0, min(90.0, $rate))];
    }
    $tiers = array_values($tiers);
    usort($tiers, function ($a, $b) {
        return $a['min'] <=> $b['min'];
    });
    return $tiers;
}

/**
 * 按到账面额挑出适用的优惠比例。
 *
 * 取「门槛不超过面额的最高那一档」。档位已按门槛升序排好，从后往前找到第一个就是。
 * 比较用「分」而不是直接比浮点：门槛 100 遇上面额 100 时，
 * 浮点误差可能让 100.0 >= 100.0 判成 false，用户看到满额却没优惠。
 */
function pay_discount_for(float $credit): float
{
    $fen   = (int) round($credit * 100);
    $tiers = pay_discount_tiers();
    for ($i = count($tiers) - 1; $i >= 0; $i--) {
        if ($fen >= (int) round($tiers[$i]['min'] * 100)) {
            return $tiers[$i]['rate'];
        }
    }
    return 0.0;
}

/**
 * 由到账面额算实付金额。
 *
 * 规则是「到账固定、实付打折」：面额 100 + 优惠 2%，用户付 98，余额加 100。
 * 分转整用 ceil 而不是 round：宁可让用户多付 1 分钱，也不能因为舍入
 * 让实付比应付少，那样每笔都亏一点，量大了就是实打实的损失。
 *
 * 优惠比例由阶梯配置决定，见 pay_discount_for()。
 */
function pay_calc(float $credit): array
{
    $rate = pay_discount_for($credit);
    // 直接算「分」：credit*100*(100-rate)/100 == credit*(100-rate)。
    // 先 round 掉浮点误差再 ceil，否则 9.8*100 会得到 980.0000000000001，
    // ceil 完凭空多收一分钱。
    $fen = (int) ceil(round($credit * (100.0 - $rate), 4));
    $pay = $fen / 100;
    // 支付宝最低 0.01
    if ($pay < 0.01) {
        $pay = 0.01;
    }
    return ['credit' => round($credit, 2), 'pay' => $pay, 'rate' => $rate];
}

/** 生成订单号：时间 + 用户 + 随机，够短又不会撞 */
function pay_make_order_no(int $userId): string
{
    return date('YmdHis') . str_pad((string) $userId, 6, '0', STR_PAD_LEFT)
        . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * 订单入账。幂等：重复调用只会加一次余额。
 *
 * 幂等靠的是那条带 status='pending' 条件的 UPDATE：
 * 只有把订单从 pending 改成 paid 成功（受影响行数为 1）的那一次才继续加余额。
 * 支付宝的异步回调会重发好几次，同步返回页用户也可能刷新，
 * 没有这道锁的话余额会被重复加。
 *
 * @return bool 这次调用是否真的完成了入账
 */
function pay_settle(string $orderNo, string $tradeNo, string $buyerId, string $raw): bool
{
    $ord = db_one('SELECT * FROM recharge_orders WHERE order_no = ? LIMIT 1', [$orderNo]);
    if (!$ord) {
        return false;
    }
    $n = db_exec('UPDATE recharge_orders
                     SET status = \'paid\', trade_no = ?, buyer_id = ?, paid_at = NOW(),
                         notify_raw = ?
                   WHERE order_no = ? AND status = \'pending\'',
        [mb_substr($tradeNo, 0, 60), mb_substr($buyerId, 0, 60),
         mb_substr($raw, 0, 60000), $orderNo]);
    if ($n < 1) {
        return false;   // 已经入过账了，直接返回
    }

    $uid    = (int) $ord['user_id'];
    $credit = (float) $ord['credit_amount'];
    db_exec('UPDATE users SET balance = balance + ? WHERE id = ?', [$credit, $uid]);
    $after = (float) db_val('SELECT balance FROM users WHERE id = ?', [$uid]);
    db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, admin_id, created_at)
             VALUES (?,?,?,?,?,0,NOW())',
        [$uid, $credit, $after, 'recharge',
         '在线充值 订单 ' . $orderNo . '（实付 ' . number_format((float) $ord['pay_amount'], 2) . '）']);

    // AFF 推介返现。走到这里说明订单刚从 pending 翻成 paid，一笔订单只会来一次。
    // 返现失败不能影响充值本身：钱已经到账了，异常只记日志。
    try {
        require_once __DIR__ . '/aff.php';
        aff_settle_recharge($uid, (float) $ord['pay_amount'], $orderNo, (int) $ord['id']);
    } catch (Throwable $e) {
        error_log('[aff] 返现结算失败 order=' . $orderNo . ' err=' . $e->getMessage());
    }
    return true;
}

/** 组装支付宝公共请求参数 */
function alipay_base_params(string $method, array $biz): array
{
    $p = [
        'app_id'      => trim((string) setting_get('alipay_app_id', '')),
        'method'      => $method,
        'format'      => 'JSON',
        'charset'     => 'utf-8',
        'sign_type'   => 'RSA2',
        'timestamp'   => date('Y-m-d H:i:s'),
        'version'     => '1.0',
        'notify_url'  => site_base_url() . '/pay/alipay_notify.php',
        'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE),
    ];
    // 扫码（当面付）是前端轮询查状态，没有浏览器跳转，不需要 return_url
    if ($method !== 'alipay.trade.precreate') {
        $p['return_url'] = site_base_url() . '/pay/alipay_return.php';
    }
    return $p;
}

/**
 * 发起支付。
 *
 * @return array 统一返回结构：
 *   ['ok'=>true, 'type'=>'redirect', 'url'=>'...']   跳转支付宝
 *   ['ok'=>true, 'type'=>'qr',       'qr'=>'...']    自己渲染二维码
 *   ['ok'=>false,'error'=>'...']
 */
function alipay_create(array $order): array
{
    if (!alipay_ready()) {
        return ['ok' => false, 'error' => '支付宝参数未配置完整'];
    }
    $scene   = (string) $order['pay_scene'];
    $subject = (string) app_name() . ' 余额充值';
    // 金额必须两位小数字符串，支付宝对格式很严；也别让浮点误差跑出第三位小数
    $money   = number_format((float) $order['pay_amount'], 2, '.', '');
    $biz     = [
        'out_trade_no' => (string) $order['order_no'],
        'total_amount' => $money,
        'subject'      => $subject,
    ];

    if ($scene === 'qr') {
        // 当面付预下单：服务端直接调接口拿二维码串
        $biz['timeout_express'] = '15m';
        $p = alipay_base_params('alipay.trade.precreate', $biz);
        $p['sign'] = alipay_sign($p);
        $resp = pay_http_post(alipay_gateway(), $p);
        if (!$resp['ok']) {
            return ['ok' => false, 'error' => '请求支付宝失败：' . $resp['error']];
        }
        $j   = json_decode($resp['body'], true);
        $sub = $j['alipay_trade_precreate_response'] ?? [];
        if (($sub['code'] ?? '') !== '10000') {
            return ['ok' => false,
                'error' => '支付宝：' . ($sub['sub_msg'] ?? ($sub['msg'] ?? '预下单失败'))];
        }
        return ['ok' => true, 'type' => 'qr', 'qr' => (string) ($sub['qr_code'] ?? '')];
    }

    // 电脑网站 / 手机网站：不用自己请求接口，把签好名的参数拼成 URL 让浏览器跳过去
    $method = $scene === 'wap' ? 'alipay.trade.wap.pay' : 'alipay.trade.page.pay';
    $biz['product_code'] = $scene === 'wap' ? 'QUICK_WAP_WAY' : 'FAST_INSTANT_TRADE_PAY';
    $biz['timeout_express'] = '15m';
    $p = alipay_base_params($method, $biz);
    $p['sign'] = alipay_sign($p);
    if ($p['sign'] === '') {
        return ['ok' => false, 'error' => '签名失败，请检查应用私钥格式'];
    }
    return ['ok' => true, 'type' => 'redirect',
            'url' => alipay_gateway() . '?' . http_build_query($p)];
}

/**
 * 主动查询订单支付状态。
 *
 * 两个地方要用：扫码页轮询、以及回调万一没收到时用户点「我已支付」补查。
 * 不能只依赖异步回调——回调可能因为网络、防火墙、证书问题打不进来，
 * 那样用户钱付了余额没到，是最难解释的故障。
 */
function alipay_query(string $orderNo): array
{
    if (!alipay_ready()) {
        return ['ok' => false, 'error' => '支付宝参数未配置'];
    }
    $p = [
        'app_id'      => trim((string) setting_get('alipay_app_id', '')),
        'method'      => 'alipay.trade.query',
        'format'      => 'JSON',
        'charset'     => 'utf-8',
        'sign_type'   => 'RSA2',
        'timestamp'   => date('Y-m-d H:i:s'),
        'version'     => '1.0',
        'biz_content' => json_encode(['out_trade_no' => $orderNo], JSON_UNESCAPED_UNICODE),
    ];
    $p['sign'] = alipay_sign($p);
    $resp = pay_http_post(alipay_gateway(), $p);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error']];
    }
    $j   = json_decode($resp['body'], true);
    $sub = $j['alipay_trade_query_response'] ?? [];
    $st  = (string) ($sub['trade_status'] ?? '');
    return [
        'ok'       => ($sub['code'] ?? '') === '10000',
        'status'   => $st,
        'paid'     => in_array($st, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true),
        'trade_no' => (string) ($sub['trade_no'] ?? ''),
        'buyer_id' => (string) ($sub['buyer_logon_id'] ?? ($sub['buyer_user_id'] ?? '')),
        'amount'   => (string) ($sub['total_amount'] ?? ''),
        'error'    => ($sub['sub_msg'] ?? ($sub['msg'] ?? '')),
    ];
}

/**
 * 支付相关日志。
 *
 * 支付出问题时用户只会说「我付了钱没到账」，没有日志根本查不下去：
 * 回调到底有没有打进来、验签过没过、金额对不对，全靠这个文件还原现场。
 * 单文件超过 4MB 就滚动一次，不然长期跑下来会很大。
 */
function pay_log(string $line): void
{
    $f = DATA_DIR . '/pay.log';
    if (is_file($f) && filesize($f) > 4194304) {
        @rename($f, $f . '.1');
    }
    @file_put_contents($f,
        date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** 简单的 POST 封装。超时给足 20 秒，支付宝偶尔会慢。 */
function pay_http_post(string $url, array $form): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($form),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    if ($body === false) {
        return ['ok' => false, 'error' => $err ?: '网络错误', 'body' => ''];
    }
    return ['ok' => true, 'error' => '', 'body' => (string) $body];
}
