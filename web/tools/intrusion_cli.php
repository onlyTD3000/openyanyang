<?php
/**
 * 入侵监控 CLI 桥接
 *
 * root 侧的 shell 脚本不直接连数据库，所有读写都经过这里。
 * 好处：数据库账号密码只留在 inc/config.php 一处，shell 脚本里不出现任何凭据。
 *
 * 用法：php tools/intrusion_cli.php <子命令> [--参数=值 ...]
 *
 *   conf                 输出全部配置，格式为 shell 可 eval 的 KEY='值'
 *   event                记录一条事件（顺带按配置发邮件、按需入封禁队列）
 *   queue                输出待处理的封禁/解封队列
 *   queue-done           回写队列执行结果
 *   expired              输出已到期、需要解封的 IP
 *   active               输出当前应处于封禁状态的全部 IP（开机后重建防火墙用）
 *   heartbeat            写入本次运行的心跳
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("此脚本仅允许命令行调用\n");
}
$ROOT = dirname(__DIR__);
require_once $ROOT . '/inc/db.php';
require_once $ROOT . '/inc/helpers.php';
require_once $ROOT . '/inc/email.php';
require_once $ROOT . '/inc/intrusion.php';
// ---------- 参数解析 ----------
$argvList = $argv;
array_shift($argvList);
$cmd = array_shift($argvList) ?: '';
$opt = [];
foreach ($argvList as $a) {
    if (preg_match('/^--([a-z0-9\-_]+)(?:=(.*))?$/is', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    }
}
function o(string $k, string $def = ''): string
{
    global $opt;
    return isset($opt[$k]) ? (string) $opt[$k] : $def;
}
/** 把值包成 shell 单引号字面量，内部单引号做转义 */
function sh_quote(string $v): string
{
    return "'" . str_replace("'", "'\\''", $v) . "'";
}
intrusion_ensure_tables();
switch ($cmd) {
    // ------------------------------------------------------------------
    // 输出配置，供 shell <code>eval "$(php ... conf)"</code> 使用
    // ------------------------------------------------------------------
    case 'conf':
        foreach (intrusion_conf() as $k => $v) {
            // 只导出单行值；邮件正文这种多行模板 shell 用不上，跳过
            if (strpos((string) $v, "\n") !== false) {
                continue;
            }
            echo strtoupper($k) . '=' . sh_quote((string) $v) . "\n";
        }
        break;
    // ------------------------------------------------------------------
    // 记录事件
    //   --etype --level --ip --target --detail --hits --want-block
    // 输出：id=/ block=0|1 / expire=秒时间戳或0 / notify=说明
    // ------------------------------------------------------------------
    case 'event':
        $etype = o('etype');
        if ($etype === '') {
            fwrite(STDERR, "缺少 --etype\n");
            exit(2);
        }
        $ip    = trim(o('ip'));
        $level = o('level', 'mid');
        $hits  = (int) o('hits', '1');
        $conf  = intrusion_conf();
        // 是否真的要封：检测侧想封 + 后台允许自动封 + 地址不受保护
        $wantBlock = o('want-block', '0') === '1';
        $doBlock   = false;
        if ($wantBlock && $conf['intr_block'] === '1'
            && $ip !== '' && !intrusion_ip_protected($ip)) {
            $doBlock = true;
        }
        [$evId, $sent, $notifyMsg] = intrusion_event_add([
            'etype'  => $etype,
            'level'  => $level,
            'ip'     => $ip,
            'target' => o('target'),
            'detail' => o('detail'),
            'hits'   => $hits,
            'blocked' => $doBlock ? 1 : 0,
        ]);
        $expireTs = 0;
        if ($doBlock) {
            $min = (int) $conf['intr_block_min'];
            $exp = $min > 0 ? 'DATE_ADD(NOW(), INTERVAL ' . $min . ' MINUTE)' : 'NULL';
            db_exec("INSERT INTO intrusion_blocks
                        (ip, reason, etype, source, state, hits, expires_at, created_at, updated_at)
                     VALUES (?,?,?, 'auto', 'pending', ?, $exp, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        reason = VALUES(reason), etype = VALUES(etype),
                        hits = hits + VALUES(hits), err = '',
                        state = IF(state = 'active', 'active', 'pending'),
                        expires_at = VALUES(expires_at), updated_at = NOW()",
                [$ip, mb_substr(intrusion_types()[$etype] ?? $etype, 0, 255), $etype, $hits]);
            $expireTs = (int) db_val(
                'SELECT COALESCE(UNIX_TIMESTAMP(expires_at), 0) FROM intrusion_blocks WHERE ip = ?',
                [$ip]);
        }
        echo "id=$evId\n";
        echo 'block=' . ($doBlock ? 1 : 0) . "\n";
        echo "expire=$expireTs\n";
        echo 'notify=' . ($sent ? 'ok' : 'skip') . ' ' . str_replace("\n", ' ', $notifyMsg) . "\n";
        break;
    // ------------------------------------------------------------------
    // 待处理队列。每行：动作|IP|封禁到期秒时间戳|记录id
    // ------------------------------------------------------------------
    case 'queue':
        $rows = db_all("SELECT id, ip, state, COALESCE(UNIX_TIMESTAMP(expires_at), 0) AS exp
                        FROM intrusion_blocks
                        WHERE state IN ('pending','unblock_req')
                        ORDER BY id ASC LIMIT 200");
        foreach ($rows as $r) {
            $act = $r['state'] === 'unblock_req' ? 'unblock' : 'block';
            echo $act . '|' . $r['ip'] . '|' . (int) $r['exp'] . '|' . (int) $r['id'] . "\n";
        }
        break;
    // ------------------------------------------------------------------
    // 回写队列结果：--id --ok=0|1 --act=block|unblock --err=
    // ------------------------------------------------------------------
    case 'queue-done':
        $id  = (int) o('id');
        $ok  = o('ok', '0') === '1';
        $act = o('act', 'block');
        $err = mb_substr(o('err'), 0, 255);
        if ($id <= 0) {
            fwrite(STDERR, "缺少 --id\n");
            exit(2);
        }
        if ($act === 'unblock') {
            if ($ok) {
                // 解封成功就删掉记录，历史留在事件表里，封禁表只反映当前状态
                db_exec('DELETE FROM intrusion_blocks WHERE id = ?', [$id]);
            } else {
                db_exec("UPDATE intrusion_blocks SET err = ?, updated_at = NOW() WHERE id = ?", [$err, $id]);
            }
        } else {
            db_exec("UPDATE intrusion_blocks
                     SET state = ?, err = ?, updated_at = NOW() WHERE id = ?",
                [$ok ? 'active' : 'failed', $ok ? '' : $err, $id]);
        }
        echo "ok\n";
        break;
    // ------------------------------------------------------------------
    // 已到期需解封的 IP（每行：IP|id）
    // ------------------------------------------------------------------
    case 'expired':
        $rows = db_all("SELECT id, ip FROM intrusion_blocks
                        WHERE state = 'active' AND expires_at IS NOT NULL
                          AND expires_at <= NOW()
                        ORDER BY id ASC LIMIT 200");
        foreach ($rows as $r) {
            echo $r['ip'] . '|' . (int) $r['id'] . "\n";
        }
        break;
    // ------------------------------------------------------------------
    // 当前应封的全部 IP，一行一个。防火墙规则丢失后用它重建
    // ------------------------------------------------------------------
    case 'active':
        $rows = db_all("SELECT ip FROM intrusion_blocks
                        WHERE state IN ('active','pending')
                          AND (expires_at IS NULL OR expires_at > NOW())");
        foreach ($rows as $r) {
            echo $r['ip'] . "\n";
        }
        break;
    // ------------------------------------------------------------------
    // 心跳：--state=ok|warn|err --msg= --ver= --fw=
    // ------------------------------------------------------------------
    case 'heartbeat':
        setting_set('intr_last_run', date('Y-m-d H:i:s'));
        setting_set('intr_last_state', mb_substr(o('state', 'ok'), 0, 16));
        setting_set('intr_last_msg', mb_substr(o('msg'), 0, 500));
        if (o('ver') !== '') {
            setting_set('intr_script_ver', mb_substr(o('ver'), 0, 32));
        }
        if (o('fw') !== '') {
            setting_set('intr_fw_mode', mb_substr(o('fw'), 0, 32));
        }
        echo "ok\n";
        break;
    default:
        fwrite(STDERR, "未知子命令：$cmd\n可用：conf event queue queue-done expired active heartbeat\n");
        exit(2);
}
