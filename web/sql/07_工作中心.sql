-- ========== 工作中心：文件与图片合库 ==========
-- 原先 uploads 只存对话里发的图片，AI 产出的文件（代码、文档、报表）没有落点。
-- 现在把它扩成「每个账号的统一文件库」：图片和文件同一张表、同一个列表，
-- 空间也按这一张表算，不会出现两处统计对不上账的情况。
--
-- 隔离原则（重要）：所有读写一律以 user_id 为条件，
-- 账号 A 的 AI 只能碰 user_id=A 的记录，落盘目录也按 用户id/年月 分开。
-- 库里 name 是账号内的虚拟相对路径（如 报价单.md、src/app.py），
-- 真实文件名是随机串，避免用户传 .php 之类可执行后缀被直接访问。
--
-- 执行方式：在数据库里跑一遍本文件即可，字段已存在时会跳过（见下方存储过程）。

-- ---------- uploads 扩字段 ----------
-- MySQL 5.7 不支持 ADD COLUMN IF NOT EXISTS，用存储过程逐个判断，可重复执行。
DROP PROCEDURE IF EXISTS `ws_add_col`;
DELIMITER $$
CREATE PROCEDURE `ws_add_col`(IN t VARCHAR(64), IN c VARCHAR(64), IN d TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', d);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

-- name：账号内的虚拟相对路径，也是展示名。AI 用它读写文件。
CALL ws_add_col('uploads', 'name',
  "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '账号内虚拟相对路径，兼展示名'");
-- kind：image 图片 / text 可编辑文本 / bin 其它二进制
CALL ws_add_col('uploads', 'kind',
  "VARCHAR(10) NOT NULL DEFAULT 'image' COMMENT 'image/text/bin'");
-- source：文件来路，用于界面上区分是谁产出的
CALL ws_add_col('uploads', 'source',
  "VARCHAR(10) NOT NULL DEFAULT 'user' COMMENT 'user 用户上传 / ai AI 产出'");
CALL ws_add_col('uploads', 'note',
  "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注，AI 写文件时说明用途'");
CALL ws_add_col('uploads', 'updated_at',
  "DATETIME DEFAULT NULL COMMENT '最后一次修改时间'");
-- ver：被改写过几次，纯计数，方便界面提示
CALL ws_add_col('uploads', 'ver',
  "INT NOT NULL DEFAULT 1 COMMENT '修改次数'");

-- 账号内同名唯一：AI 按 name 写文件时靠它做「已存在就覆盖」判定
DROP PROCEDURE IF EXISTS `ws_add_idx`;
DELIMITER $$
CREATE PROCEDURE `ws_add_idx`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads'
                   AND INDEX_NAME = 'idx_user_name') THEN
    ALTER TABLE `uploads` ADD INDEX `idx_user_name` (`user_id`, `name`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads'
                   AND INDEX_NAME = 'idx_user_kind') THEN
    ALTER TABLE `uploads` ADD INDEX `idx_user_kind` (`user_id`, `kind`, `id`);
  END IF;
END$$
DELIMITER ;
CALL ws_add_idx();

-- 老图片补上展示名：原来只有随机路径，取文件名部分当 name
UPDATE `uploads` SET `name` = SUBSTRING_INDEX(`path`, '/', -1)
 WHERE `name` = '' OR `name` IS NULL;
UPDATE `uploads` SET `updated_at` = `created_at` WHERE `updated_at` IS NULL;

-- ---------- 每账号空间配额 ----------
-- 0 表示沿用后台全局默认值（settings.upload_quota_mb），
-- 大于 0 表示这个账号单独限额，后台用户编辑页可改。
CALL ws_add_col('users', 'space_quota_mb',
  "INT NOT NULL DEFAULT 0 COMMENT '工作中心空间上限MB，0=用全局默认'");

DROP PROCEDURE IF EXISTS `ws_add_col`;
DROP PROCEDURE IF EXISTS `ws_add_idx`;
