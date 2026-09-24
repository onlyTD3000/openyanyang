<?php
/**
 * 服务器地址黑白名单。
 *
 * 设计要点（按客户确认的口径）：
 * 1. 内网与保留地址「硬禁止」，白名单也不能解除。这是防 SSRF 打内网的底线。
 * 2. 白名单的唯一作用是解除「管理员手动拉黑」，范围仅限黑名单。
 * 3. 平台自身 IP 不再自动禁止，改由管理员按需加进黑名单管理。
 *
 * 规则写法支持三种：
 *   - 单个 IP：203.0.113.5
 *   - CIDR 网段：203.0.113.0/24
 *   - 域名（含通配）：example.com、*.example.com
 */
require_once __DIR__ . '/db.php';

/** 建表，安装或首次访问时调用，幂等 */
function ip_rules_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS ip_rules ('
        . ' id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . ' kind ENUM("deny","allow") NOT NULL COMMENT "deny=黑名单 allow=白名单",'
        . ' pattern VARCHAR(120) NOT NULL COMMENT "IP/CIDR/域名",'
        . ' note VARCHAR(200) NOT NULL DEFAULT "" COMMENT "备注",'
        . ' enabled TINYINT(1) NOT NULL DEFAULT 1,'
        . ' created_by INT UNSIGNED NULL COMMENT "操作管理员ID",'
        . ' created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (id),'
        . ' UNIQUE KEY uk_kind_pattern (kind, pattern),'
        . ' KEY idx_kind_enabled (kind, enabled)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/**
 * 校验规则写法是否合法。
 *
 * @return string 空串表示合法，否则为错误原因
 */
function ip_rule_validate(string $pattern): string
{
    $p = strtolower(trim($pattern));
    if ($p === '') {
        return '规则不能为空';
    }
    if (mb_strlen($p) > 120) {
        return '规则过长（上限 120 字符）';
    }
    // CIDR 网段
    if (strpos($p, '/') !== false) {
        [$net, $bits] = array_pad(explode('/', $p, 2), 2, '');
        if (!filter_var($net, FILTER_VALIDATE_IP)) {
            return 'CIDR 的网络地址不合法';
        }
        $max = strpos($net, ':') !== false ? 128 : 32;
        if ($bits === '' || !ctype_digit($bits) || (int) $bits < 0 || (int) $bits > $max) {
            return 'CIDR 掩码位数应在 0-' . $max . ' 之间';
        }
        return '';
    }
    // 单个 IP
    if (filter_var($p, FILTER_VALIDATE_IP)) {
        return '';
    }
    // 域名，允许开头一个 *. 通配
    $bare = strpos($p, '*.') === 0 ? substr($p, 2) : $p;
    if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $bare)) {
        return '';
    }
    return '写法无法识别，请填 IP、CIDR 网段（如 1.2.3.0/24）或域名（可用 *.example.com）';
}

/**
 * 判断一个 IP 是否落在 CIDR 网段内。IPv4 / IPv6 都支持。
 */
function ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
    if ($net === '' || $bits === '' || !ctype_digit($bits)) {
        return false;
    }
    $binIp  = @inet_pton($ip);
    $binNet = @inet_pton($net);
    if ($binIp === false || $binNet === false || strlen($binIp) !== strlen($binNet)) {
        return false;   // 版本不同（v4 比 v6）直接不匹配
    }
    $bits = (int) $bits;
    $full = intdiv($bits, 8);         // 需完整比对的字节数
    $rest = $bits % 8;                // 末尾不足一字节的位数
    if ($full > 0 && strncmp($binIp, $binNet, $full) !== 0) {
        return false;
    }
    if ($rest === 0) {
        return true;
    }
    $mask = chr(0xff << (8 - $rest) & 0xff);
    return (($binIp[$full] & $mask) === ($binNet[$full] & $mask));
}

/**
 * 单条规则是否命中给定的主机名与其解析出的 IP 列表。
 *
 * @param string $pattern 规则
 * @param string $host    用户填的主机（可能是域名）
 * @param array  $ips     该主机解析出的所有 IP
 */
function ip_rule_hit(string $pattern, string $host, array $ips): bool
{
    $p = strtolower(trim($pattern));
    $h = strtolower(trim($host));
    if ($p === '') {
        return false;
    }
    // 网段：逐个 IP 比对
    if (strpos($p, '/') !== false) {
        foreach ($ips as $ip) {
            if (ip_in_cidr($ip, $p)) {
                return true;
            }
        }
        return false;
    }
    // 纯 IP：直接比对解析结果，域名指向被拉黑 IP 也拦得住
    if (filter_var($p, FILTER_VALIDATE_IP)) {
        return in_array($p, array_map('strtolower', $ips), true);
    }
    // 通配域名：*.example.com 匹配子域，也匹配主域本身
    if (strpos($p, '*.') === 0) {
        $base = substr($p, 2);
        return $h === $base || (strlen($h) > strlen($base)
            && substr($h, -strlen($base) - 1) === '.' . $base);
    }
    // 普通域名：精确匹配
    return $h === $p;
}

/**
 * 读取某类规则（已启用的）。
 *
 * @param string $kind deny 或 allow
 * @return array 每项含 pattern、note
 */
function ip_rules_get(string $kind): array
{
    ip_rules_ensure_table();
    return db_all(
        'SELECT id, pattern, note FROM ip_rules WHERE kind = ? AND enabled = 1 ORDER BY id DESC',
        [$kind === 'allow' ? 'allow' : 'deny']
    );
}

/**
 * 名单判定：先看白名单豁免，再看黑名单拦截。
 *
 * 白名单只能解除黑名单，管不到内网硬禁止（那部分在 ssh_host_deny_reason 里先判）。
 *
 * @return string 空串表示放行，否则为拒绝原因
 */
function ip_rules_deny_reason(string $host, array $ips): string
{
    ip_rules_ensure_table();
    // 命中白名单直接放行，跳过黑名单检查
    foreach (ip_rules_get('allow') as $r) {
        if (ip_rule_hit($r['pattern'], $host, $ips)) {
            return '';
        }
    }
    foreach (ip_rules_get('deny') as $r) {
        if (ip_rule_hit($r['pattern'], $host, $ips)) {
            $why = trim((string) $r['note']);
            return '该地址已被管理员加入黑名单' . ($why !== '' ? '：' . $why : '');
        }
    }
    return '';
}
