# 岩羊Ai 智能开发平台 — 技术交接文档

> 文档版本：2026-08-24  
> 适用对象：接手本项目的开发、运维人员  
> 平台定位：一个能直连服务器执行命令、改代码、查日志的 AI 开发助手平台，含网页端、Windows 桌面客户端、安卓 App 三个入口。

---

## 一、项目总览

岩羊Ai 是一个多端 AI 对话平台，核心能力是让 AI 不只是聊天，还能：

- 通过 SSH 直连用户的服务器，执行命令、装环境、看日志、排故障；
- 通过 SFTP 直接读写服务器文件（改前自动备份，可还原）；
- 通过代码仓（本地副本 + 回传）安全地改客户网站代码；
- 通过工作中心（云端文件库）持久化保存产出的代码、文档、脚本；
- 聚合多家大模型（OpenAI / Claude / DeepSeek / Kimi / Gemini 等），支持模型切换、价格计费、余额扣费；
- 支持生成 PPT、网页抓取、实时搜索、文档预览等功能。

三个入口共用同一套后端 API：

| 端 | 技术栈 | 位置 |
|---|---|---|
| 网页端 | PHP 8.5 + 原生 JS + SSE | `/www/wwwroot/code.77bot.cn`（站点根目录） |
| 桌面客户端 | Electron 31（Windows x64） | `/www/wwwroot/code.77bot.cn/客户端` |
| 安卓端 | Kotlin + Jetpack Compose | `/www/wwwroot/code.77bot.cn/安卓端` |

---

## 二、服务器与环境信息

### 2.1 服务器

| 项 | 值 |
|---|---|
| 服务器编号 | 16 |
| IP | 103.236.77.251 |
| 配置 | 西安 6138x2（双核 6 代）64G 内存 50M 带宽 |
| 登录用户 | root |
| 站点域名 | https://code.77bot.cn |
| 部署目录 | `/www/wwwroot/code.77bot.cn` |

### 2.2 运行环境

| 组件 | 版本 | 说明 |
|---|---|---|
| Nginx | 1.30.4 | Web 服务器（宝塔面板管理） |
| PHP | 8.5.8 | FPM，必需扩展清单见「12.1 PHP 扩展清单」；**SSH/SFTP 实际走 phpseclib3（composer 纯 PHP 库），不依赖 ssh2 扩展**，ssh2/redis/yac 已装但代码未直接使用 |
| MySQL | 5.7 | 数据库，库名 `8800demo`，用户 `8800demo` |
| Node.js | v24.18.0 | 打包 Electron 客户端用 |
| 数据目录 | `/www/wwwdata/8800demo/` | 工具回执、上传文件等落盘位置（网站根之外） |
| Session 目录 | `/www/php_session/code.77bot.cn/` | 由 `.user.ini` 指定 |

### 2.3 数据库连接配置

数据库配置在 `inc/config.php`，生产环境由 `inc/config.local.php` 覆盖：

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', '8800demo');
define('DB_USER', '8800demo');
define('DB_PASS', '8800demo');
```

> ⚠️ 注意：`config.local.php` 是生产配置覆盖文件，改数据库密码或迁移环境时必须同步修改，且该文件不应提交到版本库。

---

## 三、目录结构说明

### 3.1 站点根目录（网页端）

```
/www/wwwroot/code.77bot.cn/
├── index.php            # 首页（产品介绍页，未登录也可看）
├── chat.php             # 对话主页面（登录后进入）
├── login.php            # 登录页
├── register.php         # 注册页
├── forgot.php           # 找回密码
├── profile.php          # 个人中心（余额、API Key、工具权限等）
├── recharge.php         # 充值页
├── servers.php          # 服务器（SSH 主机）管理页
├── workspace.php        # 工作中心（文件库）页面
├── models.php           # 模型广场（模型列表与价格）
├── usage.php            # 用量统计
├── verify_email.php     # 邮箱验证
├── legal.php            # 法律条款
├── 404.php              # 404 页
├── logout.php           # 退出登录
├── dec_pk.php           # 密钥解密（内部调试用）
├── impersonate_exit.php # 管理员模拟用户退出
│
├── inc/                 # ★ 后端核心库（被各页面 require）
│   ├── helpers.php      #   全局辅助函数（数据库、用户、设置、路由等，84KB 最大）
│   ├── config.php       #   基础配置（数据库连接、常量）
│   ├── config.local.php #   生产配置覆盖
│   ├── db.php           #   数据库连接封装
│   ├── crypto.php       #   加密工具
│   ├── email.php        #   邮件发送（SMTP）
│   ├── pay.php          #   支付（支付宝）相关
│   ├── aff.php          #   推广返佣
│   ├── withdraw.php     #   提现
│   ├── version.php      #   客户端版本比较工具
│   ├── project.php      #   项目管理（项目/会话/绑定服务器）
│   ├── repo.php         #   代码仓服务端逻辑
│   ├── repo_sync.php    #   代码仓同步（拉取/回传）
│   ├── ws_files.php     #   工作中心文件逻辑
│   ├── ws_zip.php       #   工作中心打包
│   ├── ssh_run.php      #   SSH 命令执行
│   ├── sftp_ops.php     #   SFTP 操作
│   ├── sftp_edit.php    #   SFTP 编辑
│   ├── tool_execute.php #   工具执行（卡片）统一入口
│   ├── tool_results.php #   工具回执存储（JSONL 文件）
│   ├── tools_schema.php #   Function Calling 工具定义（OpenAI 格式）
│   ├── upstream.php     #   上游 API 转发（OpenAI / Claude 协议）
│   ├── upstream_claude.php # Claude 协议适配
│   ├── thinking.php     #   思考内容处理
│   ├── ssh_prompt.php   #   SSH 工具提示词
│   ├── sftp_prompt.php  #   SFTP 工具提示词
│   ├── repo_prompt.php  #   代码仓工具提示词
│   ├── ws_prompt.php    #   工作中心工具提示词
│   ├── web_prompt.php   #   网页抓取/搜索工具提示词
│   ├── ppt_prompt.php   #   PPT 工具提示词
│   ├── prompt_guard.php #   提示词注入防护
│   ├── soul.md          #   AI 人格/系统提示词（核心！）
│   ├── concurrency.php  #   并发控制（SK 卡并发）
│   ├── intrusion.php    #   入侵防护（登录失败、封禁）
│   ├── ip_rules.php     #   IP 规则
│   ├── office_preview.php # Word/Excel/PDF 预览
│   ├── ppt.php          #   PPT 生成
│   ├── ppt_build.py     #   PPT 生成的 Python 脚本
│   ├── qrcode.php       #   二维码
│   ├── apikey_card.php  #   API Key 卡片
│   └── topbar.php       #   顶部导航公共模板
│
├── api/                 # ★ 前端 AJAX / SSE 接口
│   ├── chat.php         #   流式对话接口（SSE，核心！）
│   ├── chat_resume.php  #   断点续聊
│   ├── chat_stop.php    #   停止生成
│   ├── conv.php         #   会话管理（列表/新建/删除）
│   ├── project.php      #   项目 CRUD
│   ├── me.php           #   当前用户信息
│   ├── models.php       #   模型列表
│   ├── version.php      #   客户端版本检查（公开，不登录）
│   ├── upload.php       #   图片上传
│   ├── img.php          #   图片鉴权输出
│   ├── files.php        #   文件操作
│   ├── ssh_hosts.php    #   服务器主机 CRUD
│   ├── ssh_run.php      #   命令执行
│   ├── ssh_cred.php     #   凭据相关
│   ├── sftp.php         #   SFTP 操作
│   ├── repo.php         #   代码仓操作
│   ├── ws.php           #   工作中心操作
│   ├── web.php          #   网页抓取
│   ├── ppt.php          #   PPT 生成
│   ├── pay.php          #   支付回调
│   ├── qrcode.php       #   二维码
│   ├── recharge.php     #   充值
│   ├── usage.php        #   用量
│   ├── profile.php      #   个人资料
│   ├── theme.php        #   主题切换
│   ├── favorite.php     #   收藏消息
│   ├── email_verify.php #   邮箱验证
│   ├── save_tools.php   #   保存工具权限
│   └── wsssh_token*.php #   WebSSH 令牌
│
├── admin/               # 后台管理（管理员专用）
│   ├── index.php        #   后台首页（数据概览）
│   ├── settings.php     #   站点设置（含版本配置）
│   ├── channels.php     #   渠道（上游 API 厂商）管理
│   ├── models.php       #   模型管理
│   ├── users.php        #   用户管理
│   ├── user_edit.php    #   用户编辑
│   ├── chats.php        #   对话列表
│   ├── chat_view.php    #   对话详情
│   ├── channel_rotate.php # 渠道轮询/健康检查
│   ├── sk_admin.php     #   SK 卡管理
│   ├── software.php     #   软件控制（版本发布）
│   ├── software_upload.php # 软件上传
│   ├── pay_settings.php #   支付设置
│   ├── recharge_orders.php # 充值订单
│   ├── withdrawals.php  #   提现管理
│   ├── aff.php          #   推广管理
│   ├── email_settings.php # 邮件设置
│   ├── email_logs.php   #   邮件日志
│   ├── servers.php      #   服务器管理（后台侧）
│   ├── server_test.php  #   服务器连通性测试
│   ├── logs.php         #   操作日志
│   ├── audit.php        #   审计日志
│   ├── intrusion.php    #   入侵防护管理
│   ├── ip_rules.php     #   IP 规则管理
│   ├── concurrency_monitor.php # 并发监控
│   ├── wsssh_server.php #   WebSSH 服务管理
│   └── wsssh_ctrl.php   #   WebSSH 控制
│
├── assets/              # 前端静态资源
│   ├── css/             #   base.css / app.css / chat.css
│   ├── js/              #   chat.js（154KB 核心） / ssh_card.js / sftp_card.js
│   │                    #   repo_card.js / ws_card.js / ppt_card.js / web_card.js
│   │                    #   project.js / project_ui.js / workspace_code.js
│   │                    #   web_term.js / highlight.min.js 等
│   ├── vendor/          #   第三方库
│   └── wsssh/           #   WebSSH 静态资源
│
├── v1/                  # 开放 API（供第三方开发者调用）
│   ├── index.php        #   v1 开放接口主入口（OpenAI 兼容风格）
│   ├── docs.php         #   开发文档页
│   └── headers_debug.php #  请求头调试
│
├── 客户端/              # ★ 桌面客户端（Electron）
├── 安卓端/              # ★ 安卓 App（Kotlin）
├── download/            # 客户端安装包下载目录（线上版本）
├── downloads/           # 旧的下载目录
├── dl/                  # 下载（临时）
├── public/              # 公开资源
├── templates/           # 邮件模板等
├── sql/                 # SQL 脚本/迁移
├── migrations/          # 迁移脚本
├── tools/               # 运维工具脚本
├── tests/               # 测试
└── .kiro_backup/        # SFTP 改文件自动备份（系统生成，勿手动删）
```

---

## 四、数据库设计

数据库：`8800demo`，共 45 张表。核心表如下：

### 4.1 用户与认证

**users** — 用户表（108 条）
- 字段：`id, username, nickname, avatar_qq, dark_mode, email, email_verified_at, password_hash, role('user'|'admin'), status, balance, token_quota, used_tokens, total_cost, register_ip, login_fail_count, login_fail_at, space_quota_mb, invite_code, referrer_id, sk_blacklisted, created_at`
- 工具权限字段（每用户独立开关）：`tool_ssh_exec, tool_sftp_read, tool_sftp_write, tool_sftp_list, tool_sftp_delete, tool_sftp_patch, tool_file_list, tool_file_read, tool_file_write, tool_file_delete, tool_file_push, tool_file_patch, tool_file_pull, tool_ws_list, tool_ws_read, tool_ws_write, tool_ws_patch, tool_ws_zip, tool_ws_delete, tool_web_open, tool_web_search, tool_ppt_generate`
- 能力开关（cap_ 前缀）：`cap_ssh_exec, cap_sftp_*, cap_file_*, cap_ws_*, cap_web_*, cap_ppt_generate`
- 密钥：密码用 `password_hash()` 存储

**auth_tokens** — 登录令牌表
**email_verifications** — 邮箱验证
**user_api_keys** — 用户 API Key（调用 v1 开放接口）
**user_ban_logs** — 用户封禁日志

### 4.2 模型与渠道

**channels** — 上游渠道（API 厂商接入点，9 条）
- 字段：`id, parent_id, name, base_url, api_key, rotate, rotate_idx, protocol('openai'|'claude'), chat_path, api_version, timeout, status, sort, remark, tool_choice_downgrade`
- 一个渠道 = 一个上游 API 服务商（如 DeepSeek、OpenAI 官方、中转站等）

**models** — 模型表（17 条）
- 字段：`id, channel_id, display_name, model_name, price_in, price_out, price_cache, price_cache_create, max_context, vision, max_tokens, system_prompt, status, sort, created_at`
- `display_name` 前端显示名；`model_name` 上游真实模型名
- 价格单位：元/百万 token

**api_platforms** — API 平台（v1 开放接口用）
**sk_cards** — SK 卡（卡密充值）
**channel_sk_health** — 渠道密钥健康状态

### 4.3 对话

**conversations** — 会话表（166 条）
- 字段：`id, user_id, project_id, title, model_id, status, created_at, updated_at`

**messages** — 消息表（21467 条）
- 字段：`id, conv_id, user_id, role('user'|'assistant'|'system'), content, images, model_id, cost, tokens_in, tokens_out, status, hidden, created_at`

**chat_runs** — 对话运行记录（工具调用轮次）
**message_favorites** — 收藏的消息
**messages_bak_toolrcpt** — 工具回执备份

> 工具回执（命令输出、文件操作结果）默认**不存 messages**，而是存到文件 `DATA_DIR/toolresults/<user_id>/<conv_id>.txt`（JSONL 格式，每行一条 JSON），由 `inc/tool_results.php` 管理。这样回执不撑大数据库，拼上下文时按会话整读。

### 4.4 项目 / 服务器 / 代码仓 / 工作中心

**projects** — 项目表
- 字段：`id, user_id, name, intro, stack, host_id, deploy_dir, site_url, pinned, archived, status, created_at, updated_at`

**ssh_hosts** — SSH 主机表（12 条）
- 字段：`id, user_id, name, host, port, user, auth_type, auth_data, status, created_at`

**repos / repo_files / repo_versions / repo_syncs** — 代码仓四件套
**ws_files** — 工作中心文件表
**sftp_edits** — SFTP 编辑记录

### 4.5 计费与支付

**balance_logs** — 余额变动流水
**recharge_orders** — 充值订单
**withdrawals** — 提现申请
**aff_commissions / aff_backfill_logs** — 推广佣金
**usage_logs** — 用量日志

### 4.6 安全与运维

**audit_logs** — 审计日志
**intrusion_events / intrusion_blocks** — 入侵事件/封禁
**ip_rules** — IP 规则
**prompt_guard_logs** — 提示词注入拦截日志
**ssh_logs / ssh_pending** — SSH 操作日志
**concurrency_slots / concurrency_rate_limits** — 并发控制
**session_sk_bind** — SK 卡与用户绑定
**settings** — ★ 全局配置表（键值对，见下）

### 4.7 settings 表（关键配置）

`settings` 表存所有站点配置（`k` = 键，`v` = 值），后台「站点设置」页管理。核心键：

| 键 | 说明 | 当前值 |
|---|---|---|
| `site_name` | 站点名称 | 岩羊Ai |
| `allow_register` | 是否开放注册 | 1 |
| `client_version` | Windows 客户端最新版本 | 1.2.13 |
| `client_dl_win` | Windows 客户端下载地址 | /download/yanyang-ai-setup-1.2.13.exe |
| `client_sha_win` | 安装包 SHA256 | （见数据库） |
| `client_size_win` | 安装包字节数 | 78734160 |
| `client_notes` | 更新说明 | 本次更新内容 |
| `client_update_on` | 客户端更新检测开关 | 1 |
| `client_force` | 是否强制更新 | 0 |
| `client_min_version` | 最低可用版本（低于则强制） | 1.2.6 |
| `client_dl_mac/linux` | 其他平台下载地址 | 空（未发布） |
| `android_version` | 安卓最新版本号 | 1.6.2 |
| `android_version_code` | 安卓 versionCode | 70 |
| `android_dl_apk` | 安卓 APK 下载地址 | /download/岩羊AI-v1.6.2.apk |
| `android_sha_apk` | APK SHA256 | （见数据库） |
| `android_size_apk` | APK 字节数 | 2088120 |
| `android_update_on` | 安卓更新检测开关 | 1 |
| `android_force` | 安卓强制更新 | 1 |
| `android_min_version` | 安卓最低版本 | 1.3.5 |
| `email_smtp_*` | 邮件 SMTP 配置 | 163 邮箱 |
| `alipay_*` | 支付宝支付配置 | 已配置 |
| `concurrency_limit` | 并发限制数 | 6 |
| `concurrency_mode` | 并发模式 | sk |
| `concurrency_rpm` | 每分钟请求上限 | 60 |
| `upload_max_image_size` | 上传图片大小上限 KB | 300 |
| `site_notice` | 站点公告 | 空 |

> 版本发布只需更新 settings 表对应键，无需改代码。详见「九、版本发布」。

---

## 五、网页端架构

### 5.1 技术要点

- **纯 PHP + 原生 JS**，无前端框架（无 Vue/React），无构建步骤，改完即生效
- 页面服务端渲染（PHP 模板 + `h()` 转义防 XSS）
- 对话用 **SSE（Server-Sent Events）** 流式输出，接口为 `api/chat.php`
- 工具卡片（SSH/SFTP/代码仓/工作中心/PPT/网页）由 `assets/js/*_card.js` 渲染，执行后回执通过 `api/chat.php` 的 `tool_kind` 参数回传给 AI
- CSRF 防护：所有 POST 请求带 `X-CSRF-Token`（页面注入 `window.CSRF`）
- 登录鉴权：`inc/helpers.php` 的 `require_login()` / `require_login_api()`

### 5.2 对话流程（核心链路）

```
用户输入 → chat.php 页面 → api/chat.php (SSE)
  → 服务端组装上下文（历史消息 + 工具回执文件 + soul.md + 工具提示词）
  → 调用上游渠道（inc/upstream.php，OpenAI/Claude 协议）
  → 流式返回给前端 chat.js 渲染
  → 若 AI 输出工具卡片（ssh-exec 等代码块）
  → 前端自动执行卡片（assets/js/ssh_card.js 等）
  → 回执走 api/chat.php 的 tool_kind 参数发给 AI
  → AI 继续生成……（工具调用循环，最多 N 轮）
  → 完成，扣费（models 表价格 × 实际 token）
```

### 5.3 等待确认机制

AI 需要在几个方案中让用户选择、或确认风险操作时，会在回复中输出标记 `[[等待用户确认]]`，前端 `chat.js` 检测到该标记就停止自动执行卡片，等用户手动点卡片或回复。该标记定义在 `inc/tool_results.php` 的 `WAIT_USER_MARK` 常量。

### 5.4 前端 JS 文件职责

| 文件 | 职责 |
|---|---|
| `assets/js/chat.js` | 对话主逻辑：SSE 流式渲染、发送、停止/暂停、卡片自动执行调度、等待闸 |
| `assets/js/ssh_card.js` | SSH 命令卡片渲染与执行 |
| `assets/js/sftp_card.js` | SFTP 文件操作卡片 |
| `assets/js/repo_card.js` | 代码仓卡片（list/read/write/patch/push 等） |
| `assets/js/ws_card.js` | 工作中心文件卡片 |
| `assets/js/ppt_card.js` | PPT 生成卡片 |
| `assets/js/web_card.js` | 网页抓取卡片 |
| `assets/js/project.js` / `project_ui.js` | 项目侧边栏 |
| `assets/js/workspace_code.js` | 工作中心页面 |
| `assets/js/web_term.js` | WebSSH 终端 |

### 5.5 后台管理

后台入口：登录管理员账号后访问 `/admin/`。管理员判断：`users.role = 'admin'`。主要功能：
- 渠道管理（`admin/channels.php`）：添加/编辑上游 API 厂商
- 模型管理（`admin/models.php`）：模型 CRUD、定价
- 用户管理（`admin/users.php`）：用户 CRUD、余额调整、权限开关、模拟登录
- 软件控制（`admin/software.php`）：发布客户端/安卓版本（写 settings 表）
- SK 卡管理（`admin/sk_admin.php`）：卡密生成与充值
- 支付设置（`admin/pay_settings.php`）：支付宝参数
- 站点设置（`admin/settings.php`）：全站配置
- 渠道轮询（`admin/channel_rotate.php`）：多 key 轮询、健康检查

---

## 六、桌面客户端架构（Electron）

### 6.1 技术栈

- Electron 31.3.1（Chromium + Node.js）
- 构建工具：electron-builder 24.13.3 → NSIS 安装包（Windows x64）
- 唯一 npm 运行时依赖：`ssh2`（本地 SSH 直连）
- 语言：JS，全程中文标识符命名（如 `态`、`发送()`、`收尾()`），无 TypeScript

### 6.2 目录结构

```
客户端/
├── main.js          # 主进程（窗口管理、IPC、密钥存储、更新、本地 SSH/SFTP）
├── preload.js       # 预加载脚本（暴露 window.后端 桥）
├── preload-term.js  # 终端窗口预加载
├── preload-files.js # 文件窗口预加载
├── updater.js       # 自动更新
├── preview-view.js  # 文档预览视图
├── 本地ssh.js       # 本地 SSH 直连实现
├── 本地sftp.js      # 本地 SFTP 实现
├── renderer/
│   ├── index.html   # 主窗口
│   ├── app.js       # ★ 渲染进程主逻辑（145KB）
│   ├── app.css      # 样式
│   ├── term.html    # 终端窗口
│   ├── term.js      # 终端逻辑
│   ├── files.html   # 文件窗口
│   ├── files.js     # 文件逻辑
│   ├── 卡片渲染.js  # 卡片渲染器
│   ├── 卡片适配.js  # 卡片适配层
│   ├── ssh_card.js  # SSH 卡片
│   ├── sftp_card.js # SFTP 卡片
│   ├── repo_card.js # 代码仓卡片
│   ├── ws_card.js   # 工作中心卡片
│   ├── ppt_card.js  # PPT 卡片
│   ├── web_card.js  # 网页卡片
│   ├── local_card.js# 本地文件卡片
│   └── vendor/      # xterm.js 等第三方
├── audio/           # 提示音（duck.js 音量压低）
└── build/           # 图标、签名证书 signing.pfx
```

### 6.3 关键设计

- **服务端地址**：`main.js` 顶部 `const 服务端 = 'https://codex.77bot.cn'` —— 换自建部署只改这一处
- **密钥存储**：`safeStorage` 加密（Windows DPAPI），密文存 `userData/auth.dat`，不明文落盘
- **本地 SSH**：`本地ssh.js` 用 `ssh2` 库直连服务器，独立于网页端通道（比走服务端代理快、稳）
- **渲染进程与主进程通信**：`preload.js` 暴露 `window.后端.*` 桥接方法（开始对话、停止对话、SSH 执行等）
- **暂停/停止**：客户端 `app.js` 有独立的「暂停」逻辑（`态.已停止` 标志），暂停后回执队列保留，用户再发消息时自动补发，AI 能看到暂停前的执行结果
- **自动更新**：`updater.js` 启动时请求 `api/version.php` 比对版本，有新版本提示下载安装

### 6.4 打包命令

```bash
cd /www/wwwroot/code.77bot.cn/客户端
npm run dist          # electron-builder --win --x64
# 产物：dist/岩羊Ai Setup <版本号>.exe
```

打包注意事项：
- 版本号在 `package.json` 的 `version` 字段
- 签名证书 `build/signing.pfx`，密码 `yanyang77`（在 package.json 的 win.certificatePassword）
- 打包耗时约 2~3 分钟，需在服务器上执行（有网络下载依赖）
- 产物约 76MB

---

## 七、安卓端架构（Kotlin）

### 7.1 技术栈

- Kotlin + Jetpack Compose（Material 3）
- 网络：OkHttp 4.12（SSE 流式对话）
- JSON：kotlinx-serialization
- 加密存储：AndroidX Security Crypto（EncryptedSharedPreferences）
- 构建：Gradle Kotlin DSL
- minSdk 26 / targetSdk 35 / compileSdk 35

### 7.2 源码结构

```
安卓端/
├── build.gradle.kts          # 根构建
├── settings.gradle.kts
├── gradle.properties
├── keystore.properties       # 签名配置（含密码，勿提交）
├── yanyang.jks               # 签名证书
├── gradlew                   # Gradle 包装器
└── app/
    ├── build.gradle.kts      # app 模块（versionCode=68, versionName=1.6.0）
    └── src/main/java/cn/bot77/yanyang/
        ├── 岩羊应用.kt        # Application（密钥仓初始化）
        ├── 主界面.kt          # 主界面框架
        ├── data/
        │   ├── 密钥仓.kt      # 加密密钥存储
        │   ├── 仓库.kt        # 数据仓库
        │   └── 模型.kt        # 数据模型
        ├── net/
        │   ├── 接口.kt        # API 接口定义
        │   ├── 对话流.kt      # SSE 流式对话
        │   ├── 版本检查.kt    # 版本检查
        │   └── 更新下载.kt    # 更新下载
        ├── ui/
        │   ├── 登录页.kt      # 登录
        │   ├── 对话页.kt      # 对话主界面
        │   ├── 消息条.kt      # 消息气泡
        │   ├── 工具卡片.kt    # 工具卡片渲染
        │   ├── 工具栏.kt      # 工具栏
        │   ├── 主状态.kt      # 状态管理
        │   ├── 工作中心页.kt  # 工作中心
        │   ├── 代码仓页.kt    # 代码仓
        │   ├── 充值页.kt      # 充值
        │   └── 主题.kt        # 主题
        └── media/
            ├── 提示音.kt      # 提示音
            └── 声设置.kt      # 声音设置
```

### 7.3 打包命令

```bash
cd /www/wwwroot/code.77bot.cn/安卓端
./gradlew assembleRelease
# 产物：app/build/outputs/apk/release/app-release.apk
```

打包注意事项：
- `app/build.gradle.kts` 中 `versionCode` / `versionName` 控制版本
- 签名从 `keystore.properties` 读取（storeFile/storePassword/keyAlias/keyPassword）
- 有签名证书才出签名包；release 开启 minify + shrinkResources
- 打出的包重命名后发布，同时更新 settings 表（见第九节）

---

## 八、API 接口清单

### 8.1 对话相关

| 接口 | 方法 | 说明 |
|---|---|---|
| `api/chat.php` | POST | 流式对话（SSE），核心接口。参数：conv_id, project_id, model_id, content, images[], tool_kind, executed_tools |
| `api/chat_resume.php` | POST | 断点续聊 |
| `api/chat_stop.php` | POST | 停止生成 |
| `api/conv.php` | GET/POST | 会话列表/新建/删除 |
| `api/project.php` | GET/POST | 项目 CRUD |

### 8.2 工具相关

| 接口 | 方法 | 说明 |
|---|---|---|
| `api/ssh_hosts.php` | GET/POST/DELETE | SSH 主机 CRUD |
| `api/ssh_run.php` | POST | 命令执行 |
| `api/sftp.php` | POST | SFTP 操作（list/read/write/patch/delete） |
| `api/repo.php` | POST | 代码仓操作 |
| `api/ws.php` | POST | 工作中心操作 |
| `api/web.php` | POST | 网页抓取 |
| `api/ppt.php` | POST | PPT 生成 |
| `api/upload.php` | POST | 图片上传（返回 id） |
| `api/img.php` | GET | 图片鉴权输出 |

### 8.3 用户 / 版本 / 其他

| 接口 | 方法 | 说明 |
|---|---|---|
| `api/me.php` | GET | 当前用户信息（含余额） |
| `api/models.php` | GET | 可用模型列表 |
| `api/version.php` | GET/POST | ★ 客户端版本检查（公开，不登录）。参数：cur 当前版本、plat 平台、side=android |
| `api/profile.php` | GET/POST | 个人资料 |
| `api/pay.php` | POST | 支付相关 |
| `api/usage.php` | GET | 用量统计 |
| `api/favorite.php` | POST | 收藏消息 |
| `api/theme.php` | POST | 主题切换 |
| `api/save_tools.php` | POST | 保存工具权限开关 |

### 8.4 开放 API（v1）

`/v1/index.php` 提供面向第三方开发者的 OpenAI 兼容接口（类似中转站），管理端可生成用户 API Key（`user_api_keys` 表）。文档页：`/v1/docs.php`。

---

## 九、版本发布流程

### 9.1 Windows 客户端发布

```bash
# 1. 修改版本号
cd /www/wwwroot/code.77bot.cn/客户端
# 编辑 package.json 的 version 字段（如 1.2.13 → 1.2.14）

# 2. 打包
npm run dist
# 产物：dist/岩羊Ai Setup 1.2.14.exe

# 3. 部署到下载目录
cp -f "dist/岩羊Ai Setup 1.2.14.exe" /www/wwwroot/code.77bot.cn/download/
cp -f "dist/岩羊Ai Setup 1.2.14.exe" /www/wwwroot/code.77bot.cn/download/yanyang-ai-setup-1.2.14.exe
chown www:www /www/wwwroot/code.77bot.cn/download/*1.2.14.exe

# 4. 计算校验值
sha256sum /www/wwwroot/code.77bot.cn/download/岩羊Ai\ Setup\ 1.2.14.exe
stat -c %s /www/wwwroot/code.77bot.cn/download/岩羊Ai\ Setup\ 1.2.14.exe

# 5. 更新数据库 settings 表
mysql -u8800demo -p8800demo 8800demo -e "
UPDATE settings SET v='1.2.14' WHERE k='client_version';
UPDATE settings SET v='/download/yanyang-ai-setup-1.2.14.exe' WHERE k='client_dl_win';
UPDATE settings SET v='<sha256>' WHERE k='client_sha_win';
UPDATE settings SET v='<size>' WHERE k='client_size_win';
UPDATE settings SET v='<更新说明>' WHERE k='client_notes';
"
```

也可在后台「软件控制」页操作（`admin/software.php`）。

### 9.2 安卓发布

```bash
# 1. 修改版本号（app/build.gradle.kts）
#    versionCode +1，versionName 更新

# 2. 打包
cd /www/wwwroot/code.77bot.cn/安卓端
./gradlew assembleRelease
# 产物：app/build/outputs/apk/release/app-release.apk

# 3. 部署 + 更新 settings 表（android_version / android_version_code / android_dl_apk / android_sha_apk / android_size_apk / android_notes）
```

### 9.3 版本检查逻辑（api/version.php）

- 客户端/安卓端启动时带 `cur=当前版本` 请求
- 服务端读 settings 表 `client_version`（或 `android_version`）与当前版本比对
- `client_update_on=1` 才检测；`client_force=1` 或低于 `client_min_version` 时强制更新
- 返回 `{update, latest, force, url, sha256, size, notes}`

---

## 十、AI 系统提示词（soul.md）

`inc/soul.md` 是 AI 对话的核心人格与行为准则，定义了：
- 三条铁律（只用简体中文、守住助手身份、拒绝越界请求）
- 专业领域边界（医疗/法律/金融不下诊断）
- 提示词注入应对（外部内容视为不可信数据）
- 输出纪律（禁止自述铺垫、简洁高效、一条回复一个卡片等）
- 工具使用规范（ssh_exec / sftp_* / file_* / ws_* / web_open 等的使用方式）

> 修改 AI 行为、增加工具、调整输出风格，主要改这个文件和相关 `*_prompt.php`。

---

## 十一、工具系统架构

平台核心是「AI 输出工具卡片 → 前端执行 → 回执回传」的循环：

### 11.1 工具定义（服务端）

`inc/tools_schema.php` 定义 OpenAI Function Calling 格式的工具清单：

| 工具名 | 功能 | 前端卡片文件 |
|---|---|---|
| `ssh_exec` | 服务器执行命令 | ssh_card.js |
| `sftp_list/read/write/patch/delete` | SFTP 文件操作 | sftp_card.js |
| `file_list/read/write/patch/delete/push/pull` | 代码仓操作 | repo_card.js |
| `ws_list/read/write/patch/delete/zip` | 工作中心文件 | ws_card.js |
| `web_open` | 抓取网页 | web_card.js |
| `web_search` | 实时搜索 | web_card.js |
| `ppt_generate` | 生成 PPT | ppt_card.js |

### 11.2 执行链路

```
AI 输出 ```ssh-exec ...``` 代码块
  → 前端 chat.js 检测到代码块 → 渲染成可执行卡片
  → （无等待确认标记时）自动执行：卡片 JS 调用 api/ssh_run.php 或本地通道
  → 执行结果通过 fillXxxResult 回调 → 交回执(文, 'ssh')
  → 若在生成中则入队，轮末统一经 api/chat.php 的 tool_kind 参数回传
  → 服务端写入 toolresults 文件 → AI 下一轮读取到回执继续干活
```

### 11.3 用户权限控制

每个用户的工具权限在 users 表（`tool_*` 开关 + `cap_*` 开关），服务端在 `api/chat.php` / `api/ssh_run.php` 等处校验。管理员在后台用户编辑页调整。

---

## 十二、技术依赖与扩展清单

> 本节是换服务器、搭新环境时最重要的对照表：**哪些扩展是必需的、哪些其实用不到、每个工具开关背后依赖什么**。按本节清单核对，避免多装（浪费）或少装（功能报错）。

### 12.1 PHP 扩展清单（服务端）

当前服务器 `php -m` 实际已装的扩展里，**必需项**与**非必需项**如下：

| 扩展 | 是否必需 | 用途 | 使用位置 |
|---|---|---|---|
| pdo_mysql | ✅ 必需 | 数据库访问（全站数据读写） | inc/db.php、全站 |
| curl | ✅ 必需 | 网页抓取（web_open/web_search）、上游渠道 API 转发 | inc/web_fetch.php、inc/upstream.php |
| mbstring | ✅ 必需 | 中文处理、字符串截断、编码转换（100+ 处调用） | 全站 |
| openssl | ✅ 必需 | 密码哈希、服务器密码/API Key 加密（crypto.php）、SMTP SSL、支付回调验签；phpseclib SSH 加解密依赖 | inc/crypto.php、inc/email.php、inc/pay.php |
| dom | ✅ 必需 | 网页正文提取（DOMDocument/DOMXPath 解析 HTML） | inc/web_fetch.php |
| libxml | ✅ 必需 | 与 dom 配套，`libxml_use_internal_errors()` 容错解析 HTML | inc/web_fetch.php |
| gd | ✅ 必需 | 登录验证码（imagecreatetruecolor/imagepng）、二维码输出（imagecreate） | inc/helpers.php、inc/qrcode.php |
| zip | ✅ 必需 | 工作中心打包（ws_zip）、代码仓压缩包上传解压（ZipArchive） | inc/ws_zip.php、inc/repo.php |
| json | ✅ 必需 | API 序列化、回执存储（JSONL） | 全站 |
| opcache | ✅ 推荐 | PHP 字节码缓存，提速 | php.ini |
| posix | ⚠️ 建议装 | `posix_geteuid()` 判断 root 权限（带 function_exists 检查，缺了只是少一层防护，不影响主功能） | inc/helpers.php、inc/crypto.php |
| sockets | ⚠️ 已装可用 | WebSocket SSH 用的是 PHP 核心 `stream_socket_*`，**不依赖 sockets 扩展**；装了无妨 | admin/wsssh_server.php |
| **ssh2** | ❌ **不需要** | **项目 SSH/SFTP 全部走 phpseclib3（composer 纯 PHP 库），代码里没有 ssh2_connect 调用**，见 inc/ssh_run.php 文件头注释 | — |
| redis | ❌ 不需要 | 已装但项目代码未直接使用（并发控制走数据库表 concurrency_slots，不用 redis） | — |
| yac | ❌ 不需要 | 已装但项目代码未直接使用 | — |
| pcntl | ❌ 不需要 | wsssh_server.php 未调用 pcntl_fork，进程由 systemd 托管 | — |
| fileinfo | ⚠️ 非必需 | **代码未直接调用 finfo_open / mime_content_type**（上传类型判断用 getimagesize + 扩展名白名单）；宝塔默认装上，不影响功能 | — |
| lexbor | ⚠️ 非必需 | PHP 8.4+ 的 HTML 解析扩展，已装但项目未使用（网页解析走 DOMDocument） | — |
| sodium / gmp | ⚠️ 可选优化 | phpseclib3 检测到会用于加解密 / 大数运算加速，缺了自动降级纯 PHP 实现 | vendor/phpseclib（自动检测） |

> ⚠️ **踩坑提醒 1**：换服务器时**不要**因为「PHP 扩展含 ssh2」就认为必须装 ssh2 扩展——本项目真正必需的是 **phpseclib3（composer 装）**。ssh2/redis/yac 装了纯属备用，没装也不影响任何功能。
> ⚠️ **踩坑提醒 2**：php.ini 的 `disable_functions` **不要禁 proc_open / exec**（ppt.php、office_preview.php 用它调外部程序）；shell_exec 可禁（项目不用）。宝塔默认禁 shell_exec 不禁 proc_open，保持默认即可。

### 12.2 Composer 依赖（vendor/）

`composer.json` 内容：

```json
{
    "require": {
        "php": ">=7.4",
        "phpseclib/phpseclib": "^3.0"
    }
}
```

- 唯一第三方库：**phpseclib/phpseclib ^3.0**（纯 PHP 实现的 SSH / SFTP / 加密库，无编译依赖）
- 它被 SSH 命令执行、SFTP 操作、代码仓同步（repo_sync）、WebSocket SSH 终端共用
- 迁移环境后执行 `composer install` 即可还原 vendor/

### 12.3 外部程序依赖

| 程序 | 版本 | 用途 | 调用方 |
|---|---|---|---|
| Python | 3.11.7（`/usr/bin/python3`，实测版本） | PPT 生成排版 | inc/ppt.php 用 `proc_open` 调 `inc/ppt_build.py` |
| python-pptx | 0.6.23 | PPT 生成的 Python 库 | inc/ppt_build.py |
| LibreOffice | 已装（`/usr/bin/soffice`） | **Office 文档在线预览**（docx/xlsx/pptx → PDF 再转图） | inc/office_preview.php（proc_open 调用） |
| poppler-utils | 已装（`/usr/bin/pdftoppm`） | PDF 转图片（Office 预览和 PDF 预览用） | inc/office_preview.php |
| Node.js | v24.18.0 | 打包 Electron 客户端 | 客户端/（打包机） |
| systemd | — | 托管 WebSocket SSH 终端服务 | wsssh-8801.service |

> ⚠️ **注意 1**：ppt.php、office_preview.php 特意用 `proc_open` 而不是 `shell_exec`——宝塔默认把 shell_exec 放进 disable_functions，proc_open/exec 没被禁。换服务器时若 php.ini 禁了 proc_open，PPT 生成和 Office 预览会失效。
> ⚠️ **注意 2**：Office 在线预览依赖 LibreOffice + pdftoppm，两者缺一会导致 `office_preview.php` 返回「服务器未安装 LibreOffice，无法生成预览」（探测命令 `command -v soffice || command -v libreoffice`、`command -v pdftoppm`）。
> ⚠️ **注意 3**：LibreOffice 首次调用较慢（秒级），`office_preview.php` 里有超时控制（约 20 秒）和并发锁，迁移后建议先手动跑一次转 PDF 验证。

### 12.4 WebSocket SSH 终端架构（wsssh）

「网页终端 / 桌面终端」功能使用的 WebSocket 服务，**不是**第三方 WS 框架：

```
浏览器 / 客户端
   │  wss://code.77bot.cn/ws/...
   ▼
Nginx（location /ws/ → 127.0.0.1:8801，带 Upgrade 头）
   ▼
WebSocket SSH 服务（admin/wsssh_server.php，PHP 原生 stream_socket_server 实现 WebSocket 协议）
   │  端口 8801，只听 127.0.0.1（不暴露公网）
   ▼
phpseclib3 → 目标服务器 SSH/SFTP
```

关键点：

| 项 | 值 |
|---|---|
| 服务文件 | `admin/wsssh_server.php`（启动：`php admin/wsssh_server.php 8801`） |
| 监听 | `127.0.0.1:8801`（只在回环，公网不可直达） |
| WebSocket 实现 | PHP 原生 `stream_socket_server` + 手动握手，**无 swoole / workerman / Ratchet** |
| SSH 库 | phpseclib3 |
| 进程托管 | systemd 单元 `wsssh-8801.service`（Restart=always、开机自启） |
| Nginx 反代 | `location /ws/ { proxy_pass http://127.0.0.1:8801/; Upgrade 头; proxy_read_timeout 3600s; }` |
| 前端 | 网页端 `assets/js/web_term.js`；桌面端 `客户端/renderer/term.js` |
| 鉴权 | `api/wsssh_token.php` 颁发一次性短时令牌（wsssh_tokens 表） |
| 会话记录 | `assets/wsssh/sessions.json`（活动会话快照） |
| 启停命令 | `systemctl start/stop/restart wsssh-8801`；后台 `admin/wsssh_ctrl.php` 可视化控制 |

> ⚠️ 后台「服务器管理」页 PID 显示与 wsssh 服务状态依赖 `sudo systemctl show -p MainPID`，已通过 sudoers 放行；换服务器时记得配置对应 sudoers 规则。

### 12.5 桌面客户端依赖（Electron）

`客户端/package.json`：

```json
{
  "dependencies": { "ssh2": "^1.17.0" },
  "devDependencies": {
    "app-builder-bin": "^4.0.0",
    "electron": "^31.3.1",
    "electron-builder": "24.13.3"
  }
}
```

- **ssh2 ^1.17.0**：客户端**本地** SSH/SFTP 直连（`本地ssh.js` / `本地sftp.js`），走 Node 原生实现，不经服务端代理——这就是为什么之前提示「node 升级好了」后客户端本地 SSH 就能用
- electron 31.3.1 + electron-builder 24.13.3：仅打包期使用
- 打包机需要 Node.js v24（服务器已装）

### 12.6 安卓端依赖（Kotlin）

`安卓端/app/build.gradle.kts` 关键依赖：

| 依赖 | 版本 | 用途 |
|---|---|---|
| compose-bom | 2024.09.02 | Jetpack Compose（Material 3）UI |
| okhttp | 4.12.0 | HTTP + SSE 流式对话 |
| kotlinx-serialization-json | 1.7.3 | JSON 解析 |
| security-crypto | 1.1.0-alpha06 | 密钥加密存储（EncryptedSharedPreferences） |
| lifecycle / activity-compose | 2.8.x | 生命周期与 Compose 集成 |

- 构建需要 JDK + Android SDK，签名用 `yanyang.jks`（keystore.properties 里配密码）

### 12.7 22 个工具开关与依赖对照表（★ 核心）

`users` 表里 22 个 `tool_*` 字段（每个用户独立开关，1=开 0=关），对应关系如下：

| # | users 表开关字段 | AI 工具名 | 服务端实现 | 运行依赖 |
|---|---|---|---|---|
| 1 | `tool_ssh_exec` | ssh_exec | inc/ssh_run.php | phpseclib3（纯 PHP） |
| 2 | `tool_sftp_list` | sftp_list | inc/sftp_ops.php | phpseclib3 SFTP |
| 3 | `tool_sftp_read` | sftp_read | inc/sftp_ops.php | phpseclib3 SFTP |
| 4 | `tool_sftp_write` | sftp_write | inc/sftp_ops.php | phpseclib3 SFTP |
| 5 | `tool_sftp_patch` | sftp_patch | inc/sftp_ops.php | phpseclib3 SFTP |
| 6 | `tool_sftp_delete` | sftp_delete | inc/sftp_ops.php | phpseclib3 SFTP |
| 7 | `tool_file_list` | file_list | inc/repo.php | PHP 文件系统 |
| 8 | `tool_file_read` | file_read | inc/repo.php | PHP 文件系统 |
| 9 | `tool_file_write` | file_write | inc/repo.php | PHP 文件系统 |
| 10 | `tool_file_patch` | file_patch | inc/repo.php | PHP 文件系统 |
| 11 | `tool_file_delete` | file_delete | inc/repo.php | PHP 文件系统 |
| 12 | `tool_file_push` | file_push | inc/repo_sync.php | phpseclib3 SFTP + zip |
| 13 | `tool_file_pull` | （拉取，schema 当前未启用） | inc/repo_sync.php | phpseclib3 SFTP |
| 14 | `tool_ws_list` | ws_list | inc/ws_files.php | PHP 文件系统 |
| 15 | `tool_ws_read` | ws_read | inc/ws_files.php | PHP 文件系统 |
| 16 | `tool_ws_write` | ws_write | inc/ws_files.php | PHP 文件系统 |
| 17 | `tool_ws_patch` | ws_patch | inc/ws_files.php | PHP 文件系统 |
| 18 | `tool_ws_delete` | ws_delete | inc/ws_files.php | PHP 文件系统 |
| 19 | `tool_ws_zip` | ws_zip | inc/ws_zip.php | **zip 扩展** |
| 20 | `tool_web_open` | web_open | inc/web_fetch.php | **curl + dom + libxml 扩展**（抓取用 curl，正文提取用 DOMDocument） |
| 21 | `tool_web_search` | web_search | inc/web_fetch.php | **curl + dom + libxml 扩展** |
| 22 | `tool_ppt_generate` | ppt_generate | inc/ppt.php + inc/ppt_build.py | **Python3.11 + python-pptx + proc_open** |

说明：

- 工具定义在 `inc/tools_schema.php`（OpenAI Function Calling 格式），按开关字段决定是否把该工具注入给 AI；
- 执行入口 `inc/tool_execute.php` 按工具名分发到对应实现文件；
- 权限双重校验：`tools_schema.php` 注入时看开关，`tool_execute.php` 执行时再看 `cap_*` 字段；
- 管理员在后台用户编辑页（`admin/user_edit.php`）调整这些开关；
- 前端卡片渲染文件对应关系见「11.1 工具定义」表。

### 12.8 数据与存储目录（落盘位置）

| 数据 | 位置 | 说明 |
|---|---|---|
| 工具回执 | `DATA_DIR/toolresults/<user_id>/<conv_id>.txt` | JSONL 每行一条，见 inc/tool_results.php |
| 工作中心文件 | `DATA_DIR/ws_files/<user_id>/<年月>/` | 按用户隔离，见 inc/ws_files.php |
| 上传图片 | `DATA_DIR/uploads/` | 网站根之外，api/img.php 鉴权输出 |
| 代码仓本地副本 | `DATA_DIR/repos/<user_id>/` | 服务器端代码仓落盘 |
| SFTP 自动备份 | 站点根 `.kiro_backup/` | 平台改文件自动备份，可还原 |

> `DATA_DIR` 实际值为 `/www/wwwdata/8800demo/`（config.php 按 open_basedir 探测，见代码注释）。

---

## 十三、常见运维操作

### 13.1 查看站点日志

```bash
# PHP 错误日志（宝塔面板路径）
tail -f /www/wwwroot/code.77bot.cn/php_error.log 2>/dev/null
# 或
tail -f /www/server/php/85/var/log/php-fpm.log

# Nginx 日志
tail -f /www/wwwlogs/code.77bot.cn.log
```

### 13.2 查看数据库

```bash
mysql -u8800demo -p8800demo 8800demo
```

### 13.3 重启 PHP / Nginx（宝塔）

```bash
/etc/init.d/php-fpm-85 restart
/etc/init.d/nginx restart
```

### 13.4 检查渠道健康

```bash
# 渠道健康状态表
SELECT * FROM channel_sk_health;
# 后台：admin/channel_rotate.php 有可视化管理
```

### 13.5 检查并发

```bash
# 后台 admin/concurrency_monitor.php 可视化
# 或查表
SELECT * FROM concurrency_slots;
```

### 13.6 数据备份

- 数据库：`mysqldump -u8800demo -p8800demo 8800demo > backup.sql`
- 数据目录：`/www/wwwdata/8800demo/`（含工具回执、上传文件，务必一起备份）
- 部署目录：`/www/wwwroot/code.77bot.cn`（代码）

### 13.7 文件修改自动备份

平台集成了 SFTP 修改自动备份机制：通过平台 SFTP 通道改文件时，原文件自动备份到 `.kiro_backup/` 目录并登记，改坏了可一键还原。命令行直接改文件**没有**这层保护，改文件优先用平台的 SFTP 通道。

---

## 十四、二次开发指南

### 14.1 新增一个 AI 工具（如新增「数据库查询」工具）

1. `inc/tools_schema.php` 添加工具定义（name、description、parameters JSON Schema）
2. 写对应的 prompt 文件（如 `inc/db_prompt.php`）并在 `api/chat.php` require
3. 写执行逻辑（如 `api/db_query.php` + `inc/db_query.php`）
4. 前端写卡片渲染（`assets/js/db_card.js`）并注册 `window.fillDbResult` 回调
5. `chat.js` 的卡片自动执行调度加对应分支
6. users 表加 `tool_db_query` / `cap_db_query` 权限字段，后台用户编辑页加开关
7. 桌面客户端 `renderer/卡片渲染.js` 和 `renderer/卡片适配.js` 同步支持
8. 安卓端 `ui/工具卡片.kt` 同步支持

### 14.2 新增模型渠道

后台「渠道管理」添加渠道（填 base_url、api_key、协议类型），再在「模型管理」添加模型并选渠道，前端自动出现。

### 14.3 修改 AI 行为

改 `inc/soul.md`（人格）或各 `*_prompt.php`（工具使用说明）。

### 14.4 多语言/品牌改名

- 站点名：settings 表 `site_name`
- 客户端产品名：`客户端/package.json` 的 `productName`，重新打包
- 安卓应用名：`安卓端/app/src/main/` 的 strings.xml

### 14.5 换服务器迁移

1. 打包代码目录 + `mysqldump` 导出数据库 + 备份 `/www/wwwdata/8800demo/`
2. 新服务器装同版本 Nginx / PHP 8.5 / MySQL 5.7，PHP 扩展按「12.1 PHP 扩展清单」装必需项：**pdo_mysql、curl、mbstring、openssl、dom、libxml、gd、zip、json、opcache（posix 建议装）**；**ssh2/redis/yac/fileinfo 非必需**；再 `composer install` 还原 phpseclib3、装 **Python3.11 + python-pptx、LibreOffice（soffice）+ poppler-utils（pdftoppm）**、配好 systemd 的 wsssh-8801 服务（含 sudoers 规则，见 12.4）
3. 还原代码、数据库、数据目录
4. 改 `inc/config.local.php` 数据库配置
5. 改客户端 `main.js` 的 `服务端` 常量，重新打包
6. 改安卓端 `net/接口.kt` 的服务端地址，重新打包
7. 检查 `.user.ini` 的 open_basedir / session 路径
8. 更新域名解析

---

## 十五、当前线上版本（截至 2026-08-24）

| 端 | 版本 | 下载 |
|---|---|---|
| 网页端 | 随代码更新（无版本号概念） | https://code.77bot.cn |
| Windows 客户端 | 1.2.13 | /download/yanyang-ai-setup-1.2.13.exe |
| 安卓 App | 1.6.2（versionCode 70） | /download/岩羊AI-v1.6.2.apk |

---

## 十六、注意事项与约定

1. **代码里大量使用中文标识符**（变量名、函数名、类名），这是本项目的刻意约定，接手开发请保持一致，便于跨端理解。
2. **工具回执存文件不存库**：`DATA_DIR/toolresults/<user_id>/<conv_id>.txt`，备份时别漏。
3. **settings 表是全局配置中心**：改配置优先改这里，别硬编码在代码里。
4. **`.kiro_backup/` 是平台自动备份目录**：改文件出错时在这里找还原点，但别把它当版本库，代码本身没有 git 历史（目录无 .git），建议接手后尽快初始化 git 做基线。
5. **证书与密钥**：`build/signing.pfx`（客户端签名）、`yanyang.jks`（安卓签名）、`config.local.php`（数据库密码）、`inc/crypto.php` 相关密钥，都需妥善保管。
6. **支付/邮件等外部服务**：支付宝（alipay_*）、163 邮箱 SMTP（email_smtp_*），凭据都在 settings 表。
7. **并发控制**：SK 卡模式下并发上限在 settings 表 `concurrency_limit`，做活动放量时注意调整。
8. **安全**：v1 开放接口、工具权限（cap_*）在用户维度做了隔离，新增功能时保持同样的鉴权习惯，避免越权。

---

*文档完。如有疑问，可结合代码注释（代码里注释非常详尽）进一步排查。*
