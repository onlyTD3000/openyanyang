<?php
require_once __DIR__ . '/db.php';

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_name('AIAGENTSID');
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    // 用 API Key 鉴权的请求跳过 CSRF。
    // CSRF 防的是「浏览器被诱导发请求时自动带上 Cookie」，而 Bearer 头浏览器不会自动附带，
    // 攻击者拿不到别人的 Token 也就伪造不了请求，这类请求本身不存在 CSRF 风险。
    // 桌面端、脚本这类非浏览器客户端也没有会话可存 csrf，硬校验会把它们全挡在外面。
    if (function_exists('auth_is_token') && auth_is_token()) {
        return;
    }
    start_session();
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf']) || !is_string($t) || !hash_equals($_SESSION['csrf'], $t)) {
        json_out(['error' => '请求校验失败，请刷新页面重试'], 419);
    }
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 给 API 装上兜底的异常与致命错误处理。
 *
 * 不装的话，未捕获异常只会返回一个空的 500，前端只能显示「服务端返回异常」，
 * 排查起来全靠翻服务器日志。装上之后错误摘要会随 JSON 返回，同时写进错误日志。
 * 注意：只回摘要，不回调用栈和文件路径，避免泄露服务器目录结构。
 */
function api_error_guard(): void
{
    static $装过 = false;
    if ($装过) {
        return;
    }
    $装过 = true;

    set_exception_handler(function (Throwable $t) {
        error_log('[API 异常] ' . get_class($t) . ': ' . $t->getMessage()
            . ' @ ' . $t->getFile() . ':' . $t->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => '服务端出错：' . $t->getMessage()], JSON_UNESCAPED_UNICODE);
    });

    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        error_log('[API 致命] ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => '服务端出错：' . $e['message']], JSON_UNESCAPED_UNICODE);
        }
    });
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * 客户端下载平台的定义。设置键、显示名、图标放在一处，
 * 后台设置页和首页都读它，避免两边各写一份导致对不上。
 */
function download_platforms(): array
{
    return [
        'dl_win'     => ['Windows', '🖥️'],
        'dl_mac'     => ['macOS',   '🍎'],
        'dl_android' => ['Android', '🤖'],
        'dl_ios'     => ['iOS',     '📱'],
    ];
}

/**
 * 已配置下载地址的平台，返回 [['key'=>, 'name'=>, 'icon'=>, 'url'=>], ...]。
 * 一个都没配就返回空数组，首页据此把整个下载入口隐藏掉。
 */
function download_links(): array
{
    $out = [];
    foreach (download_platforms() as $k => [$name, $icon]) {
        $url = trim((string) setting_get($k, ''));
        if ($url === '') {
            continue;
        }
        $out[] = ['key' => $k, 'name' => $name, 'icon' => $icon, 'url' => $url];
    }
    return $out;
}

/** 自动登录 Cookie 名与有效期（天）。七天是需求定的 */
const 记住我COOKIE = 'kiro_remember';
const 记住我天数 = 7;

/** 当前请求是不是 HTTPS。Cookie 的 secure 位据此决定，写死 true 会让 HTTP 站点收不到 */
function 是否https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // 走 nginx 反代时原始协议在这个头里
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * 签发一张自动登录令牌，写库并下发 Cookie。
 *
 * selector 明文存、validator 只存哈希，详见 sql/11_记住登录.sql 里的说明。
 */
function 记住我_签发(int $用户id): void
{
    $selector  = bin2hex(random_bytes(16));   // 32 位
    $validator = bin2hex(random_bytes(32));   // 64 位
    $到期 = time() + 记住我天数 * 86400;

    db_exec('INSERT INTO auth_tokens (user_id, selector, validator, ua, ip, expires_at, created_at)
             VALUES (?,?,?,?,?,?,NOW())',
        [$用户id, $selector, hash('sha256', $validator),
         mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
         client_ip(), date('Y-m-d H:i:s', $到期)]);

    setcookie(记住我COOKIE, $selector . ':' . $validator, [
        'expires'  => $到期,
        'path'     => '/',
        'secure'   => 是否https(),
        'httponly' => true,        // JS 读不到，XSS 也偷不走
        'samesite' => 'Lax',       // 跟着顶层导航发送，跨站表单提交不带
    ]);
}

/** 删掉 Cookie，并按需删库里那一行。退出登录和令牌失效时都要调 */
function 记住我_清除(bool $连库一起删 = true): void
{
    $原始 = (string) ($_COOKIE[记住我COOKIE] ?? '');
    if ($连库一起删 && $原始 !== '' && strpos($原始, ':') !== false) {
        [$selector] = explode(':', $原始, 2);
        db_exec('DELETE FROM auth_tokens WHERE selector = ?', [$selector]);
    }
    unset($_COOKIE[记住我COOKIE]);
    setcookie(记住我COOKIE, '', [
        'expires' => time() - 42000, 'path' => '/',
        'secure'  => 是否https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/**
 * 拿 Cookie 里的令牌换一个登录会话。成功返回用户 id，失败返回 0。
 *
 * 校验通过后立刻轮换：删旧的、发新的。令牌被复制走时，真实用户下次访问
 * 就会把它顶掉，攻击者手上那张随即失效。
 */
function 记住我_尝试登录(): int
{
    $原始 = (string) ($_COOKIE[记住我COOKIE] ?? '');
    if ($原始 === '' || strpos($原始, ':') === false) {
        return 0;
    }
    [$selector, $validator] = explode(':', $原始, 2);
    // 长度先卡一遍，形状不对的直接扔，省一次查库
    if (strlen($selector) !== 32 || strlen($validator) !== 64
        || !ctype_xdigit($selector) || !ctype_xdigit($validator)) {
        记住我_清除(false);
        return 0;
    }

    $行 = db_one('SELECT id, user_id, validator FROM auth_tokens
                   WHERE selector = ? AND expires_at > NOW() LIMIT 1', [$selector]);
    if (!$行) {
        记住我_清除(false);   // 库里没有或已过期，Cookie 留着没意义
        return 0;
    }
    // 定长比较，避免按字节提前返回泄漏信息
    if (!hash_equals((string) $行['validator'], hash('sha256', $validator))) {
        // selector 对上但 validator 不对：这张令牌很可能被人拿去试了，
        // 保险起见把这一行删掉，逼真实用户重新登录一次。
        db_exec('DELETE FROM auth_tokens WHERE id = ?', [(int) $行['id']]);
        记住我_清除(false);
        return 0;
    }

    $用户 = db_one('SELECT id, status FROM users WHERE id = ? LIMIT 1', [(int) $行['user_id']]);
    if (!$用户 || (int) $用户['status'] !== 1) {
        // 账号被停用或删了，令牌一并作废
        db_exec('DELETE FROM auth_tokens WHERE user_id = ?', [(int) $行['user_id']]);
        记住我_清除(false);
        return 0;
    }

    // 轮换：旧行删掉再发新的
    db_exec('DELETE FROM auth_tokens WHERE id = ?', [(int) $行['id']]);
    记住我_签发((int) $用户['id']);
    // 顺手清掉全站过期令牌，不用另开定时任务
    db_exec('DELETE FROM auth_tokens WHERE expires_at < NOW()');
    return (int) $用户['id'];
}

function current_user(): ?array
{
    start_session();
    // 会话里没有登录态时，再看有没有自动登录令牌。
    // 放在这里而不是各个页面里，是为了让所有入口（含 API）都自动享受到。
    if (empty($_SESSION['uid']) && !empty($_COOKIE[记住我COOKIE])) {
        $uid = 记住我_尝试登录();
        if ($uid > 0) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            db_exec('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
                [client_ip(), $uid]);
        }
    }
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $cache = null;
    if ($cache !== null && (int) $cache['id'] === (int) $_SESSION['uid']) {
        return $cache;
    }
    $u = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $_SESSION['uid']]);
    if (!$u || (int) $u['status'] !== 1) {
        session_destroy();
        return null;
    }
    $cache = $u;
    return $u;
}

/** 页面级登录拦截 */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: /login.php');
        exit;
    }
    return $u;
}

/** 接口级登录拦截 */
/**
 * 记录本次请求是靠什么方式通过鉴权的：cookie 或 token。
 * csrf_check() 要靠它决定是否跳过校验，见该函数注释。
 */
$GLOBALS['auth_via'] = '';
function require_login_api(): array
{
    $u = current_user();
    if ($u) {
        $GLOBALS['auth_via'] = 'cookie';
    } else {
        $u = api_key_validate();
        if ($u) { $GLOBALS['auth_via'] = 'token'; }
    }
    if (!$u) {
        json_out(['error' => '未登录或会话已过期'], 401);
    }
    return $u;
}
/** 本次请求是否通过 API Key（Bearer Token）鉴权 */
function auth_is_token(): bool
{
    return ($GLOBALS['auth_via'] ?? '') === 'token';
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('无权访问');
    }
    return $u;
}


// ================ 【管理员模拟登录 impersonation】 ================
//
// 设计说明：
// 浏览器对同一站点只有一份 session cookie，做不到「两个账号在同一浏览器同时在线」。
// 这里的做法是身份压栈：切过去时把管理员自己的 uid 存进 $_SESSION[模拟登录_原管理员]，
// $_SESSION['uid'] 换成目标用户。切回时把 uid 还原、清掉栈。
// 管理员账号自身不做任何改动（不改密码、不动 last_login），所以「不影响原账号」成立。
//
// 安全约束（都在 impersonate_start 里强制）：
//   1. 只有 admin 能发起；
//   2. 不允许模拟其他管理员，避免管理员之间互相冒用、以及被降权的管理员绕权；
//   3. 不允许嵌套模拟（已经在模拟中就必须先退出）；
//   4. 不允许模拟被禁用的账号（current_user 会把这种 session 直接销毁）；
//   5. 进出都 session_regenerate_id，防会话固定；
//   6. 进出都写 audit_logs 留痕。

const 模拟登录_原管理员 = 'imp_admin_uid';

/** 当前是否处于模拟登录状态 */
function impersonating(): bool
{
    start_session();
    return !empty($_SESSION[模拟登录_原管理员]);
}

/** 取发起模拟的管理员信息，不在模拟态时返回 null */
function impersonator(): ?array
{
    if (!impersonating()) {
        return null;
    }
    $u = db_one('SELECT id,username,role FROM users WHERE id = ? LIMIT 1',
        [(int) $_SESSION[模拟登录_原管理员]]);
    return $u ?: null;
}

/**
 * 开始模拟登录。成功返回空字符串，失败返回错误原因。
 */
function impersonate_start(int $targetId): string
{
    $me = current_user();
    if (!$me || $me['role'] !== 'admin') {
        return '无权操作';
    }
    if (impersonating()) {
        return '已处于模拟登录状态，请先返回管理员账号';
    }
    if ($targetId === (int) $me['id']) {
        return '不能模拟登录自己';
    }
    $t = db_one('SELECT id,username,role,status FROM users WHERE id = ? LIMIT 1', [$targetId]);
    if (!$t) {
        return '用户不存在';
    }
    if ((int) $t['status'] !== 1) {
        return '该账号已被禁用，无法登入';
    }
    if ($t['role'] === 'admin') {
        return '不能模拟登录其他管理员账号';
    }

    audit_log((int) $me['id'], 'impersonate_start', (int) $t['id'], 0,
        "模拟登录进入：{$t['username']}");

    $原管理员 = (int) $me['id'];
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $t['id'];
    $_SESSION[模拟登录_原管理员] = $原管理员;
    return '';
}

/**
 * 结束模拟登录，回到管理员身份。成功返回空字符串，失败返回错误原因。
 */
function impersonate_stop(): string
{
    start_session();
    if (empty($_SESSION[模拟登录_原管理员])) {
        return '当前不处于模拟登录状态';
    }
    $adminId = (int) $_SESSION[模拟登录_原管理员];
    $admin = db_one('SELECT id,role,status FROM users WHERE id = ? LIMIT 1', [$adminId]);

    $被模拟 = (int) ($_SESSION['uid'] ?? 0);
    unset($_SESSION[模拟登录_原管理员]);

    // 管理员账号在模拟期间被删/被禁/被降权，就不能再切回去，直接踢到登录页重新认证
    if (!$admin || (int) $admin['status'] !== 1 || $admin['role'] !== 'admin') {
        session_destroy();
        return '原管理员账号已不可用，请重新登录';
    }

    audit_log($adminId, 'impersonate_stop', $被模拟, 0, '模拟登录退出');

    session_regenerate_id(true);
    $_SESSION['uid'] = $adminId;
    return '';
}

/**
 * 读取 token 估算的各档系数，来自后台「站点设置 → Token 估算」。
 *
 * setting_get() 自己不带缓存，而 estimate_tokens() 一轮对话要被调好几次，
 * 所以在这里按请求缓存一份（PHP 静态变量随请求结束释放，后台改完下个请求即生效）。
 * 计费函数不能因为读配置失败而崩，所以查库整段包在 try 里，出错一律回落硬编码默认值。
 */
function estimate_token_cfg(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $默认 = [
        // 前七项与后台库里现存的值保持一致：查库抛异常时会整套回落到这里，
        // 写成 1.2 / 3.5 的话一次异常就让全站静默切到一组更贵的系数，没人会发现。
        'cjk'        => 1.1,
        'prose'      => 3.2,
        'symbols'    => '{}[]":,',
        'rare_on'    => true,
        'rare_min'   => 12,
        'rare_per'   => 0.0,   // 0 表示整词算 1 token
        'tail_bonus' => true,
        // 以下六档新增，管的都是旧四档没覆盖到的字符
        'cjk_rare'   => 0.6,   // 生僻字：字 / token（比常用字费）
        'tab'        => 1.0,   // 制表符：token / 个（原先并在符号档，默认值等价）
        'zw'         => 1.0,   // 零宽字符：token / 个
        'emoji'      => 1.5,   // emoji：token / 个
        'emoji_mod'  => 1.0,   // 肤色修饰符、变体选择符：token / 个
        'ent_on'     => true,
        'ent_min'    => 20,    // 高熵串最短长度
        'ent_per'    => 2.2,   // 高熵串：字符 / token
    ];
    $cfg = $默认;
    if (!function_exists('setting_get')) {
        return $cfg;
    }
    try {
        $取 = function (string $k, $d) {
            $v = setting_get($k, null);
            return ($v === null || $v === '') ? $d : $v;
        };
        // 除数一律带下限：后台的 number 类型允许存 0，直接拿来做除数会白屏
        $cfg['cjk']        = max(0.1, (float) $取('est_cjk_per_token', $默认['cjk']));
        $cfg['prose']      = max(0.1, (float) $取('est_prose_per_token', $默认['prose']));
        $cfg['symbols']    = (string) $取('est_symbol_chars', $默认['symbols']);
        $cfg['rare_on']    = ((string) $取('est_rare_enable', '1')) === '1';
        $cfg['rare_min']   = max(4, (int) $取('est_rare_min_len', $默认['rare_min']));
        $cfg['rare_per']   = max(0.0, (float) $取('est_rare_per_token', 0));
        $cfg['tail_bonus'] = ((string) $取('est_tail_bonus', '1')) === '1';
        $cfg['cjk_rare']   = max(0.1, (float) $取('est_cjk_rare_per_token', $默认['cjk_rare']));
        $cfg['tab']        = max(0.0, (float) $取('est_tab_tokens', $默认['tab']));
        $cfg['zw']         = max(0.0, (float) $取('est_zw_tokens', $默认['zw']));
        $cfg['emoji']      = max(0.0, (float) $取('est_emoji_tokens', $默认['emoji']));
        $cfg['emoji_mod']  = max(0.0, (float) $取('est_emoji_mod_tokens', $默认['emoji_mod']));
        $cfg['ent_on']     = ((string) $取('est_entropy_enable', '1')) === '1';
        $cfg['ent_min']    = max(8, (int) $取('est_entropy_min_len', $默认['ent_min']));
        $cfg['ent_per']    = max(0.1, (float) $取('est_entropy_per_token', $默认['ent_per']));
    } catch (Throwable $e) {
        $cfg = $默认;
    }
    return $cfg;
}

/**
 * 粗略估算 token，分十档折算，系数在后台「站点设置 → Token 估算」里配：
 *   1. 零宽字符：est_zw_tokens token / 个
 *   2. 肤色修饰符、变体选择符：est_emoji_mod_tokens token / 个
 *   3. emoji：est_emoji_tokens token / 个
 *   4. 制表符：est_tab_tokens token / 个
 *   5. 中文常用字及全角标点：est_cjk_per_token 字 / token
 *   6. 生僻字（扩展 A 及以上）：est_cjk_rare_per_token 字 / token
 *   7. 结构符号 est_symbol_chars 与换行：1 字符 / token
 *   8. 高熵串（key、哈希、base64）：est_entropy_per_token 字符 / token
 *   9. 长生僻英文单词：整词算 1 个，或按 est_rare_per_token 字符折算
 *  10. 其余英文散文（含空格、数字）：est_prose_per_token 字符 / token
 * 十档互斥，逐档从文本里剔除后再交给下一档，不会重复计数。
 * 顺序不能随便调：1～3 必须排在散文档之前（否则 emoji 只值 0.31 token），
 * 8 必须排在 9 之前（一串 key 里的字母段同样会被长词正则命中，会算两遍）。
 *
 * $cfg覆盖 只给后台试算框用：让管理员不保存也能预览改系数后的结果。
 */
function estimate_tokens(string $text, ?array $cfg覆盖 = null): int
{
    if ($text === '') {
        return 0;
    }
    // 覆盖数组可能只带了一部分键（后台试算框是按表单字段拼的），
    // 用 + 从正式配置里补齐缺的，避免下面取到 null 直接白屏。
    $cfg = $cfg覆盖 !== null ? ($cfg覆盖 + estimate_token_cfg()) : estimate_token_cfg();

    // 扩展 A 从常用区间里摘出去，交给生僻字档单独计价
    $cjk正则 = '/[\x{4e00}-\x{9fff}\x{3000}-\x{303f}\x{ff00}-\x{ffef}]/u';
    // 生僻字：扩展 A 加 BMP 以外的扩展 B～F。后者旧口径连 CJK 都不算，
    // 是掉进散文档按 3.2 字符/token 折的，明显偏低。
    $生僻字正则 = '/[\x{3400}-\x{4dbf}\x{20000}-\x{3ffff}]/u';
    // 零宽字符：肉眼不可见，旧口径同样掉进散文档
    $零宽正则 = '/[\x{200b}-\x{200d}\x{2060}\x{feff}]/u';
    // 肤色修饰符与变体选择符：跟在 emoji 后面，本身也占 token
    $修饰符正则 = '/[\x{1f3fb}-\x{1f3ff}\x{fe0e}\x{fe0f}]/u';
    // emoji 只收 1F 平面那几段真 emoji。BMP 区的 ✓ ★ ☂（26xx/27bx）故意不收：
    // 它们在正常文本和代码输出里很常见，真实分词器也就 1 token，
    // 按 emoji 档收 1.5 反而是新的高估。
    $emoji正则 = '/[\x{1f300}-\x{1f5ff}\x{1f600}-\x{1f64f}\x{1f680}-\x{1f6ff}'
        . '\x{1f900}-\x{1f9ff}\x{1fa70}-\x{1faff}\x{1f1e6}-\x{1f1ff}]/u';

    // 逐字符加反斜杠，防止管理员填进 - ^ ] 之类把字符类写坏；多字节字符原样放行
    $符号集 = '';
    foreach (preg_split('//u', (string) $cfg['symbols'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $符号集 .= (strlen($ch) === 1 && !ctype_alnum($ch)) ? '\\' . $ch : $ch;
    }
    // 换行、回车固定算结构符号：单行输入框填不了它们，不做成配置项。
    // 制表符原先也在这里按 1 token 收，现在拆给 est_tab_tokens 单独计，
    // 默认值同样是 1，行为逐字不变；拆出来是为了那个参数不是摆设。
    $符号正则 = '/[' . $符号集 . '\r\n]/u';

    // 各档剔除内容时统一换成这个占位符，而不是直接删掉。
    // 直接删会让两侧的字母粘连成假单词：JSON 去掉 {}[]":, 之后
    // role user content 会连成一个 38 字符的长串，被第三档误判为长生僻词。
    // \x01 不属于 [A-Za-z'-]，能可靠切断单词，也不会被中文正则或符号正则二次匹配。
    $占位 = "\x01";

    // 零宽字符最先摘：它夹在词中间会把一个词切成两半，先清掉后面几档才匹配得准
    $零宽 = (int) preg_match_all($零宽正则, $text);
    $剩余 = preg_replace($零宽正则, $占位, $text);
    if ($剩余 === null) {
        // 文本不是合法 UTF-8，preg 会整体失败，退回最粗的按字数折算，避免估成 0
        return (int) ceil(mb_strlen($text, 'UTF-8') / 2) + 1;
    }

    // 修饰符排在 emoji 之前：它紧跟在 emoji 后面，
    // 先摘 emoji 会把修饰符剩在原地掉进散文档
    $修饰 = (int) preg_match_all($修饰符正则, $剩余);
    $剩余 = (string) preg_replace($修饰符正则, $占位, $剩余);

    $emoji = (int) preg_match_all($emoji正则, $剩余);
    $剩余  = (string) preg_replace($emoji正则, $占位, $剩余);

    // 制表符：原先并在符号档里按 1 token 收，现在单独计，默认值一致
    $制表 = substr_count($剩余, "\t");
    $剩余 = str_replace("\t", $占位, $剩余);

    // 第一档：中文常用字（生僻区间已从正则里摘出去）
    $cjk  = (int) preg_match_all($cjk正则, $剩余);
    $剩余 = (string) preg_replace($cjk正则, $占位, $剩余);

    // 生僻字：比常用字更费 token，单独一档
    $生僻字 = (int) preg_match_all($生僻字正则, $剩余);
    $剩余   = (string) preg_replace($生僻字正则, $占位, $剩余);

    // 第二档：结构符号
    $符号 = (int) preg_match_all($符号正则, $剩余);
    $剩余 = (string) preg_replace($符号正则, $占位, $剩余);

    // 高熵串：API key、token、哈希、base64 这类随机串。
    // 必须排在长生僻词档之前，否则串里的字母段会被那一档再算一遍。
    $高熵 = 0.0;
    if (!empty($cfg['ent_on'])) {
        $剩余 = (string) preg_replace_callback(
            // 字符类不含连字符：真 key / 哈希 / base64 的主体是连续长段（sk- 只是前缀），
            // 而 deepseek-v4-flash-0731、text-embedding-3-small 这类靠连字符分段。
            // 含进来会把整个模型名当成随机串多收几个 token，第三档注释里早就踩过同一个坑。
            '/[A-Za-z0-9+_=]{' . max(8, (int) $cfg['ent_min']) . ',}/',
            function (array $m) use (&$高熵, $cfg, $占位) {
                // 必须同时含字母和数字才算随机串，否则长英文标识符
                // （very_long_variable_name 这类）会被误判成 key
                if (!preg_match('/[A-Za-z]/', $m[0]) || !preg_match('/\d/', $m[0])) {
                    return $m[0];
                }
                $高熵 += ceil(strlen($m[0]) / max(0.1, (float) $cfg['ent_per']));
                return $占位;
            },
            $剩余
        );
    }

    // 第三档：长生僻英文词。常见长词不算生僻，留给第四档按字符折算
    static $常见长词 = [
        'international', 'communication', 'configuration', 'implementation',
        'documentation', 'organization', 'administrator', 'authentication',
        'authorization', 'notification', 'particularly', 'professional',
        'relationship', 'successfully', 'understanding', 'unfortunately',
        'specifically', 'responsibility', 'recommendation', 'transformation',
        'representative', 'infrastructure', 'functionality', 'introduction',
        'construction', 'requirements', 'applications', 'availability',
    ];
    $生僻词 = 0.0;
    if ($cfg['rare_on']) {
        // 字符类里只留撇号，不含连字符：连字符是 kebab-case 标识符的分隔符
        // （claude-opus-5、text-embedding-3-small），含进来会把整个模型名算成 1 token，
        // 而真实分词器要 5～6 个。撇号保留是为了 don't、it's 这类缩写不被拆断。
        $剩余 = (string) preg_replace_callback(
            "/[A-Za-z][A-Za-z']{" . max(3, $cfg['rare_min'] - 1) . ",}/",
            function (array $m) use (&$生僻词, $常见长词, $cfg) {
                if (in_array(strtolower($m[0]), $常见长词, true)) {
                    return $m[0];   // 常见长词不算生僻，留给第四档按字符折算
                }
                // rare_per 填 0 = 整词算 1 个；填 2.5 = 每 2.5 字符 1 个，贴近真实分词器
                $生僻词 += $cfg['rare_per'] > 0
                    ? ceil(strlen($m[0]) / $cfg['rare_per'])
                    : 1;
                return "\x01";   // 同样留占位符，避免词两侧的内容再粘起来
            },
            $剩余
        );
    }

    // 第四档：剩下的英文散文，含空格。占位符本身不是原文，要从字符数里扣掉
    $其它 = max(0, mb_strlen($剩余, 'UTF-8') - substr_count($剩余, "\x01"));

    $合计 = $cjk / $cfg['cjk']
          + $生僻字 / max(0.1, (float) $cfg['cjk_rare'])
          + $符号
          + $制表 * (float) $cfg['tab']
          + $零宽 * (float) $cfg['zw']
          + $emoji * (float) $cfg['emoji']
          + $修饰 * (float) $cfg['emoji_mod']
          + $高熵
          + $生僻词
          + $其它 / $cfg['prose'];
    return (int) ceil($合计) + ($cfg['tail_bonus'] ? 1 : 0);
}

/**
 * 按百万 token 单价计费。
 *
 * $tin 是输入总量（含缓存命中的部分），$tcache 是其中命中缓存的量。
 * 命中的那部分按 $priceCache 结算，剩下的按 $priceIn。
 * $priceCache 传 0 表示这个模型不区分缓存价，全部按输入价算——
 * 这样老数据和没配缓存价的模型行为完全不变。
 */
function calc_cost(int $tin, int $tout, $priceIn, $priceOut, int $tcache = 0, $priceCache = 0, int $tcache_create = 0, $priceCacheCreate = 0): string
{
    $pc = (float) $priceCache;
    $pcc = (float) $priceCacheCreate;
    // 缓存量不可能超过输入总量，真超了说明上游给的数不一致，按总量截断，
    // 否则普通输入会算出负数、把费用抹掉。
    $tcache = max(0, min($tcache, $tin));
    $tcache_create = max(0, min($tcache_create, $tin - $tcache));
    if ($pc <= 0) {
        $tcache = 0;   // 没配缓存价，缓存部分照输入价收
    }
    if ($pcc <= 0) {
        $tcache_create = 0;   // 没配缓存创建价，缓存创建部分照输入价收
    }
    $plain = $tin - $tcache - $tcache_create;
    $c = ($plain / 1000000) * (float) $priceIn
       + ($tcache / 1000000) * $pc
       + ($tcache_create / 1000000) * $pcc
       + ($tout / 1000000) * (float) $priceOut;
    return number_format($c, 6, '.', '');
}

function money($v): string
{
    return number_format((float) $v, 4, '.', '');
}

function fmt_int($v): string
{
    return number_format((int) $v);
}

/** 表单页 CSRF 校验（失败输出纯文本而非 JSON） */
function csrf_check_page(): void
{
    start_session();
    $t = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($t) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(419);
        exit('请求校验失败，请返回上一页刷新后重试。');
    }
}

/**
 * 存一条一次性提示，供重定向后的页面显示。
 *
 * 配合 POST-Redirect-GET 用：POST 处理完把结果存进会话再 302 回本页，
 * 浏览器地址栏最终停在 GET 上，按 F5 只是重新 GET，不会重发表单。
 * 没有这一步的话，充值这类累加操作每刷新一次就多加一笔。
 */
function flash_set(string $类型, string $文本): void
{
    start_session();
    $_SESSION['flash'] = ['t' => $类型, 'm' => $文本];
}

/** 取出并清掉一次性提示，返回 ['ok'|'error', 文本]，没有就返回 ['', ''] */
function flash_get(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!is_array($f)) {
        return ['', ''];
    }
    return [(string) ($f['t'] ?? ''), (string) ($f['m'] ?? '')];
}

/**
 * 处理完 POST 后跳回当前页（只保留 GET 参数）。
 * 必须在任何输出之前调用，否则 header 发不出去。
 */
function redirect_self(): void
{
    $q = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?') . $q, true, 303);
    exit;
}

/**
 * 校验并认领前端提交的图片 id：只接受本人上传、且尚未被其他消息使用的图。
 * 返回可用的 uploads 行数组（保持前端给的顺序）。
 */
function claim_uploads(array $ids, int $userId, int $convId): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) {
        return [];
    }
    $maxNum = max(1, (int) setting_get('upload_max_num', 4));
    $ids = array_slice($ids, 0, $maxNum);

    // 只校验归属，不再要求 used=0：工作中心里的旧图允许反复引用
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $rows = db_all(
        "SELECT * FROM uploads WHERE id IN ($in) AND user_id = ?",
        array_merge($ids, [$userId])
    );
    // 按前端顺序重排
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['id']] = $r;
    }
    $out = [];
    foreach ($ids as $i) {
        if (isset($map[$i])) {
            $out[] = $map[$i];
        }
    }
    if ($out) {
        // 标记已使用；conv_id 只在首次为 0 时写入，避免旧图被后来的会话改走归属
        $okIds = array_column($out, 'id');
        $in2   = implode(',', array_fill(0, count($okIds), '?'));
        db_exec("UPDATE uploads SET used = 1, conv_id = IF(conv_id = 0, ?, conv_id) WHERE id IN ($in2)",
            array_merge([$convId], $okIds));
    }
    return $out;
}

/** 把图片文件读成 data URL，用于发给上游多模态接口 */
function upload_data_url(array $row): ?string
{
    $base = realpath(rtrim(UPLOAD_DIR, '/'));
    $file = realpath($base . '/' . $row['path']);
    if ($base === false || $file === false
        || strncmp($file, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0
        || !is_file($file)) {
        return null;
    }
    $bin = @file_get_contents($file);
    if ($bin === false || $bin === '') {
        return null;
    }
    $mime = preg_match('#^image/(jpeg|png|gif|webp)$#', (string) $row['mime'])
        ? $row['mime'] : 'image/jpeg';
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/**
 * 组装一条发给上游的消息。
 * 无图片时用普通字符串；有图片时用 OpenAI 多模态数组格式。
 */
function build_chat_msg(string $role, string $text, array $rows)
{
    if (!$rows) {
        return ['role' => $role, 'content' => $text];
    }
    $maxKB = (int) setting_get('upload_max_image_size', 300);
    $maxBytes = $maxKB > 0 ? $maxKB * 1024 : 0;
    $parts = [];
    foreach ($rows as $r) {
        // 超过体积限制的图片直接跳过，避免把上游请求体撑爆
        if ($maxBytes > 0 && !empty($r['path'])) {
            $base = realpath(rtrim(UPLOAD_DIR, '/'));
            $file = realpath($base . '/' . $r['path']);
            if ($file && is_file($file) && filesize($file) > $maxBytes) {
                continue;
            }
        }
        $url = upload_data_url($r);
        if ($url !== null) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }
    }
    if ($text !== '') {
        $parts[] = ['type' => 'text', 'text' => $text];
    }
    return $parts ? ['role' => $role, 'content' => $parts] : ['role' => $role, 'content' => $text];
}

/** content 统一成 parts 数组：纯文本包成 text part，空串给空数组 */
function msg_content_parts($c): array
{
    if (is_array($c)) {
        return $c;
    }
    $t = (string) $c;
    return $t === '' ? [] : [['type' => 'text', 'text' => $t]];
}
/** 相邻同角色消息合并成一条。content 可能是纯文本，也可能是多模态 parts 数组 */
function merge_msg_content($a, $b)
{
    // 两边都是纯文本：空行隔开拼起来，保持可读
    if (!is_array($a) && !is_array($b)) {
        $x = (string) $a;
        $y = (string) $b;
        if ($x === '') {
            return $y;
        }
        if ($y === '') {
            return $x;
        }
        return $x . "\n\n" . $y;
    }
    // 任一边带图片等 parts，统一转成数组再合并，避免丢掉图片
    return array_merge(msg_content_parts($a), msg_content_parts($b));
}
/** 格式化字节大小 */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

/** 历史消息的 images 字段（JSON id 数组）转成 uploads 行，只取本人的 */
function history_upload_rows(string $imagesJson, int $userId): array
{
    $imagesJson = trim($imagesJson);
    if ($imagesJson === '') {
        return [];
    }
    $ids = json_decode($imagesJson, true);
    if (!is_array($ids) || !$ids) {
        return [];
    }
    $ids = array_slice(array_filter(array_map('intval', $ids), fn($v) => $v > 0), 0, 8);
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return db_all("SELECT * FROM uploads WHERE id IN ($in) AND user_id = ?",
        array_merge($ids, [$userId]));
}

/** 字节数转可读文本，如 1.2 MB */
function size_text(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}

/**
 * 静态资源地址（带版本号）。
 * 版本号取文件最后修改时间，文件一改 URL 就变，浏览器必然重新下载。
 * 手机浏览器无法强制刷新，靠这个来避免用户看到旧的 JS / CSS。
 */
function asset(string $path): string
{
    $rel = '/' . ltrim($path, '/');
    $abs = APP_ROOT . $rel;
    $ver = is_file($abs) ? filemtime($abs) : 0;
    return h($rel . '?v=' . $ver);
}

/**
 * 记一条管理员审计日志。
 * 管理员查看用户私密内容属高权限操作，必须留痕。
 */
function audit_log(int $adminId, string $action, int $targetUserId = 0,
                   int $targetConvId = 0, string $note = ''): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (strlen($ip) > 45) {
        $ip = substr($ip, 0, 45);
    }
    db_exec('INSERT INTO audit_logs (admin_id, action, target_user_id, target_conv_id, ip, note, created_at)
             VALUES (?,?,?,?,?,?,NOW())',
        [$adminId, mb_substr($action, 0, 40), $targetUserId, $targetConvId, $ip, mb_substr($note, 0, 255)]);
}

/**
 * 读取平台准则 inc/soul.md，作为所有模型的基础系统提示词。
 * 用静态变量缓存，同一次请求内只读一次盘。
 * 文件缺失或为空时返回空串，调用方需自行兜底。
 */
function soul_prompt(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $file = __DIR__ . '/soul.md';
    if (!is_file($file) || !is_readable($file)) {
        $cache = '';
        return $cache;
    }
    $txt = (string) file_get_contents($file);
    // 去掉 UTF-8 BOM，避免污染提示词开头
    $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
    $cache = trim($txt);
    return $cache;
}

/**
 * 拼出最终系统提示词：平台准则在前，模型专属补充在后。
 * 准则优先级更高，放前面；模型补充用于设定该模型的特定行为。
 */
function build_system_prompt(string $modelPrompt): string
{
    $soul = soul_prompt();
    $extra = trim($modelPrompt);
    if ($soul === '') {
        return $extra;
    }
    if ($extra === '') {
        return $soul;
    }
    return $soul . "\n\n---\n\n## 本模型的额外要求\n\n" . $extra;
}

/**
 * 本地提示词缓存：把拼好的系统提示词按 (账号,项目,模型) 缓存到磁盘。
 *
 * 为什么要缓存：系统提示词由平台准则 + 项目档案 + SFTP/SSH 说明 + 代码仓文件清单 +
 * 工作中心文件清单拼成，每轮对话都要跑七八条 SQL（其中仓文件、工作区文件是几百行的列表）
 * 才能拼出一段每轮几乎完全一样的文字。缓存后这些查询整段跳过。
 *
 * 失效靠 $指纹：调用方把「会影响提示词内容的那几个数」拼成一个串传进来
 * （项目改动时间、主机数、仓文件版本、工作区文件版本等）。指纹一变就重新拼。
 * 这样比定 TTL 可靠——用户刚传完文件就发消息，不能让他等缓存过期。
 *
 * @param string   $键     缓存键，调用方保证已含账号 id，避免跨账号串数据
 * @param string   $指纹   内容版本标记，变了就重新生成
 * @param callable $生成   缓存未命中时用来拼提示词的闭包，返回 string
 */
function prompt_cache_get(string $键, string $指纹, callable $生成): string
{
    // 关掉本地缓存时直接现拼，方便排查「提示词没更新」这类问题
    if ((int) setting_get('prompt_cache_local', 1) !== 1) {
        return (string) $生成();
    }
    $目录 = DATA_DIR . '/promptcache';
    if (!is_dir($目录)) {
        @mkdir($目录, 0775, true);
    }
    $文件 = $目录 . '/' . hash('sha256', $键) . '.json';
    if (is_file($文件)) {
        $包 = json_decode((string) @file_get_contents($文件), true);
        if (is_array($包) && ($包['fp'] ?? '') === $指纹 && isset($包['txt'])) {
            return (string) $包['txt'];
        }
    }
    $文本 = (string) $生成();
    // 写失败不影响功能，下轮再试，所以不判返回值
    @file_put_contents($文件,
        json_encode(['fp' => $指纹, 'txt' => $文本], JSON_UNESCAPED_UNICODE),
        LOCK_EX);
    // 顺手清掉 7 天没读过的缓存文件，避免目录无限增长
    if (mt_rand(1, 50) === 1) {
        foreach ((array) glob($目录 . '/*.json') as $旧) {
            if (is_file($旧) && time() - (int) @filemtime($旧) > 604800) {
                @unlink($旧);
            }
        }
    }
    return $文本;
}

/**
 * 剥掉回答开头的英文思考前言。
 *
 * 现象：部分中转通道把模型的思考过程与正文塞进同一个 text 字段，
 * 结果正文前面粘着一段英文自述（如 "I need to..."、"The user wants..."），
 * 中文正文紧跟其后，中间没有分隔符。字段名一样，解析层无法过滤，只能按内容剥。
 *
 * 策略：只在「开头」处理，且必须满足全部条件才剥，避免误伤正常的英文内容。
 *   1. 文本开头是 ASCII 英文字母；
 *   2. 该英文段落以第一人称元叙述句式开头（I / The user / Let me ...）；
 *   3. 后面确实出现了中日韩文字（说明正文是中文，英文只是前言）。
 *
 * @param string $txt 模型返回的完整回答
 * @return string 剥掉前言后的正文；不满足条件时原样返回
 */
/**
 * 【已停用】原先直接剥掉思考前言的实现，只认英文、且有误伤风险。
 * 现由 inc/thinking.php 的 split_thinking() 接手，改为拆分后折叠显示。
 * 保留本函数仅为便于回退，业务代码中已无调用。
 */
function strip_thinking_preamble(string $txt): string
{
    $s = ltrim($txt);
    if ($s === '' || !preg_match('/^[A-Za-z]/', $s)) {
        return $txt;
    }
    // 第一人称元叙述开头，这是思考前言的典型特征
    $meta = '/^(I\s|I\'m\s|I\'ll\s|The user\s|Let me\s|Looking at\s|Now\s|First,?\s|Okay,?\s|Alright,?\s)/i';
    if (!preg_match($meta, $s)) {
        return $txt;
    }
    // 找第一个中日韩字符的位置，前面全是英文才认定为前言
    if (!preg_match('/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}]/u', $s, $mm, PREG_OFFSET_CAPTURE)) {
        return $txt;
    }
    $pos = $mm[0][1];
    $head = substr($s, 0, $pos);
    // 前言里不该含代码块或行内代码，含了说明是正常的技术回答，不动
    if (strpos($head, '```') !== false || strpos($head, '`') !== false) {
        return $txt;
    }
    // 前言长度限制：太长（超 1200 字节）说明可能是正常英文长文，不动
    if ($pos > 1200) {
        return $txt;
    }
    $rest = ltrim(substr($s, $pos));
    return $rest !== '' ? $rest : $txt;
}

/**
 * 输出顶栏菜单用的行内图标（16px 线性图标，随文字颜色变化）。
 * 用行内 svg 而非图标字体，省一次请求，也不怕字体加载失败。
 *
 * @param string $name 图标名
 * @return string svg 标签，未知名称返回空串
 */
function nav_icon(string $name): string
{
    $paths = [
        'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>'
                  . '<rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/>'
                  . '<line x1="7" y1="7.5" x2="7.01" y2="7.5"/><line x1="7" y1="16.5" x2="7.01" y2="16.5"/>',
        'chart'  => '<line x1="4" y1="20" x2="20" y2="20"/><rect x="6" y="12" width="3" height="6" rx="1"/>'
                  . '<rect x="11" y="8" width="3" height="10" rx="1"/><rect x="16" y="4" width="3" height="14" rx="1"/>',
        'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        'logout' => '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M16 17l5-5-5-5"/>'
                  . '<line x1="21" y1="12" x2="9" y2="12"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/>'
                  . '<circle cx="17" cy="14.5" r="1.2"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg class="nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" '
         . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" '
         . 'stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}


/**
 * 生成 4 位数字验证码并写入 session，输出 PNG 图片。
 */
function captcha_output(): void
{
    start_session();
    $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $_SESSION['captcha'] = $code;

    $w = 120;
    $h = 40;
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 245, 247, 250);
    imagefill($img, 0, 0, $bg);

    // 干扰线
    for ($i = 0; $i < 4; $i++) {
        $c = imagecolorallocate($img, random_int(150, 200), random_int(150, 200), random_int(150, 200));
        imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
    }
    // 干扰点
    for ($i = 0; $i < 80; $i++) {
        $c = imagecolorallocate($img, random_int(100, 200), random_int(100, 200), random_int(100, 200));
        imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
    }

    // 文字
    $colors = [
        imagecolorallocate($img, 30, 80, 160),
        imagecolorallocate($img, 160, 30, 80),
        imagecolorallocate($img, 30, 140, 90),
        imagecolorallocate($img, 140, 90, 30),
    ];
    $font = 5;
    $x = 14;
    for ($i = 0; $i < 4; $i++) {
        $y = random_int(8, 14);
        $c = $colors[$i % count($colors)];
        imagestring($img, $font, $x, $y, $code[$i], $c);
        $x += 24 + random_int(-2, 4);
    }

    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    imagepng($img);
    imagedestroy($img);
    exit;
}

/**
 * 校验验证码（不区分大小写，使用后立即销毁）。
 */
function captcha_check(string $input): bool
{
    start_session();
    if (empty($_SESSION['captcha']) || !is_string($input)) {
        return false;
    }
    $ok = hash_equals($_SESSION['captcha'], $input);
    unset($_SESSION['captcha']);
    return $ok;
}

/**
 * 校验密码强度：最少 8 位，且必须包含英文、数字、符号中的至少两种。
 * 返回 [true, ''] 或 [false, 错误信息]。
 */
function password_strength_check(string $pass): array
{
    if (mb_strlen($pass) < 8) {
        return [false, '密码至少 8 位'];
    }
    $hasLetter = (bool) preg_match('/[A-Za-z]/', $pass);
    $hasDigit  = (bool) preg_match('/[0-9]/', $pass);
    $hasSymbol = (bool) preg_match('/[^A-Za-z0-9]/', $pass);
    $count = (int) $hasLetter + (int) $hasDigit + (int) $hasSymbol;
    if ($count < 2) {
        return [false, '密码必须包含英文、数字、符号中的至少两种'];
    }
    return [true, ''];
}

/**
 * 注册防刷：同 IP 在设定间隔内只能注册一次。
 * 间隔秒数从 settings 读取，key 为 register_ip_interval，默认 60 秒。
 */
function register_ratelimit_check(): array
{
    $interval = max(0, (int) setting_get('register_ip_interval', '60'));
    if ($interval <= 0) {
        return [true, ''];
    }
    $ip = client_ip();
    $last = db_val(
        'SELECT created_at FROM users WHERE register_ip = ? ORDER BY id DESC LIMIT 1',
        [$ip]
    );
    if (!$last) {
        return [true, ''];
    }
    $elapsed = time() - strtotime($last);
    if ($elapsed < $interval) {
        $remain = $interval - $elapsed;
        return [false, '同一 IP 注册过于频繁，请 ' . $remain . ' 秒后再试'];
    }
    return [true, ''];
}

/** 登录失败计数的滚动窗口长度，1 小时 */
const 登录失败窗口秒 = 3600;

/**
 * 记录登录失败次数，超过阈值则封禁账号。
 *
 * 计数是「每小时」滚动窗口，不再无限累加：
 * users.login_fail_at 记录本轮计数的起始时间，距今满 1 小时后下次失败重新从 1 计起。
 * 这样零散的手误不会跨天攒到封禁线，只有一小时内连续失败才会触发。
 *
 * 阈值从 settings 读取：login_fail_max（默认 5，含义是每小时最多失败几次）。
 * 封禁提示从 settings 读取：login_ban_msg。
 *
 * @return array{0:bool,1:int} [是否已被封禁, 本小时内还剩几次可尝试]
 */
function login_attempt_record(string $username): array
{
    $max = max(1, (int) setting_get('login_fail_max', '5'));
    $u = db_one('SELECT id, login_fail_count, login_fail_at FROM users WHERE username = ? LIMIT 1',
        [$username]);
    if (!$u) {
        return [false, $max];
    }
    // 窗口起点为空或已过期，本次失败算作新窗口的第 1 次
    $起点 = !empty($u['login_fail_at']) ? strtotime((string) $u['login_fail_at']) : 0;
    $窗口内 = $起点 > 0 && (time() - $起点) < 登录失败窗口秒;
    $count = $窗口内 ? (int) $u['login_fail_count'] + 1 : 1;

    if ($count >= $max) {
        db_exec('UPDATE users SET status = 0, login_fail_count = ?, login_fail_at = NOW() WHERE id = ?',
            [$count, $u['id']]);
        return [true, 0];
    }
    // 只有开新窗口才刷新起点。窗口内累加时保持原起点，否则每次失败都把窗口往后推，
    // 攻击者慢速试探就永远不会过期，等于又变回了无限累加。
    if ($窗口内) {
        db_exec('UPDATE users SET login_fail_count = ? WHERE id = ?', [$count, $u['id']]);
    } else {
        db_exec('UPDATE users SET login_fail_count = ?, login_fail_at = NOW() WHERE id = ?',
            [$count, $u['id']]);
    }
    return [false, $max - $count];
}

/**
 * 登录成功后重置失败计数，连窗口起点一起清掉。
 */
function login_attempt_reset(int $userId): void
{
    db_exec('UPDATE users SET login_fail_count = 0, login_fail_at = NULL WHERE id = ?', [$userId]);
}


// ================ 【子分类 SK 轮询】 ================

/**
 * 把 channels.api_key 里存的内容解析成 SK 数组。
 * 兼容两种历史格式：单个 SK（一行）/ 多个 SK（换行分隔）。
 * 顺带过滤空行、去重、去掉首尾空白，保证顺序稳定。
 */
function channel_sk_list($rawKey): array
{
    $raw = (string)$rawKey;
    if (trim($raw) === '') {
        return [];
    }
    // 兼容用户粘贴时混入的逗号/分号分隔
    $raw  = str_replace(["\r\n", "\r", ',', ';', '，', '；'], "\n", $raw);
    $list = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line !== '' && !in_array($line, $list, true)) {
            $list[] = $line;
        }
    }
    return $list;
}

/**
 * 按轮询游标从子分类里挑出本次要用的 SK。
 *
 * rotate=0 或只有 1 个 SK 时，直接返回第 1 个，不写库、不产生额外开销。
 * rotate=1 且有多个 SK 时，取 rotate_idx % N 作为本次使用的下标，
 * 然后把游标 +1 写回，实现顺序轮询（下次请求自动用下一个 SK）。
 *
 * 游标用 SQL 自增而不是 PHP 读改写，避免并发请求拿到同一个 SK。
 * 返回值在原数组上补三个字段：api_key（本次选中）、_sk_total、_sk_index。
 */
/**
 * 取某渠道当前处于熔断中的 SK 下标集合。
 * 返回 [下标 => 解冻时间]，正常的 SK 不出现在结果里。
 * 过期的熔断记录直接当正常看，不特意去清，反正下次失败会覆盖写。
 */
function channel_sk_cooled(int $chId): array
{
    if ($chId <= 0) {
        return [];
    }
    $rows = db_all('SELECT sk_index, cooled_until FROM channel_sk_health
                     WHERE channel_id = ? AND cooled_until IS NOT NULL AND cooled_until > NOW()',
                   [$chId]);
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['sk_index']] = $r['cooled_until'];
    }
    return $out;
}
/**
 * 读取后台配置里的 HTTP 故障码清单（sk_http_auth_codes / sk_http_busy_codes）。
 *
 * 后台存的是一行逗号分隔的文本，这里统一解析成整数数组供判断用。
 * 只接受 100~599 的合法状态码，顺带去重；配置为空或全是垃圾字符时
 * 退回调用方给的默认清单，保证熔断判断永远有一份可用的码表。
 *
 * @param string $key     设置项键名
 * @param int[]  $default 配置缺失时使用的默认码表
 * @return int[]
 */
function sk_http_codes(string $key, array $default = []): array
{
    $raw = (string) setting_get($key, '');
    $out = [];
    // 兼容中英文逗号、分号、空格、换行等各种手写分隔方式
    foreach (preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $seg) {
        $code = (int) $seg;
        if ($code >= 100 && $code <= 599 && !in_array($code, $out, true)) {
            $out[] = $code;
        }
    }
    if ($out === []) {
        // 默认清单也过一遍去重，避免调用方写重复值影响后续 array_merge
        foreach ($default as $code) {
            $code = (int) $code;
            if ($code >= 100 && $code <= 599 && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
    }
    return $out;
}

/**
 * 记一次 SK 失败，够阈值就熔断。
 *
 * 两类故障分开处理，因为性质完全不同：
 *   401/403 —— key 废了或没权限，重试一万次也是废的，一次就熔断，冷却时间长
 *   429     —— 只是被打满，过一会儿就能用，攒够次数才熔断，冷却时间短
 * 冷却时长可在后台「站点设置」调 sk_cool_auth / sk_cool_rate（单位秒）。
 */
function channel_sk_fail(int $chId, int $skIdx, int $httpCode, string $errMsg = ''): void
{
    if ($chId <= 0 || $skIdx < 0) {
        return;
    }
    $认证类 = in_array($httpCode, [401, 403], true);
    $限流类 = ($httpCode === 429);
    // 上游过载/网关错误：比限流恢复得快，给它更短的基础冷却，
    // 避免一波过载把可用 SK 池冻得太小。
    $过载类 = in_array($httpCode, [502, 503, 504, 520, 522, 524], true);
    // 429 一次就熔断：高峰期限流是瞬时的，攒两次只是白白多打一次废请求。
    $阈值   = ($认证类 || $限流类) ? 1 : max(1, (int) setting_get('sk_fail_threshold', 2));
    if ($认证类) {
        $基础秒 = max(60, (int) setting_get('sk_cool_auth', 600));
    } elseif ($过载类) {
        $基础秒 = max(15, (int) setting_get('sk_cool_busy', 45));
    } else {
        $基础秒 = max(30, (int) setting_get('sk_cool_rate', 120));
    }
    // 先累加失败次数（UNIQUE 键保证并发下不会插出两行）
    db_exec('INSERT INTO channel_sk_health
                (channel_id, sk_index, fail_count, last_http, last_error, updated_at)
             VALUES (?, ?, 1, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                fail_count = fail_count + 1,
                last_http  = VALUES(last_http),
                last_error = VALUES(last_error),
                updated_at = NOW()',
        [$chId, $skIdx, $httpCode, mb_substr($errMsg, 0, 240)]);
    // 读回累计失败次数，算叠加倍数。这里必须再查一次：
    // 上面的 INSERT ... ON DUPLICATE 不会告诉我们加完是多少，
    // 而叠加倒数第二步就得知道“这是第几次失败”。
    $已失败 = (int) db_val('SELECT fail_count FROM channel_sk_health
                                    WHERE channel_id = ? AND sk_index = ?', [$chId, $skIdx]);
    if ($已失败 < $阈值) {
        return;   // 还没到阈值，只记账不熔断
    }
    // 叠加冷却：同一个 SK 反复失败就让它冷得越来越久。
    // 刚到阈值算 1 倍，之后每多失败一次翻一倍：1 / 2 / 4 / 8 …
    // 中间只要成功一次，channel_sk_ok() 会把 fail_count 清零，倍数自动回到 1。
    // 认证类不参与叠加：key 被删或没权限，翻到几小时没意义，
    // 反正每次选到它都会立即再熔断一次。
    if ($认证类) {
        $冷却秒 = $基础秒;
    } else {
        $倍数上限 = max(1, (int) setting_get('sk_cool_factor_max', 8));
        $超出次数 = $已失败 - $阈值;                    // 0 就是刚到阈值
        $倍数     = min($倍数上限, 1 << min(30, $超出次数));  // 1,2,4,8… 并防移位溢出
        $硬顶     = max(60, (int) setting_get('sk_cool_max', 1800));
        $冷却秒   = min($硬顶, $基础秒 * $倍数);
    }
    // 只往后延，不把已有的熔断提前解除：
    // GREATEST 防止并发下后一次算出的较短冷却覆盖前一次的长冷却。
    db_exec('UPDATE channel_sk_health
                SET cooled_until = GREATEST(
                        COALESCE(cooled_until, NOW()),
                        DATE_ADD(NOW(), INTERVAL ? SECOND))
              WHERE channel_id = ? AND sk_index = ?',
        [$冷却秒, $chId, $skIdx]);
    error_log(sprintf('[sk] 渠道 %d SK #%d 熔断：第 %d 次失败(HTTP %d)，冷却 %d 秒',
        $chId, $skIdx + 1, $已失败, $httpCode, $冷却秒));
}
/**
 * 某个 SK 成功用过一次，清掉它的失败计数和熔断状态。
 * 没有记录时什么都不用做，所以只 UPDATE 不 INSERT，省一次写。
 */
function channel_sk_ok(int $chId, int $skIdx): void
{
    if ($chId <= 0 || $skIdx < 0) {
        return;
    }
    db_exec('UPDATE channel_sk_health
                SET fail_count = 0, cooled_until = NULL, updated_at = NOW()
              WHERE channel_id = ? AND sk_index = ? AND (fail_count > 0 OR cooled_until IS NOT NULL)',
        [$chId, $skIdx]);
}
function channel_pick_sk(array $ch, ?int $bindIdx = null): array
{
    // api_key 在首次选中后会被压成单个 SK，重试再进来时若从 api_key 重新解析会只剩 1 个，
    // 轮换就转不动了。所以把解析好的完整列表存进 _sk_list，后续（含 upstream_stream 的重试）复用。
    if (isset($ch['_sk_list']) && is_array($ch['_sk_list'])) {
        $list = $ch['_sk_list'];
    } else {
        $list = channel_sk_list($ch['api_key'] ?? '');
        $ch['_sk_list'] = $list;
    }
    $n    = count($list);
    if ($n === 0) {
        $ch['_sk_total'] = 0;
        $ch['_sk_index'] = 0;
        return $ch;
    }

    $ch['_sk_total'] = $n;
    $轮询开 = (int)($ch['rotate'] ?? 0) === 1;
    $chId  = (int)($ch['id'] ?? 0);

    if (!$轮询开 || $n === 1) {
        $ch['api_key'] = $list[0];
        $ch['_sk_index'] = 0;
        return $ch;
    }

    // 熔断中的 SK 集合，选 key 时要跳过
    $熔断 = channel_sk_cooled($chId);

    // 全员熔断时：不 blindly 用 rotate_idx，而是挑冷却时间最短、最快解冻的那个 SK 试一次
    $全员熔断 = count($熔断) >= $n;
    $最短冷却Idx = null;
    $最短冷却时间 = null;
    if ($全员熔断) {
        foreach ($熔断 as $试 => $解冻时间) {
            $t = strtotime($解冻时间);
            if ($最短冷却时间 === null || $t < $最短冷却时间) {
                $最短冷却时间 = $t;
                $最短冷却Idx  = $试;
            }
        }
    }

    // 会话已绑定过 SK：固定复用，不推游标，保证 prompt 缓存能连续命中
    if ($bindIdx !== null) {
        $idx = $bindIdx % $n;
        if ($idx < 0) {
            $idx = 0;
        }
        // 绑定的 key 正在熔断：临时借一个健康的用，但不动数据库里的绑定关系。
        // 冷却结束后这条对话会自动再回到原 key，缓存还在，不白白丢一次重建。
        if (isset($熔断[$idx])) {
            $借 = null;
            for ($k = 1; $k <= $n; $k++) {
                $试 = ($idx + $k) % $n;
                if (!isset($熔断[$试])) {
                    $借 = $试;
                    break;
                }
            }
            if ($借 !== null) {
                $ch['api_key']    = $list[$借];
                $ch['_sk_index']  = $借;
                $ch['_sk_borrow'] = $idx;
                return $ch;
            }
            // 全熔断了：用最快解冻的那个，而不是死磕绑定的坏 key
            if ($最短冷却Idx !== null) {
                $ch['api_key']   = $list[$最短冷却Idx];
                $ch['_sk_index'] = $最短冷却Idx;
                return $ch;
            }
        }
        $ch['api_key']   = $list[$idx];
        $ch['_sk_index'] = $idx;
        return $ch;
    }

    // 新对话：负载均衡——选「当前承载对话数最少」且没熔断的 SK。
    // 会话粘性下，conversations.sk_index 记录了每条对话绑定的 SK，这就是实时负载。
    // 旧的顺序轮询会盯着一轮一轮地打同一个 SK，把它打到 429，再换下一个又 429；
    // 这里改成看哪个 SK 当前用得最少就用哪个，把请求摊开，避免单个 SK 被集中打满。
    $负载 = [];
    if ($chId > 0) {
        $rows = db_all('SELECT cv.sk_index AS idx, COUNT(*) AS n
                          FROM conversations cv JOIN models m ON m.id = cv.model_id
                         WHERE m.channel_id = ? AND cv.sk_index IS NOT NULL
                         GROUP BY cv.sk_index', [$chId]);
        foreach ($rows as $r) {
            $负载[((int)$r['idx']) % $n] = (int)$r['n'];
        }
        // v1 没有对话表，它的会话粘性落在 session_sk_bind（会话指纹 → SK）里。
        // 根目录与 v1 共享同一批 SK 时，必须把两边的绑定数合并起来算负载，
        // 否则两边各自只看自己那半，都会以为某个 SK 很空，结果又挤到一起。
        // 表是惰性建的，先确保存在，避免纯根目录流量（没触发过建表）时查空表报错。
        if (channel_session_sk_table_ok()) {
            $rows2 = db_all('SELECT sk_index AS idx, COUNT(*) AS n FROM session_sk_bind WHERE channel_id = ? GROUP BY sk_index', [$chId]);
            foreach ($rows2 as $r) {
                $idx2 = ((int)$r['idx']) % $n;
                $负载[$idx2] = ($负载[$idx2] ?? 0) + (int)$r['n'];
            }
        }
    }

    // 从游标位开始扫，找「没熔断 + 负载最少」的 SK；负载相同时优先靠近游标位（保留顺序兜底语义）
    $起点 = (int)($ch['rotate_idx'] ?? 0) % $n;
    if ($起点 < 0) {
        $起点 = 0;
    }
    $idx = null;
    $最少负载 = PHP_INT_MAX;
    for ($k = 0; $k < $n; $k++) {
        $试 = ($起点 + $k) % $n;
        if (isset($熔断[$试])) {
            continue;   // 熔断中的跳过
        }
        $load = $负载[$试] ?? 0;
        if ($load < $最少负载) {
            $最少负载 = $load;
            $idx = $试;
        }
    }

    // 全员熔断时选最快解冻的；否则兜底回游标位（正常情况下不会走到这里）
    if ($idx === null) {
        $idx = ($全员熔断 && $最短冷却Idx !== null) ? $最短冷却Idx : $起点;
    }

    $ch['api_key']   = $list[$idx];
    $ch['_sk_index'] = $idx;

    // 游标推进：负载均衡下仅作 tie-break 起点，仍推进一步保持原语义，取模防溢出。
    if ($chId > 0) {
        db_exec('UPDATE channels SET rotate_idx = ? WHERE id = ?', [($idx + 1) % $n, $chId]);
    }
    return $ch;
}


// ================ 【会话 ↔ SK 持久绑定】===============
// 会话指纹算出的绑定 SK 熔断时，channel_pick_sk 会临时借一个健康 SK。
// 这里把「借来的 SK」持久化：同会话后续请求直接用借来的 SK，
// 直到它也故障熔断再换新的，不再每次回到坏 SK 上撞限流。

/**
 * 惰性建表：session_sk_bind 表不存在才创建（静态变量只检查一次）。
 */
function channel_session_sk_table_ok(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $r = db_one("SHOW TABLES LIKE 'session_sk_bind'");
    if (!$r) {
        // 表不存在：直接建新结构，复合主键 (channel_id, session_fp)。
        // 同一个会话指纹在不同渠道下应各自独立绑定，不能互相串。
        db_exec("CREATE TABLE IF NOT EXISTS session_sk_bind (
            channel_id INT NOT NULL,
            session_fp BIGINT NOT NULL,
            sk_index INT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (channel_id, session_fp),
            KEY idx_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        // 表已存在：检查是否还是旧的单主键结构（缺 channel_id）。
        $col = db_one("SHOW COLUMNS FROM session_sk_bind LIKE 'channel_id'");
        if (!$col) {
            // 旧结构只存了 session_fp + sk_index，没有渠道归属，
            // 在负载均衡语义下已经对不上号，直接重建，旧绑定下次重新分配即可。
            db_exec("DROP TABLE session_sk_bind");
            db_exec("CREATE TABLE session_sk_bind (
                channel_id INT NOT NULL,
                session_fp BIGINT NOT NULL,
                sk_index INT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (channel_id, session_fp),
                KEY idx_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }
    $ok = true;
    return $ok;
}

/**
 * 读取会话持久绑定的 SK 下标；没绑定过返回 null。
 */
function channel_session_sk_get(int $sessionFp, int $chId): ?int
{
    if (!channel_session_sk_table_ok()) {
        return null;
    }
    $row = db_one('SELECT sk_index FROM session_sk_bind WHERE channel_id = ? AND session_fp = ?', [$chId, $sessionFp]);
    return $row ? (int) $row['sk_index'] : null;
}

/**
 * 记录会话绑定的 SK 下标（新会话负载均衡选中后，或绑定的 SK 熔断换线后）。
 * 顺带清理 7 天未活跃的绑定，防止表无限膨胀。
 */
function channel_session_sk_set(int $sessionFp, int $skIndex, int $chId): void
{
    if (!channel_session_sk_table_ok()) {
        return;
    }
    db_exec(
        'INSERT INTO session_sk_bind (channel_id, session_fp, sk_index, updated_at) VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE sk_index = VALUES(sk_index), updated_at = NOW()',
        [$chId, $sessionFp, $skIndex]
    );
    db_exec('DELETE FROM session_sk_bind WHERE updated_at < NOW() - INTERVAL 7 DAY');
}


// ================ 【api_platforms 父子分类】核心合并函数 ================

/**
 * 把子分类 (channels) 和父分类 (api_platforms) 字段合并。
 * 子分类自己有值的就用子分类的，空的就从父分类继承。
 * 没有父分类时原样返回。返回字段完全兼容旧代码对 $ch 的用法。
 */
function channel_merge_platform(array $ch, ?array $platform = null, ?int $bindIdx = null, bool $skipSk = false): array
{
    // SK 轮询：放在最前面，保证 parent_id=0 的遗留渠道也能用上轮询
    // $skipSk=true 时只做字段继承，不选 SK、不推游标。
    // chat.php 分两步走：先继承字段，等 $convId 确定后再单独选 SK。
    if (!$skipSk) {
        $ch = channel_pick_sk($ch, $bindIdx);
    }

    $pid = (int)($ch['parent_id'] ?? 0);
    if ($pid <= 0) {
        return $ch;
    }
    if ($platform === null) {
        $platform = db_one('SELECT * FROM api_platforms WHERE id = ?', [$pid]);
        if (!$platform) {
            return $ch;
        }
    }
    $继承字段 = ['base_url', 'protocol', 'chat_path', 'api_version', 'timeout'];
    $out = $ch;
    foreach ($继承字段 as $f) {
        $子值 = $out[$f] ?? null;
        if ($子值 === null || $子值 === '' || $子值 === '0') {
            if (isset($platform[$f]) && $platform[$f] !== null && $platform[$f] !== '') {
                $out[$f] = $platform[$f];
            }
        }
    }
    $out['_platform_token'] = $platform['access_token'] ?? '';
    $out['_platform_name']  = $platform['name'] ?? '';
    return $out;
}


/**
 * 用平台 access_token 调 NewAPI 的两个余额接口：
 *   GET /v1/dashboard/billing/subscription  → soft/hard 上限
 *   GET /v1/dashboard/billing/usage         → total_usage（美分）
 * 优先级：
 *   1) 父分类填写的 access_token
 *   2) 否则兜底：取该平台下任一启用子分类的 api_key
 * 认证方式：先试 Bearer (NewAPI/OneAPI)，401 再试 x-api-key (Claude协议变体)
 */
function platform_balance_check($plat): array
{
    if (is_numeric($plat)) {
        $row = db_one('SELECT * FROM api_platforms WHERE id = ?', [(int)$plat]);
        if (!$row) return ['ok' => false, 'err' => '平台不存在'];
        $plat = $row;
    }
    if (!is_array($plat)) return ['ok' => false, 'err' => '参数错误'];

    $pid   = (int)($plat['id'] ?? 0);
    $token = trim((string)($plat['access_token'] ?? ''));
    $base  = rtrim(trim((string)($plat['base_url'] ?? '')), '/');
    $源    = '父分类access_token';
    if ($base === '') return ['ok' => false, 'err' => '父分类 base_url 未填写'];

    // 兜底：父分类没填 token，从该平台下找一个启用子分类的 SK（多行存储时取第一行）
    if ($token === '' && $pid > 0) {
        $fallback = db_val(
            "SELECT api_key FROM channels WHERE parent_id=? AND status=1 AND api_key<>'' ORDER BY sort DESC, id LIMIT 1",
            [$pid]
        );
        if (is_string($fallback) && trim($fallback) !== '') {
            // api_key 可能是多行 SK，取第一个有效行
            $skList = channel_sk_list($fallback);
            $token  = $skList[0] ?? trim($fallback);
            $源     = '子分类SK兜底';
        }
    }
    if ($token === '') {
        return ['ok' => false,
            'err' => '请填父分类访问令牌，或先给该平台下至少一个子分类填SK并启用'];
    }

    // 裸域名（去掉 /v1，避免最终拼成 /v1/v1/dashboard/...）
    $裸 = $base;
    if (substr($裸, -3) === '/v1') $裸 = substr($裸, 0, -3);
    elseif (substr($裸, -2) === 'v1')  $裸 = substr($裸, 0, -2);
    $裸 = rtrim($裸, '/');

    $userId = trim((string)($plat['api_user_id'] ?? ''));
    $用户头 = $userId !== '' ? ['New-Api-User: ' . $userId] : [];
    $头集合 = [
        'bearer'  => array_merge(['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token], $用户头),
        'xapikey' => array_merge(['Accept: application/json', 'Content-Type: application/json', 'x-api-key: ' . $token], $用户头),
    ];

    $单次 = function (string $path, array $头) use ($裸): array {
        $c = curl_init($裸 . $path);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $头,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        $err  = curl_error($c);
        return [$code, $body, $err];
    };

    // 遍历两种认证方式，先 Bearer；只要 HTTP < 400 就算成功
    $挑 = function (string $path) use ($头集合, $单次): array {
        $last = null;
        $lastName = null;
        foreach ($头集合 as $auth名 => $头) {
            $r = $单次($path, $头);
            list($code) = $r;
            $last = $r;
            $lastName = $auth名;
            if ($code >= 200 && $code < 400) return [$auth名, $r];
        }
        return [$lastName, $last];
    };

    list($sAuth, list($sCode, $sBody, $sErr)) = $挑('/v1/dashboard/billing/subscription');
    list($uAuth, list($uCode, $uBody, $uErr)) = $挑('/v1/dashboard/billing/usage');

    $sub = json_decode($sBody, true);
    $use = json_decode($uBody, true);

    if ($sCode >= 400 && $uCode >= 400) {
        $sample = is_string($sBody) && $sBody !== '' ? $sBody : (is_string($uBody) ? $uBody : '');
        $用什么 = [];
        if ($sAuth) $用什么[] = 'sub:'.$sAuth;
        if ($uAuth) $用什么[] = 'usage:'.$uAuth;
        return [
            'ok'         => false,
            'err'        => '查询失败(sub HTTP-'.$sCode.', usage HTTP-'.$uCode.')'
                            .($用什么 ? ' [认证:'.implode('/', $用什么).'] ' : ' ')
                            .mb_substr((string)$sample, 0, 200),
            'auth_sub'   => $sAuth,
            'auth_usage' => $uAuth,
            'token_source' => $源,
            'raw_sub'    => is_array($sub) ? $sub : null,
            'raw_usage'  => is_array($use) ? $use : null,
            'curl_err'   => trim($sErr.' '.$uErr) ?: null,
        ];
    }

    $soft    = (float)($sub['soft_limit_usd']   ?? 0);
    $hard    = (float)($sub['hard_limit_usd']   ?? 0);
    $until   = (int)  ($sub['access_until']     ?? 0);
    $cent    = (float)($use['total_usage']      ?? 0);
    $已用USD = round($cent / 100, 4);
    $剩余USD = $soft > 0 ? max(0, round($soft - $已用USD, 4)) : null;

    return [
        'ok'                => true,
        'token_source'      => $源,
        'auth_sub'          => $sAuth,
        'auth_usage'        => $uAuth,
        'soft_limit_usd'    => $soft,
        'hard_limit_usd'    => $hard,
        'access_until_ts'   => $until,
        'access_until_str'  => $until ? date('Y-m-d H:i:s', $until) : '无到期时间(按量付费)',
        'total_usage_usd'   => $已用USD,
        'total_usage_cent'  => $cent,
        'remaining_usd'     => $剩余USD,
        'raw_sub'           => is_array($sub) ? $sub : null,
        'raw_usage'         => is_array($use) ? $use : null,
        'curl_err'          => trim($sErr.' '.$uErr) ?: null,
    ];
}
/**
 * 文件落盘后的统一收尾：设权限，并在以 root 身份运行时把属主对齐到父目录。
 *
 * 为什么需要：站点由 PHP-FPM 以 www 身份运行，正常落盘的文件属主就是 www。
 * 但维护时若用 root 直接跑 CLI 脚本（生成、迁移、补数据），产出的文件会是
 * root:root + 0640，之后网页端以 www 身份 readfile() 读不了，表现为下载
 * 得到空内容。属主取父目录的，不写死 www，换运行用户也不会错。
 *
 * @param string $路径 刚写好的文件绝对路径
 * @param int    $模式 权限位，默认 0640
 */
function 落盘收尾(string $路径, int $模式 = 0640): void
{
    if ($路径 === '' || !is_file($路径)) {
        return;
    }
    @chmod($路径, $模式);
    // chown/chgrp 在宝塔默认的 disable_functions 里，被禁用后是「未定义函数」，
    // PHP 8 下这属于致命错误，前面的 @ 压不住，整个请求直接 500。
    // 表现过一次：上传图片压缩后落盘收尾时白屏报 Call to undefined function chown()。
    // 所以必须先探在不在，不能只靠 @。禁用了也没关系：FPM 以 www 跑时属主本来就对。
    if (!function_exists('chown') || !function_exists('chgrp')) {
        return;
    }
    // 只有 root 才有权 chown，普通身份跳过（此时属主本来就是对的）
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return;
    }
    $父 = dirname($路径);
    $uid = @fileowner($父);
    $gid = @filegroup($父);
    if ($uid === false || $gid === false || $uid === 0) {
        return; // 父目录本身就归 root，说明这套部署就是 root 跑的，不动
    }
    @chown($路径, $uid);
    @chgrp($路径, $gid);
}
// ==================== API Key 认证（供 Electron 等第三方客户端使用） ====================
/** 生成随机 API Token */
function generate_api_key(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}
/** 对 API Token 做 hash 存储 */
function hash_api_token(string $token): string
{
    return hash('sha256', $token);
}
/** 为用户创建一枚 API Key，返回明文 token（仅在此时可见，请提示用户保存） */
function api_key_create(int $userId, string $name = '默认密钥'): ?string
{
    $token = generate_api_key();
    $hash  = hash_api_token($token);
    $ok = db_exec(
        'INSERT INTO user_api_keys (user_id, name, token_hash, created_at) VALUES (?, ?, ?, NOW())',
        [$userId, $name, $hash]
    );
    return $ok ? $token : null;
}
/** 通过请求头中的 Bearer Token 验证用户身份，成功返回用户数组，失败返回 null */
function api_key_validate(): ?array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    // 兼容 Anthropic 风格：x-api-key 头（Claude Code / Codex 等客户端用它）
    if (empty($authHeader)) {
        $xKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (empty($xKey) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $xKey = $headers['x-api-key'] ?? $headers['X-Api-Key'] ?? '';
        }
        if ($xKey !== '') {
            $authHeader = 'Bearer ' . trim($xKey);
        }
    }
    if (empty($authHeader) || stripos($authHeader, 'Bearer ') !== 0) {
        return null;
    }
    $token = trim(substr($authHeader, 7));
    if ($token === '') {
        return null;
    }
    $hash = hash_api_token($token);
    $row = db_one(
        'SELECT k.user_id, k.status AS key_status, k.last_used_at
           FROM user_api_keys k
          WHERE k.token_hash = ? AND k.status = 1
          LIMIT 1',
        [$hash]
    );
    if (!$row) {
        return null;
    }
    $u = db_one('SELECT * FROM users WHERE id = ? AND status = 1 LIMIT 1', [(int)$row['user_id']]);
    if (!$u) {
        return null;
    }
    // 更新最后使用时间
    db_exec('UPDATE user_api_keys SET last_used_at = NOW() WHERE token_hash = ?', [$hash]);
    return $u;
}

// ==================== SK 卡密认证（供 /v1 中转 API 使用） ====================

/** 生成 sk- 前缀的卡密 token */
function generate_sk_token(): string
{
    return 'sk-' . bin2hex(random_bytes(24));
}

/** 为用户创建一枚 SK 卡密，返回明文 token */
function sk_card_create(int $userId, string $name, float $balance, ?string $expiresAt, string $ipWhitelist, string $ipBlacklist, string $allowedModels = ''): ?string
{
    // 拉黑用户不能创建卡密
    $bl = (int) db_val('SELECT sk_blacklisted FROM users WHERE id = ?', [$userId]);
    if ($bl === 1) {
        return null;
    }
    $token = generate_sk_token();
    $hash  = hash_api_token($token);
    $ok = db_exec(
        'INSERT INTO sk_cards (user_id, token_hash, name, balance, expires_at, ip_whitelist, ip_blacklist, allowed_models, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
        [$userId, $hash, $name, $balance,
         $expiresAt ?: null,
         $ipWhitelist !== '' ? $ipWhitelist : null,
         $ipBlacklist !== '' ? $ipBlacklist : null,
         $allowedModels !== '' ? $allowedModels : null]
    );
    return $ok ? $token : null;
}

/**
 * 通过 SK 卡密验证身份（供 /v1 中转 API 使用）。
 * 成功返回用户数组（附带 _sk_card 信息），失败返回 null。
 * 失败时将具体原因写入 $GLOBALS['sk_validate_error']，供调用方输出给客户端。
 */
function sk_card_validate(): ?array
{
    $GLOBALS['sk_validate_error'] = '';

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    // 兼容 x-api-key 头
    if (empty($authHeader)) {
        $xKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (empty($xKey) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $xKey = $headers['x-api-key'] ?? $headers['X-Api-Key'] ?? '';
        }
        if ($xKey !== '') {
            $authHeader = 'Bearer ' . trim($xKey);
        }
    }
    // 兼容 Gemini CLI 的 x-goog-api-key 头（Google Gemini API 风格认证）
    if (empty($authHeader)) {
        $xKey = $_SERVER['HTTP_X_GOOG_API_KEY'] ?? '';
        if (empty($xKey) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $xKey = $headers['x-goog-api-key'] ?? $headers['X-Goog-Api-Key'] ?? '';
        }
        if ($xKey !== '') {
            $authHeader = 'Bearer ' . trim($xKey);
        }
    }
    if (empty($authHeader) || stripos($authHeader, 'Bearer ') !== 0) {
        // 必须携带 Authorization: Bearer sk-xxx 或 x-api-key: sk-xxx 头，
        // 不带头不允许访问，不走默认卡密。
        $GLOBALS['sk_validate_error'] = '缺少 Authorization: Bearer sk-xxx 请求头。请在个人中心生成卡密后使用。';
        return null;
    }
    $token = trim(substr($authHeader, 7));
    // 只认 sk- 前缀
    if ($token === '' || strpos($token, 'sk-') !== 0) {
        $GLOBALS['sk_validate_error'] = 'API Key 格式错误，应以 sk- 开头。';
        return null;
    }
    $hash = hash_api_token($token);
    $card = db_one(
        'SELECT * FROM sk_cards WHERE token_hash = ? AND status = 1 LIMIT 1',
        [$hash]
    );
    if (!$card) {
        $GLOBALS['sk_validate_error'] = 'API Key 无效或已被删除，请确认使用的是最新生成的卡密。';
        return null;
    }
    // 检查过期
    if ($card['expires_at'] !== null && $card['expires_at'] !== '') {
        if (strtotime((string) $card['expires_at']) < time()) {
            $GLOBALS['sk_validate_error'] = '卡密已过期（到期时间：' . $card['expires_at'] . '），请重新生成。';
            return null;
        }
    }
    // 检查余额
    if ((float) $card['balance'] <= 0) {
        $GLOBALS['sk_validate_error'] = '卡密余额为 0，请充值或更换有余额的卡密。';
        return null;
    }
    // 检查 IP 限制
    $ip = client_ip();
    // IP 白名单校验
    if (!empty($card['ip_whitelist'])) {
        $ips = array_filter(array_map('trim', explode(',', (string) $card['ip_whitelist'])));
        $allowed = false;
        foreach ($ips as $allowedIp) {
            $allowedIp = trim($allowedIp);
            if ($allowedIp === '') continue;
            if ($ip === $allowedIp || sk_ip_match($ip, $allowedIp)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $GLOBALS['sk_validate_error'] = '当前 IP（' . $ip . '）不在卡密白名单中。';
            return null;
        }
    }
    // IP 黑名单校验
    if (!empty($card['ip_blacklist'])) {
        $ips = array_filter(array_map('trim', explode(',', (string) $card['ip_blacklist'])));
        foreach ($ips as $blockedIp) {
            $blockedIp = trim($blockedIp);
            if ($blockedIp === '') continue;
            if ($ip === $blockedIp || sk_ip_match($ip, $blockedIp)) {
                $GLOBALS['sk_validate_error'] = '当前 IP（' . $ip . '）已被卡密黑名单拦截。';
                return null;
            }
        }
    }
    // 取用户
    $u = db_one('SELECT * FROM users WHERE id = ? AND status = 1 LIMIT 1', [(int) $card['user_id']]);
    if (!$u) {
        $GLOBALS['sk_validate_error'] = '卡密关联的账号已被停用。';
        return null;
    }
    // 拉黑用户的卡密不可用
    if ((int) ($u['sk_blacklisted'] ?? 0) === 1) {
        $GLOBALS['sk_validate_error'] = '该账号已被拉黑，卡密不可用。';
        return null;
    }
    // 更新最后使用时间和 IP
    db_exec('UPDATE sk_cards SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?',
        [$ip, (int) $card['id']]);
    // 把卡密信息附在用户数组上，供计费用
    $u['_sk_card'] = $card;
    return $u;
}

/** CIDR IP 匹配 */
function sk_ip_match(string $ip, string $rule): bool
{
    if (strpos($rule, '/') !== false) {
        [$subnet, $mask] = explode('/', $rule, 2);
        $mask = (int) $mask;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $maskLong = $mask === 0 ? 0 : (~((1 << (32 - $mask)) - 1));
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
    return false;
}

/** SK 卡密扣费：从卡密余额里扣，同时写余额流水 */
function sk_card_charge(int $cardId, int $userId, string $cost, string $note): void
{
    db_exec('UPDATE sk_cards SET balance = balance - ?, total_cost = total_cost + ? WHERE id = ?',
        [$cost, $cost, $cardId]);
    if ((float) $cost > 0) {
        $余额后 = (float) db_val('SELECT balance FROM sk_cards WHERE id = ?', [$cardId]);
        db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, created_at)
                 VALUES (?,?,?,?,?,NOW())',
            [$userId, -$cost, $余额后, 'consume', $note]);
    }
}
