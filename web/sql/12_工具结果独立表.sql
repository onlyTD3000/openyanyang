-- ========== 工具回执迁出 messages，改存独立表 ==========
-- 背景：08 号脚本用 messages.hidden=1 把工具回执藏起来，但它仍以 role=user 躺在
-- messages 里。后果有三个：后台聊天记录被大段命令输出灌满（451 条隐藏行 0.61MB，
-- 是正常用户消息体积的 60 倍）；这些内容在语义上根本不是用户说的话；
-- 前端得伪造一条用户消息才能把结果送进下一轮。
--
-- 做法：回执落到 tool_results，按 after_msg_id 挂在触发它的那条 assistant 消息后面。
-- 拼上下文时再按顺序插回去当 user 轮，messages 表从此只存真实对话。
CREATE TABLE IF NOT EXISTS tool_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  conv_id INT UNSIGNED NOT NULL,
  after_msg_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '挂在哪条 assistant 消息之后，0=会话开头',
  kind VARCHAR(16) NOT NULL DEFAULT 'other' COMMENT 'ssh/sftp/repo/ws/ppt/other',
  content MEDIUMTEXT NOT NULL COMMENT '回执正文，已在前端截断',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_conv (conv_id, after_msg_id, id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
