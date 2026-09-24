<?php
/**
 * 后台「站点设置 → Token 估算」的实时试算接口（纯 JSON，不输出 HTML）
 *
 * settings.php 的「试算」按钮 fetch 本文件，期望拿到 {chars, tokens} 或 {error}。
 * 只做内存计算、不写任何库表，所以沿用同目录 ajax 的鉴权方式：
 * 登录校验 + 管理员判定，不另外引一套 CSRF token。
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';
start_session();
$me = require_login_api();
if (($me['role'] ?? '') !== 'admin') {
    json_out(['error' => '无权限']);
}
header('Content-Type: application/json; charset=utf-8');
session_write_close();   // 后面全是内存计算，提前释放 session 锁
$text = (string) ($_POST['text'] ?? '');
if ($text === '') {
    json_out(['chars' => 0, 'tokens' => 0, 'note' => '']);
}
if (!mb_check_encoding($text, 'UTF-8')) {
    json_out(['error' => '文本不是合法的 UTF-8']);
}
// 防止整段日志粘进来把 PHP 卡住：超长先截断，结果里说明只算了前一段
$上限 = 200000;
$截断 = mb_strlen($text, 'UTF-8') > $上限;
if ($截断) {
    $text = mb_substr($text, 0, $上限, 'UTF-8');
}
/*
 * 表单键名映射成 estimate_tokens() 的内部键。
 * 下限兜底必须在这里重做一遍：$cfg覆盖 走的是另一条路，
 * 完全绕过 estimate_token_cfg() 里那组 max()，管理员填 0 就是除零白屏。
 * 缺省值取库里的现值，不再硬编码第三套口径。
 */
$cfg = null;
if (((string) ($_POST['use_form'] ?? '')) === '1') {
    $库 = estimate_token_cfg();
    $数 = function (string $k, $d, float $下限) {
        $v = $_POST[$k] ?? null;
        return ($v === null || $v === '') ? (float) $d : max($下限, (float) $v);
    };
    $整 = function (string $k, $d, int $下限) {
        $v = $_POST[$k] ?? null;
        return ($v === null || $v === '') ? (int) $d : max($下限, (int) $v);
    };
    // checkbox 没勾选时前端可能整个字段都不传，这种情况按库里的现值算，
    // 不能默认成开启，否则关掉开关试算出来的还是开启的结果
    $开 = function (string $k, $d) {
        return array_key_exists($k, $_POST) ? ((string) $_POST[$k]) === '1' : (bool) $d;
    };
    $cfg = [
        'cjk'        => $数('est_cjk_per_token', $库['cjk'], 0.1),
        'prose'      => $数('est_prose_per_token', $库['prose'], 0.1),
        // 符号集清空是合法操作（等于整档不计价），所以提交了空串就按空串算，
        // 只有字段完全没提交时才回落库里的值
        'symbols'    => array_key_exists('est_symbol_chars', $_POST)
            ? (string) $_POST['est_symbol_chars']
            : (string) $库['symbols'],
        'rare_on'    => $开('est_rare_enable', $库['rare_on']),
        'rare_min'   => $整('est_rare_min_len', $库['rare_min'], 4),
        'rare_per'   => $数('est_rare_per_token', $库['rare_per'], 0.0),
        'tail_bonus' => $开('est_tail_bonus', $库['tail_bonus']),
        'cjk_rare'   => $数('est_cjk_rare_per_token', $库['cjk_rare'], 0.1),
        'tab'        => $数('est_tab_tokens', $库['tab'], 0.0),
        'zw'         => $数('est_zw_tokens', $库['zw'], 0.0),
        'emoji'      => $数('est_emoji_tokens', $库['emoji'], 0.0),
        'emoji_mod'  => $数('est_emoji_mod_tokens', $库['emoji_mod'], 0.0),
        'ent_on'     => $开('est_entropy_enable', $库['ent_on']),
        'ent_min'    => $整('est_entropy_min_len', $库['ent_min'], 8),
        'ent_per'    => $数('est_entropy_per_token', $库['ent_per'], 0.1),
    ];
}
try {
    $tokens = estimate_tokens($text, $cfg);
} catch (Throwable $e) {
    json_out(['error' => '计算失败：' . $e->getMessage()]);
}
json_out([
    'chars'  => mb_strlen($text, 'UTF-8'),
    'tokens' => $tokens,
    'note'   => $截断 ? '文本超过 20 万字符，只计算了前 20 万' : '',
]);
