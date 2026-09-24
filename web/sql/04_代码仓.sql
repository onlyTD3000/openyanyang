-- ========== 工作中心：客户代码仓 ==========
-- 目标流程：把客户服务器上的代码拉到工作中心 → AI 在本地副本上改 → 回传到服务器。
--
-- 为什么要有本地副本：原先 AI 只能用 ssh-exec 直接在线上敲命令改代码，
-- 改动没有可比对的基线，出错只能靠 .bak 文件补救。有了本地副本，
-- 每次改动都留版本、可 diff、可一键回滚，回传前还能预演。
--
-- 文件正文不进数据库，落在 DATA_DIR/projects/<用户id>/<项目id>/ 下，
-- 该目录在网站根目录之外但在 open_basedir 白名单内，URL 直接访问不到。
-- 数据库只存元信息与哈希，避免大表拖慢查询。

-- 代码仓：一个项目一个仓，与 projects 一对一
CREATE TABLE IF NOT EXISTS `repos` (
  `id`           int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`      int(10) unsigned NOT NULL COMMENT '归属用户，严格隔离',
  `project_id`   int(10) unsigned NOT NULL COMMENT '所属项目',
  `host_id`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT '来源主机 ssh_hosts.id',
  `remote_dir`   varchar(255) NOT NULL DEFAULT '' COMMENT '远程根目录',
  `file_count`   int(11)      NOT NULL DEFAULT 0 COMMENT '文件数，冗余计数',
  `total_bytes`  bigint(20)   NOT NULL DEFAULT 0 COMMENT '占用字节',
  `last_pull_at` datetime     DEFAULT NULL COMMENT '最近一次拉取时间',
  `last_push_at` datetime     DEFAULT NULL COMMENT '最近一次回传时间',
  `created_at`   datetime     NOT NULL,
  `updated_at`   datetime     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_proj` (`project_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目代码仓';

-- 文件清单。path 是相对仓根的相对路径，如 inc/db.php
CREATE TABLE IF NOT EXISTS `repo_files` (
  `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `repo_id`     int(10) unsigned NOT NULL,
  `user_id`     int(10) unsigned NOT NULL COMMENT '冗余，便于带 user_id 直查',
  `path`        varchar(500) NOT NULL COMMENT '相对仓根路径',
  `size`        int(10) unsigned NOT NULL DEFAULT 0,
  `hash`        char(40)     NOT NULL DEFAULT '' COMMENT '本地内容 sha1',
  `remote_hash` char(40)     NOT NULL DEFAULT '' COMMENT '拉取时的远端 sha1，用于判断本地是否改过',
  `is_text`     tinyint(1)   NOT NULL DEFAULT 1 COMMENT '0=二进制，不给 AI 看正文',
  `state`       varchar(12)  NOT NULL DEFAULT 'same'
                COMMENT 'same 与远端一致 / edited 本地已改 / new 本地新增 / gone 远端已删',
  `ver`         int(11)      NOT NULL DEFAULT 0 COMMENT '本地改动次数',
  `updated_at`  datetime     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_repo_path` (`repo_id`, `path`),
  KEY `idx_user` (`user_id`),
  KEY `idx_state` (`repo_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代码仓文件清单';

-- 版本历史。每次覆写/打补丁前把旧内容存一份，用于 diff 与回滚。
-- 正文存盘（DATA_DIR/.../_版本/<file_id>/<ver>），库里只留元信息。
CREATE TABLE IF NOT EXISTS `repo_versions` (
  `id`         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `file_id`    bigint(20) unsigned NOT NULL,
  `repo_id`    int(10) unsigned NOT NULL,
  `user_id`    int(10) unsigned NOT NULL,
  `ver`        int(11)      NOT NULL COMMENT '第几版，从 1 开始',
  `size`       int(10) unsigned NOT NULL DEFAULT 0,
  `hash`       char(40)     NOT NULL DEFAULT '',
  `action`     varchar(12)  NOT NULL DEFAULT 'write' COMMENT 'pull/write/patch/rollback',
  `conv_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '哪条对话改的，便于追溯',
  `note`       varchar(255) NOT NULL DEFAULT '' COMMENT '改动说明，AI 填',
  `created_at` datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_file` (`file_id`, `ver`),
  KEY `idx_repo` (`repo_id`, `created_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件版本历史';

-- 同步日志：拉取与回传都记一笔，出问题能回溯是哪次同步导致的
CREATE TABLE IF NOT EXISTS `repo_syncs` (
  `id`         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `repo_id`    int(10) unsigned NOT NULL,
  `user_id`    int(10) unsigned NOT NULL,
  `direction`  varchar(8)   NOT NULL COMMENT 'pull 拉取 / push 回传',
  `ok`         tinyint(1)   NOT NULL DEFAULT 0,
  `file_count` int(11)      NOT NULL DEFAULT 0 COMMENT '本次涉及文件数',
  `bytes`      bigint(20)   NOT NULL DEFAULT 0,
  `backup`     varchar(255) NOT NULL DEFAULT '' COMMENT '回传前在服务器上生成的备份路径',
  `detail`     text         COMMENT '文件清单与错误详情',
  `ms`         int(11)      NOT NULL DEFAULT 0,
  `client_ip`  varchar(45)  NOT NULL DEFAULT '',
  `created_at` datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_repo` (`repo_id`, `created_at`),
  KEY `idx_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代码仓同步日志';
