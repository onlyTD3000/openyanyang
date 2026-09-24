<?php
/**
 * 入侵监控核心模块
 *
 * 职责分工：
 *   - 本文件（PHP，www 用户）：建表、读写配置、记录事件、发通知、维护封禁队列。
 *     PHP 没有 root 权限，<strong>不直接操作防火墙</strong>。
 *   - tools/intrusion_monitor.sh（shell，root，cron 每分钟）：真正的检测与拦截，
 *     检测到异常后通过 tools/intrusion_cli.php 回写事件，并消费后台下发的封禁队列。
 *
 * 这样拆的原因：给 www 开 sudo 等于把提权口子留给 Web 层，本身就是风险。
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';
/** 建表。只在用到的页面/脚本里调一次，重复调用无副作用。 */
function intrusion_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db_exec("CREATE TABLE IF NOT EXISTS intrusion_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        etype      VARCHAR(32)  NOT NULL DEFAULT '',
        level      VARCHAR(8)   NOT NULL DEFAULT 'mid',
        ip         VARCHAR(45)  NOT NULL DEFAULT '',
        target     VARCHAR(255) NOT NULL DEFAULT '',
        detail     TEXT         NULL,
        hits       INT          NOT NULL DEFAULT 1,
        blocked    TINYINT      NOT NULL DEFAULT 0,
        notified   TINYINT      NOT NULL DEFAULT 0,
        notify_err VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_ip (ip),
        KEY idx_etype (etype),
        KEY idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_exec("CREATE TABLE IF NOT EXISTS intrusion_blocks (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip         VARCHAR(45)  NOT NULL,
        reason     VARCHAR(255) NOT NULL DEFAULT '',
        etype      VARCHAR(32)  NOT NULL DEFAULT '',
        source     VARCHAR(8)   NOT NULL DEFAULT 'auto',
        state      VARCHAR(12)  NOT NULL DEFAULT 'pending',
        hits       INT          NOT NULL DEFAULT 0,
        err        VARCHAR(255) NOT NULL DEFAULT '',
        expires_at DATETIME     NULL,
        created_by INT          NOT NULL DEFAULT 0,
        created_at DATETIME     NOT NULL,
        updated_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_ip (ip),
        KEY idx_state (state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
/** 事件类型中文名 */
function intrusion_types(): array
{
    return [
        'ssh_brute'  => 'SSH 暴力破解',
        'ssh_newip'  => 'SSH 陌生 IP 登录成功',
        'web_flood'  => 'Web 高频探测',
        'web_attack' => 'Web 攻击特征',
        'webshell'   => '可疑 PHP 文件',
        'suid'       => '新增 SUID 提权文件',
        'cron'       => '计划任务被改动',
        'proc'       => '可疑进程',
        'virus'      => '病毒木马',
        'virus_scan' => '病毒扫描报告',
        'rootkit'    => '后门 Rootkit',
        'rkhunter'   => '后门扫描报告',
        'malware'    => 'Webshell 恶意代码',
        'maldet'     => '恶意代码扫描报告',
        'sysfile'    => '系统关键文件改动',
        'manual'     => '手动封禁',
        'unblock'    => '解除封禁',
    ];
}
/** 危险等级中文名 */
function intrusion_levels(): array
{
    return ['high' => '高危', 'mid' => '可疑', 'low' => '提示'];
}
function intrusion_level_rank(string $lv): int
{
    return ['low' => 1, 'mid' => 2, 'high' => 3][$lv] ?? 2;
}
/**
 * 全部可配置项的默认值。
 * 键名统一 intr_ 前缀，存在 settings 表里，跟站点设置共用一张表。
 */
function intrusion_conf_defaults(): array
{
    return [
        // ---- 总开关与拦截 ----
        'intr_enabled'       => '0',
        'intr_block'         => '1',
        'intr_block_min'     => '1440',   // 封禁时长（分钟），0 = 永久
        'intr_whitelist'     => '',
        // ---- SSH ----
        'intr_ssh'           => '1',
        'intr_ssh_max'       => '5',
        'intr_ssh_win'       => '300',
        'intr_ssh_newip'     => '1',
        'intr_ssh_log'       => '/var/log/secure',
        // ---- Web ----
        'intr_web'           => '1',
        'intr_web_max'       => '60',
        'intr_web_win'       => '120',
        'intr_web_rule'      => '1',
        'intr_web_log'       => '/www/wwwlogs/code.77bot.cn.log',
        // ---- 文件与系统 ----
        'intr_file'          => '1',
        'intr_scan_dir'      => '/www/wwwroot/code.77bot.cn',
        'intr_scan_exclude'  => '/vendor/,/node_modules/,/.git/,/.kiro_backup/,/backup/,/代码备份/,/.备份',
        'intr_scan_min'      => '10',
        'intr_suid'          => '1',
        'intr_suid_min'      => '60',
        'intr_cron'          => '1',
        'intr_proc'          => '1',
        'intr_proc_cpu'      => '85',
        'intr_sysfile'       => '1',
        // ---- 邮件通知 ----
        'intr_mail'          => '0',
        'intr_mail_to'       => '',
        'intr_mail_level'    => 'mid',
        'intr_mail_cooldown' => '600',
        'intr_mail_subject'  => '[{site}] 入侵告警：{type_name}（{ip}）',
        'intr_mail_body'     => "检测到一条异常，详情如下：\n\n站点：{site}\n服务器：{host}\n时间：{time}\n类型：{type_name}\n等级：{level_name}\n来源 IP：{ip}\n触发次数：{hits}\n处置结果：{blocked}\n对象：{target}\n\n原始详情：\n{detail}\n\n本邮件由入侵监控自动发出，可在后台「入侵监控」页面关闭通知或调整阈值。",
    ];
}
/** 读取全部配置，缺失项用默认值兜底 */
function intrusion_conf(): array
{
    $def = intrusion_conf_defaults();
    $all = settings_all();
    $out = [];
    foreach ($def as $k => $v) {
        $out[$k] = (array_key_exists($k, $all) && $all[$k] !== '') ? $all[$k] : $v;
    }
    return $out;
}
/** 收件邮箱：支持逗号/分号/换行分隔，最多 5 个 */
function intrusion_mail_list(string $raw): array
{
    $parts = preg_split('/[\s,;，；]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($p)] = $p;
        }
    }
    return array_slice(array_values($out), 0, 5);
}
/** 白名单条目列表（一行一条，支持 IP 与 CIDR） */
function intrusion_whitelist_list(): array
{
    $raw = intrusion_conf()['intr_whitelist'];
    $parts = preg_split('/[\s,;，；]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_map('trim', $parts)));
}
/** IPv4 是否落在 CIDR 网段内 */
function intrusion_ip_in_cidr(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false) {
        return false;
    }
    [$net, $bits] = explode('/', $cidr, 2);
    $bits = (int) $bits;
    if ($bits < 0 || $bits > 32) {
        return false;
    }
    $i = ip2long($ip);
    $n = ip2long($net);
    if ($i === false || $n === false) {
        return false;
    }
    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
    return ($i & $mask) === ($n & $mask);
}
/**
 * 这个 IP 是否受保护（不允许封禁）。
 * 内网、回环、保留地址硬保护，白名单管不着也不需要管——封了它们只会把自己关在门外。
 */
function intrusion_ip_protected(string $ip): bool
{
    if ($ip === '' || $ip === '-') {
        return true;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return true;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;   // 内网 / 回环 / 保留地址
    }
    foreach (intrusion_whitelist_list() as $w) {
        if ($w === $ip) {
            return true;
        }
        if (strpos($w, '/') !== false && intrusion_ip_in_cidr($ip, $w)) {
            return true;
        }
    }
    return false;
}
/** 模板变量替换 */
function intrusion_render(string $tpl, array $vars): string
{
    $find = $repl = [];
    foreach ($vars as $k => $v) {
        $find[] = '{' . $k . '}';
        $repl[] = (string) $v;
    }
    return str_replace($find, $repl, $tpl);
}
/**
 * 记录一条事件，并按配置发通知。
 *
 * @param array $ev etype/level/ip/target/detail/hits/blocked
 * @return array [事件id, 是否发信, 发信错误]
 */
function intrusion_event_add(array $ev): array
{
    intrusion_ensure_tables();
    $etype   = substr((string) ($ev['etype'] ?? ''), 0, 32);
    $level   = (string) ($ev['level'] ?? 'mid');
    if (!isset(intrusion_levels()[$level])) {
        $level = 'mid';
    }
    $ip      = substr((string) ($ev['ip'] ?? ''), 0, 45);
    $target  = mb_substr((string) ($ev['target'] ?? ''), 0, 255);
    $detail  = mb_substr((string) ($ev['detail'] ?? ''), 0, 4000);
    $hits    = max(1, (int) ($ev['hits'] ?? 1));
    $blocked = !empty($ev['blocked']) ? 1 : 0;
    $id = db_insert('INSERT INTO intrusion_events
            (etype, level, ip, target, detail, hits, blocked, created_at)
            VALUES (?,?,?,?,?,?,?,NOW())',
        [$etype, $level, $ip, $target, $detail, $hits, $blocked]);
    [$sent, $err] = intrusion_notify([
        'id' => $id, 'etype' => $etype, 'level' => $level, 'ip' => $ip,
        'target' => $target, 'detail' => $detail, 'hits' => $hits, 'blocked' => $blocked,
    ]);
    db_exec('UPDATE intrusion_events SET notified = ?, notify_err = ? WHERE id = ?',
        [$sent ? 1 : 0, mb_substr($err, 0, 255), $id]);
    return [$id, $sent, $err];
}
/**
 * 按配置决定要不要发信，然后发。
 * 返回 [是否已发, 说明]。没发不算失败，说明里写清原因，后台能看到。
 */
function intrusion_notify(array $ev): array
{
    $c = intrusion_conf();
    if ($c['intr_mail'] !== '1') {
        return [false, '邮件通知未开启'];
    }
    $to = intrusion_mail_list($c['intr_mail_to']);
    if (!$to) {
        return [false, '未填写接收邮箱'];
    }
    if (intrusion_level_rank($ev['level']) < intrusion_level_rank($c['intr_mail_level'])) {
        return [false, '低于通知等级门槛'];
    }
    // 同 IP 同类型的通知冷却，防止一次爆破刷出几十封信。
    // 时间比较全部交给 MySQL，避免 PHP 与数据库时区不一致算错。
    $cd = (int) $c['intr_mail_cooldown'];
    if ($cd > 0 && $ev['ip'] !== '') {
        $hit = db_val('SELECT id FROM intrusion_events
                        WHERE ip = ? AND etype = ? AND notified = 1 AND id <> ?
                          AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                        ORDER BY id DESC LIMIT 1',
            [$ev['ip'], $ev['etype'], (int) ($ev['id'] ?? 0), $cd]);
        if ($hit) {
            return [false, '处于通知冷却期（' . $cd . ' 秒）'];
        }
    }
    $types  = intrusion_types();
    $levels = intrusion_levels();
    $vars = [
        'site'       => setting_get('site_name', '本站'),
        'host'       => php_uname('n'),
        'time'       => date('Y-m-d H:i:s'),
        'type'       => $ev['etype'],
        'type_name'  => $types[$ev['etype']] ?? $ev['etype'],
        'level'      => $ev['level'],
        'level_name' => $levels[$ev['level']] ?? $ev['level'],
        'ip'         => $ev['ip'] !== '' ? $ev['ip'] : '（无 IP）',
        'hits'       => $ev['hits'],
        'target'     => $ev['target'] !== '' ? $ev['target'] : '—',
        'detail'     => $ev['detail'] !== '' ? $ev['detail'] : '—',
        'blocked'    => $ev['blocked'] ? '已自动封禁' : '仅记录，未拦截',
    ];
    $subject = intrusion_render($c['intr_mail_subject'], $vars);
    $bodyTxt = intrusion_render($c['intr_mail_body'], $vars);
    $html = '<div style="font:14px/1.8 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;color:#222">'
          . nl2br(htmlspecialchars($bodyTxt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
          . '</div>';
    $okAny = false;
    $errs  = [];
    foreach ($to as $addr) {
        [$ok, $err] = email_send($addr, $subject, $html, '入侵告警');
        if ($ok) {
            $okAny = true;
        } else {
            $errs[] = $addr . '：' . $err;
        }
    }
    return [$okAny, $okAny ? '已发送 ' . count($to) . ' 个收件人' : implode('；', $errs)];
}
/**
 * 后台下发封禁请求。PHP 不动防火墙，只落库排队，
 * root 侧的 shell 脚本下一次运行（最多 1 分钟）会真正执行。
 */
function intrusion_block_request(string $ip, string $reason, int $minutes, int $adminId): array
{
    intrusion_ensure_tables();
    if (intrusion_ip_protected($ip)) {
        return [false, '这个地址受保护（内网/回环/白名单），不允许封禁'];
    }
    $exp = $minutes > 0 ? 'DATE_ADD(NOW(), INTERVAL ' . $minutes . ' MINUTE)' : 'NULL';
    db_exec("INSERT INTO intrusion_blocks
                (ip, reason, etype, source, state, expires_at, created_by, created_at, updated_at)
             VALUES (?,?, 'manual', 'manual', 'pending', $exp, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                reason = VALUES(reason), source = 'manual', state = 'pending',
                expires_at = VALUES(expires_at), err = '',
                created_by = VALUES(created_by), updated_at = NOW()",
        [$ip, mb_substr($reason, 0, 255), $adminId]);
    return [true, '已加入封禁队列，最多 1 分钟内生效'];
}
/** 后台下发解封请求 */
function intrusion_unblock_request(string $ip): array
{
    intrusion_ensure_tables();
    $row = db_one('SELECT * FROM intrusion_blocks WHERE ip = ?', [$ip]);
    if (!$row) {
        return [false, '没有这条封禁记录'];
    }
    db_exec("UPDATE intrusion_blocks SET state = 'unblock_req', err = '', updated_at = NOW() WHERE id = ?",
        [(int) $row['id']]);
    return [true, '已加入解封队列，最多 1 分钟内生效'];
}
/** 概览数字 */
function intrusion_stats(): array
{
    intrusion_ensure_tables();
    return [
        'blocked_now' => (int) db_val("SELECT COUNT(*) FROM intrusion_blocks WHERE state IN ('active','pending')"),
        'ev_24h'      => (int) db_val('SELECT COUNT(*) FROM intrusion_events WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'),
        'ev_high_24h' => (int) db_val("SELECT COUNT(*) FROM intrusion_events WHERE level = 'high' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
        'ev_total'    => (int) db_val('SELECT COUNT(*) FROM intrusion_events'),
        'pending'     => (int) db_val("SELECT COUNT(*) FROM intrusion_blocks WHERE state IN ('pending','unblock_req')"),
    ];
}
/** 脚本心跳：最近一次运行时间、结果、版本 */
function intrusion_heartbeat(): array
{
    return [
        'at'    => (string) setting_get('intr_last_run', ''),
        'state' => (string) setting_get('intr_last_state', ''),
        'msg'   => (string) setting_get('intr_last_msg', ''),
        'ver'   => (string) setting_get('intr_script_ver', ''),
        'mode'  => (string) setting_get('intr_fw_mode', ''),
    ];
}
