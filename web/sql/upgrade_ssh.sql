-- SSH 远程执行功能建表（在已有库上执行，可重复执行）
-- 用户登记自己的服务器，AI 经用户确认后可在其上执行命令

-- 用户登记的服务器
CREATE TABLE IF NOT EXISTS `ssh_hosts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '归属用户，越权校验的依据',
  `name` VARCHAR(80) NOT NULL COMMENT '备注名，如「我的测试机」',
  `host` VARCHAR(255) NOT NULL COMMENT '域名或公网IP',
  `port` SMALLINT UNSIGNED NOT NULL DEFAULT 22,
  `username` VARCHAR(64) NOT NULL,
  `auth_type` ENUM('password','key') NOT NULL DEFAULT 'key',
  `secret_enc` TEXT NOT NULL COMMENT '密码或私钥，AES-256-GCM 加密后存储',
  `key_pass_enc` TEXT NOT NULL COMMENT '私钥口令，同样加密；无口令则空',
  `fingerprint` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '首次连接记录的主机指纹，之后变更即拒连',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `last_ok_at` DATETIME DEFAULT NULL COMMENT '最近一次连通时间',
  `last_error` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 命令执行审计日志，只增不改
CREATE TABLE IF NOT EXISTS `ssh_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `host_id` INT UNSIGNED NOT NULL,
  `conv_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的对话，0=非对话触发',
  `command` TEXT NOT NULL COMMENT '实际执行的命令原文',
  `risk` ENUM('safe','write','denied') NOT NULL DEFAULT 'safe' COMMENT 'safe只读 write写操作 denied被拒绝',
  `deny_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '被拒原因',
  `exit_code` INT NOT NULL DEFAULT -1,
  `output` MEDIUMTEXT NOT NULL COMMENT '合并后的输出，已截断',
  `duration_ms` INT NOT NULL DEFAULT 0,
  `client_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_host` (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 待确认的命令。AI 提出后先落这里，用户点确认才执行
CREATE TABLE IF NOT EXISTS `ssh_pending` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` CHAR(32) NOT NULL COMMENT '一次性令牌，前端凭它确认',
  `user_id` INT UNSIGNED NOT NULL,
  `host_id` INT UNSIGNED NOT NULL,
  `conv_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `command` TEXT NOT NULL,
  `risk` ENUM('safe','write') NOT NULL DEFAULT 'safe',
  `state` ENUM('wait','done','cancel','expired') NOT NULL DEFAULT 'wait',
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL COMMENT '过期即失效，默认 5 分钟',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_user_state` (`user_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
