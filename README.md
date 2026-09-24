# openyanyang

**岩羊AI 智能开发平台 —— 全端源码**

一个多端 AI 对话与开发助手平台：AI 不只是聊天，还能直连服务器执行命令、读写文件、改代码、查日志，并聚合多家大模型完成计费与调度。

<p>
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white">
  <img alt="JavaScript" src="https://img.shields.io/badge/JavaScript-Native-F7DF1E?logo=javascript&logoColor=black">
  <img alt="Electron" src="https://img.shields.io/badge/Electron-31.3.1-47848F?logo=electron&logoColor=white">
  <img alt="Kotlin" src="https://img.shields.io/badge/Kotlin-Compose-7F52FF?logo=kotlin&logoColor=white">
  <img alt="MySQL" src="https://img.shields.io/badge/MySQL-5.7-4479A1?logo=mysql&logoColor=white">
</p>

---

## 简介

岩羊AI 是一个多端 AI 对话平台，三个入口共用同一套后端 API：

| 端 | 技术栈 | 说明 |
|---|---|---|
| **网页端** | PHP 8.5 + 原生 JS + SSE | 主站，含对话、充值、个人中心、工作中心、模型广场 |
| **桌面客户端** | Electron 31.3.1（Windows x64） | 支持本地 SSH / SFTP 直连 |
| **安卓端** | Kotlin + Jetpack Compose（Material 3） | OkHttp SSE 流式对话 |

核心能力：

- **SSH 直连**——连接用户服务器执行命令、装环境、看日志、排故障
- **SFTP 读写**——直接编辑服务器文件，改前自动备份、可还原
- **代码仓**——本地副本 + 回传，安全地改客户网站代码
- **工作中心**——云端文件库，持久化保存产出的代码 / 文档 / 脚本
- **模型聚合**——OpenAI / Claude / DeepSeek / Kimi / Gemini 等多上游，支持模型切换、价格计费、余额扣费
- **工具链**——PPT 生成、网页抓取、实时搜索、Office 文档在线预览

---

## 目录结构

```
openyanyang/
├── web/                    # 网页端（PHP）
│   ├── inc/                #   后端核心库（helpers / db / crypto / ssh_run / upstream …）
│   ├── api/                #   接口层（chat / conv / sub / pay / ws / ticket …）
│   ├── admin/              #   管理后台
│   ├── assets/             #   前端资源（js / css）
│   ├── views/              #   视图片段
│   ├── pay/                #   支付回调（支付宝）
│   ├── sql/                #   建表与迁移脚本
│   └── tools/              #   运维脚本
│
├── client/                 # 桌面客户端（Electron）
│   ├── main.js             #   主进程：窗口 / IPC / 密钥存储 / 更新 / 本地 SSH-SFTP
│   ├── preload*.js         #   预加载脚本（contextIsolation）
│   └── renderer/           #   渲染层（对话、工具卡片、代码仓、WebSSH）
│
├── android/                # 安卓端（Kotlin）
│   └── app/src/main/java/cn/bot77/yanyang/
│       ├── net/            #   接口 / 对话流 / 更新下载
│       ├── ui/             #   Compose 页面（对话页 / 登录页 / 充值页 / 工作中心 …）
│       └── data/           #   数据仓库
│
├── database/               # 数据库
│   └── 8800demo_clean.sql  #   干净数据库：45 张表结构 + 核心配置（脱敏）
│
└── docs/                   # 项目文档
    ├── 岩羊Ai平台交接文档.md
    └── 部署说明.md
```

---

## 技术栈

### 网页端

- **PHP 8.5**（FPM）+ Nginx
- **MySQL 5.7**，库名 `8800demo`，共 **45 张表**
- 原生 JS + **SSE** 流式对话，无框架
- SSH / SFTP 走 **phpseclib 3**（纯 PHP，不依赖 ssh2 扩展）
- 依赖极少：`phpseclib/phpseclib`、`paragonie/constant_time_encoding`、`paragonie/random_compat`

### 桌面客户端

- **Electron 31.3.1** + electron-builder 24.13.3 → NSIS 安装包
- 唯一 npm 运行时依赖：`ssh2`（本地 SSH 直连）
- 全程中文标识符命名，无 TypeScript

### 安卓端

- **Kotlin + Jetpack Compose**（Material 3）
- 网络：**OkHttp 4.12**（SSE 流式）
- JSON：kotlinx-serialization ｜ 加密存储：AndroidX Security Crypto
- 构建：Gradle Kotlin DSL ｜ minSdk 26 / targetSdk 35 / compileSdk 35

---

## 快速开始

### 网页端

```bash
cd web
composer install                 # 安装 phpseclib 等依赖

# 配置数据库连接：inc/config.php，生产环境用 inc/config.local.php 覆盖
#   DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS

mysql -u<用户> -p <数据库名> < ../database/8800demo_clean.sql
```

### 桌面客户端

```bash
cd client
npm install
npm start                        # 开发运行
npm run dist                     # 打包 → dist/岩羊Ai Setup <版本>.exe
```

### 安卓端

```bash
cd android
./gradlew assembleRelease        # 产物：app/build/outputs/apk/release/app-release.apk
```

---

## 数据库

`database/8800demo_clean.sql` 为**脱敏演示库**，含 45 张表结构 + 核心配置数据，可直接导入跑通。

核心表分组：用户与认证、模型与渠道、对话、项目 / 服务器 / 代码仓 / 工作中心、计费与支付、安全与运维。

---

## 文档

| 文档 | 内容 |
|---|---|
| [岩羊Ai平台交接文档](docs/岩羊Ai平台交接文档.md) | 项目总览、环境信息、目录结构、数据库设计、三端架构、API 清单、发布流程 |
| [部署说明](docs/部署说明.md) | 部署步骤、数据库导入、站点配置、数据目录说明 |

---

## 许可

MIT
