-- 生成任务表：让「刷新页面后回答不丢」成立。
--
-- 背景：chat.php 里已经有 ignore_user_abort(true)，浏览器断开后 PHP 仍会跑到底，
-- 最终答案照样落进 messages。但生成过程中的增量原先只存在内存里，
-- 用户刷新后既看不到已经生成的部分，也不知道这轮还在跑，只能干等。
--
-- 这张表就是那份增量的落脚点：流式回调每攒够一批就写一次 answer，
-- 前端重进页面时先查有没有 running 的任务，有就把 answer 一次性铺上，
-- 再轮询补齐剩下的部分。任务结束后保留一小段时间，供刷新后读取末尾状态。
CREATE TABLE IF NOT EXISTS chat_runs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  conv_id      INT UNSIGNED NOT NULL,
  -- running=生成中，done=正常收尾，error=上游失败，dead=进程没了（由读取方判定）
  status       ENUM('running','done','error','dead') NOT NULL DEFAULT 'running',
  -- 已生成的正文。MEDIUMTEXT 与 messages.content 对齐，装得下长回答
  answer       MEDIUMTEXT NULL,
  -- 后端判定的思考前言原文，前端靠它把开头折叠起来，和 SSE 的 fold 事件同源
  fold         TEXT NULL,
  err_msg      VARCHAR(500) NOT NULL DEFAULT '',
  tokens_in    INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_out   INT UNSIGNED NOT NULL DEFAULT 0,
  cost         DECIMAL(10,6) NOT NULL DEFAULT 0,
  is_estimated TINYINT(1) NOT NULL DEFAULT 0,
  balance      DECIMAL(12,4) NOT NULL DEFAULT 0,
  -- 落库后的 messages.id，前端拿它对齐历史记录，避免重复渲染同一条回答
  msg_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL,
  -- 心跳。每次写增量都会更新，读取方据此判断进程是否已经死掉
  beat_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  -- 重进页面时按 (user_id, conv_id, status) 找当前还在跑的任务，走这条索引
  KEY idx_user_conv (user_id, conv_id, status),
  -- 清理过期任务用
  KEY idx_beat (beat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
