<?php
require_once __DIR__ . '/inc/helpers.php';
// 自动创建必要的新文件（一次性）
if (!is_file(__DIR__ . '/captcha.php')) {
    @file_put_contents(__DIR__ . '/captcha.php', <<<'PHP'
<?php
require_once __DIR__ . '/inc/helpers.php';
captcha_output();
PHP
    );
}
if (!is_file(__DIR__ . '/admin/servers.php')) {
    $dir = __DIR__ . '/admin';
    if (is_dir($dir)) {
        @file_put_contents($dir . '/servers.php', 'placeholder');
    }
}
// 数据库迁移：自动添加必要字段（一次性）
if (!is_file(__DIR__ . '/inc/migration_done.lock')) {
    $db = db();
    $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('register_ip', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN register_ip VARCHAR(45) NOT NULL DEFAULT '' AFTER token_quota");
    }
    if (!in_array('login_fail_count', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN login_fail_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER register_ip");
    }
    @file_put_contents(__DIR__ . '/inc/migration_done.lock', date('c'));
}
// 登录失败改为每小时滚动计数，需要 login_fail_at 存本轮窗口起点。
// 单独一把锁：上面那把在老站点上早就存在了，加进去不会被执行。
if (!is_file(__DIR__ . '/inc/migration_fail_window.lock')) {
    $db = db();
    $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('login_fail_at', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN login_fail_at DATETIME NULL"
            . " COMMENT '本轮登录失败计数的起始时间' AFTER login_fail_count");
    }
    @file_put_contents(__DIR__ . '/inc/migration_fail_window.lock', date('c'));
}
// 验证码路由：/index.php?route=captcha （兼容不用新文件的情况）
if (($_GET['route'] ?? '') === 'captcha') {
    require_once __DIR__ . '/inc/helpers.php';
    captcha_output();
}
require_once __DIR__ . '/inc/helpers.php';
// 首页对登录和未登录用户都展示。登录态只影响导航区与主按钮文案。
$当前用户 = current_user();
$siteName = app_name();
$notice = trim((string) setting_get('site_notice', ''));
// 后台一个下载地址都没配时这里是空数组，下面据此整个按钮都不渲染
$downloads = download_links();
// 首页展示用的库内统计
try {
    $模型数 = (int) db()->query("SELECT COUNT(*) FROM models WHERE status = 1")->fetchColumn();
    $厂商数 = (int) db()->query("SELECT COUNT(DISTINCT channel_id) FROM models WHERE status = 1")->fetchColumn();
    $用户数 = (int) db()->query("SELECT COUNT(*) FROM users WHERE status = 1")->fetchColumn();
} catch (Throwable $e) {
    $模型数 = 0;
    $厂商数 = 0;
    $用户数 = 0;
}
/**
 * 读桌面客户端的版本号。直接取客户端目录里的 package.json，
 * 这样发新版本只要打包完就自动同步到首页，不用再改这里。
 * 文件缺失或格式坏了就返回空串，页面上对应的地方不显示版本。
 */
function 客户端版本(): string
{
    $文件 = __DIR__ . '/客户端/package.json';
    if (!is_file($文件)) {
        return '';
    }
    $j = json_decode((string) @file_get_contents($文件), true);
    return is_array($j) && isset($j['version']) ? (string) $j['version'] : '';
}
$客户端版本 = 客户端版本();
/**
 * 读安卓端最新版本号。直接从数据库 settings 表取 android_version，
 * 后台发版时更新数据库后首页自动同步，不再依赖扫描 apk 目录文件名。
 */
function 安卓版本(): string
{
    return trim((string) setting_get('android_version', ''));
}
$安卓版本 = 安卓版本();
// 安卓包的直链，从数据库读取，给顶部版本条和安卓专区的下载按钮共用
$安卓包 = trim((string) setting_get('android_dl_apk', ''));
/**
 * 首页用的线性图标。nav_icon() 只覆盖导航那几个，这里补齐首页要用的。
 */
function 首页图标(string $名): string
{
    $图形 = [
        'chat'     => '<path d="M21 11.5a8 8 0 0 1-8 8H8l-4 3v-5.6A8 8 0 1 1 21 11.5z"/><line x1="8.5" y1="11" x2="15.5" y2="11"/>',
        'server'   => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><line x1="7" y1="7.5" x2="7.01" y2="7.5"/><line x1="7" y1="16.5" x2="7.01" y2="16.5"/>',
        'code'     => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'slides'   => '<rect x="3" y="4" width="18" height="12" rx="2"/><line x1="12" y1="16" x2="12" y2="20"/><line x1="8" y1="20" x2="16" y2="20"/>',
        'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        'folder'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'layers'   => '<polygon points="12 2 22 8.5 12 15 2 8.5 12 2"/><polyline points="2 15.5 12 22 22 15.5"/>',
        // 客户端专区用的几个
        'terminal' => '<rect x="2.5" y="4" width="19" height="16" rx="2"/><polyline points="7 9 10 12 7 15"/><line x1="12.5" y1="15" x2="17" y2="15"/>',
        'shield'   => '<path d="M12 2.5 20 6v6c0 5-3.4 8.4-8 9.5-4.6-1.1-8-4.5-8-9.5V6z"/><polyline points="9 12 11.2 14.2 15.5 10"/>',
        'download' => '<path d="M12 3v12"/><polyline points="7.5 10.5 12 15 16.5 10.5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'refresh'  => '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><polyline points="20.5 4 20.5 9.5 15 9.5"/>',
        'clip'     => '<rect x="8" y="3" width="8" height="4" rx="1"/><path d="M8 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>',
        'zap'      => '<polygon points="13 2 4 14 11 14 10 22 20 9 13 9 13 2"/>',
        'window'   => '<rect x="2.5" y="3.5" width="19" height="17" rx="2"/><line x1="2.5" y1="8.5" x2="21.5" y2="8.5"/><circle cx="6" cy="6" r="0.6"/><circle cx="8.5" cy="6" r="0.6"/>',
        'plug'     => '<path d="M9 2v6"/><path d="M15 2v6"/><path d="M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v5"/>',
        // 三端分区用的
        'globe'    => '<circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z"/>',
        'phone'    => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><line x1="10.8" y1="18.4" x2="13.2" y2="18.4"/>',
        'desktop'  => '<rect x="2.5" y="3.5" width="19" height="13" rx="2"/><line x1="8" y1="20.5" x2="16" y2="20.5"/><line x1="12" y1="16.5" x2="12" y2="20.5"/>',
        'mic'      => '<rect x="9" y="2.5" width="6" height="11" rx="3"/><path d="M5.5 11.5a6.5 6.5 0 0 0 13 0"/><line x1="12" y1="18" x2="12" y2="21.5"/>',
        'bell'     => '<path d="M18 15V10a6 6 0 1 0-12 0v5l-1.5 3h15z"/><path d="M10 21h4"/>',
        'cloud'    => '<path d="M7 18h10a4 4 0 0 0 .3-8A6 6 0 0 0 6 11.4A3.3 3.3 0 0 0 7 18z"/>',
        'lock'     => '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8.5 10.5V7a3.5 3.5 0 0 1 7 0v3.5"/>',
        'branch'   => '<circle cx="6.5" cy="5.5" r="2.5"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="17.5" cy="12" r="2.5"/><path d="M6.5 8v8"/><path d="M9 12h6"/>',
    ];
    if (!isset($图形[$名])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"'
         . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $图形[$名] . '</svg>';
}
// 页脚信息全部来自后台，没填的项不渲染，所以这里统一 trim 后按空串判断
$foot = [];
foreach (['contact_qq', 'contact_wechat', 'contact_email', 'contact_phone',
          'icp_no', 'icp_url', 'terms_privacy', 'terms_service'] as $fk) {
    $foot[$fk] = trim((string) setting_get($fk, ''));
}
// 平台能力卡片：集中成数组，模板里循环渲染，加减条目不用动 HTML
$能力列表 = [
    ['chat',   '多模型对话',   "接入 {$模型数} 个模型、{$厂商数} 家厂商，随时切换。支持图片和文件输入，长上下文不断线。"],
    ['server', '服务器直连',   '绑定主机后 AI 直接执行命令：装环境、起停服务、看日志、排故障，跑完把结果贴回对话。'],
    ['code',   '项目代码仓',   '把服务器上的项目拉成本地副本再改，有版本历史、能对比、能回滚，确认无误才回传上线。'],
    ['edit',   '远程改文件',   'SFTP 直连读写，没有通道时自动改走命令行，不会卡在半路。整文件覆写和局部打补丁都支持。'],
    ['folder', '工作中心',     '你的专属文件库，产出的代码、文档、脚本长期留存，下次对话还在，随时下载或打包成压缩包。'],
    ['slides', '生成 PPT',     '给个主题或大纲，直接产出可下载的 pptx，封面、目录、页码、配色都排好。'],
    ['eye',    '文档预览',     'Word、Excel、PDF 在线转换预览，不下载也能确认内容对不对。'],
    ['layers', '多项目并行',   '每个项目独立绑服务器和目录，上下文各自持久化，互不串台。'],
];
// 网页端能力：浏览器打开就能用的部分
$网页端特性 = [
    ['globe',  '零安装即开即用',   '浏览器打开就能对话，不装任何东西。换电脑换系统，登录进来上下文和项目都还在。'],
    ['branch', '项目与上下文持久化', '每个项目独立绑服务器和目录，对话历史长期保存，隔几天回来接着往下干。'],
    ['eye',    '在线预览产出物',   'Word、Excel、PDF 直接在网页里看，代码和文档不用下载到本地确认。'],
    ['cloud',  '工作中心云端留存', '产出的代码、文档、脚本存在你自己的文件库里，随时下载或打包成 zip。'],
];
// 安卓端特性卡片
$安卓端特性 = [
    ['terminal', '手机也能连服务器', '躺着就能连上生产环境跑命令、看日志。半夜告警不用爬起来开电脑。'],
    ['mic',      '语音输入',       '按住说话直接转成文字发出去，路上、开车时提需求比打字快得多。'],
    ['bell',     '任务完成推送',   '长任务丢给它自己跑，跑完手机推送提醒，不用一直守着屏幕。'],
    ['refresh',  '启动自检更新',   '打开就比对版本，有新包直接下载覆盖安装，不用自己找下载页。'],
];
// 客户端特性卡片
$客户端特性 = [
    ['shield',   '令牌本地加密',     '登录令牌交给系统级 safeStorage 加密后落盘，不写明文配置文件。'],
    ['folder',   '本地目录直接拖',   '文件从资源管理器拖进对话就上传，产出物也直接落到本地磁盘，不用经浏览器下载再翻文件夹。'],
    ['clip',     '截图直接粘',       '剪贴板里的图按 Ctrl+V 就能贴进对话，不用先存成文件再上传。'],
    ['refresh',  '自动检查更新',     '启动时静默比对版本，有新版提示你升级，不用自己盯着下载页。'],
    ['window',   '原生窗口体验',     '独立进程、系统托盘、窗口最小化和最大化，跟浏览器标签页抢不到的稳定性。'],
];
// 所有用户都展示原有首页模板
$tplName = trim((string) setting_get('home_template', 'home_tpl1'));
$tplName = trim((string) ($_GET['preview_tpl'] ?? $tplName));
if (!preg_match('/^[A-Za-z0-9_\-]+$/', $tplName)) {
    $tplName = 'home_tpl1';
}
$tplFile = __DIR__ . '/templates/home/' . $tplName . '.php';
if (!is_file($tplFile)) {
    $tplName = 'home_tpl1';
    $tplFile = __DIR__ . '/templates/home/home_tpl1.php';
}
require $tplFile;
