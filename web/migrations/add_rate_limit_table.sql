-- 并发限流表（每分钟请求计数）
CREATE TABLE IF NOT EXISTS `concurrency_rate_limits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rate_key` VARCHAR(100) NOT NULL COMMENT '限流键：ch{渠道ID}_sk{SK下标} 或 user{用户ID}',
  `created_at` DATETIME NOT NULL COMMENT '请求时间',
  KEY `idx_rate_key` (`rate_key`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='并发限流记录表，用于每分钟请求数统计';
