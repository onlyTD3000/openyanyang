-- 岩羊AI 平台数据库（干净版）-- 由 8800demo 脱敏生成

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `aff_backfill_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `aff_backfill_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '被绑定的下级用户',
  `referrer_id` int(10) unsigned NOT NULL COMMENT '绑定到的推介人',
  `admin_id` int(10) unsigned NOT NULL COMMENT '执行操作的管理员',
  `order_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '回溯到的订单笔数',
  `total_amount` decimal(14,6) NOT NULL DEFAULT '0.000000' COMMENT '补返总金额',
  `rate` decimal(6,3) NOT NULL DEFAULT '0.000' COMMENT '当时使用的返佣比例',
  `action` varchar(20) NOT NULL DEFAULT 'bind' COMMENT 'bind 绑定 / unbind 解绑',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_referrer` (`referrer_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COMMENT='后台手动绑定推介人的回溯记录';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aff_commissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `aff_commissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '获得返现的上级',
  `from_user_id` int(10) unsigned NOT NULL COMMENT '充值的下级',
  `order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `order_no` varchar(40) NOT NULL DEFAULT '',
  `recharge_amount` decimal(14,6) NOT NULL DEFAULT '0.000000' COMMENT '下级实付金额',
  `rate` decimal(6,3) NOT NULL DEFAULT '0.000' COMMENT '当时返现比例(%)',
  `amount` decimal(14,6) NOT NULL DEFAULT '0.000000' COMMENT '返现金额',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`order_no`),
  KEY `idx_user` (`user_id`,`id`),
  KEY `idx_from` (`from_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COMMENT='AFF推介返现记录';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_platforms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `api_platforms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL COMMENT 'NewAPI平台名称',
  `base_url` varchar(255) NOT NULL,
  `access_token` text NOT NULL,
  `api_user_id` varchar(40) NOT NULL DEFAULT '' COMMENT 'New-Api-User头，NewAPI型平台需要',
  `protocol` enum('openai','claude') NOT NULL DEFAULT 'claude',
  `chat_path` varchar(120) NOT NULL DEFAULT '/messages',
  `api_version` varchar(40) NOT NULL DEFAULT '2023-06-01',
  `timeout` int(11) NOT NULL DEFAULT '120',
  `sort` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `remark` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'view_conv / view_chat_list',
  `target_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `target_conv_id` int(10) unsigned NOT NULL DEFAULT '0',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`,`created_at`),
  KEY `idx_target` (`target_user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=820 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `selector` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `validator` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ua` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_selector` (`selector`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=278 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `balance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `balance_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `amount` decimal(14,6) NOT NULL,
  `balance_after` decimal(14,6) NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=9178 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `channel_sk_health`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `channel_sk_health` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `channel_id` int(10) unsigned NOT NULL COMMENT '所属子分类',
  `sk_index` int(11) NOT NULL COMMENT '第几个 SK（0 基）',
  `fail_count` int(11) NOT NULL DEFAULT '0' COMMENT '连续失败次数，成功即归零',
  `cooled_until` datetime DEFAULT NULL COMMENT '熔断到什么时候，NULL=正常',
  `last_http` int(11) NOT NULL DEFAULT '0' COMMENT '最后一次失败的 HTTP 码',
  `last_error` varchar(255) NOT NULL DEFAULT '' COMMENT '最后一次错误摘要',
  `updated_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL COMMENT '最近活跃时间（含 /v1 中转调用）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ch_sk` (`channel_id`,`sk_index`),
  KEY `idx_cooled` (`channel_id`,`cooled_until`)
) ENGINE=InnoDB AUTO_INCREMENT=643294 DEFAULT CHARSET=utf8mb4 COMMENT='SK 健康度与熔断状态';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `channels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned NOT NULL DEFAULT '0',
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'æ¸ é“å',
  `base_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'å¦‚ https://api.xxx.com/v1',
  `api_key` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `rotate` tinyint(4) NOT NULL DEFAULT '0',
  `rotate_idx` int(11) NOT NULL DEFAULT '0',
  `protocol` enum('openai','claude') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'openai' COMMENT 'ä¸Šæ¸¸åè®®ç±»åž‹',
  `chat_path` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '/chat/completions',
  `api_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '2023-06-01' COMMENT 'Claude ç”¨ anthropic-version',
  `timeout` int(11) NOT NULL DEFAULT '120',
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `sort` int(11) NOT NULL DEFAULT '0',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `tool_choice_downgrade` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'tool_choice required降级为auto开关',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `conv_id` int(10) unsigned NOT NULL,
  `status` enum('running','done','error','dead','stopped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `stop_req` tinyint(1) NOT NULL DEFAULT '0',
  `answer` mediumtext COLLATE utf8mb4_unicode_ci,
  `fold` text COLLATE utf8mb4_unicode_ci,
  `err_msg` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `tokens_in` int(10) unsigned NOT NULL DEFAULT '0',
  `tokens_out` int(10) unsigned NOT NULL DEFAULT '0',
  `cost` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `is_estimated` tinyint(1) NOT NULL DEFAULT '0',
  `balance` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `msg_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `beat_at` datetime NOT NULL,
  `tokens_cache` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '输入中命中提示词缓存的量，已含在 tokens_in 里',
  `tokens_cache_create` int(11) NOT NULL DEFAULT '0' COMMENT '缓存创建token数',
  PRIMARY KEY (`id`),
  KEY `idx_user_conv` (`user_id`,`conv_id`,`status`),
  KEY `idx_beat` (`beat_at`)
) ENGINE=InnoDB AUTO_INCREMENT=26115 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `concurrency_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `concurrency_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rate_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '限流键：ch{渠道ID}_sk{SK下标} 或 user{用户ID}',
  `created_at` datetime NOT NULL COMMENT '请求时间',
  PRIMARY KEY (`id`),
  KEY `idx_rate_key` (`rate_key`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=41045 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='并发限流记录表，用于每分钟请求数统计';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `concurrency_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `concurrency_slots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slot_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '槽位键：ch{渠道ID}_sk{SK下标} 或 user{用户ID}',
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '释放令牌',
  `created_at` datetime NOT NULL COMMENT '占用时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token` (`token`),
  KEY `idx_slot_key` (`slot_key`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=27287 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='并发控制槽位';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `project_id` int(10) unsigned NOT NULL DEFAULT '0',
  `model_id` int(10) unsigned NOT NULL DEFAULT '0',
  `context_limit` smallint(6) NOT NULL DEFAULT '0' COMMENT '会话级上下文条数：0=跟随模型默认(models.max_context)，2..60=自定义',
  `sk_index` int(11) DEFAULT NULL COMMENT 'SK轮询绑定下标,NULL=未分配',
  `lang_follow` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=跟随提问语言，0=只用简体中文',
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'æ–°å¯¹è¯',
  `msg_count` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`updated_at`),
  KEY `idx_proj` (`project_id`,`updated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=443 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '关联用户 ID',
  `to_email` varchar(255) NOT NULL COMMENT '收件人邮箱',
  `subject` varchar(500) NOT NULL DEFAULT '' COMMENT '邮件主题',
  `body` mediumtext COMMENT '邮件正文（HTML）',
  `reason` varchar(100) NOT NULL DEFAULT '' COMMENT '发送原因',
  `status` enum('pending','ok','fail') NOT NULL DEFAULT 'pending' COMMENT '发送状态',
  `error_msg` varchar(500) NOT NULL DEFAULT '' COMMENT '错误信息',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_reason` (`reason`)
) ENGINE=InnoDB AUTO_INCREMENT=1736 DEFAULT CHARSET=utf8mb4 COMMENT='邮件发送日志';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_verifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '关联用户 ID',
  `email` varchar(255) NOT NULL COMMENT '验证邮箱',
  `code` varchar(10) NOT NULL COMMENT '6 位验证码',
  `scene` varchar(20) NOT NULL DEFAULT 'register' COMMENT '用途:register 注册 / login 登录',
  `expires_at` datetime NOT NULL COMMENT '过期时间',
  `used` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=已使用,防重复',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '校验失败次数,防爆破',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '申请来源IP',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_email_code` (`email`,`code`),
  KEY `idx_scene_email` (`scene`,`email`,`used`)
) ENGINE=InnoDB AUTO_INCREMENT=108 DEFAULT CHARSET=utf8mb4 COMMENT='邮箱验证码';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `intrusion_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `intrusion_blocks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `etype` varchar(32) NOT NULL DEFAULT '',
  `source` varchar(8) NOT NULL DEFAULT 'auto',
  `state` varchar(12) NOT NULL DEFAULT 'pending',
  `hits` int(11) NOT NULL DEFAULT '0',
  `err` varchar(255) NOT NULL DEFAULT '',
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip` (`ip`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB AUTO_INCREMENT=700 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `intrusion_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `intrusion_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `etype` varchar(32) NOT NULL DEFAULT '',
  `level` varchar(8) NOT NULL DEFAULT 'mid',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `target` varchar(255) NOT NULL DEFAULT '',
  `detail` text,
  `hits` int(11) NOT NULL DEFAULT '1',
  `blocked` tinyint(4) NOT NULL DEFAULT '0',
  `notified` tinyint(4) NOT NULL DEFAULT '0',
  `notify_err` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip`),
  KEY `idx_etype` (`etype`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1803 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ip_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ip_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` enum('deny','allow') NOT NULL COMMENT 'deny=黑名单 allow=白名单',
  `pattern` varchar(120) NOT NULL COMMENT 'IP/CIDR/域名',
  `note` varchar(200) NOT NULL DEFAULT '' COMMENT '备注',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int(10) unsigned DEFAULT NULL COMMENT '操作管理员ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kind_pattern` (`kind`,`pattern`),
  KEY `idx_kind_enabled` (`kind`,`enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_favorites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `msg_id` bigint(20) unsigned NOT NULL,
  `conv_id` int(10) unsigned NOT NULL,
  `role` enum('user','assistant') NOT NULL DEFAULT 'assistant',
  `content` longtext NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_msg` (`user_id`,`msg_id`),
  KEY `idx_user` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conv_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` enum('user','assistant','system') COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `images` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'JSON: 关联的 uploads.id',
  `tokens_in` int(11) NOT NULL DEFAULT '0',
  `tokens_out` int(11) NOT NULL DEFAULT '0',
  `cost` decimal(12,6) NOT NULL DEFAULT '0.000000',
  `created_at` datetime NOT NULL,
  `hidden` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '1=工具回执，不在聊天界面渲染',
  `tokens_cache` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '输入中命中提示词缓存的量，已含在 tokens_in 里',
  `tokens_cache_create` int(11) NOT NULL DEFAULT '0' COMMENT '缓存创建token数',
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conv_id`,`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=35526 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages_bak_toolrcpt`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_bak_toolrcpt` (
  `id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `conv_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` enum('user','assistant','system') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `images` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'JSON: 关联的 uploads.id',
  `tokens_in` int(11) NOT NULL DEFAULT '0',
  `tokens_out` int(11) NOT NULL DEFAULT '0',
  `cost` decimal(12,6) NOT NULL DEFAULT '0.000000',
  `created_at` datetime NOT NULL,
  `hidden` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '1=工具回执，不在聊天界面渲染',
  `tokens_cache` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '输入中命中提示词缓存的量，已含在 tokens_in 里',
  `tokens_cache_create` int(11) NOT NULL DEFAULT '0' COMMENT '缓存创建token数'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `models` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `channel_id` int(10) unsigned NOT NULL,
  `display_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'å‰å°æ˜¾ç¤ºå',
  `model_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ä¸Šæ¸¸çœŸå®žæ¨¡åž‹å',
  `price_in` decimal(12,6) NOT NULL DEFAULT '0.000000' COMMENT 'è¾“å…¥ä»· å…ƒ/ç™¾ä¸‡token',
  `price_out` decimal(12,6) NOT NULL DEFAULT '0.000000' COMMENT 'è¾“å‡ºä»· å…ƒ/ç™¾ä¸‡token',
  `price_cache` decimal(12,6) NOT NULL DEFAULT '0.000000' COMMENT '缓存命中输入价 元/百万token，0=不区分按输入价',
  `price_cache_create` decimal(12,6) NOT NULL DEFAULT '0.000000' COMMENT '缓存创建价(5m) 元/百万tokens',
  `max_context` int(11) NOT NULL DEFAULT '20' COMMENT 'å¸¦å…¥ä¸Šä¸‹æ–‡æ¡æ•°',
  `vision` tinyint(4) NOT NULL DEFAULT '0' COMMENT '1=支持图片输入',
  `max_tokens` int(11) NOT NULL DEFAULT '4096' COMMENT 'å•æ¬¡å›žå¤ä¸Šé™',
  `system_prompt` text COLLATE utf8mb4_unicode_ci,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `sort` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_channel` (`channel_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `projects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(80) NOT NULL DEFAULT '',
  `intro` varchar(500) NOT NULL DEFAULT '',
  `stack` varchar(200) NOT NULL DEFAULT '',
  `host_id` int(10) unsigned NOT NULL DEFAULT '0',
  `deploy_dir` varchar(255) NOT NULL DEFAULT '',
  `site_url` varchar(255) NOT NULL DEFAULT '',
  `color` varchar(16) NOT NULL DEFAULT '',
  `pinned` tinyint(1) NOT NULL DEFAULT '0',
  `archived` tinyint(1) NOT NULL DEFAULT '0',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=隐藏项目，本地项目对应的影子项目用，不在项目列表展示',
  `project_type` enum('cloud','local') NOT NULL DEFAULT 'cloud' COMMENT '项目类型：cloud云端，local客户端本地',
  `conv_count` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`archived`,`pinned`,`updated_at`),
  KEY `idx_host` (`host_id`)
) ENGINE=InnoDB AUTO_INCREMENT=138 DEFAULT CHARSET=utf8mb4 COMMENT='开发项目';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prompt_guard_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prompt_guard_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `conv_id` int(10) unsigned NOT NULL DEFAULT '0',
  `hit_rule` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '命中的规则名',
  `excerpt` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '原文片段，供人工复核',
  `strike_no` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '本窗口内第几次',
  `banned` tinyint(1) NOT NULL DEFAULT '0' COMMENT '本次是否触发封禁',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_at`),
  KEY `idx_banned` (`banned`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='提示词套取检测记录';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recharge_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recharge_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(40) NOT NULL COMMENT '本站订单号，传给支付宝的 out_trade_no',
  `user_id` int(10) unsigned NOT NULL,
  `channel` varchar(20) NOT NULL DEFAULT 'alipay' COMMENT '支付渠道标识',
  `pay_scene` varchar(20) NOT NULL DEFAULT 'page' COMMENT 'page=电脑网站 wap=手机网站 qr=当面付扫码',
  `credit_amount` decimal(14,6) NOT NULL COMMENT '到账金额（后台设的面额）',
  `pay_amount` decimal(14,6) NOT NULL COMMENT '实付金额（面额打折后）',
  `discount_rate` decimal(6,3) NOT NULL DEFAULT '0.000' COMMENT '下单时的优惠比例快照，单位%',
  `status` enum('pending','paid','closed','failed') NOT NULL DEFAULT 'pending',
  `trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '支付宝交易号',
  `buyer_id` varchar(64) NOT NULL DEFAULT '' COMMENT '付款方支付宝用户号',
  `paid_at` datetime DEFAULT NULL,
  `notify_raw` text COMMENT '最后一次回调原文，排账用',
  `client_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user` (`user_id`,`id`),
  KEY `idx_status` (`status`,`id`),
  KEY `idx_trade` (`trade_no`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COMMENT='余额充值订单';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `repo_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `repo_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `repo_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL COMMENT '冗余，便于带 user_id 直查',
  `path` varchar(500) NOT NULL COMMENT '相对仓根路径',
  `size` int(10) unsigned NOT NULL DEFAULT '0',
  `hash` char(40) NOT NULL DEFAULT '' COMMENT '本地内容 sha1',
  `remote_hash` char(40) NOT NULL DEFAULT '' COMMENT '拉取时的远端 sha1，用于判断本地是否改过',
  `is_text` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0=二进制，不给 AI 看正文',
  `state` varchar(12) NOT NULL DEFAULT 'same' COMMENT 'same 与远端一致 / edited 本地已改 / new 本地新增 / gone 远端已删',
  `ver` int(11) NOT NULL DEFAULT '0' COMMENT '本地改动次数',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_repo_path` (`repo_id`,`path`),
  KEY `idx_user` (`user_id`),
  KEY `idx_state` (`repo_id`,`state`)
) ENGINE=InnoDB AUTO_INCREMENT=7336 DEFAULT CHARSET=utf8mb4 COMMENT='代码仓文件清单';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `repo_syncs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `repo_syncs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `repo_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `direction` varchar(8) NOT NULL COMMENT 'pull 拉取 / push 回传',
  `ok` tinyint(1) NOT NULL DEFAULT '0',
  `file_count` int(11) NOT NULL DEFAULT '0' COMMENT '本次涉及文件数',
  `bytes` bigint(20) NOT NULL DEFAULT '0',
  `backup` varchar(255) NOT NULL DEFAULT '' COMMENT '回传前在服务器上生成的备份路径',
  `detail` text COMMENT '文件清单与错误详情',
  `ms` int(11) NOT NULL DEFAULT '0',
  `client_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_repo` (`repo_id`,`created_at`),
  KEY `idx_user` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=utf8mb4 COMMENT='代码仓同步日志';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `repo_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `repo_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `file_id` bigint(20) unsigned NOT NULL,
  `repo_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `ver` int(11) NOT NULL COMMENT '第几版，从 1 开始',
  `size` int(10) unsigned NOT NULL DEFAULT '0',
  `hash` char(40) NOT NULL DEFAULT '',
  `action` varchar(12) NOT NULL DEFAULT 'write' COMMENT 'pull/write/patch/rollback',
  `conv_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '哪条对话改的，便于追溯',
  `note` varchar(255) NOT NULL DEFAULT '' COMMENT '改动说明，AI 填',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_file` (`file_id`,`ver`),
  KEY `idx_repo` (`repo_id`,`created_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=187 DEFAULT CHARSET=utf8mb4 COMMENT='文件版本历史';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `repos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `repos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '归属用户，严格隔离',
  `project_id` int(10) unsigned NOT NULL COMMENT '所属项目',
  `host_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '来源主机 ssh_hosts.id',
  `remote_dir` varchar(255) NOT NULL DEFAULT '' COMMENT '远程根目录',
  `file_count` int(11) NOT NULL DEFAULT '0' COMMENT '文件数，冗余计数',
  `total_bytes` bigint(20) NOT NULL DEFAULT '0' COMMENT '占用字节',
  `last_pull_at` datetime DEFAULT NULL COMMENT '最近一次拉取时间',
  `last_push_at` datetime DEFAULT NULL COMMENT '最近一次回传时间',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_proj` (`project_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COMMENT='项目代码仓';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `session_sk_bind`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `session_sk_bind` (
  `channel_id` int(11) NOT NULL,
  `session_fp` bigint(20) NOT NULL,
  `sk_index` int(11) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`channel_id`,`session_fp`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `k` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `v` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sftp_edits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sftp_edits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '归属用户，严格隔离',
  `project_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '所属项目',
  `host_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '目标主机 ssh_hosts.id',
  `conv_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '触发本次编辑的对话',
  `path` varchar(512) NOT NULL DEFAULT '' COMMENT '远端绝对路径',
  `action` varchar(16) NOT NULL DEFAULT 'write' COMMENT 'write/patch/restore',
  `old_text` mediumtext COMMENT '改动前内容，用于还原',
  `new_text` mediumtext COMMENT '改动后内容，用于比对',
  `backup_path` varchar(512) NOT NULL DEFAULT '' COMMENT '远端备份文件路径',
  `note` varchar(255) NOT NULL DEFAULT '' COMMENT '本次改动说明',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_at`),
  KEY `idx_proj` (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2704 DEFAULT CHARSET=utf8mb4 COMMENT='直连 SFTP 编辑留档';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sk_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sk_cards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '默认卡密',
  `balance` decimal(14,6) NOT NULL DEFAULT '0.000000',
  `total_cost` decimal(14,6) NOT NULL DEFAULT '0.000000',
  `expires_at` datetime DEFAULT NULL,
  `ip_whitelist` text,
  `ip_blacklist` text,
  `allowed_models` text,
  `remark` text,
  `last_used_at` datetime DEFAULT NULL,
  `last_used_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_hash` (`token_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=148 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ssh_hosts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ssh_hosts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '归属用户，越权校验的依据',
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '备注名，如「我的测试机」',
  `host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '域名或公网IP',
  `port` smallint(5) unsigned NOT NULL DEFAULT '22',
  `username` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auth_type` enum('password','key') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'key',
  `secret_enc` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '密码或私钥，AES-256-GCM 加密后存储',
  `key_pass_enc` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '私钥口令，同样加密；无口令则空',
  `fingerprint` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '首次连接记录的主机指纹，之后变更即拒连',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
  `last_ok_at` datetime DEFAULT NULL COMMENT '最近一次连通时间',
  `last_error` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ssh_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ssh_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `host_id` int(10) unsigned NOT NULL,
  `conv_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '关联的对话，0=非对话触发',
  `command` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '实际执行的命令原文',
  `risk` enum('safe','write','denied') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'safe' COMMENT 'safe只读 write写操作 denied被拒绝',
  `deny_reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '被拒原因',
  `exit_code` int(11) NOT NULL DEFAULT '-1',
  `output` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '合并后的输出，已截断',
  `duration_ms` int(11) NOT NULL DEFAULT '0',
  `client_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_at`),
  KEY `idx_host` (`host_id`)
) ENGINE=InnoDB AUTO_INCREMENT=24962 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ssh_pending`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ssh_pending` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token` char(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '一次性令牌，前端凭它确认',
  `user_id` int(10) unsigned NOT NULL,
  `host_id` int(10) unsigned NOT NULL,
  `conv_id` int(10) unsigned NOT NULL DEFAULT '0',
  `command` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `risk` enum('safe','write') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'safe',
  `state` enum('wait','done','cancel','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'wait',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL COMMENT '过期即失效，默认 5 分钟',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_user_state` (`user_id`,`state`)
) ENGINE=InnoDB AUTO_INCREMENT=129 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tool_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tool_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `conv_id` int(10) unsigned NOT NULL,
  `after_msg_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '挂在哪条 assistant 消息之后，0=会话开头',
  `tool_call_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Function Calling工具调用ID',
  `kind` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other' COMMENT 'ssh/sftp/repo/ws/ppt/other',
  `content` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '回执正文，已在前端截断',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conv_id`,`after_msg_id`,`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_tool_call` (`conv_id`,`tool_call_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2060 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `uploads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `conv_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '0=还未附到会话',
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '相对 UPLOAD_DIR 的路径',
  `mime` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `size` int(10) unsigned NOT NULL DEFAULT '0',
  `width` int(11) NOT NULL DEFAULT '0',
  `height` int(11) NOT NULL DEFAULT '0',
  `used` tinyint(4) NOT NULL DEFAULT '0' COMMENT '1=已在某条消息中使用',
  `created_at` datetime NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '账号内虚拟相对路径，兼展示名',
  `kind` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image' COMMENT 'image/text/bin',
  `source` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user' COMMENT 'user 用户上传 / ai AI 产出',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注，AI 写文件时说明用途',
  `updated_at` datetime DEFAULT NULL COMMENT '最后一次修改时间',
  `ver` int(11) NOT NULL DEFAULT '1' COMMENT '修改次数',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`id`),
  KEY `idx_conv` (`conv_id`),
  KEY `idx_gc` (`used`,`created_at`),
  KEY `idx_user_name` (`user_id`,`name`),
  KEY `idx_user_kind` (`user_id`,`kind`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=632 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usage_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usage_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `conv_id` int(10) unsigned NOT NULL DEFAULT '0',
  `channel_id` int(10) unsigned NOT NULL DEFAULT '0',
  `model_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `tokens_in` int(11) NOT NULL DEFAULT '0',
  `tokens_out` int(11) NOT NULL DEFAULT '0',
  `cost` decimal(12,6) NOT NULL DEFAULT '0.000000',
  `is_estimated` tinyint(4) NOT NULL DEFAULT '0',
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `error_msg` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `upstream_http` int(11) NOT NULL DEFAULT '0' COMMENT '上游HTTP状态码(0=未记录)',
  `latency_ms` int(11) NOT NULL DEFAULT '0',
  `ttft_ms` int(11) DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `client_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '调用来源: android/desktop/web',
  `sk_card_id` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `tokens_cache` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '输入中命中提示词缓存的量，已含在 tokens_in 里',
  `tokens_cache_create` int(11) NOT NULL DEFAULT '0' COMMENT '缓存创建token数',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`created_at`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=126621 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_api_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT 'Default',
  `token_hash` varchar(255) NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_token` (`token_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_ban_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_ban_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '0=系统自动封禁',
  `action` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ban / unban',
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin' COMMENT 'admin/prompt_guard/login_fail',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_at`),
  KEY `idx_action` (`action`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账号封禁/解封记录';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `avatar_qq` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `dark_mode` tinyint(1) NOT NULL DEFAULT '0',
  `email` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email_verified_at` datetime DEFAULT NULL COMMENT '邮箱验证时间',
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('user','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '1æ­£å¸¸ 0ç¦ç”¨',
  `balance` decimal(14,6) NOT NULL DEFAULT '0.000000' COMMENT 'ä½™é¢(å…ƒ)',
  `token_quota` bigint(20) NOT NULL DEFAULT '0' COMMENT 'æ€»tokenä¸Šé™,0=ä¸é™',
  `register_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `login_fail_count` int(10) unsigned NOT NULL DEFAULT '0',
  `login_fail_at` datetime DEFAULT NULL COMMENT '本轮登录失败计数的起始时间',
  `used_tokens` bigint(20) NOT NULL DEFAULT '0',
  `total_cost` decimal(14,6) NOT NULL DEFAULT '0.000000',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `space_quota_mb` int(11) NOT NULL DEFAULT '0' COMMENT '工作中心空间上限MB，0=用全局默认',
  `invite_code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '本人邀请码',
  `referrer_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '上级用户ID',
  `referred_at` datetime DEFAULT NULL COMMENT '绑定上级时间',
  `tool_ssh_exec` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'SSH 命令执行',
  `tool_sftp_read` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'SFTP 读文件',
  `tool_sftp_write` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'SFTP 写文件',
  `tool_sftp_list` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'SFTP 列目录',
  `tool_sftp_delete` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'SFTP 删文件',
  `tool_sftp_patch` tinyint(4) NOT NULL DEFAULT '1',
  `tool_file_list` tinyint(4) NOT NULL DEFAULT '1' COMMENT '代码仓列表',
  `tool_file_read` tinyint(4) NOT NULL DEFAULT '1' COMMENT '代码仓读文件',
  `tool_file_write` tinyint(4) NOT NULL DEFAULT '1' COMMENT '代码仓写文件',
  `tool_file_delete` tinyint(4) NOT NULL DEFAULT '1' COMMENT '代码仓删除工具开关',
  `tool_file_push` tinyint(4) NOT NULL DEFAULT '1' COMMENT '代码仓推送',
  `tool_file_patch` tinyint(4) NOT NULL DEFAULT '1',
  `tool_file_pull` tinyint(4) NOT NULL DEFAULT '1',
  `tool_ws_list` tinyint(4) NOT NULL DEFAULT '1' COMMENT '工作中心列表',
  `tool_ws_read` tinyint(4) NOT NULL DEFAULT '1' COMMENT '工作中心读文件',
  `tool_ws_write` tinyint(4) NOT NULL DEFAULT '1' COMMENT '工作中心写文件',
  `tool_ws_patch` tinyint(4) NOT NULL DEFAULT '1',
  `tool_ws_zip` tinyint(4) NOT NULL DEFAULT '1',
  `tool_ws_delete` tinyint(4) NOT NULL DEFAULT '1' COMMENT '工作中心删除工具开关',
  `tool_web_open` tinyint(4) NOT NULL DEFAULT '1' COMMENT '网页抓取',
  `tool_web_search` tinyint(4) NOT NULL DEFAULT '1' COMMENT '实时搜索',
  `tool_ppt_generate` tinyint(4) NOT NULL DEFAULT '1' COMMENT '生成 PPT',
  `cap_ssh_exec` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'SSH命令执行权限',
  `cap_sftp_read` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'SFTP读取权限',
  `cap_sftp_write` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'SFTP写入权限',
  `cap_sftp_patch` tinyint(1) NOT NULL DEFAULT '1',
  `cap_sftp_list` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'SFTP列表权限',
  `cap_sftp_delete` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'SFTP删除权限',
  `cap_file_list` tinyint(1) NOT NULL DEFAULT '1' COMMENT '代码仓列表权限',
  `cap_file_read` tinyint(1) NOT NULL DEFAULT '1' COMMENT '代码仓读取权限',
  `cap_file_write` tinyint(1) NOT NULL DEFAULT '1' COMMENT '代码仓写入权限',
  `cap_file_patch` tinyint(1) NOT NULL DEFAULT '1',
  `cap_file_pull` tinyint(1) NOT NULL DEFAULT '1',
  `cap_file_delete` tinyint(1) NOT NULL DEFAULT '1' COMMENT '代码仓删除权限',
  `cap_file_push` tinyint(1) NOT NULL DEFAULT '1' COMMENT '代码仓推送权限',
  `cap_ws_list` tinyint(1) NOT NULL DEFAULT '1' COMMENT '工作中心列表权限',
  `cap_ws_read` tinyint(1) NOT NULL DEFAULT '1' COMMENT '工作中心读取权限',
  `cap_ws_write` tinyint(1) NOT NULL DEFAULT '1' COMMENT '工作中心写入权限',
  `cap_ws_patch` tinyint(1) NOT NULL DEFAULT '1',
  `cap_ws_zip` tinyint(1) NOT NULL DEFAULT '1',
  `cap_ws_delete` tinyint(1) NOT NULL DEFAULT '1' COMMENT '工作中心删除权限',
  `cap_web_open` tinyint(1) NOT NULL DEFAULT '1' COMMENT '网页访问权限',
  `cap_web_search` tinyint(1) NOT NULL DEFAULT '1' COMMENT '网页搜索权限',
  `cap_ppt_generate` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'PPT生成权限',
  `sk_blacklisted` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'SK卡密功能被拉黑 0正常 1拉黑',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_invite_code` (`invite_code`),
  KEY `idx_status` (`status`),
  KEY `idx_referrer` (`referrer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=136 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `withdrawals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `withdrawals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `amount` decimal(14,6) NOT NULL COMMENT '申请提现金额',
  `fee` decimal(14,6) NOT NULL DEFAULT '0.000000' COMMENT '手续费',
  `actual` decimal(14,6) NOT NULL COMMENT '实际到手',
  `fee_rate` decimal(6,3) NOT NULL DEFAULT '0.000' COMMENT '当时的费率快照',
  `method` varchar(10) NOT NULL DEFAULT 'alipay' COMMENT 'alipay/wechat',
  `real_name` varchar(50) NOT NULL DEFAULT '',
  `phone` varchar(20) NOT NULL DEFAULT '',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `qr_upload_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '收款码在 uploads 表的 id',
  `status` enum('pending','processing','done','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` varchar(255) NOT NULL DEFAULT '',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0',
  `client_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `handled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`id`),
  KEY `idx_status` (`status`,`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提现申请';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wsssh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wsssh_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` char(64) NOT NULL COMMENT '一次性令牌，sha256 十六进制',
  `user_id` int(10) unsigned NOT NULL,
  `host_id` int(10) unsigned NOT NULL,
  `used` tinyint(4) NOT NULL DEFAULT '0' COMMENT '已用过就作废，防重放',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_exp` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=153 DEFAULT CHARSET=utf8mb4 COMMENT='网络终端一次性握手令牌';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- ============================================
-- 岩羊AI 平台 干净数据（脱敏版）
-- 生成时间: 2026-08-24 12:14:41
-- 说明: 已清除用户数据/对话/日志，渠道密钥已替换为占位符
-- ============================================
SET NAMES utf8mb4;

INSERT INTO `models` (`id`,`channel_id`,`display_name`,`model_name`,`price_in`,`price_out`,`price_cache`,`price_cache_create`,`max_context`,`vision`,`max_tokens`,`system_prompt`,`status`,`sort`,`created_at`) VALUES
('1','1','claude-opus-5-cc','claude-opus-5','1.000000','5.000000','0.100000','1.250000','24','1','393216','简体中文回复','1','10000','2026-07-27 00:16:00'),
('2','2','claude-opus-5-ccmax','claude-opus-5','4.000000','20.000000','0.400000','5.000000','24','1','16384','简体中文回复','1','9999','2026-07-27 00:34:11'),
('3','2','claude-fable-5-ccmax','claude-fable-5','8.000000','40.000000','0.800000','10.000000','24','1','393216','简体中文回复','1','9997','2026-07-27 09:33:56'),
('4','3','gpt-5.6-sol','gpt-5.6-sol','1.500000','5.800000','0.150000','1.875000','24','1','4096','你有内置工具例如ssh，sftp等等，直接在服务器调用即可,你不是个容器，你是直接运行在服务器里的，你可以直接用服务器去操作','1','9993','2026-07-27 11:52:18'),
('5','8','deepseek-v4-pro','deepseek-v4-pro','0.000000','0.000000','0.000000','0.000000','24','0','393216','切换简体中文回复','1','99998','2026-07-27 11:57:55'),
('6','9','kimi-k3','kimi-k3','11.000000','59.000000','1.100000','13.750000','24','1','4096','切换简体中文','1','9992','2026-07-27 16:05:54'),
('7','1','claude-sonnet-5-cc','claude-sonnet-5','0.600000','3.000000','0.060000','0.750000','24','1','393216','切换简体中文','1','9995','2026-07-27 20:13:59'),
('8','2','claude-sonnet-5-ccmax','claude-sonnet-5','2.400000','12.000000','0.240000','3.000000','24','1','393216','切换简体中文','1','9994','2026-07-27 20:15:52'),
('10','6','gemini-3-flash-preview','gemini-3-flash-preview','0.400000','2.400000','0.040000','0.500000','24','1','4096','切换简体中文','0','9991','2026-07-30 01:01:55'),
('12','8','deepseek-v4-flash-0731','deepseek-v4-flash-0731','0.000000','0.000000','0.000000','0.000000','24','0','393216','切换简体中文','1','99999','2026-08-02 23:23:39'),
('14','8','qwen3.8-max','qwen3.8-max','1.200000','2.000000','0.120000','0.000000','24','1','134246','','1','99995','2026-08-03 14:31:50'),
('15','8','glm-5.2','glm-5.2','0.800000','1.800000','0.080000','0.000000','24','0','131072','','1','99996','2026-08-06 20:12:54'),
('16','10','claude-opus-5-kiro','claude-opus-5','0.500000','2.500000','0.050000','0.625000','24','1','393216','','1','10001','2026-08-13 15:32:35'),
('17','10','claude-sonnet-5-kiro','claude-sonnet-5','0.300000','1.500000','0.030000','0.375000','24','1','393216','','1','9996','2026-08-13 15:42:38'),
('18','10','Claude Fable 5 cursor版','claude-fable-5','0.300000','1.200000','0.000000','0.000000','24','1','393216','','0','9998','2026-08-13 15:45:53'),
('19','11','grok-4.6','grok-4.6','0.200000','0.550000','0.050000','0.250000','24','1','204800','','0','10002','2026-08-13 22:21:54'),
('21','8','deepseek-v4-pro-0813','deepseek-v4-pro-0813','1.000000','3.000000','0.100000','0.000000','20','0','0','','1','99997','2026-08-18 00:41:10');

INSERT INTO `channels` (`id`,`parent_id`,`name`,`base_url`,`api_key`,`rotate`,`rotate_idx`,`protocol`,`chat_path`,`api_version`,`timeout`,`status`,`sort`,`remark`,`created_at`,`tool_choice_downgrade`) VALUES
('1','1','岩羊cc','https://api.yiyuantoken.com/v1','sk-demo-0001-REPLACE-ME','0','0','claude','/messages','2023-06-01','360','1','0','','2026-07-27 00:08:24','0'),
('2','1','岩羊ccmax','https://api.yiyuantoken.com/v1','sk-demo-0002-REPLACE-ME','0','0','claude','/messages','2023-06-01','360','1','0','','2026-07-27 00:32:39','0'),
('3','1','岩羊GPTpro','https://api.yiyuantoken.com/v1','sk-demo-0003-REPLACE-ME','0','0','claude','/messages','2023-06-01','360','1','0','','2026-07-27 11:51:09','0'),
('5','1','岩羊国模','https://api.yiyuantoken.com/v1','sk-demo-0005-REPLACE-ME','0','0','claude','/messages','2023-06-01','360','1','0','','2026-07-27 11:57:02','0'),
('6','1','岩羊gemini稳定版','https://api.yiyuantoken.com/v1','sk-demo-0006-REPLACE-ME','0','0','claude','/messages','2023-06-01','120','1','0','','2026-07-30 01:00:53','0'),
('8','2','官方版','https://tokenrhythm.studio/v1','sk-demo-0008-REPLACE-ME','1','20','openai','/chat/completions','2023-06-01','120','1','0','','2026-08-02 23:22:24','0'),
('9','1','国模难产','https://api.yiyuantoken.com/v1','sk-demo-0009-REPLACE-ME','0','0','claude','/messages','2023-06-01','360','1','0','','2026-08-11 15:02:04','0'),
('10','1','岩羊sursor','https://api.yiyuantoken.com/v1','sk-demo-0010-REPLACE-ME','0','0','claude','/messages','2023-06-01','360','1','0','','2026-08-13 15:29:32','0'),
('11','1','岩羊Grok','https://api.yiyuantoken.com/v1','sk-demo-0011-REPLACE-ME','0','0','claude','/messages','2023-06-01','360','1','0','','2026-08-13 22:14:55','0');

INSERT INTO `api_platforms` (`id`,`name`,`base_url`,`access_token`,`api_user_id`,`protocol`,`chat_path`,`api_version`,`timeout`,`sort`,`status`,`remark`,`created_at`) VALUES
('1','一元token','https://api.yiyuantoken.com/v1','sk-demo-0001-REPLACE-ME','1747','claude','/messages','2023-06-01','360','0','1','自动迁移','2026-07-31 11:52:29'),
('2','deepseek官方','https://tokenrhythm.studio/v1','sk-demo-0002-REPLACE-ME','','openai','/chat/completions','2023-06-01','600','0','1','','2026-08-02 23:19:37');

INSERT INTO `settings` (`k`,`v`) VALUES
('aff_enabled','1'),
('aff_max_per_order','0.00'),
('aff_rate','5.000'),
('alipay_app_id',''),
('alipay_gateway',''),
('alipay_private_key',''),
('alipay_public_key',''),
('alipay_scene_page','1'),
('alipay_scene_qr','0'),
('alipay_scene_wap','1'),
('allow_register','1'),
('android_code','68'),
('android_dl_apk','/download/岩羊AI-v1.6.2.apk'),
('android_force','1'),
('android_min_version','1.3.5'),
('android_notes','1.修复 命令卡片堆在正文最下方的问题，卡片和正文按时间顺序交错显示
2.优化 去掉消息气泡，正文平铺成步骤流
3.优化 工具执行每一步都入库，刷新后完整可见
4.修复 跨轮对话 AI 不记得文件位置的问题
5.修复 断点续写工具结果保存失败的问题'),
('android_sha_apk','5922dad903f8730163d7aa43243c8155552cb30578a81e9f9bb953b4e432eb11'),
('android_size_apk','2088120'),
('android_update_on','1'),
('android_ver','1.5.3'),
('android_version','1.6.2'),
('android_version_code','70'),
('balance_warn_on','1'),
('balance_warn_threshold','1.00'),
('client_dl_linux',''),
('client_dl_mac',''),
('client_dl_win','/download/yanyang-ai-setup-1.2.13.exe'),
('client_force','0'),
('client_min_version','1.2.6'),
('client_notes','1.修复 多轮工具调用后中间思考内容跑到正文的问题
2.优化 每轮思考独立卡片、实时出现'),
('client_sha_win','652c7277ee5f9c1d5cdba009d3752f59270e04b57bd9b8ead8f5fbeaedd9a740'),
('client_size_win','78734160'),
('client_update_on','1'),
('client_version','1.2.13'),
('concurrency_limit','6'),
('concurrency_mode','sk'),
('concurrency_rpm','60'),
('contact_email',''),
('contact_phone',''),
('contact_qq',''),
('contact_wechat','y_rom1'),
('dl_android','/download/岩羊AI-1.3.5.apk'),
('dl_ios',''),
('dl_mac',''),
('dl_win','/download/岩羊Ai Setup 1.2.6.exe'),
('email_from',''),
('email_from_name','岩羊智能开发平台'),
('email_smtp_encrypt','ssl'),
('email_smtp_host','smtp.163.com'),
('email_smtp_pass',''),
('email_smtp_port','465'),
('email_smtp_user',''),
('email_verify_enabled','1'),
('est_cjk_per_token','1.1000'),
('est_cjk_rare_per_token','0.4000'),
('est_emoji_mod_tokens','3.0000'),
('est_emoji_tokens','5.0000'),
('est_entropy_enable','1'),
('est_entropy_min_len','20'),
('est_entropy_per_token','1.0000'),
('est_prose_per_token','3.2000'),
('est_rare_enable','1'),
('est_rare_min_len','12'),
('est_rare_per_token','0.0000'),
('est_symbol_chars','{}[]\":,'),
('est_tab_tokens','1.0000'),
('est_tail_bonus','1'),
('est_zw_tokens','1.0000'),
('google_api_key',''),
('google_cx',''),
('history_block_step','4'),
('history_char_budget','120000'),
('history_dedup_read','1'),
('history_full_blocks','4'),
('history_img_keep','1'),
('history_text_cap','3000'),
('history_tool_cap','1200'),
('home_template','home_tpl1'),
('icp_no','苏ICP备2025196049号-1'),
('icp_url','https://beian.miit.gov.cn'),
('intr_block','0'),
('intr_block_min','1440'),
('intr_cron','1'),
('intr_enabled','1'),
('intr_file','1'),
('intr_fw_mode','iptables'),
('intr_last_msg','检测完成，无异常'),
('intr_last_run','2026-08-24 12:14:03'),
('intr_last_state','ok'),
('intr_mail',''),
('intr_mail_body','检测到一条异常，详情如下：

站点：{site}
服务器：{host}
时间：{time}
类型：{type_name}
等级：{level_name}
来源 IP：{ip}
触发次数：{hits}
处置结果：{blocked}
对象：{target}

原始详情：
{detail}

本邮件由入侵监控自动发出，可在后台「入侵监控」页面关闭通知或调整阈值。'),
('intr_mail_cooldown','600'),
('intr_mail_level','mid'),
('intr_mail_subject','[{site}] 入侵告警：{type_name}（{ip}）'),
('intr_mail_to',''),
('intr_proc','0'),
('intr_proc_cpu','85'),
('intr_scan_dir','/www/wwwroot/code.77bot.cn'),
('intr_scan_min','10'),
('intr_script_ver','1.0.0'),
('intr_ssh','1'),
('intr_ssh_log',''),
('intr_ssh_max','5'),
('intr_ssh_newip','1'),
('intr_ssh_win','300'),
('intr_suid','1'),
('intr_suid_min','60'),
('intr_sysfile','1'),
('intr_web','0'),
('intr_web_log',''),
('intr_web_max','60'),
('intr_web_rule','0'),
('intr_web_win','120'),
('intr_whitelist','127.0.0.1
103.236.77.251
103.236.78.0/24
103.236.77.0/24
150.242.82.0/24
112.82.133.0/24
103.146.230.0/24
160.30.231.0/24
154.9.229.0/24
45.138.68.0/24
110.42.67.0/24'),
('login_ban_msg','查询到您的账号存在异常，解封请联系客服。'),
('login_fail_max','5'),
('min_balance','0.5000'),
('pay_amounts','5,10,30,50,100,200,500,1000'),
('pay_channels_on','alipay'),
('pay_custom_max','9999999.00'),
('pay_custom_min','1.00'),
('pay_custom_on','1'),
('pay_discount','0.000'),
('pay_discount_min','0.00'),
('pay_discount_tiers','30:1
100:2
500:3'),
('prompt_cache','1'),
('prompt_cache_local','1'),
('prompt_debug','1'),
('prompt_ppt_ondemand','1'),
('prompt_zip_ondemand','1'),
('reg_notify','1'),
('reg_notify_email',''),
('register_balance','0.0000'),
('register_ip_interval','60'),
('register_quota','0'),
('site_name','岩羊智能开发平台'),
('site_notice','新人注册赠送1元平台余额供开发测试
充值30元以上优惠1%，例：充值30实付29.7
充值100元以上优惠2% 例：充值100实付98
充值500元以上优惠3% 例：充值500实付485

QQ交流群：1061035818 欢迎新朋友加入🌹'),
('site_url','https://code.77bot.cn'),
('sk_cool_auth','600'),
('sk_cool_busy','45'),
('sk_cool_factor_max','8'),
('sk_cool_max','1800'),
('sk_cool_rate','30'),
('sk_fail_threshold','1'),
('sk_http_auth_codes','401,402,403'),
('sk_http_busy_codes','500,501,502,503,504,505,506,507,508,509,510,511,520,522,524'),
('ssh_block_ips',''),
('terms_privacy','生效日期：2026 年 8 月 1 日

岩羊智能开发平台（以下称「本平台」）重视你的隐私。本条款说明我们收集哪些信息、如何使用，以及你拥有哪些权利。

一、我们收集哪些信息

1. 账号信息：用户名、邮箱地址、加密存储的密码。密码经哈希算法处理后存储，我们无法还原出原文。
2. 登录与安全记录：注册时间、最近登录时间、最近登录 IP 地址，用于账号安全核查和异常登录排查。
3. 使用与计费记录：调用的模型、消耗的 token 数量、产生的费用、账户余额及充值消费流水。
4. 对话与文件：你与 AI 的对话内容、上传的图片和文件、创建的项目与代码仓内容。
5. 服务器接入凭据：你主动登记的服务器地址、端口、登录用户名，以及登录密码或私钥。
6. 命令执行记录：AI 在你的服务器上执行的每一条命令及其返回结果。

二、我们如何使用这些信息

仅用于向你提供服务本身，具体包括：完成身份验证、生成 AI 回复、按用量计费、在你授权的服务器上执行操作、排查故障、防范滥用与攻击。

我们不将你的信息出售、出租或交换给任何第三方，也不用于与本服务无关的广告投放。

三、服务器凭据的特别说明

这是本平台最敏感的一类数据，我们的处理方式如下：

1. 密码和私钥采用 AES-256-GCM 加密后存储，不以明文形式保存在数据库中。
2. 首次连接时记录服务器的主机指纹，之后指纹发生变化即拒绝连接，用于防范中间人攻击。
3. 每一条执行过的命令都会写入审计日志，日志只增不改，你可以随时查阅。
4. 你可以在服务器管理页面随时停用或删除已登记的服务器，删除后对应凭据一并清除。

请注意：将服务器凭据交给任何平台都存在风险。我们建议你为本平台单独创建权限受限的账号，而不是直接使用 root，并定期更换凭据。

四、平台管理员的访问范围

为了处理故障申报、核查计费争议和调查违规行为，本平台管理员在必要时可以查看用户的对话内容和使用记录。

每一次这类访问都会记录到审计日志中，包含操作人、访问对象、操作时间和来源 IP。我们不会出于上述目的之外的原因查看你的对话。

五、第三方服务

本平台的 AI 能力由第三方模型服务商提供。你发送的对话内容和上传的文件，会被转发到你所选择模型对应的服务商以生成回复。这部分内容同时受该服务商的隐私政策约束。

如果你的内容涉及商业秘密或个人敏感信息，请在发送前自行评估风险。

六、信息的保存与删除

1. 账号信息在账号存续期间保留。
2. 对话记录、上传文件、项目与代码仓内容在你主动删除前一直保留。
3. 计费流水和命令执行审计日志出于对账与安全追溯需要，即使相关对话被删除也会保留。
4. 你可以联系我们注销账号。注销后我们将删除你的账号信息、对话内容、上传文件和服务器凭据，但依法或依约需要留存的计费与审计记录除外。

七、你的权利

你可以随时查看和修改自己的账号资料、查询用量与消费明细、导出或删除自己的对话内容、管理已登记的服务器。如需协助行使上述权利，可通过本页末尾的方式联系我们。

八、Cookie 的使用

我们使用 Cookie 维持你的登录状态，包括「记住登录」功能所需的长效令牌。这些 Cookie 是服务运行所必需的，不用于跨站追踪。清除浏览器 Cookie 会导致登录状态失效。

九、未成年人

本平台不面向未满 14 周岁的儿童提供服务。如你是未成年人，请在监护人同意并陪同的情况下使用。

十、条款变更

本条款如有修订，我们将更新本页的生效日期。涉及你权利的重大变更，我们会通过站内提示或邮件另行通知。

十一、联系我们

对本隐私条款有疑问，或需要行使上述权利，请通过以下方式联系我们：

客服 QQ：3173631174
客服微信：y_rom1'),
('terms_service','生效日期：2026 年 8 月 1 日

本条款是你与岩羊智能开发平台（以下称「本平台」）之间的协议。请在使用前完整阅读，其中第五条涉及你的服务器安全，务必读完。

一、条款的接受

注册账号或使用本平台，即表示你已阅读并同意本条款及《隐私条款》。如不同意，请停止使用本服务。

二、服务内容

本平台提供的服务包括：调用平台内接入的 AI 模型进行对话与代码生成、在线编辑与管理代码项目、以及在你主动授权的服务器上执行运维命令。

服务的具体功能、可用模型和资源限制可能随平台迭代而调整。

三、账号

1. 注册时应提供真实可用的邮箱，用于接收验证码和重要通知。
2. 你对账号下的全部操作负责，包括妥善保管密码、不将账号转借他人。
3. 发现账号被盗用应立即修改密码并联系我们。
4. 一人注册多个账号规避用量限制的，我们保留停用相关账号的权利。

四、计费与余额

1. AI 调用按实际消耗的 token 计费，单价随模型不同，以调用时页面展示的价格为准。
2. 使用服务需要账户余额充足，余额不足时相关功能将无法使用。
3. 充值金额仅用于抵扣平台服务费用。
4. 用量与消费明细可在用量页面自行查询。如对计费有异议，请在产生费用后 30 日内联系我们核查。
5. 因第三方模型服务商调价导致的价格变动，我们将在页面同步更新，恕不逐一通知。

五、服务器接入与命令执行

这一条关系到你的服务器安全，请务必读完。

1. 服务器由你自行登记。登记即表示你确认对该服务器拥有合法的管理权限，或已获得所有者的明确授权。
2. AI 生成的命令将在你的服务器上真实执行，可能修改文件、变更配置、启停服务，部分操作不可撤销。
3. AI 生成的命令可能包含错误。执行前请自行判断其影响，重要操作前请先备份。
4. 因执行命令导致的数据丢失、服务中断、配置损坏或其他损失，由你自行承担。我们提供审计日志供你追溯，但不对执行结果承担赔偿责任。
5. 严禁登记并操作你无权管理的服务器。此类行为可能构成违法，我们将停用账号并配合有权机关调查。

六、使用限制

不得利用本平台从事下列行为：

1. 制作或传播病毒、木马、勒索软件、挖矿程序及其他恶意代码。
2. 未经授权侵入、扫描、爆破他人系统，或破解付费与授权校验。
3. 实施诈骗、钓鱼、洗钱、伪造证件与公文等违法活动。
4. 生成或传播涉及色情、暴力、赌博、毒品的内容。
5. 生成侮辱诽谤他人、侵犯他人隐私，或基于种族、性别、地域、宗教、性取向、残障等特征进行歧视与煽动仇恨的内容。
6. 任何涉及性化、诱骗或伤害未成年人的内容。
7. 通过脚本刷量、绕过限流、逆向接口等方式滥用平台资源。
8. 侵犯他人知识产权，或违反中华人民共和国法律法规的其他行为。

七、AI 输出的性质

1. AI 生成的内容由模型概率推理产生，可能存在事实错误、代码缺陷或安全隐患，不构成任何形式的专业建议。
2. 涉及医疗、法律、金融、税务等专业领域的输出仅供参考，请咨询相应领域的专业人士。
3. 你需要对采用 AI 输出后产生的结果自行负责，包括将生成代码用于生产环境的后果。

八、内容权利归属

1. 你上传的内容、你的项目代码，权利归你所有。我们仅在提供服务所必需的范围内处理这些内容。
2. AI 针对你的输入生成的内容，我们不主张权利，你可自由使用。但需注意：相同提问可能产生相似输出，我们无法保证生成内容的独创性，也不保证其不与第三方既有权利冲突。
3. 本平台自身的界面设计、程序代码、文档与商标归本平台所有，未经许可不得复制或用于商业用途。

九、服务的可用性

1. 本服务按「现状」提供。我们努力保持稳定运行，但不承诺服务永不中断、无差错。
2. 系统维护、升级、第三方模型服务商故障、网络故障或不可抗力都可能导致服务暂时不可用。计划内维护我们会提前公告。
3. 我们保留调整、暂停或终止部分功能的权利。如涉及付费功能永久下线，将对未消耗余额作合理处理。

十、违规处理

发现违反本条款的行为，我们可视情节采取下列措施：警告、限制功能、暂停账号、终止服务。涉嫌违法犯罪的，将保存记录并向有权机关报告。因你的违规行为给本平台或第三方造成损失的，你应承担相应责任。

十一、责任限制

在法律允许的最大范围内，本平台不对下列情形承担责任：AI 输出错误导致的损失、你执行命令导致的服务器损失、数据丢失、业务中断、利润损失及其他间接损失。

如经认定我们确需承担赔偿责任，赔偿总额以你在最近 12 个月内向本平台实际支付的费用为上限。

十二、条款变更与通知

本条款如有修订，将更新本页生效日期。重大变更我们会通过站内提示或邮件通知。修订生效后你继续使用本服务，视为接受修订后的条款。

十三、法律适用与争议解决

本条款适用中华人民共和国法律。因本条款产生的争议，双方应先友好协商；协商不成的，任何一方可向本平台运营方所在地有管辖权的人民法院提起诉讼。

十四、联系我们

客服 QQ：3173631174
客服微信：y_rom1'),
('ui_page_size','48'),
('upload_max_image_size','100'),
('upload_max_mb','5'),
('upload_max_num','4'),
('upload_pending_max','20'),
('upload_quota_mb','200'),
('upstream_retry_interval','1'),
('upstream_retry_times','3'),
('wd_daily_limit','3'),
('wd_enabled','1'),
('wd_fee_min','0'),
('wd_fee_rate','6'),
('wd_max','5000'),
('wd_min','10');

INSERT INTO `users` (`id`,`username`,`nickname`,`email`,`email_verified_at`,`password_hash`,`role`,`status`,`balance`,`token_quota`,`register_ip`,`used_tokens`,`total_cost`,`created_at`,`invite_code`,`referrer_id`,`tool_ssh_exec`,`tool_sftp_read`,`tool_sftp_write`,`tool_sftp_list`,`tool_sftp_delete`,`tool_sftp_patch`,`tool_file_list`,`tool_file_read`,`tool_file_write`,`tool_file_delete`,`tool_file_push`,`tool_file_patch`,`tool_file_pull`,`tool_ws_list`,`tool_ws_read`,`tool_ws_write`,`tool_ws_patch`,`tool_ws_zip`,`tool_ws_delete`,`tool_web_open`,`tool_web_search`,`tool_ppt_generate`)
VALUES (1,'admin','演示管理员','admin@example.com',NOW(),'$2y$12$vz1pOl4DzquaWebMigv3nuer9jwA2pHPfXrXC2HJx3aUQ6a8MU53u','admin',1,100.000000,0,'',0,0.000000,NOW(),'DEMO2026',0,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1);

