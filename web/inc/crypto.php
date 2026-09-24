<?php
/**
 * 凭据加解密。用于 SSH 密码与私钥的落库保护。
 *
 * 用 AES-256-GCM：带认证标签，密文被篡改会解密失败，比 CBC 安全。
 * 主密钥不写在网站目录里，存放于 open_basedir 允许的数据目录，
 * 这样源码被拖走也拿不到密钥。
 */

/**
 * 取主密钥（32 字节）。首次调用自动生成并落盘。
 * 密钥文件权限 0600，只有运行 PHP 的用户能读。
 */
function crypto_master_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    // 放在网站根目录之外的数据目录，避免被 HTTP 直接访问。
    // DATA_DIR 由 config.php 探测得出，已确保在 open_basedir 白名单内且可写。
    if (defined('DATA_DIR')) {
        $dir = rtrim(DATA_DIR, '/') . '/secure';
    } elseif (defined('UPLOAD_DIR')) {
        $dir = dirname(UPLOAD_DIR) . '/secure';
    } else {
        $dir = sys_get_temp_dir() . '/kiro_secure';
    }
    $file = rtrim($dir, '/') . '/master.key';
    if (is_file($file) && is_readable($file)) {
        $raw = (string) file_get_contents($file);
        $bin = base64_decode(trim($raw), true);
        if ($bin !== false && strlen($bin) === 32) {
            $key = $bin;
            return $key;
        }
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        密钥属主对齐($dir, 0700);
    }
    $bin = random_bytes(32);
    // 先写临时文件再改名，避免并发下读到半截内容
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, base64_encode($bin)) === false) {
        throw new RuntimeException('无法写入主密钥文件，请检查数据目录权限：' . $dir);
    }
    密钥属主对齐($tmp, 0600);
    @rename($tmp, $file);
    密钥属主对齐($file, 0600);
    $key = $bin;
    return $key;
}

/**
 * 加密。返回 base64(iv + tag + 密文)，可直接存数据库文本字段。
 */
function enc_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $iv  = random_bytes(12); // GCM 推荐 12 字节
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', crypto_master_key(),
        OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('加密失败');
    }
    return base64_encode($iv . $tag . $ct);
}

/**
 * 解密。密文被改动或密钥不对时返回空串，不抛异常，交调用方判断。
 */
function dec_secret(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    $bin = base64_decode($stored, true);
    if ($bin === false || strlen($bin) < 29) {
        return '';
    }
    $iv  = substr($bin, 0, 12);
    $tag = substr($bin, 12, 16);
    $ct  = substr($bin, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', crypto_master_key(),
        OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '' : $pt;
}
/**
 * 密钥文件/目录的属主对齐。crypto.php 是零依赖底层模块，
 * 不引入 helpers.php，这里自带一份精简实现。
 *
 * 场景：首次部署若由 root 跑 CLI，master.key 会是 root:root 0600、
 * secure 目录 root 0700，之后 PHP-FPM 以 www 身份连目录都进不去，
 * 整站解密全部失败。属主取上一级目录的，不写死用户名。
 *
 * @param string $路径 目标文件或目录
 * @param int    $模式 权限位
 */
function 密钥属主对齐(string $路径, int $模式): void
{
    if ($路径 === '' || !file_exists($路径)) {
        return;
    }
    @chmod($路径, $模式);
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return;
    }
    $uid = @fileowner(dirname($路径));
    $gid = @filegroup(dirname($路径));
    if ($uid === false || $gid === false || $uid === 0) {
        return;
    }
    @chown($路径, $uid);
    @chgrp($路径, $gid);
}
