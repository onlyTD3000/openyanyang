-- ========== 直连 SFTP 编辑留档 ==========
-- 命令行改文件（sed -i / heredoc / > 重定向）已在 ssh_guard 里全部拒绝，
-- 改文件统一走 SFTP。走 SFTP 有两条路：
--   1. 代码仓：批量拉到工作中心改完再回传，有完整版本树（repos / repo_files）。
--   2. 直连编辑：只改一两个文件时直接读远端、改、存回，就是本表记录的场景。
--
-- 直连编辑没有本地副本做基线，所以每次写入前必须留档：
-- 远端存一份 .kiro_backup/ 备份，库里再存一份 old_text。
-- 远端备份可能被客户清理，库里这份是最后的退路，能一键还原。
--
-- old_text / new_text 截到 20 万字符入库，超大文件本来就不适合在线改，
-- 真要改这种文件应该走代码仓那条路。

CREATE TABLE IF NOT EXISTS `sftp_edits` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) unsigned NOT NULL COMMENT '归属用户，严格隔离',
  `project_id`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属项目',
  `host_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '目标主机 ssh_hosts.id',
  `conv_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '触发本次编辑的对话',
  `path`        varchar(512) NOT NULL DEFAULT '' COMMENT '远端绝对路径',
  `action`      varchar(16)  NOT NULL DEFAULT 'write' COMMENT 'write/patch/restore',
  `old_text`    mediumtext   COMMENT '改动前内容，用于还原',
  `new_text`    mediumtext   COMMENT '改动后内容，用于比对',
  `backup_path` varchar(512) NOT NULL DEFAULT '' COMMENT '远端备份文件路径',
  `note`        varchar(255) NOT NULL DEFAULT '' COMMENT '本次改动说明',
  `created_at`  datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_proj` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='直连 SFTP 编辑留档';
