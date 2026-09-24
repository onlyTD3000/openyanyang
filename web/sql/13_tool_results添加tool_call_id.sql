-- 为 tool_results 表添加 tool_call_id 字段，用于断点重续
-- 存储 Function Calling 工具调用的 ID，方便断点重续时查询

ALTER TABLE tool_results 
ADD COLUMN tool_call_id VARCHAR(255) NULL DEFAULT NULL COMMENT 'Function Calling工具调用ID' AFTER after_msg_id,
ADD INDEX idx_tool_call (conv_id, tool_call_id);
