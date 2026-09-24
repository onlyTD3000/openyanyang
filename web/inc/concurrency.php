<?php
/**
 * 并发控制模块
 * 
 * 支持两种模式：
 * 1. 按 SK 控制：每个 SK 独立计数，防止单个 SK 被打爆
 * 2. 按用户控制：每个用户独立计数，防止单个用户占满资源
 */

/**
 * 尝试获取并发槽位（进入临界区）
 * 
 * @param string $mode 'sk' 或 'user'
 * @param int    $limit 并发上限（0=不限制）
 * @param int    $channelId 渠道 ID（按 SK 模式时必填）
 * @param int    $skIndex SK 下标（按 SK 模式时必填）
 * @param int    $userId 用户 ID（按用户模式时必填）
 * @return array ['ok' => bool, 'token' => string|null, 'wait' => int]
 *               ok: 是否获取成功
 *               token: 成功时返回的释放令牌（调用方需保存，用于释放）
 *               wait: 失败时建议等待的秒数
 */
function concurrency_acquire(string $mode, int $limit, int $channelId = 0, int $skIndex = 0, int $userId = 0): array
{
    if ($limit <= 0) {
        return ['ok' => true, 'token' => null, 'wait' => 0];
    }

    $key = '';
    if ($mode === 'sk') {
        if ($channelId <= 0 || $skIndex < 0) {
            return ['ok' => false, 'token' => null, 'wait' => 0];
        }
        $key = "ch{$channelId}_sk{$skIndex}";
    } elseif ($mode === 'user') {
        if ($userId <= 0) {
            return ['ok' => false, 'token' => null, 'wait' => 0];
        }
        $key = "user{$userId}";
    } else {
        return ['ok' => false, 'token' => null, 'wait' => 0];
    }

    // 清理超时的槽位（超过 10 分钟视为异常未释放）
    db_exec('DELETE FROM concurrency_slots WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)');

    // 查询当前占用数
    $current = (int) db_val('SELECT COUNT(*) FROM concurrency_slots WHERE slot_key = ?', [$key]);

    if ($current >= $limit) {
        // 已满，计算建议等待时间（取最早的槽位还剩多久到 10 分钟）
        $oldest = db_val('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) 
                          FROM concurrency_slots 
                          WHERE slot_key = ? 
                          ORDER BY created_at ASC 
                          LIMIT 1', [$key]);
        $wait = max(1, 600 - (int)$oldest);  // 10分钟 - 已占用时间
        return ['ok' => false, 'token' => null, 'wait' => $wait];
    }

    // 生成唯一令牌
    $token = bin2hex(random_bytes(16));

    // 尝试插入槽位
    try {
        db_exec('INSERT INTO concurrency_slots (slot_key, token, created_at) VALUES (?, ?, NOW())',
            [$key, $token]);
        return ['ok' => true, 'token' => $token, 'wait' => 0];
    } catch (Throwable $e) {
        // 插入失败（并发竞争），重新检查
        $current = (int) db_val('SELECT COUNT(*) FROM concurrency_slots WHERE slot_key = ?', [$key]);
        if ($current >= $limit) {
            return ['ok' => false, 'token' => null, 'wait' => 5];
        }
        // 再试一次
        try {
            $token = bin2hex(random_bytes(16));
            db_exec('INSERT INTO concurrency_slots (slot_key, token, created_at) VALUES (?, ?, NOW())',
                [$key, $token]);
            return ['ok' => true, 'token' => $token, 'wait' => 0];
        } catch (Throwable $e2) {
            return ['ok' => false, 'token' => null, 'wait' => 3];
        }
    }
}

/**
 * 释放并发槽位（退出临界区）
 * 
 * @param string|null $token 获取时返回的令牌
 */
function concurrency_release(?string $token): void
{
    if ($token === null || $token === '') {
        return;
    }
    try {
        db_exec('DELETE FROM concurrency_slots WHERE token = ? LIMIT 1', [$token]);
    } catch (Throwable $e) {
        error_log('[concurrency] 释放槽位失败: ' . $e->getMessage());
    }
}

/**
 * 获取当前并发统计
 * 
 * @return array 按 slot_key 分组的并发数统计
 */
function concurrency_stats(): array
{
    // 先清理超时的
    db_exec('DELETE FROM concurrency_slots WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    
    $rows = db_all('SELECT slot_key, COUNT(*) as count, MIN(created_at) as oldest, MAX(created_at) as newest
                    FROM concurrency_slots 
                    GROUP BY slot_key 
                    ORDER BY count DESC 
                    LIMIT 50');
    return $rows ?: [];
}

/**
 * 检查每分钟限流
 * 
 * @param string $mode 'sk' 或 'user'
 * @param int    $limit 每分钟请求上限（0=不限制）
 * @param int    $channelId 渠道 ID（按 SK 模式时必填）
 * @param int    $skIndex SK 下标（按 SK 模式时必填）
 * @param int    $userId 用户 ID（按用户模式时必填）
 * @return array ['ok' => bool, 'current' => int, 'wait' => int]
 *               ok: 是否通过限流检查
 *               current: 当前一分钟内的请求数
 *               wait: 失败时建议等待的秒数
 */
function concurrency_rate_limit(string $mode, int $limit, int $channelId = 0, int $skIndex = 0, int $userId = 0): array
{
    if ($limit <= 0) {
        return ['ok' => true, 'current' => 0, 'wait' => 0];
    }

    $key = '';
    if ($mode === 'sk') {
        if ($channelId <= 0 || $skIndex < 0) {
            return ['ok' => false, 'current' => 0, 'wait' => 0];
        }
        $key = "ch{$channelId}_sk{$skIndex}";
    } elseif ($mode === 'user') {
        if ($userId <= 0) {
            return ['ok' => false, 'current' => 0, 'wait' => 0];
        }
        $key = "user{$userId}";
    } else {
        return ['ok' => false, 'current' => 0, 'wait' => 0];
    }

    // 清理 1 分钟前的记录
    db_exec('DELETE FROM concurrency_rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)');

    // 查询最近 1 分钟的请求数
    $current = (int) db_val('SELECT COUNT(*) FROM concurrency_rate_limits 
                              WHERE rate_key = ? 
                              AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)', [$key]);

    if ($current >= $limit) {
        // 已超限，计算最早的记录还剩多久过期
        $oldest = db_val('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) 
                          FROM concurrency_rate_limits 
                          WHERE rate_key = ? 
                          ORDER BY created_at ASC 
                          LIMIT 1', [$key]);
        $wait = max(1, 60 - (int)$oldest);  // 60秒 - 已过去的时间
        return ['ok' => false, 'current' => $current, 'wait' => $wait];
    }

    // 记录本次请求
    try {
        db_exec('INSERT INTO concurrency_rate_limits (rate_key, created_at) VALUES (?, NOW())', [$key]);
        return ['ok' => true, 'current' => $current + 1, 'wait' => 0];
    } catch (Throwable $e) {
        error_log('[concurrency] 记录限流失败: ' . $e->getMessage());
        // 记录失败不影响业务，直接放行
        return ['ok' => true, 'current' => $current, 'wait' => 0];
    }
}

/**
 * 获取限流统计
 * 
 * @return array 按 rate_key 分组的请求数统计
 */
function concurrency_rate_stats(): array
{
    // 先清理 1 分钟前的记录
    db_exec('DELETE FROM concurrency_rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    
    $rows = db_all('SELECT rate_key, COUNT(*) as count, MIN(created_at) as oldest, MAX(created_at) as newest
                    FROM concurrency_rate_limits 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                    GROUP BY rate_key 
                    ORDER BY count DESC 
                    LIMIT 50');
    return $rows ?: [];
}
