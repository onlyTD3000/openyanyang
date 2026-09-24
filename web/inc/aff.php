<?php
/**
 * AFF 推介：邀请码、绑定关系、充值返现与统计。
 *
 * 设计要点：
 * - 邀请码存 users.invite_code，注册时生成，靠唯一索引兜底防重。
 * - 绑定关系存 users.referrer_id，只在注册那一刻写入，之后不再改动。
 *   不做后期改绑：返现已经发出去了，改绑会让账目无法追溯。
 * - 返现记录存 aff_commissions，order_no 上有唯一索引。
 *   幂等就靠这个索引：同一笔订单重复结算会撞唯一键，捕获后跳过。
 */
require_once __DIR__ . '/db.php';
/** 邀请码字符集：去掉 0/O/1/I/L，用户手抄时不容易看错 */
const AFF_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
const AFF_CODE_LEN = 8;
/* ============ 开关与配置 ============ */
/** AFF 功能总开关，关掉后不再绑定新关系、不再产生返现 */
function aff_enabled(): bool
{
    return (string) setting_get('aff_enabled', '1') === '1';
}
/** 返现比例（百分数，例如 10 表示返实付金额的 10%） */
function aff_rate(): float
{
    $r = (float) setting_get('aff_rate', '10');
    return max(0, min(100, $r));
}
/** 单笔返现上限，0 表示不限 */
function aff_max_per_order(): float
{
    return max(0, (float) setting_get('aff_max_per_order', '0'));
}
/** 邀请注册时给邀请人的一次性奖励，0 表示不给 */
function aff_signup_bonus(): float
{
    return max(0, (float) setting_get('aff_signup_bonus', '0'));
}
/* ============ 邀请码 ============ */
/** 生成一个未被占用的邀请码 */
function aff_gen_code(): string
{
    $max = strlen(AFF_CODE_ALPHABET) - 1;
    $code = '';
    // 随机撞库概率极低，撞上就重试；重试用尽仍返回最后一个，交给唯一索引拦
    for ($try = 0; $try < 12; $try++) {
        $code = '';
        for ($i = 0; $i < AFF_CODE_LEN; $i++) {
            $code .= AFF_CODE_ALPHABET[random_int(0, $max)];
        }
        if (!db_one('SELECT id FROM users WHERE invite_code = ? LIMIT 1', [$code])) {
            return $code;
        }
    }
    return $code;
}
/** 取用户邀请码，为空则补发一个（存量账号可能没有） */
function aff_user_code(int $uid): string
{
    $code = (string) db_val('SELECT invite_code FROM users WHERE id = ?', [$uid]);
    if ($code !== '') {
        return $code;
    }
    $new = aff_gen_code();
    db_exec("UPDATE users SET invite_code = ? WHERE id = ? AND invite_code = ''", [$new, $uid]);
    return (string) db_val('SELECT invite_code FROM users WHERE id = ?', [$uid]);
}
/** 按邀请码找用户，找不到返回 null。邀请码不区分大小写 */
function aff_find_by_code(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '' || strlen($code) > 16) {
        return null;
    }
    $u = db_one('SELECT id, username, status FROM users WHERE invite_code = ? LIMIT 1', [$code]);
    return $u ?: null;
}
/** 校验邀请码能否用于注册，返回 [上级用户或null, 错误提示] */
function aff_check_code(string $code): array
{
    if (trim($code) === '') {
        return [null, ''];           // 没填不算错，邀请码是选填的
    }
    if (!aff_enabled()) {
        return [null, '推介功能当前未开放'];
    }
    $u = aff_find_by_code($code);
    if (!$u) {
        return [null, '邀请码不存在'];
    }
    if ((int) $u['status'] !== 1) {
        return [null, '该邀请码已失效'];
    }
    return [$u, ''];
}
/** 站点基地址，用于拼推介链接 */
function aff_base_url(): string
{
    $set = trim((string) setting_get('site_url', ''));
    if ($set !== '') {
        return rtrim($set, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}
/** 专属推介链接 */
function aff_invite_url(string $code): string
{
    return aff_base_url() . '/register.php?ref=' . urlencode($code);
}
/* ============ 绑定 ============ */
/**
 * 注册成功后绑定上级。只在新用户刚创建时调用一次。
 * 邀请注册奖励也在这里发，避免调用方漏掉。
 */
function aff_bind(int $newUid, int $referrerId): bool
{
    if ($newUid <= 0 || $referrerId <= 0 || $newUid === $referrerId) {
        return false;
    }
    if (!aff_enabled()) {
        return false;
    }
    // 只认第一次绑定：referrer_id 已有值就不覆盖
    $n = db_exec('UPDATE users SET referrer_id = ?, referred_at = NOW()
                   WHERE id = ? AND referrer_id = 0', [$referrerId, $newUid]);
    if ($n <= 0) {
        return false;
    }
    $bonus = aff_signup_bonus();
    if ($bonus > 0) {
        aff_credit($referrerId, $bonus, '邀请注册奖励（下级 #' . $newUid . '）');
    }
    return true;
}
/* ============ 返现 ============ */
/**
 * 给账户加钱并记一条余额流水。
 * 这里不开事务：调用方 pay_settle 已在自己的事务语境里。
 */
function aff_credit(int $uid, float $amount, string $note): void
{
    if ($amount <= 0) {
        return;
    }
    db_exec('UPDATE users SET balance = balance + ? WHERE id = ?', [$amount, $uid]);
    $after = (float) db_val('SELECT balance FROM users WHERE id = ?', [$uid]);
    db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
        [$uid, $amount, $after, 'aff', $note]);
}
/**
 * 下级充值成功后结算返现，由 pay_settle() 在订单转 paid 之后调用。
 *
 * 基数用实付金额而非到账金额：返现是从真金白银里分的，
 * 按赠送后的到账额算会把站点的优惠也算进返现基数。
 *
 * 返回实际返现金额，0 表示没产生返现。
 */
function aff_settle_recharge(int $payerUid, float $payAmount, string $orderNo, int $orderId = 0): float
{
    if (!aff_enabled() || $payAmount <= 0) {
        return 0;
    }
    $payer = db_one('SELECT id, referrer_id FROM users WHERE id = ?', [$payerUid]);
    if (!$payer || (int) $payer['referrer_id'] <= 0) {
        return 0;                    // 没有上级
    }
    $upId = (int) $payer['referrer_id'];
    $up = db_one('SELECT id, status FROM users WHERE id = ?', [$upId]);
    if (!$up || (int) $up['status'] !== 1) {
        return 0;                    // 上级被禁用
    }
    $rate = aff_rate();
    if ($rate <= 0) {
        return 0;
    }
    $amount = round($payAmount * $rate / 100, 6);
    $cap = aff_max_per_order();
    if ($cap > 0 && $amount > $cap) {
        $amount = $cap;
    }
    if ($amount <= 0) {
        return 0;
    }
    // 幂等：order_no 唯一索引，重复结算撞 1062 后当已处理
    try {
        db_exec('INSERT INTO aff_commissions
                   (user_id, from_user_id, order_id, order_no, recharge_amount, rate, amount, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$upId, $payerUid, $orderId, $orderNo, $payAmount, $rate, $amount]);
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), '1062') !== false
            || stripos($e->getMessage(), 'Duplicate') !== false) {
            return 0;
        }
        throw $e;
    }
    aff_credit($upId, $amount,
        '推介返现 ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%（下级 #'
        . $payerUid . ' 充值 ' . number_format($payAmount, 2, '.', '') . '）');
    return $amount;
}
/**
 * 回溯补算某用户历史已付订单的返现。
 *
 * 用在后台手动给用户补绑上级之后：这个人之前充的钱本该产生返现，
 * 但当时没有上级所以一笔都没算。这里把已付订单重放一遍。
 *
 * 直接复用 aff_settle_recharge，幂等由 aff_commissions.order_no
 * 的唯一索引保证——已经算过的订单会撞 1062 被跳过，不会重复入账。
 * 所以这个函数重复调用是安全的。
 *
 * 比例用的是「当前」设置而非充值当时的设置，因为历史比例没有留存，
 * 而且后台补绑本身就是事后追认，用现行比例更符合管理员的预期。
 *
 * @return array{count:int, total:float} 实际补算的笔数与金额
 */
function aff_backfill_recharges(int $uid): array
{
    $count = 0;
    $total = 0.0;
    if ($uid <= 0 || !aff_enabled()) {
        return ['count' => 0, 'total' => 0.0];
    }
    $orders = db_all('SELECT id, order_no, pay_amount FROM recharge_orders
                       WHERE user_id = ? AND status = \'paid\' AND pay_amount > 0
                       ORDER BY id ASC', [$uid]);
    foreach ($orders as $o) {
        $got = aff_settle_recharge(
            $uid,
            (float) $o['pay_amount'],
            (string) $o['order_no'],
            (int) $o['id']
        );
        if ($got > 0) {
            $count++;
            $total += $got;
        }
    }
    return ['count' => $count, 'total' => round($total, 6)];
}

/* ============ 统计（用户端） ============ */
/** 某用户的推介总览 */
function aff_my_stat(int $uid): array
{
    $invited = (int) db_val('SELECT COUNT(*) FROM users WHERE referrer_id = ?', [$uid]);
    $c = db_one('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
                   FROM aff_commissions WHERE user_id = ?', [$uid]);
    // 下级充值总额只算已支付订单
    $r = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(o.pay_amount), 0) AS total
                   FROM recharge_orders o
                   JOIN users u ON u.id = o.user_id
                  WHERE u.referrer_id = ? AND o.status = 'paid'", [$uid]);
    return [
        'invited'        => $invited,
        'commission_n'   => (int) ($c['n'] ?? 0),
        'commission_sum' => (float) ($c['total'] ?? 0),
        'recharge_n'     => (int) ($r['n'] ?? 0),
        'recharge_sum'   => (float) ($r['total'] ?? 0),
    ];
}
/** 我邀请的人：每人的充值笔数、充值总额、为我贡献的返现 */
function aff_my_invitees(int $uid, int $limit = 20, int $offset = 0, bool $withAccount = false): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    // 默认不返回账号和邮箱：用户端推广页只显示用户 ID，不暴露下级身份
    // $withAccount 仅供后台调用，字符串写死在代码里，不含外部输入
    $accountCols = $withAccount ? 'u.username, u.email,' : '';
    return db_all("SELECT u.id, {$accountCols} u.status, u.created_at, u.referred_at,
                          COALESCE(r.n, 0) AS recharge_n,
                          COALESCE(r.total, 0) AS recharge_sum,
                          COALESCE(c.total, 0) AS commission_sum
                     FROM users u
                     LEFT JOIN (SELECT user_id, COUNT(*) AS n, SUM(pay_amount) AS total
                                  FROM recharge_orders WHERE status = 'paid'
                                 GROUP BY user_id) r ON r.user_id = u.id
                     LEFT JOIN (SELECT from_user_id, SUM(amount) AS total
                                  FROM aff_commissions
                                 GROUP BY from_user_id) c ON c.from_user_id = u.id
                    WHERE u.referrer_id = ?
                    ORDER BY u.id DESC
                    LIMIT " . $limit . " OFFSET " . $offset, [$uid]);
}
/** 我邀请的人数（配合分页） */
function aff_my_invitee_count(int $uid): int
{
    return (int) db_val('SELECT COUNT(*) FROM users WHERE referrer_id = ?', [$uid]);
}
/** 我的返现流水 */
function aff_my_commissions(int $uid, int $limit = 20, int $offset = 0): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    // 用户端只显示来源用户 ID，不需要 JOIN 出账号，顺带省掉一次关联
    return db_all('SELECT c.*
                     FROM aff_commissions c
                    WHERE c.user_id = ?
                    ORDER BY c.id DESC
                    LIMIT ' . $limit . ' OFFSET ' . $offset, [$uid]);
}
/** 我的返现流水条数 */
function aff_my_commission_count(int $uid): int
{
    return (int) db_val('SELECT COUNT(*) FROM aff_commissions WHERE user_id = ?', [$uid]);
}
/* ============ 统计（后台） ============ */
/** 全站推介概况 */
function aff_admin_overview(): array
{
    $bound = (int) db_val('SELECT COUNT(*) FROM users WHERE referrer_id > 0');
    $promoters = (int) db_val('SELECT COUNT(DISTINCT referrer_id) FROM users WHERE referrer_id > 0');
    $c = db_one('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM aff_commissions');
    $r = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(o.pay_amount), 0) AS total
                   FROM recharge_orders o
                   JOIN users u ON u.id = o.user_id
                  WHERE u.referrer_id > 0 AND o.status = 'paid'");
    return [
        'bound'          => $bound,
        'promoters'      => $promoters,
        'commission_n'   => (int) ($c['n'] ?? 0),
        'commission_sum' => (float) ($c['total'] ?? 0),
        'recharge_n'     => (int) ($r['n'] ?? 0),
        'recharge_sum'   => (float) ($r['total'] ?? 0),
    ];
}
/** 推广人列表：邀请人数、下级充值总额、累计返现 */
function aff_admin_promoters(string $kw = '', int $limit = 20, int $offset = 0): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $where = 'u.id IN (SELECT referrer_id FROM users WHERE referrer_id > 0)';
    $params = [];
    if ($kw !== '') {
        $where .= ' AND (u.username LIKE ? OR u.email LIKE ? OR u.invite_code LIKE ?)';
        $like = '%' . $kw . '%';
        $params = [$like, $like, $like];
    }
    return db_all("SELECT u.id, u.username, u.email, u.invite_code, u.status, u.balance,
                          (SELECT COUNT(*) FROM users s WHERE s.referrer_id = u.id) AS invited,
                          (SELECT COALESCE(SUM(c.amount), 0) FROM aff_commissions c
                            WHERE c.user_id = u.id) AS commission_sum,
                          (SELECT COALESCE(SUM(o.pay_amount), 0)
                             FROM recharge_orders o JOIN users s ON s.id = o.user_id
                            WHERE s.referrer_id = u.id AND o.status = 'paid') AS sub_recharge
                     FROM users u
                    WHERE " . $where . "
                    ORDER BY invited DESC, u.id DESC
                    LIMIT " . $limit . " OFFSET " . $offset, $params);
}
/** 推广人总数 */
function aff_admin_promoter_count(string $kw = ''): int
{
    $where = 'u.id IN (SELECT referrer_id FROM users WHERE referrer_id > 0)';
    $params = [];
    if ($kw !== '') {
        $where .= ' AND (u.username LIKE ? OR u.email LIKE ? OR u.invite_code LIKE ?)';
        $like = '%' . $kw . '%';
        $params = [$like, $like, $like];
    }
    return (int) db_val('SELECT COUNT(*) FROM users u WHERE ' . $where, $params);
}
/** 全站返现流水 */
function aff_admin_commissions(int $limit = 30, int $offset = 0): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    return db_all('SELECT c.*, up.username AS up_username, fu.username AS from_username
                     FROM aff_commissions c
                     LEFT JOIN users up ON up.id = c.user_id
                     LEFT JOIN users fu ON fu.id = c.from_user_id
                    ORDER BY c.id DESC
                    LIMIT ' . $limit . ' OFFSET ' . $offset);
}
/** 返现流水总条数 */
function aff_admin_commission_count(): int
{
    return (int) db_val('SELECT COUNT(*) FROM aff_commissions');
}
