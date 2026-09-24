<?php
// ========== 数据库与全局配置 ==========
// 若同目录存在 config.local.php，则优先使用其中的数据库配置（便于本地/测试环境覆盖，
// 无需修改本文件；部署到生产时可不上传 config.local.php）。
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', 3306);
defined('DB_NAME') || define('DB_NAME', '8800demo');
defined('DB_USER') || define('DB_USER', '8800demo');
defined('DB_PASS') || define('DB_PASS', '8800demo');
// 数据表前缀（本项目建表不带前缀，保持空串即可）
defined('DB_PREFIX') || define('DB_PREFIX', '');
// 站点名称的兜底值。真实值来自后台「站点设置」的 site_name，取值一律走 app_name()。
//
// 为什么这里不直接查库：本文件是被 db.php 第 2 行 require 的，执行到这一行时
// setting_get() 还没定义，直接调用就是循环依赖，会 fatal error。
// 所以取值推迟到 db.php 里的 app_name()，首次调用时才查库并缓存。
//
// 这里只有 FALLBACK，没有 APP_NAME 常量：常量是编译期定死的，拿不到后台改过的名字，
// 留着迟早有人图省事直接用它，页面上就冒出个跟设置不一致的站名，还很难发现。
// 删掉之后误用会直接抛未定义常量错误，当场暴露，比静默显示错名字好。
define('APP_NAME_FALLBACK', '云智 AI Agent 平台');
define('APP_ROOT', dirname(__DIR__));
// 新用户注册赠送余额(元)，也可在后台「站点设置」中调整
define('DEFAULT_BALANCE', '1.000000');
// ---------- 数据目录（上传文件、加密主密钥的存放处）----------
// 必须满足两点：在网站根目录之外（防止被 URL 直接下载）、在 open_basedir 白名单内。
// 面板环境的 open_basedir 通常只放行站点目录、/tmp 和 /www/wwwdata/<库名>，
// 所以这里按优先级探测，取第一个可写的，避免写死路径踩到 open_basedir 限制。
if (!defined('DATA_DIR')) {
    $候选 = [
        '/www/wwwdata/' . DB_NAME,          // 宝塔为站点分配的数据目录
        dirname(APP_ROOT) . '/wwwdata/' . DB_NAME,
        APP_ROOT . '/../data_' . DB_NAME,
        sys_get_temp_dir() . '/' . DB_NAME . '_data',
    ];
    $首选 = $候选[0];
    $选中 = '';
    foreach ($候选 as $d) {
        // is_dir/is_writable 在 open_basedir 外会报警告，用 @ 压掉，探测失败就换下一个
        if (@is_dir($d) && @is_writable($d)) {
            $选中 = $d;
            break;
        }
        if (!@is_dir($d) && @mkdir($d, 0700, true) && @is_writable($d)) {
            $选中 = $d;
            break;
        }
    }
    // 全都不行就退到临时目录，保证功能可用（重启后密钥会失效，届时凭据需重填）
    $兜底 = ($选中 === '');
    if ($兜底) {
        $选中 = sys_get_temp_dir();
    }
    define('DATA_DIR', $选中);
    // ---------- 降级告警 ----------
    // 换了数据目录 = 换了主密钥。crypto.php 在新目录里找不到 master.key 会静默新建一把，
    // 于是数据库里用旧密钥加密的服务器密码、上游 API Key 全部解不开，且不会有任何报错。
    // 这里把「降级」这件事显式记下来，避免又悄悄多出一把废弃密钥。
    $降级原因 = '';
    if ($兜底) {
        $降级原因 = '所有候选数据目录都不可写，已退到临时目录 ' . $选中
            . '，主机重启后主密钥即丢失';
    } elseif ($选中 !== $首选) {
        $降级原因 = '首选数据目录 ' . $首选 . ' 不可用，已降级使用 ' . $选中;
    }
    // 把实际选中的目录钉住，跨请求比对。首次运行只记录不告警。
    // 记录文件只存一个路径，不含任何密钥内容。
    $记录文件 = __DIR__ . '/.data_dir.pin';
    $上次目录 = @is_file($记录文件) ? trim((string) @file_get_contents($记录文件)) : '';
    if ($上次目录 === '') {
        @file_put_contents($记录文件, $选中);
    } elseif ($上次目录 !== $选中) {
        // 故意不改写记录文件：保留原值，告警才会一直响到人来处理
        $降级原因 = ($降级原因 !== '' ? $降级原因 . '；' : '')
            . '数据目录已从 ' . $上次目录 . ' 变为 ' . $选中
            . '，旧目录中的 master.key 不再被读取，已加密的凭据将无法解密';
    }
    // 供页面判断要不要挂告警横幅：正常时为空串
    define('DATA_DIR_WARNING', $降级原因);
    if ($降级原因 !== '') {
        // 每请求都写日志会淹掉 error_log，同一条告警一小时只记一次
        $节流文件 = $记录文件 . '.warned';
        $上次告警 = @is_file($节流文件) ? (int) @filemtime($节流文件) : 0;
        if (time() - $上次告警 > 3600) {
            @file_put_contents($节流文件, (string) time());
            error_log('[数据目录降级] ' . $降级原因);
        }
    }
}
// DATA_DIR 若由 config.local.php 预先定义，上面整段探测不执行，这里补上常量兜底，
// 保证引用 DATA_DIR_WARNING 的页面不会撞上未定义常量。
defined('DATA_DIR_WARNING') || define('DATA_DIR_WARNING', '');
// 上传图片的存放目录。故意放在网站根目录之外，禁止直接用 URL 访问，
// 一律走 api/img.php 鉴权后输出，避免别的用户猜到路径拿走图片。
defined('UPLOAD_DIR') || define('UPLOAD_DIR', DATA_DIR . '/uploads');
date_default_timezone_set('Asia/Shanghai');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
