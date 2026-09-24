<?php
/**
 * 提现业务层。
 *
 * 两条硬规则：
 *   1. 注册赠送的余额不可提现
 *   2. 提现金额必须是整数元
 *
 * 第一条靠 balance_logs 的 type 判定，不靠猜：
 *   register 注册赠送 → 不可提现
 *   recharge 在线充值 → 可提现
 *   aff      推介返现 → 可提现
 *   admin    后台调整 → 可提现
 *
 * 可提现额度 = min(当前余额, 可提现入账合计 - 已占用)，再向下取整到元。
 * 取 min 是必须的：消费会让余额下降，但历史入账合计不变。少了它，
 * 一个充值 100 又花掉 95 的人还能申请提现 100。
 */
require_once __DIR__ . '/db.php';
/** 可提现的流水类型，register 故意不在里面 */
const WD_WITHDRAWABLE_TYPES = ['recharge', 'aff', 'admin'];
/* ============================ 配置读写 ============================ */
function wd_enabled(): bool
{
    return (string) setting_get('wd_enabled', '1') === '1';
}
/** 单笔最低提现金额，整数元 */
function wd_min(): int
{
    $v = (int) setting_get('wd_min', '10');
    return $v > 0 ? $v : 10;
}
/** 单笔最高提现金额，0 表示不限 */
function wd_max(): int
{
    $v = (int) setting_get('wd_max', '5000');
    return $v > 0 ? $v : 0;
}
/** 每日最多申请几次，0 表示不限 */
function wd_daily_limit(): int
{
    $v = (int) setting_get('wd_daily_limit', '3');
    return $v > 0 ? $v : 0;
}
/** 手续费率，百分比 */
function wd_fee_rate(): float
{
    $v = (float) setting_get('wd_fee_rate', '0');
    return max(0.0, min(100.0, $v));
}
/** 手续费下限，0 表示不设 */
function wd_fee_min(): float
{
    $v = (float) setting_get('wd_fee_min', '0');
    return $v > 0 ? $v : 0.0;
}
/* ============================ 金额计算 ============================ */
/**
 * 算手续费和实际到手。
 * 手续费不允许吃掉全部金额，至少留 0.01 到手。
 */
function wd_calc_fee(float $amount): array
{
    $rate = wd_fee_rate();
    $fee = round($amount * $rate / 100, 2);
    $min = wd_fee_min();
    if ($min > 0 && $fee < $min) {
        $fee = $min;
    }
    if ($fee >= $amount) {
        $fee = max(0, round($amount - 0.01, 2));
    }
    return ['fee' => $fee, 'actual' => round($amount - $fee, 2), 'rate' => $rate];
}
/** 可提现入账合计：充值 + 返现 + 后台正数调整 */
function wd_income_total(int $uid): float
{
    $in = '"' . implode('","', WD_WITHDRAWABLE_TYPES) . '"';
    return (float) db_val(
        "SELECT COALESCE(SUM(amount), 0) FROM balance_logs
          WHERE user_id = ? AND amount > 0 AND type IN ($in)",
        [$uid]
    );
}
/** 已被申请占用的金额，驳回的不算 */
function wd_locked_total(int $uid): float
{
    return (float) db_val(
        'SELECT COALESCE(SUM(amount), 0) FROM withdrawals
          WHERE user_id = ? AND status IN ("pending","processing","done")',
        [$uid]
    );
}
/**
 * 不可提现的入账合计，仅用于页面说明「这部分不能提」。
 * 包含注册赠送和管理员标记为不可提现的赠送。
 */
function wd_gift_total(int $uid): float
{
    return (float) db_val(
        'SELECT COALESCE(SUM(amount), 0) FROM balance_logs
          WHERE user_id = ? AND amount > 0 AND type IN ("register","admin_gift")',
        [$uid]
    );
}
/** 今日已申请次数 */
function wd_today_count(int $uid): int
{
    return (int) db_val(
        'SELECT COUNT(*) FROM withdrawals
          WHERE user_id = ? AND DATE(created_at) = CURDATE()',
        [$uid]
    );
}
/**
 * 额度明细，页面和校验共用一份口径。
 * quota 是最终可提金额，已向下取整到整数元。
 */
function wd_quota_detail(int $uid): array
{
    $bal = (float) db_val('SELECT balance FROM users WHERE id = ?', [$uid]);
    $income = wd_income_total($uid);
    $locked = wd_locked_total($uid);
    $avail = min($bal, $income - $locked);
    if ($avail < 0) {
        $avail = 0.0;
    }
    return [
        'balance' => $bal,
        'income'  => $income,
        'locked'  => $locked,
        'gift'    => wd_gift_total($uid),
        'quota'   => (int) floor($avail),
    ];
}
/** 可提现额度，整数元 */
function wd_quota(int $uid): int
{
    return wd_quota_detail($uid)['quota'];
}
/* ============================ 提交申请 ============================ */
/**
 * 提交提现申请。
 *
 * 校验通过后在一个事务里扣余额、写流水、建申请单，三者同生共死，
 * 不会出现扣了钱却没有申请记录的情况。
 *
 * 申请一提交就扣款是故意的：否则用户可以先申请再把余额花光，
 * 管理员打款时才发现账上没钱。
 */
function wd_submit(int $uid, array $in): array
{
    if (!wd_enabled()) {
        return ['ok' => false, 'msg' => '提现功能当前已关闭'];
    }
    $raw    = trim((string) ($in['amount'] ?? ''));
    $method = (string) ($in['method'] ?? '');
    $name   = trim((string) ($in['real_name'] ?? ''));
    $phone  = trim((string) ($in['phone'] ?? ''));
    $reason = trim((string) ($in['reason'] ?? ''));
    $qrId   = (int) ($in['qr_upload_id'] ?? 0);
    // 金额必须是纯整数，"10.5" "10." "1e3" 一律不收
    if (!preg_match('/^\d+$/', $raw)) {
        return ['ok' => false, 'msg' => '提现金额必须是整数，不能带小数点'];
    }
    $amount = (int) $raw;
    if (!in_array($method, ['alipay', 'wechat'], true)) {
        return ['ok' => false, 'msg' => '请选择收款方式'];
    }
    if ($name === '' || mb_strlen($name) > 50) {
        return ['ok' => false, 'msg' => '请填写收款人姓名'];
    }
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
        return ['ok' => false, 'msg' => '手机号格式不正确'];
    }
    if ($reason === '') {
        return ['ok' => false, 'msg' => '请填写提现理由'];
    }
    if (mb_strlen($reason) > 500) {
        return ['ok' => false, 'msg' => '提现理由请控制在 500 字以内'];
    }
    $min = wd_min();
    if ($amount < $min) {
        return ['ok' => false, 'msg' => '单笔最低提现 ' . $min . ' 元'];
    }
    $max = wd_max();
    if ($max > 0 && $amount > $max) {
        return ['ok' => false, 'msg' => '单笔最高提现 ' . $max . ' 元'];
    }
    $limit = wd_daily_limit();
    if ($limit > 0 && wd_today_count($uid) >= $limit) {
        return ['ok' => false, 'msg' => '今天已经申请 ' . $limit . ' 次，请明天再来'];
    }
    // 收款码校验归属，不能引用别人的上传
    if ($qrId > 0) {
        $own = db_val('SELECT user_id FROM uploads WHERE id = ?', [$qrId]);
        if ($own === null || (int) $own !== $uid) {
            return ['ok' => false, 'msg' => '收款码无效，请重新上传'];
        }
    } else {
        return ['ok' => false, 'msg' => '请上传收款码'];
    }
    $calc = wd_calc_fee((float) $amount);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 行锁住这个用户，防止两个请求同时提交把额度花两遍
        $u = db_one('SELECT id, balance FROM users WHERE id = ? FOR UPDATE', [$uid]);
        if (!$u) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => '用户不存在'];
        }
        // 锁内重算额度，用的是同一套口径
        $quota = wd_quota($uid);
        if ($amount > $quota) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => '可提现额度只有 ' . $quota . ' 元（注册赠送的余额不可提现）'];
        }
        $after = (float) $u['balance'] - $amount;
        db_exec('UPDATE users SET balance = balance - ? WHERE id = ?', [$amount, $uid]);
        db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, created_at)
                 VALUES (?, ?, ?, "withdraw", ?, NOW())',
            [$uid, -$amount, $after, '提现申请 ' . $amount . ' 元（手续费 ' . $calc['fee'] . '，到手 ' . $calc['actual'] . '）']);
        $id = db_insert('INSERT INTO withdrawals
                (user_id, amount, fee, actual, fee_rate, method, real_name, phone, reason,
                 qr_upload_id, status, client_ip, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", ?, NOW())',
            [$uid, $amount, $calc['fee'], $calc['actual'], $calc['rate'], $method,
             $name, $phone, $reason, $qrId, (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);
        $pdo->commit();
        return ['ok' => true, 'msg' => '提现申请已提交，请等待处理', 'id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[withdraw] 提交失败 uid=' . $uid . ' ' . $e->getMessage());
        return ['ok' => false, 'msg' => '提交失败，请稍后重试'];
    }
}
/* ============================ 查询 ============================ */
/** 用户自己的申请记录 */
function wd_my_list(int $uid, int $limit = 20, int $offset = 0): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    return db_all('SELECT * FROM withdrawals WHERE user_id = ?
                    ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, [$uid]);
}
/** 用户申请总数，翻页用 */
function wd_my_count(int $uid): int
{
    return (int) db_val('SELECT COUNT(*) FROM withdrawals WHERE user_id = ?', [$uid]);
}
/** 状态中文名 */
function wd_status_text(string $s): string
{
    $map = [
        'pending'    => '待处理',
        'processing' => '处理中',
        'done'       => '已处理',
        'rejected'   => '已驳回',
    ];
    return $map[$s] ?? $s;
}
/** 收款方式中文名 */
function wd_method_text(string $m): string
{
    $map = ['alipay' => '支付宝', 'wechat' => '微信'];
    return $map[$m] ?? $m;
}
/** 拼后台列表的筛选条件，列表和计数共用，避免两处口径不一致 */
function wd_admin_where(string $status, string $kw): array
{
    $where = '1=1';
    $args = [];
    if (in_array($status, ['pending', 'processing', 'done', 'rejected'], true)) {
        $where .= ' AND w.status = ?';
        $args[] = $status;
    }
    if ($kw !== '') {
        $where .= ' AND (u.username LIKE ? OR w.real_name LIKE ? OR w.phone LIKE ? OR w.user_id = ?)';
        $like = '%' . $kw . '%';
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
        $args[] = (int) $kw;
    }
    return [$where, $args];
}
/**
 * 后台列表。管理员要看得见是谁申请的，所以 JOIN 出账号。
 * 用户端只显示 ID，两边口径不同是有意的。
 */
function wd_admin_list(string $status = '', string $kw = '', int $limit = 30, int $offset = 0): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    [$where, $args] = wd_admin_where($status, $kw);
    return db_all(
        "SELECT w.*, u.username, u.email, u.balance
           FROM withdrawals w LEFT JOIN users u ON u.id = w.user_id
          WHERE $where ORDER BY w.id DESC LIMIT $limit OFFSET $offset",
        $args
    );
}
/** 后台列表总数 */
function wd_admin_count(string $status = '', string $kw = ''): int
{
    [$where, $args] = wd_admin_where($status, $kw);
    return (int) db_val(
        "SELECT COUNT(*) FROM withdrawals w LEFT JOIN users u ON u.id = w.user_id WHERE $where",
        $args
    );
}
/** 后台汇总：各状态笔数与金额 */
function wd_admin_stats(): array
{
    $rows = db_all('SELECT status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS amt,
                           COALESCE(SUM(fee),0) AS fee, COALESCE(SUM(actual),0) AS act
                      FROM withdrawals GROUP BY status');
    $out = [];
    foreach (['pending', 'processing', 'done', 'rejected'] as $s) {
        $out[$s] = ['n' => 0, 'amt' => 0.0, 'fee' => 0.0, 'act' => 0.0];
    }
    foreach ($rows as $r) {
        $s = (string) $r['status'];
        if (isset($out[$s])) {
            $out[$s] = [
                'n'   => (int) $r['n'],
                'amt' => (float) $r['amt'],
                'fee' => (float) $r['fee'],
                'act' => (float) $r['act'],
            ];
        }
    }
    // 待处理口径包含处理中：那些钱同样还没打出去
    $out['todo'] = [
        'n'   => $out['pending']['n'] + $out['processing']['n'],
        'amt' => $out['pending']['amt'] + $out['processing']['amt'],
    ];
    return $out;
}
/* ======================== 后台状态流转 ======================== */
/**
 * 改申请状态。
 *
 * 驳回要把钱退回用户余额，这是唯一涉及资金的分支，所以走事务。
 * 已经退过款的单子不允许再退第二次：靠状态判断，只有非 rejected
 * 的单子能转成 rejected。
 */
function wd_set_status(int $id, string $status, int $adminId, string $note = ''): array
{
    if (!in_array($status, ['pending', 'processing', 'done', 'rejected'], true)) {
        return ['ok' => false, 'msg' => '状态值不合法'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $w = db_one('SELECT * FROM withdrawals WHERE id = ? FOR UPDATE', [$id]);
        if (!$w) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => '申请不存在'];
        }
        $old = (string) $w['status'];
        if ($old === $status) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => '状态没有变化'];
        }
        // 已驳回的单子钱已退回，不能再改成其它状态，否则等于凭空再打一笔
        if ($old === 'rejected') {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => '已驳回的申请不能再改状态，请让用户重新提交'];
        }
        db_exec('UPDATE withdrawals SET status = ?, admin_note = ?, admin_id = ?, handled_at = NOW()
                  WHERE id = ?', [$status, mb_substr($note, 0, 255), $adminId, $id]);
        // 驳回退款
        if ($status === 'rejected') {
            $uid = (int) $w['user_id'];
            $amt = (float) $w['amount'];
            db_exec('UPDATE users SET balance = balance + ? WHERE id = ?', [$amt, $uid]);
            $after = (float) db_val('SELECT balance FROM users WHERE id = ?', [$uid]);
            db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, admin_id, created_at)
                     VALUES (?, ?, ?, "withdraw_refund", ?, ?, NOW())',
                [$uid, $amt, $after, '提现申请 #' . $id . ' 被驳回，退回余额' . ($note !== '' ? '：' . mb_substr($note, 0, 100) : ''), $adminId]);
        }
        $pdo->commit();
        return ['ok' => true, 'msg' => '已标记为' . wd_status_text($status) . ($status === 'rejected' ? '，金额已退回用户余额' : '')];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[withdraw] 改状态失败 id=' . $id . ' ' . $e->getMessage());
        return ['ok' => false, 'msg' => '操作失败，请稍后重试'];
    }
}
/** 金额格式化 */
function wd_money($v): string
{
    return number_format((float) $v, 2, '.', ',');
}
