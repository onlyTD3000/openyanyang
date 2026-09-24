<?php
$adminOn = 'settings';
$pageTitle = '站点设置';

// POST 要在 _head.php 之前处理完，否则重定向发不出去，刷新会重发表单。
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();

$msg = $err = '';
// 第三项是 bool 型开关旁边的说明文案。以前这段文案写死成「允许新用户自助注册」，
// 结果两个缓存开关也跟着显示注册相关的说明，勾选时完全看不出自己在开什么。
$keys = [
    'home_template'    => ['首页模板', 'select'],
    'site_name'        => ['站点名称', 'text'],
    'site_notice'      => ['首页/聊天页公告（留空不显示）', 'textarea'],
    'allow_register'   => ['开放注册', 'bool', '允许新用户自助注册'],
    'register_balance' => ['注册赠送余额（元）', 'number'],
    'register_quota'   => ['注册赠送 Token 配额（0=不限）', 'int'],
    'min_balance'           => ['允许发起对话的最低余额（元）', 'number'],
    'register_ip_interval'  => ['注册防刷：同IP注册间隔（秒，0=关闭）', 'int'],
    'upload_max_image_size' => ['上传图片体积上限（KB）', 'int',
        '用户上传图片时，超过该大小的直接拒绝。默认 300，填 0 表示不限制'],
    'login_fail_max'        => ['登录失败最大次数（每小时）', 'int',
        '同一账号一小时内失败达到该次数即封禁。计数为滚动窗口，距上次失败满 1 小时后重新计起'],
    'login_ban_msg'         => ['账号封禁提示语', 'text'],
    'prompt_cache'       => ['上游提示词缓存', 'bool',
        'Claude 显式挂 cache_control，OpenAI 自动前缀缓存，命中后输入按缓存价结算（建议开）'],
    'prompt_cache_local' => ['本地提示词缓存', 'bool',
        '把拼好的系统提示词缓存到磁盘，省掉每轮重复查库（建议开）'],
    'upstream_retry_times'  => ['上游错误最大重试次数', 'int',
        '对话接口遇到 400/502/503/超时等瞬时故障时最多重试几次（0=不重试）'],
    'upstream_retry_interval' => ['重试初始间隔（秒）', 'int',
        '退避重试的起始间隔，第 n 次重试前等待 间隔×n 秒，避免冲垮上游'],
    // ---- 多 SK 轮询的熔断与冷却 ----
    // 这几项以前只能改代码，现在全部放到后台。
    // 不填（值为 0）时 helpers.php 里的 max() 会兑回各自的下限，不会真的变 0。
    'sk_fail_threshold'  => ['SK 熔断失败阈值（次）', 'int',
        '同一个 SK 累计失败几次后开始熔断（默认 2）。401/403 不受此限，一次就熔'],
    'sk_cool_busy'       => ['过载类基础冷却（秒）', 'int',
        '502/503/504 等上游过载。默认 45，下限 15。这类恢复快，不宜设太长'],
    'sk_cool_rate'       => ['限流类基础冷却（秒）', 'int',
        '429 被打满。默认 120，下限 30'],
    'sk_cool_auth'       => ['认证失效冷却（秒）', 'int',
        '401/403，key 废了或没权限。默认 600，下限 60。固定时长，不参与叠加'],
    'sk_cool_factor_max' => ['冷却叠加倍数上限', 'int',
        '反复失败时冷却逐次翻倍：1→2→4→8… 到这个倍数封顶（默认 8，填 1 等于关掉叠加）。中间成功一次就重置回 1 倍'],
    'sk_cool_max'        => ['单次冷却最长（秒）', 'int',
        '叠加后的硬顶，再怎么翻也不超过它。默认 1800（1 小时），下限 60'],
    // ---- 并发控制 ----
    'concurrency_limit'  => ['全局并发上限', 'int',
        '同时处理的请求数上限（0=不限制）。超过上限的请求将排队等待或直接拒绝'],
    'concurrency_mode'   => ['并发控制模式', 'select',
        '按 SK 控制：每个 SK 独立计数；按用户控制：每个用户独立计数'],
    'concurrency_rpm'    => ['每分钟请求上限', 'int',
        '每分钟允许的请求数（0=不限制）。与并发控制模式一致，按 SK 或按用户计数'],
    'sk_http_auth_codes' => ['SK 认证类故障码', 'text',
        '逗号分隔。命中这些码判定为 key 失效/欠费/无权限，短期内不会自己恢复，'
        . '1 次即熔断（不受上面失败阈值限制），且自动解绑对话换用其他 SK。默认 401,402,403'],
    'sk_http_busy_codes' => ['SK 过载类故障码', 'text',
        '逗号分隔。命中这些码判定为上游过载/网关故障，恢复较快，用上面「过载类基础冷却」的时长，'
        . '按失败阈值次数才熔断。默认 500,501,502,503,504,505,506,507,508,509,510,511,520,522,524'],
    'prompt_debug'       => ['记录提示词体积明细', 'bool',
        '开启后每轮把系统提示词各模块的字节和 token 写入 data/prompt_size.log，'
        . '用于排查输入 token 偏高。查完记得关掉'],
    'history_img_keep'   => ['历史图片回发条数', 'int',
        '只有最近这么多条带图消息会真的把图片发给上游，更早的换成一行文字说明。'
        . '历史图片每轮都要重传，条数越大请求体越容易超限。默认 2，填 0 表示历史图片一律不回发'],
    'ui_page_size'       => ['界面首屏消息条数', 'int',
        '打开会话时先渲染这么多条，往上滚动继续加载更早的。默认 60。'
        . '这一项只影响界面流畅度，不影响发给模型的上下文，调它不会省 token'],
    'prompt_zip_ondemand' => ['打包说明按需注入', 'bool',
        '开启后只在用户提到打包、压缩包时才注入工作中心的打包那一节，平时省约 700 字节'],
    'prompt_ppt_ondemand' => ['PPT 说明按需注入', 'bool',
        '开启后只在用户提到 PPT、幻灯片、汇报材料时才注入这段说明，'
        . '平时省下约 1100 token。关掉则每轮都带上'],
    'history_dedup_read' => ['历史重复内容去重', 'bool',
        '同一份文件清单或同一个文件在会话里被反复读取时，较早那几份换成一行指引，'
        . '只保留最近一份原文。实测长会话能省下七成读取类回执体积。最近几块不受影响'],
    'history_char_budget' => ['历史字符预算', 'int',
        '整个历史窗口允许占用的字符数，这是实际生效的那道闸，直接决定 AI 能回看多少轮。'
        . '默认 120000（约 5 到 7 万 token），距 Claude 200k 窗口留 35k 余量，再往上加有撞窗口风险'],
    'history_text_cap'   => ['老消息截断上限（字）', 'int',
        '较早的 AI 回复压到这个长度，最近几块不受影响。默认 3000。'
        . '这项和下面两项共同决定预算能覆盖多少轮：压得越狠、回看越远，但细节丢得越多'],
    'history_tool_cap'   => ['老工具回执截断上限（字）', 'int',
        '较早的命令输出、文件内容压到这个长度，留个头尾够 AI 知道当时干了什么。默认 2000。'
        . '回执通常是历史的主体（实测能占到消息体积的 4 倍），这项对预算影响最大'],
    'history_full_blocks' => ['保留原文的最近块数', 'int',
        '最近这几块的工具回执保留完整原文，AI 正靠它接着往下做，截了会直接干不动活。默认 3'],
    'history_block_step' => ['历史起点量化步长', 'int',
        '起点每这么多块才移动一次，让缓存前缀在中间几轮保持完全一致。默认 4。'
        . '调成 1 等于关掉量化，缓存会逐轮失配、费用可能翻好几倍，不建议动'],
    'reg_notify'         => ['新用户注册邮件通知', 'bool', '有新用户注册时给管理员发送邮件通知'],
    'reg_notify_email'   => ['通知接收邮箱', 'text'],

    // 搜索已改用必应结果页抓取，无需 API Key，实现见 inc/web_fetch.php 的 web_search()。
    // ---- Token 估算：上游不返回用量时，本地折算所用的四档系数 ----
    'est_cjk_per_token'   => ['中文每 token 字数', 'number',
        '中文与全角标点按多少个字折算 1 token，当前 1.1。填 0 会自动兜到 0.1，不会除零'],
    'est_prose_per_token' => ['英文散文每 token 字符数', 'number',
        '普通英文（含空格、数字）按多少个字符折算 1 token，当前 3.2'],
    'est_symbol_chars'    => ['结构符号集合', 'text',
        'JSON 与代码里的这些符号一个字符算 1 token，默认 {}[]":, 。换行回车固定计入，制表符改由下面单独一项控制'],
    'est_rare_enable'     => ['长生僻英文词单独一档', 'bool',
        '开启后长生僻词不走散文档，单独折算；关掉则和普通英文一样按字符算'],
    'est_rare_min_len'    => ['长词判定字母数下限', 'int',
        '连续字母达到该长度才可能判为长生僻词，默认 12。常见长词表内的词不算'],
    'est_rare_per_token'  => ['长生僻词每 token 字符数', 'number',
        '填 0 表示整词算 1 token（当前口径）；填 2.5 则按每 2.5 字符 1 token 拆，更贴近真实分词器'],
    'est_tail_bonus'      => ['末尾保底 +1', 'bool',
        '每段文本的估算结果额外加 1，抵消消息封装开销。关掉后极短文本的估值更贴近实际'],
    // 以下六档管的都是旧四档没覆盖到的字符，键名与 inc/helpers.php 里的读取一一对应，
    // 改名的话两边要一起改，否则这里填的值读不到、页面看着生效实际没用。
    'est_cjk_rare_per_token' => ['生僻字每 token 字数', 'number',
        '扩展 A 区及更靠后的生僻汉字按多少个字折算 1 token，默认 0.6，比常用字贵。填 0 兜到 0.1'],
    'est_tab_tokens'      => ['制表符每个算几 token', 'number',
        '一个制表符算多少 token，默认 1，与改动前的口径逐字一致。填 0 表示不计价'],
    'est_zw_tokens'       => ['零宽字符每个算几 token', 'number',
        '零宽空格、零宽连接符等看不见的字符，默认 1。肉眼看不到，但上游照样收费'],
    'est_emoji_tokens'    => ['emoji 每个算几 token', 'number',
        '默认 1.5。改动前 emoji 掉进英文散文档只算 0.31 个，明显偏低'],
    'est_emoji_mod_tokens' => ['emoji 修饰符每个算几 token', 'number',
        '跟在 emoji 后面的肤色修饰符、变体选择符等附加码位，默认 1'],
    'est_entropy_enable'  => ['高熵长串单独一档', 'bool',
        '开启后 API key、哈希、base64 这类无规律长串单独折算；关掉则按英文散文算，会明显低估'],
    'est_entropy_min_len' => ['高熵串判定长度下限', 'int',
        '连续的字母数字达到该长度才判为高熵串，默认 20。填小于 8 会自动兜到 8'],
    'est_entropy_per_token' => ['高熵串每 token 字符数', 'number',
        '按多少个字符折算 1 token，默认 2.2。带连字符的模型名会被切开，不会命中本档'],
];

// 客户端下载地址。类型标 dl 是为了在下面的基础设置网格里跳过它们，
// 单独放到「客户端下载」那张卡片里展示，那边有格式说明。
foreach (download_platforms() as $dlKey => [$dlName]) {
    $keys[$dlKey] = [$dlName . ' 下载地址', 'dl'];
}

// 页脚信息。类型标 ft / ftarea 同样是为了在基础设置网格里跳过，
// 单独放到「页脚与合规信息」卡片，那边按「联系方式」和「条款正文」分组更好填。
$footKeys = [
    'contact_qq'     => ['客服 QQ', 'ft'],
    'contact_wechat' => ['客服微信', 'ft'],
    'contact_email'  => ['客服邮箱', 'ft'],
    'contact_phone'  => ['联系电话', 'ft'],
    'icp_no'         => ['ICP 备案号', 'ft'],
    'icp_url'        => ['备案查询链接', 'ft'],
    'terms_privacy'  => ['隐私条款正文', 'ftarea'],
    'terms_service'  => ['服务条款正文', 'ftarea'],
];
$keys += $footKeys;

// 设置项按标签分组，供前端切换展示
$tabs = [
    'basic' => [
        'name' => '基础设置',
        'keys' => [
            'home_template', 'site_name', 'site_notice', 'allow_register', 'register_balance',
            'register_quota', 'min_balance', 'register_ip_interval',
            'upload_max_image_size', 'login_fail_max', 'login_ban_msg', 'reg_notify', 'reg_notify_email',
        ],
    ],
    'cache' => [
        'name' => '缓存与性能',
        'keys' => [
            'ui_page_size', 'history_dedup_read',
            'prompt_ppt_ondemand', 'prompt_zip_ondemand',
            'history_char_budget', 'history_text_cap', 'history_tool_cap',
            'history_full_blocks', 'history_block_step',
            'history_img_keep', 'prompt_debug',
            'prompt_cache', 'prompt_cache_local', 'upstream_retry_times',
            'upstream_retry_interval', 'sk_fail_threshold', 'sk_cool_busy',
            'sk_cool_rate', 'sk_cool_auth', 'sk_cool_factor_max', 'sk_cool_max',
            'concurrency_limit', 'concurrency_mode', 'concurrency_rpm',
            'sk_http_auth_codes', 'sk_http_busy_codes',
        ],
    ],
    'client' => [
        'name' => '客户端与页脚',
        'keys' => [
            'contact_qq', 'contact_wechat', 'contact_email', 'contact_phone',
            'icp_no', 'icp_url', 'terms_privacy', 'terms_service',
        ],
    ],
    'est' => [
        'name' => 'Token 估算',
        // 顺序即页面上的显示顺序，按「中文 / 英文与代码 / 高熵串 / 符号与空白 / 表情」分组排。
        // 这里漏掉哪个键，保存时该键就会被当成页面上没有的项写成 0，务必和上面的定义表成对。
        'keys' => [
            'est_cjk_per_token', 'est_cjk_rare_per_token',
            'est_prose_per_token',
            'est_rare_enable', 'est_rare_min_len', 'est_rare_per_token',
            'est_entropy_enable', 'est_entropy_min_len', 'est_entropy_per_token',
            'est_symbol_chars', 'est_tab_tokens', 'est_zw_tokens',
            'est_emoji_tokens', 'est_emoji_mod_tokens',
            'est_tail_bonus',
        ],
    ],
];

// 把下载地址键加到客户端组，这些键来自 download_platforms()，类型为 dl
foreach (download_platforms() as $dlKey => [$dlName]) {
    $tabs['client']['keys'][] = $dlKey;
}

// 辅助函数：判断某个 key 属于哪个 tab
$tabOf = function (string $k) use ($tabs): string {
    foreach ($tabs as $tid => $tab) {
        if (in_array($k, $tab['keys'], true)) {
            return $tid;
        }
    }
    return '';
};

// 为每个 tab 预创建字段列表
$tabKeys = [];
foreach ($tabs as $tid => $tab) {
    $tabKeys[$tid] = $tab['keys'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    if (($_POST['act'] ?? '') === 'save') {
        foreach ($keys as $k => $conf) {
            $type = $conf[1];
            if ($type === 'bool') {
                setting_set($k, isset($_POST[$k]) ? '1' : '0');
            } elseif ($type === 'number') {
                setting_set($k, number_format(max(0, (float) ($_POST[$k] ?? 0)), 4, '.', ''));
            } elseif ($type === 'int') {
                setting_set($k, (string) max(0, (int) ($_POST[$k] ?? 0)));
            } else {
                setting_set($k, trim((string) ($_POST[$k] ?? '')));
            }
        }
        $msg = '设置已保存';
    } elseif (($_POST['act'] ?? '') === 'passwd') {
        $old = (string) ($_POST['old'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify($old, $me['password_hash'])) {
            $err = '当前密码不正确';
        } elseif (mb_strlen($new) < 6) {
            $err = '新密码至少 6 位';
        } else {
            db_exec('UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            $msg = '密码已修改';
        }
    }

    flash_set($err !== '' ? 'error' : 'ok', $err !== '' ? $err : $msg);
    redirect_self();
}

[$flash类型, $flash文本] = flash_get();
if ($flash类型 === 'error') {
    $err = $flash文本;
} elseif ($flash类型 === 'ok') {
    $msg = $flash文本;
}

require __DIR__ . '/_head.php';

$cur = settings_all();
$phpVer = PHP_VERSION;

// 并发控制模式选项
$concurrencyModes = [
    'sk'   => '按 SK 控制',
    'user' => '按用户控制',
];

// 扫描首页模板目录，生成「模板ID => 显示名」映射，供基础设置里下拉选择与预览按钮使用。
// 模板文件首行可写 @name: 模板显示名 来指定名称，没有则回退用文件名。
$homeTpls = [];
foreach (glob(__DIR__ . '/../templates/home/*.php') ?: [] as $tplFile) {
    $tid = basename($tplFile, '.php');
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $tid)) {
        continue;
    }
    $name = $tid;
    $head = (string) @file_get_contents($tplFile, false, null, 0, 512);
    if (preg_match('/@name:\s*([^*]+)/u', $head, $m)) {
        $name = trim($m[1]);
    }
    $homeTpls[$tid] = $name;
}
if (empty($homeTpls)) {
    $homeTpls['home_tpl1'] = '默认模板';
}
if (!isset($homeTpls[$cur['home_template'] ?? 'home_tpl1'])) {
    $cur['home_template'] = 'home_tpl1';
}
$curlOk = function_exists('curl_init');
$dbVer = db_val('SELECT VERSION()');
?>
<div class="page-head"><h1 class="page-title">站点设置</h1></div>

<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<!-- 站点设置 - 分标签切换 -->
<div class="card mb-16">
  <div class="card-head" style="padding-bottom:0">
    <div class="tab-nav" id="tabNav" style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:0">
      <?php foreach ($tabs as $tid => $tab): ?>
        <button type="button" class="tab-btn" data-tab="<?= h($tid) ?>"
                style="padding:10px 20px;border:none;background:none;cursor:pointer;
                       border-bottom:2px solid transparent;margin-bottom:-2px;
                       font-size:14px;color:#6b7280;
                       <?= $tid === 'basic' ? 'border-bottom-color:#6366f1;color:#6366f1;font-weight:600;' : '' ?>">
          <?= h($tab['name']) ?>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="save">

      <?php foreach ($tabs as $tid => $tab): ?>
      <div class="tab-panel" data-panel="<?= h($tid) ?>" style="<?= $tid === 'basic' ? '' : 'display:none;' ?>">
        <?php if ($tid === 'basic'): ?>
          <div class="form-grid">
            <?php foreach ($tab['keys'] as $k): ?>
              <?php if (!isset($keys[$k])) continue; $conf = $keys[$k]; $label = $conf[0]; $type = $conf[1]; $note = $conf[2] ?? ''; ?>
              <?php if ($type === 'textarea'): continue; endif; // site_notice 在下面单独渲染 ?>
              <label class="field"><span class="field-label"><?= h($label) ?></span>
                <?php if ($type === 'bool'): ?>
                  <span><input type="checkbox" name="<?= h($k) ?>" value="1"
                    <?= ($cur[$k] ?? '0') === '1' ? 'checked' : '' ?>> <?= h($note !== '' ? $note : '启用') ?></span>
                <?php elseif ($type === 'number'): ?>
                  <input class="input" type="number" step="0.0001" min="0" name="<?= h($k) ?>"
                         value="<?= h($cur[$k] ?? '0') ?>">
                <?php elseif ($type === 'select'): $selVal = (string) ($cur[$k] ?? 'home_tpl1'); if (!isset($homeTpls[$selVal])) { $selVal = 'home_tpl1'; } ?>
                  <?php if ($k === 'home_template'): ?>
                  <!-- 首页模板：自定义下拉，避免手机原生弹窗 -->
                  <div class="tpl-select" id="tplSelectWrap">
                    <button type="button" class="input tpl-select-btn" id="tplSelectBtn">
                      <span id="tplSelectLabel"><?= h($homeTpls[$selVal]) ?></span>
                      <span class="tpl-caret">▾</span>
                    </button>
                    <div class="tpl-select-menu" id="tplSelectMenu" hidden>
                      <?php foreach ($homeTpls as $tid => $tname): ?>
                        <button type="button" class="tpl-opt<?= $tid === $selVal ? ' on' : '' ?>" data-val="<?= h($tid) ?>"><?= h($tname) ?></button>
                      <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="home_template" value="<?= h($selVal) ?>" id="tplInput">
                  </div>
                  <span class="hint">
                    <a href="/?preview_tpl=<?= h($selVal) ?>" target="_blank" rel="noopener" id="tplPreviewLink">在新窗口预览当前模板</a>
                  </span>
                  <style>
                    .tpl-select{position:relative;max-width:420px}
                    .tpl-select-btn{display:flex;align-items:center;justify-content:space-between;width:100%;text-align:left;cursor:pointer}
                    .tpl-caret{display:inline-block;margin-left:8px;color:#9ca3af;transition:transform .15s}
                    .tpl-select.open .tpl-caret{transform:rotate(180deg)}
                    .tpl-select-menu{position:absolute;z-index:100;top:calc(100% + 4px);left:0;right:0;max-height:280px;overflow:auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:4px}
                    .tpl-opt{display:block;width:100%;padding:10px 12px;border:none;background:none;text-align:left;border-radius:6px;cursor:pointer;font-size:14px;color:#1f2937}
                    .tpl-opt:hover{background:#f3f4f6}
                    .tpl-opt.on{background:#eef2ff;color:#6366f1;font-weight:600}
                    @media(prefers-color-scheme:dark){.tpl-select-menu{background:#1f2937;border-color:#374151}.tpl-opt{color:#e5e7eb}.tpl-opt:hover{background:#374151}.tpl-opt.on{background:#312e81;color:#c7d2fe}}
                  </style>
                  <script>
                  (function(){
                    var wrap=document.getElementById('tplSelectWrap');
                    if(!wrap) return;
                    var btn=document.getElementById('tplSelectBtn');
                    var menu=document.getElementById('tplSelectMenu');
                    var input=document.getElementById('tplInput');
                    var label=document.getElementById('tplSelectLabel');
                    var link=document.getElementById('tplPreviewLink');
                    function close(){wrap.classList.remove('open');menu.hidden=true;}
                    btn.addEventListener('click',function(e){e.stopPropagation();var isOpen=!menu.hidden;close();if(!isOpen){wrap.classList.add('open');menu.hidden=false;}});
                    menu.addEventListener('click',function(e){
                      var opt=e.target.closest('.tpl-opt'); if(!opt) return;
                      var v=opt.getAttribute('data-val');
                      input.value=v; label.textContent=opt.textContent;
                      link.setAttribute('href','/?preview_tpl='+encodeURIComponent(v));
                      close();
                    });
                    document.addEventListener('click',function(e){ if(!wrap.contains(e.target)) close(); });
                    document.addEventListener('keydown',function(e){ if(e.key==='Escape') close(); });
                  })();
                  </script>
                  <?php elseif ($k === 'concurrency_mode'): ?>
                  <select class="input" name="<?= h($k) ?>">
                    <?php foreach ($concurrencyModes as $val => $name): ?>
                      <option value="<?= h($val) ?>" <?= ($cur[$k] ?? 'sk') === $val ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php else: ?>
                  <select class="input" name="<?= h($k) ?>">
                    <?php foreach ($homeTpls as $tid => $tname): ?>
                      <option value="<?= h($tid) ?>" <?= ($cur[$k] ?? 'home_tpl1') === $tid ? 'selected' : '' ?>><?= h($tname) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php endif; ?>
                <?php elseif ($type === 'int'): ?>
                  <input class="input" type="number" min="0" name="<?= h($k) ?>" value="<?= h($cur[$k] ?? '0') ?>">
                <?php else: ?>
                  <input class="input" name="<?= h($k) ?>" value="<?= h($cur[$k] ?? '') ?>">
                <?php endif; ?>
                <?php if ($note !== '' && $type !== 'bool'): ?>
                  <span class="hint"><?= h($note) ?></span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
          <label class="field mt-16"><span class="field-label">站点公告</span>
            <textarea class="textarea" name="site_notice" rows="3"><?= h($cur['site_notice'] ?? '') ?></textarea></label>

        <?php elseif ($tid === 'cache'): ?>
          <div class="form-grid">
            <?php foreach ($tab['keys'] as $k): ?>
              <?php if (!isset($keys[$k])) continue; $conf = $keys[$k]; $label = $conf[0]; $type = $conf[1]; $note = $conf[2] ?? ''; ?>
              <label class="field"><span class="field-label"><?= h($label) ?></span>
                <?php if ($type === 'bool'): ?>
                  <span><input type="checkbox" name="<?= h($k) ?>" value="1"
                    <?= ($cur[$k] ?? '0') === '1' ? 'checked' : '' ?>> <?= h($note !== '' ? $note : '启用') ?></span>
                <?php elseif ($k === 'concurrency_mode'): ?>
                  <select class="input" name="<?= h($k) ?>">
                    <?php foreach ($concurrencyModes as $val => $name): ?>
                      <option value="<?= h($val) ?>" <?= ($cur[$k] ?? 'sk') === $val ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($type === 'int'): ?>
                  <input class="input" type="number" min="0" name="<?= h($k) ?>" value="<?= h($cur[$k] ?? '0') ?>">
                <?php else: ?>
                  <input class="input" name="<?= h($k) ?>" value="<?= h($cur[$k] ?? '') ?>">
                <?php endif; ?>
                <?php if ($note !== '' && $type !== 'bool'): ?>
                  <span class="hint"><?= h($note) ?></span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>

        <?php elseif ($tid === 'client'): ?>
          <div style="border-top:1px solid #eef0f3;padding-top:14px;margin-bottom:16px">
            <div class="field-label" style="margin-bottom:6px">客户端下载地址</div>
            <p class="muted mb-8">
              填完整网址（<code>https://…</code>）或站内路径（<code>/downloads/app.zip</code>）皆可。
              留空的平台不会出现在首页；<b>四个全部留空时，首页整个「下载客户端」按钮都不显示</b>。
            </p>
            <div class="form-grid">
              <?php foreach (download_platforms() as $dk => [$name, $icon]): ?>
                <label class="field">
                  <span class="field-label"><?= h($icon . ' ' . $name) ?></span>
                  <input class="input" name="<?= h($dk) ?>" value="<?= h($cur[$dk] ?? '') ?>"
                         placeholder="留空则不显示">
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="border-top:1px solid #eef0f3;padding-top:14px;margin-bottom:16px">
            <div class="field-label" style="margin-bottom:6px">页脚与合规信息</div>
            <p class="muted mb-8">
              这些内容显示在首页底部。<b>留空的项不会显示</b>，全部留空则页脚只剩版权行。
              条款正文填了才会在页脚出现对应链接，点开跳 <code>/legal.php</code>。
            </p>
            <div class="form-grid">
              <?php foreach ($footKeys as $k => $conf): ?>
                <?php if ($conf[1] !== 'ft') continue; ?>
                <label class="field">
                  <span class="field-label"><?= h($conf[0]) ?></span>
                  <input class="input" name="<?= h($k) ?>" value="<?= h($cur[$k] ?? '') ?>"
                         placeholder="留空则不显示">
                </label>
              <?php endforeach; ?>
            </div>
            <?php foreach ($footKeys as $k => $conf): ?>
              <?php if ($conf[1] !== 'ftarea') continue; ?>
              <label class="field mt-16"><span class="field-label"><?= h($conf[0]) ?></span>
                <textarea class="textarea" name="<?= h($k) ?>" rows="6"
                          placeholder="纯文本，按空行分段"><?= h($cur[$k] ?? '') ?></textarea></label>
            <?php endforeach; ?>
          </div>
        <?php elseif ($tid === 'caps'): ?>
          <div class="form-grid">
            <?php foreach ($tab['keys'] as $k): ?>
              <?php if (!isset($keys[$k])) continue; $conf = $keys[$k]; $label = $conf[0]; $note = $conf[2] ?? ''; ?>
              <label class="field"><span class="field-label"><?= h($label) ?></span>
                <span><input type="checkbox" name="<?= h($k) ?>" value="1"
                  <?= ($cur[$k] ?? '1') === '1' ? 'checked' : '' ?>> <?= h($note !== '' ? $note : '启用') ?></span>
              </label>
            <?php endforeach; ?>
          </div>

        <?php elseif ($tid === 'est'): ?>
          <?php
          // 库里还没这几行时输入框显示代码里的默认值，避免显示成空、
          // 管理员一保存把它们写成空串
          // 这几个值必须和 inc/helpers.php 里 estimate_token_cfg() 的 $默认 一致：
          // 库里某个键为空时，页面显示的就是这里的值，管理员点一次保存它就被真写进库。
          // 原先这里是 1.2 / 3.5，比库里实际在跑的 1.1 / 3.2 贵，保存一次就静默涨价。
          $estDef = [
              'est_cjk_per_token'      => '1.1',
              'est_prose_per_token'    => '3.2',
              'est_symbol_chars'       => '{}[]":,',
              'est_rare_min_len'       => '12',
              'est_rare_per_token'     => '0',
              'est_cjk_rare_per_token' => '0.6',
              'est_tab_tokens'         => '1',
              'est_zw_tokens'          => '1',
              'est_emoji_tokens'       => '1.5',
              'est_emoji_mod_tokens'   => '1',
              'est_entropy_min_len'    => '20',
              'est_entropy_per_token'  => '2.2',
          ];

          // 分组只管显示顺序和折叠，不影响保存。
          // $tabs['est']['keys'] 里有、这里漏列的键会自动收进末尾的「其它」组：
          // 键一旦没渲染出来，保存时就会被当成空值写成 0，所以宁可多一个兜底分组。
          $estGroups = [
              '中文'                   => ['est_cjk_per_token', 'est_cjk_rare_per_token'],
              '英文与代码'             => ['est_prose_per_token', 'est_rare_enable',
                                           'est_rare_min_len', 'est_rare_per_token'],
              '高熵长串（key / 哈希）' => ['est_entropy_enable', 'est_entropy_min_len',
                                           'est_entropy_per_token'],
              '符号与空白'             => ['est_symbol_chars', 'est_tab_tokens', 'est_zw_tokens'],
              '表情与整体'             => ['est_emoji_tokens', 'est_emoji_mod_tokens',
                                           'est_tail_bonus'],
          ];
          $estListed = [];
          foreach ($estGroups as $gKeys) {
              foreach ($gKeys as $gk) { $estListed[$gk] = true; }
          }
          $estRest = [];
          foreach ($tab['keys'] as $rk) {
              if (isset($keys[$rk]) && !isset($estListed[$rk])) { $estRest[] = $rk; }
          }
          if ($estRest) { $estGroups['其它'] = $estRest; }
          ?>
          <p class="muted mb-8">
            这些系数只在<b>上游不返回用量</b>时生效（例如 cursor 转出的通道，其 usage 全为 0），
            这类请求在用量日志里标记为「估算」。上游给了真实 token 的渠道不走这套折算。
          </p>
          <?php $estFirst = true; foreach ($estGroups as $gName => $gKeys): ?>
            <details <?= $estFirst ? 'open' : '' ?>
                     style="border:1px solid #eef0f3;border-radius:8px;padding:10px 12px;margin-bottom:10px">
              <summary style="cursor:pointer;font-weight:600"><?= h($gName) ?></summary>
              <div class="form-grid" style="margin-top:10px">
                <?php foreach ($gKeys as $k): ?>
                  <?php if (!isset($keys[$k])) continue; $conf = $keys[$k]; $label = $conf[0]; $type = $conf[1]; $note = $conf[2] ?? ''; ?>
                  <label class="field"><span class="field-label"><?= h($label) ?></span>
                    <?php if ($type === 'bool'): ?>
                      <span><input type="checkbox" name="<?= h($k) ?>" value="1"
                        <?= ($cur[$k] ?? '1') === '1' ? 'checked' : '' ?>> <?= h($note !== '' ? $note : '启用') ?></span>
                    <?php elseif ($type === 'number'): ?>
                      <input class="input" type="number" step="0.1" min="0" name="<?= h($k) ?>"
                             value="<?= h($cur[$k] ?? ($estDef[$k] ?? '0')) ?>">
                    <?php elseif ($type === 'int'): ?>
                      <input class="input" type="number" min="0" name="<?= h($k) ?>"
                             value="<?= h($cur[$k] ?? ($estDef[$k] ?? '0')) ?>">
                    <?php else: ?>
                      <input class="input" name="<?= h($k) ?>"
                             value="<?= h($cur[$k] ?? ($estDef[$k] ?? '')) ?>">
                    <?php endif; ?>
                    <?php if ($note !== '' && $type !== 'bool'): ?>
                      <span class="hint"><?= h($note) ?></span>
                    <?php endif; ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </details>
          <?php $estFirst = false; endforeach; ?>
          <div style="border-top:1px solid #eef0f3;padding-top:14px;margin-top:16px">
            <div class="field-label" style="margin-bottom:6px">实时试算</div>
            <p class="muted mb-8">
              粘一段文本，按<b>上面当前填的系数</b>算一次，不用先保存。
              计算走后端同一个 <code>estimate_tokens()</code>，和实际计费口径完全一致。
            </p>
            <textarea class="textarea" id="estText" rows="5"
                      placeholder="粘贴一段中文、英文散文或 JSON / 代码，看看按当前系数值多少 token"></textarea>
            <div class="mt-8">
              <button type="button" class="btn" id="estBtn">试算</button>
              <span id="estOut" class="muted" style="margin-left:10px"></span>
            </div>
          </div>
          <script>
          (function() {
            var btn = document.getElementById('estBtn');
            if (!btn) return;
            btn.onclick = function() {
              var out = document.getElementById('estOut');
              var fd = new FormData();
              fd.append('use_form', '1');
              fd.append('text', document.getElementById('estText').value);
              // 这两个数组要和 est_test_ajax.php 里的键名一一对应，
              // 漏一个不会报错，只是试算时那一档偷偷用库里的旧值，让人以为"改了没反应"
              ['est_cjk_per_token', 'est_cjk_rare_per_token', 'est_prose_per_token',
               'est_rare_min_len', 'est_rare_per_token',
               'est_entropy_min_len', 'est_entropy_per_token',
               'est_symbol_chars', 'est_tab_tokens', 'est_zw_tokens',
               'est_emoji_tokens', 'est_emoji_mod_tokens'].forEach(function(n) {
                var el = document.querySelector('[name="' + n + '"]');
                if (el) fd.append(n, el.value);
              });
              ['est_rare_enable', 'est_tail_bonus', 'est_entropy_enable'].forEach(function(n) {
                var el = document.querySelector('[name="' + n + '"]');
                fd.append(n, (el && el.checked) ? '1' : '0');
              });
              out.textContent = '计算中…';
              fetch('est_test_ajax.php', {method: 'POST', body: fd, credentials: 'same-origin'})
                .then(function(r) { return r.json(); })
                .then(function(j) {
                  out.textContent = j.error ? ('出错：' + j.error)
                    : (j.chars + ' 字符 → ' + j.tokens + ' token');
                })
                .catch(function(e) { out.textContent = '请求失败：' + e; });
            };
          })();
          </script>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <div class="form-actions"><button class="btn btn-primary" type="submit">保存设置</button></div>
    </form>
  </div>
</div>

<script>
(function() {
  var btns = document.querySelectorAll('#tabNav .tab-btn');
  btns.forEach(function(b) {
    b.onclick = function() {
      var tid = this.getAttribute('data-tab');
      btns.forEach(function(x) {
        x.style.borderBottomColor = 'transparent';
        x.style.color = '#6b7280';
        x.style.fontWeight = 'normal';
      });
      this.style.borderBottomColor = '#6366f1';
      this.style.color = '#6366f1';
      this.style.fontWeight = '600';
      document.querySelectorAll('.tab-panel').forEach(function(p) {
        p.style.display = (p.getAttribute('data-panel') === tid) ? '' : 'none';
      });
    };
  });
})();
</script>
<script>
// 新用户注册通知：勾选"邮件通知"时显示邮箱输入框，取消勾选时隐藏
(function() {
    var cb = document.querySelector('input[name="reg_notify"]');
    var email = document.querySelector('input[name="reg_notify_email"]');
    if (!cb || !email) return;
    function toggle() {
        var field = email.closest('.field');
        if (field) field.style.display = cb.checked ? '' : 'none';
    }
    toggle();
    cb.addEventListener('change', toggle);
})();
</script>

<div class="form-grid" style="align-items:start">
  <div class="card">
    <div class="card-head">修改我的密码</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="passwd">
        <label class="field"><span class="field-label">当前密码</span>
          <input class="input" type="password" name="old" required autocomplete="current-password"></label>
        <label class="field mt-16"><span class="field-label">新密码</span>
          <input class="input" type="password" name="new" required minlength="6" autocomplete="new-password"></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">修改密码</button></div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head">运行环境</div>
    <div class="card-body">
      <table class="tbl">
        <tbody>
          <tr><td>PHP 版本</td><td><?= h($phpVer) ?></td></tr>
          <tr><td>MySQL 版本</td><td><?= h((string) $dbVer) ?></td></tr>
          <tr><td>cURL 扩展</td><td><?= $curlOk
            ? '<span class="badge badge-ok">已启用</span>'
            : '<span class="badge badge-err">缺失，无法调用上游</span>' ?></td></tr>
          <tr><td>数据表前缀</td><td><code><?= h(DB_PREFIX) ?></code></td></tr>
        </tbody>
      </table>
      <div class="hint mt-16">安装完成后建议删除 install.php，避免被重复执行。</div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_foot.php'; ?>