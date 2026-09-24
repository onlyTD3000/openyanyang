<?php
/**
 * 客户端版本相关的公共函数。
 *
 * 后台「软件控制」页和 /api/version.php 都要用，所以抽出来放这儿，
 * 免得两边各写一份比较逻辑、哪天改了一处忘了另一处。
 */
/**
 * 支持的下载平台。键就是 settings 表里的键名。
 *
 * 目前只做 Windows 的 exe，其余先留位：客户端是 electron-builder 打的，
 * 加平台只是改 build.target，服务端这边不用动代码。
 */
function client_platforms(): array
{
    return [
        'client_dl_win'   => 'Windows（exe）',
        'client_dl_mac'   => 'macOS（dmg）',
        'client_dl_linux' => 'Linux（AppImage）',
    ];
}
/**
 * 安卓端的下载平台。单独一张表，不和电脑端混在一起。
 *
 * 为什么分开：两端的版本号各自独立演进，安卓发新版不该让电脑端也提示更新。
 * 键名前缀用 android_，settings 表里和电脑端那套 client_ 完全隔离。
 */
function android_platforms(): array
{
    return [
        'android_dl_apk' => 'Android（apk）',
    ];
}
/**
 * 按端别取设置键前缀。
 *
 * @param string $端 pc 或 android
 * @return string 键名前缀，pc 得到 client_，安卓得到 android_
 */
function ver_prefix(string $端): string
{
    return $端 === 'android' ? 'android_' : 'client_';
}
/**
 * 把版本号规整成「点分数字」。
 *
 * 允许用户填 v1.2.3、1.2.3-beta 这类写法，统一剥成 1.2.3。
 * 非法输入返回空串，调用方据此报错，不要拿空串当 0.0.0 用——
 * 那会把「填错了」和「真的是 0 版」混为一谈。
 *
 * @return string 规整后的版本号，非法时为空串
 */
function ver_normalize(string $v): string
{
    $v = trim($v);
    if ($v === '') { return ''; }
    // 去掉常见前缀 v / V，以及 -beta、+build 之类的后缀
    $v = preg_replace('/^[vV]/', '', $v);
    $v = preg_split('/[-+]/', $v)[0] ?? '';
    if (!preg_match('/^\d+(\.\d+)*$/', $v)) { return ''; }
    // 每段去掉前导零，避免 1.02 和 1.2 被当成两个版本
    $段 = array_map(static fn($s) => (string) (int) $s, explode('.', $v));
    // 最多保留 4 段，够用了；再多是打包工具的构建号，不参与版本判断
    $段 = array_slice($段, 0, 4);
    return implode('.', $段);
}
/**
 * 比较两个版本号。
 *
 * 按段转整数比，所以 1.10.0 > 1.9.0 判断正确；
 * 段数不同时短的那边补 0，1.2 和 1.2.0 视为相等。
 *
 * @return int $a 大于 $b 返回 1，小于返回 -1，相等返回 0
 */
function ver_cmp(string $a, string $b): int
{
    $x = explode('.', ver_normalize($a) ?: '0');
    $y = explode('.', ver_normalize($b) ?: '0');
    $n = max(count($x), count($y));
    for ($i = 0; $i < $n; $i++) {
        $p = (int) ($x[$i] ?? 0);
        $q = (int) ($y[$i] ?? 0);
        if ($p !== $q) { return $p > $q ? 1 : -1; }
    }
    return 0;
}
