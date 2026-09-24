-- ============================================================
-- 升级脚本：图片上传（多模态对话）支持
-- 适用于已经跑起来的旧库，在数据库里执行一次即可。
-- 全新安装用 schema.sql，无需再跑本文件。
-- MySQL 5.7 / utf8mb4
-- ============================================================

-- 1) 模型表增加「视觉模型」开关：只有开启的模型，前台才允许上传图片
ALTER TABLE `models`
  ADD COLUMN `vision` TINYINT NOT NULL DEFAULT 0 COMMENT '1=支持图片输入' AFTER `max_context`;

-- 2) 消息表增加图片字段：存 uploads 表的 id 数组，如 [12,13]
ALTER TABLE `messages`
  ADD COLUMN `images` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'JSON: 关联的 uploads.id' AFTER `content`;

-- 3) 上传文件表。文件实体存在 webroot 之外，只能通过 api/img.php 鉴权后读取
CREATE TABLE IF NOT EXISTS `uploads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `conv_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=还未附到会话',
  `path` VARCHAR(255) NOT NULL COMMENT '相对 UPLOAD_DIR 的路径',
  `mime` VARCHAR(60) NOT NULL DEFAULT '',
  `size` INT UNSIGNED NOT NULL DEFAULT 0,
  `width` INT NOT NULL DEFAULT 0,
  `height` INT NOT NULL DEFAULT 0,
  `used` TINYINT NOT NULL DEFAULT 0 COMMENT '1=已在某条消息中使用',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`id`),
  KEY `idx_conv` (`conv_id`),
  KEY `idx_gc` (`used`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) 图片相关的默认设置项
INSERT INTO `settings` (`k`, `v`) VALUES
  ('upload_max_mb', '5'),
  ('upload_max_num', '4')
ON DUPLICATE KEY UPDATE `k` = `k`;

-- 5) 存储配额（防止批量上传把磁盘占满）
INSERT INTO `settings` (`k`, `v`) VALUES
  ('upload_quota_mb',    '200'),
  ('upload_pending_max', '20')
ON DUPLICATE KEY UPDATE `k` = `k`;
