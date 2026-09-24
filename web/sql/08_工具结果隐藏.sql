-- ========== 工具结果消息不占聊天气泡 ==========
-- 背景：SFTP / 命令执行 / 工作中心的操作结果，原先是塞进输入框再当普通用户消息发出的。
-- AI 必须看到这些结果才能接着往下做，但它们以「我」的身份出现在聊天里，
-- 用户看到的是自己发了一大段文件内容，而 AI 上一条已经把同样的事说过一遍，重复且突兀。
--
-- 做法：消息照旧入库、照旧进 AI 上下文，但打上 hidden=1，聊天列表不渲染它。
-- 不改 role：这类结果在语义上确实是「反馈给 AI 的用户侧输入」，
-- 换成 system 会让部分上游（Claude）拒收对话中途的 system 消息。
--
-- 可重复执行：字段已存在时跳过。

DROP PROCEDURE IF EXISTS `msg_add_col`;
DELIMITER $$
CREATE PROCEDURE `msg_add_col`(IN t VARCHAR(64), IN c VARCHAR(64), IN d TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', d);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

-- hidden：1 表示这条是工具回执，进 AI 上下文但不在聊天界面显示
CALL msg_add_col('messages', 'hidden',
  "TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=工具回执，不在聊天界面渲染'");

DROP PROCEDURE IF EXISTS `msg_add_col`;
