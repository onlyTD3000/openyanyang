-- 并发控制槽位表
CREATE TABLE IF NOT EXISTS `concurrency_slots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slot_key` VARCHAR(100) NOT NULL COMMENT '槽位键：ch{渠道ID}_sk{SK下标} 或 user{用户ID}',
  `token` VARCHAR(64) NOT NULL COMMENT '释放令牌',
  `created_at` DATETIME NOT NULL COMMENT '占用时间',
  UNIQUE KEY `idx_token` (`token`),
  KEY `idx_slot_key` (`slot_key`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='并发控制槽位';
