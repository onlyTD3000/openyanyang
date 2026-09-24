-- ========== 缓存 token 单独记账 ==========
-- 背景：计费早就区分缓存价了（calc_cost 的 $tcache 参数），但库里只存了
-- tokens_in / tokens_out 两个数。tokens_in 是输入总量、含缓存命中的部分，
-- 于是气泡下方那行「输入 12000 · 输出 800 · ￥0.03」里，用户看到的输入量很大、
-- 费用却很低，对不上账，只能猜是不是算错了。
--
-- 加一列 tokens_cache 把命中量单独存下来，展示时和输入并列成独立一段。
-- 不从 tokens_in 里扣掉：tokens_in 作为「这轮送了多少上下文」的口径没变，
-- 后台统计、用量页、余额扣减全都依赖它，动了要连带改一大片。
--
-- 三张表都要加：messages 供历史消息回显，chat_runs 供刷新后重连取末尾状态，
-- usage_logs 供后台核账。老数据留 0，展示时按 0 处理即不显示，行为不变。
--
-- 可重复执行：字段已存在时跳过。

DROP PROCEDURE IF EXISTS `tk_add_col`;
DELIMITER $$
CREATE PROCEDURE `tk_add_col`(IN t VARCHAR(64), IN c VARCHAR(64), IN d TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', d);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

SET @def = "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '输入中命中提示词缓存的量，已含在 tokens_in 里'";

CALL tk_add_col('messages',   'tokens_cache', @def);
CALL tk_add_col('chat_runs',  'tokens_cache', @def);
CALL tk_add_col('usage_logs', 'tokens_cache', @def);

DROP PROCEDURE IF EXISTS `tk_add_col`;
