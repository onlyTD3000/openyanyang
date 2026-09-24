-- 云 AI Agent 平台 数据库结构 (MySQL 5.7 / utf8mb4)

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(120) NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('user','admin') NOT NULL DEFAULT 'user',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `balance` DECIMAL(14,6) NOT NULL DEFAULT 0 COMMENT '余额(元)',
  `token_quota` BIGINT NOT NULL DEFAULT 0 COMMENT '总token上限,0=不限',
  `used_tokens` BIGINT NOT NULL DEFAULT 0,
  `total_cost` DECIMAL(14,6) NOT NULL DEFAULT 0,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL COMMENT '渠道名',
  `base_url` VARCHAR(255) NOT NULL COMMENT '如 https://api.xxx.com/v1',
  `api_key` TEXT NOT NULL,
  `chat_path` VARCHAR(120) NOT NULL DEFAULT '/chat/completions',
  `timeout` INT NOT NULL DEFAULT 120,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 0,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `models` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_id` INT UNSIGNED NOT NULL,
  `display_name` VARCHAR(120) NOT NULL COMMENT '前台显示名',
  `model_name` VARCHAR(120) NOT NULL COMMENT '上游真实模型名',
  `price_in` DECIMAL(12,6) NOT NULL DEFAULT 0 COMMENT '输入价 元/百万token',
  `price_out` DECIMAL(12,6) NOT NULL DEFAULT 0 COMMENT '输出价 元/百万token',
  `price_cache` DECIMAL(12,6) NOT NULL DEFAULT 0 COMMENT '缓存命中输入价 元/百万token，0=不区分按输入价',
  `max_context` INT NOT NULL DEFAULT 20 COMMENT '带入上下文条数',
  `vision` TINYINT NOT NULL DEFAULT 0 COMMENT '1=支持图片输入',
  `system_prompt` TEXT,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_channel` (`channel_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `model_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(160) NOT NULL DEFAULT '新对话',
  `msg_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conv_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('user','assistant','system') NOT NULL,
  `content` LONGTEXT NOT NULL,
  `images` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'JSON: 关联的 uploads.id',
  `tokens_in` INT NOT NULL DEFAULT 0,
  `tokens_out` INT NOT NULL DEFAULT 0,
  `cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conv_id`,`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uploads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `conv_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `path` VARCHAR(255) NOT NULL COMMENT '相对 UPLOAD_DIR 的路径',
  -- Office 文档的 MIME 很长（pptx 就有 73 字符），60 装不下
  `mime` VARCHAR(120) NOT NULL DEFAULT '',
  `size` INT UNSIGNED NOT NULL DEFAULT 0,
  `width` INT NOT NULL DEFAULT 0,
  `height` INT NOT NULL DEFAULT 0,
  `used` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`id`),
  KEY `idx_conv` (`conv_id`),
  KEY `idx_gc` (`used`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usage_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `conv_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `channel_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `model_name` VARCHAR(120) NOT NULL DEFAULT '',
  `tokens_in` INT NOT NULL DEFAULT 0,
  `tokens_out` INT NOT NULL DEFAULT 0,
  `cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
  `is_estimated` TINYINT NOT NULL DEFAULT 0,
  `status` VARCHAR(10) NOT NULL DEFAULT 'ok',
  `error_msg` VARCHAR(500) NOT NULL DEFAULT '',
  `latency_ms` INT NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`created_at`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `balance_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,6) NOT NULL,
  `balance_after` DECIMAL(14,6) NOT NULL,
  `type` VARCHAR(30) NOT NULL DEFAULT 'admin',
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `admin_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(60) NOT NULL,
  `v` TEXT,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 管理员查看用户对话的审计留痕。高权限操作必须可追溯。
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(40) NOT NULL COMMENT 'view_conv / view_chat_list',
  `target_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `target_conv_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`,`created_at`),
  KEY `idx_target` (`target_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 网络终端的一次性握手令牌。
-- ws 协议没法带 Cookie 做鉴权（浏览器 WebSocket API 不允许自定义头），
-- 所以先由已登录的 PHP 接口发一张短时效令牌，ws 服务端凭令牌确认身份和主机归属。
CREATE TABLE IF NOT EXISTS `wsssh_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` CHAR(64) NOT NULL COMMENT '令牌明文的 sha256，库里不存明文',
  `user_id` INT UNSIGNED NOT NULL,
  `host_id` INT UNSIGNED NOT NULL COMMENT '这张令牌只能开这一台机器',
  `used` TINYINT NOT NULL DEFAULT 0 COMMENT '用过即废，防重放',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
