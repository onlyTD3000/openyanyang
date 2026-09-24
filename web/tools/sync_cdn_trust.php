#!/usr/bin/env php
<?php
/**
 * 同步 CDN 白名单到 nginx 配置。
 * 从 settings.intr_whitelist 读取 IP/CIDR，生成 set_real_ip_from 配置片段。
 */
require_once dirname(__DIR__) . '/inc/helpers.php';
$OUTPUT_FILE = '/www/server/panel/vhost/nginx/includes/cdn_trust.conf';
$NGINX_BIN   = '/www/server/nginx/sbin/nginx';
// 从数据库读取白名单
$raw = db_val("SELECT v FROM settings WHERE k = 'intr_whitelist'") ?: '';
echo "读取到白名单：\n$raw\n\n";
$lines = preg_split('/[\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$ips = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (preg_match('/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/', $line)) {
        $ips[] = $line;
    }
}
echo "解析出 " . count($ips) . " 条有效记录\n\n";
// 生成配置内容
$content = "# 自动生成，请勿手动编辑\n";
$content .= "# 由 tools/sync_cdn_trust.php 根据入侵检测白名单同步\n";
$content .= "# 生成时间：" . date('Y-m-d H:i:s') . "\n\n";
if (empty($ips)) {
    $content .= "# 当前白名单为空\n";
} else {
    foreach ($ips as $ip) {
        $content .= "set_real_ip_from $ip;\n";
    }
    $content .= "real_ip_header X-Real-IP;\n";
    $content .= "real_ip_recursive on;\n";
}
// 确保目录存在
$dir = dirname($OUTPUT_FILE);
if (!is_dir($dir)) {
    echo "创建目录 $dir\n";
    mkdir($dir, 0755, true);
}
// 写入文件
if (file_put_contents($OUTPUT_FILE, $content) === false) {
    fwrite(STDERR, "错误：无法写入 $OUTPUT_FILE\n");
    exit(1);
}
echo "已生成 $OUTPUT_FILE\n";
// 测试 nginx 配置
echo "测试 nginx 配置...\n";
exec("$NGINX_BIN -t 2>&1", $output, $code);
if ($code !== 0) {
    fwrite(STDERR, "nginx 配置测试失败：\n" . implode("\n", $output) . "\n");
    exit(2);
}
echo "配置测试通过\n";
// 重载 nginx
echo "重载 nginx...\n";
exec("$NGINX_BIN -s reload 2>&1", $output, $code);
if ($code !== 0) {
    fwrite(STDERR, "nginx 重载失败：\n" . implode("\n", $output) . "\n");
    exit(3);
}
echo "nginx 已重载\n";
