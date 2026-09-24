-- ========== 项目化改造 ==========
-- 侧栏从「一列对话」改成「项目 → 该项目下的对话」两层结构。
-- 项目保存共用档案（绑定服务器、部署目录、技术栈、说明），
-- 这些信息会注入该项目下每一条对话的系统提示，AI 因此知道往哪台机器、哪个目录干活。

CREATE TABLE IF NOT EXISTS `projects` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) unsigned NOT NULL COMMENT '归属用户，严格隔离',
  `name`        varchar(80)  NOT NULL DEFAULT '' COMMENT '项目名称',
  `intro`       varchar(500) NOT NULL DEFAULT '' COMMENT '项目说明，会进 AI 上下文',
  `stack`       varchar(200) NOT NULL DEFAULT '' COMMENT '技术栈，如 PHP+MySQL',
  `host_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '绑定的 ssh_hosts.id，0 为未绑定',
  `deploy_dir`  varchar(255) NOT NULL DEFAULT '' COMMENT '部署目录，如 /www/wwwroot/abc',
  `site_url`    varchar(255) NOT NULL DEFAULT '' COMMENT '站点访问地址',
  `color`       varchar(16)  NOT NULL DEFAULT '' COMMENT '侧栏色标',
  `pinned`      tinyint(1)   NOT NULL DEFAULT 0 COMMENT '1 置顶',
  `archived`    tinyint(1)   NOT NULL DEFAULT 0 COMMENT '1 已归档，默认不显示',
  `conv_count`  int(11)      NOT NULL DEFAULT 0 COMMENT '对话数，冗余计数',
  `created_at`  datetime     NOT NULL,
  `updated_at`  datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `archived`, `pinned`, `updated_at`),
  KEY `idx_host` (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开发项目';

-- 对话挂到项目下。project_id=0 的是改造前的历史对话，归入「未分类」。
ALTER TABLE `conversations`
  ADD COLUMN `project_id` int(10) unsigned NOT NULL DEFAULT 0
      COMMENT '所属项目，0 表示未分类' AFTER `user_id`,
  ADD KEY `idx_proj` (`project_id`, `updated_at`);
