<?php
/**
 * 后台「入侵监控」页面。
 *
 * 页面本身只做三件事：读写配置、看事件、管封禁名单。
 * 真正的检测与拦截在 tools/intrusion_monitor.sh 里（root，cron 每分钟），
 * 因为 PHP 跑在 www 用户下，没有权限动防火墙，也不该有。
 */
$adminOn   = 'intrusion';
$pageTitle = '入侵监控';
// POST 必须在 _head.php 之前处理完，否则重定向发不出去，刷新会重复提交。
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();
require_once __DIR__ . '/../inc/intrusion.php';
intrusion_ensure_tables();
$SH_PATH   = APP_ROOT . '/tools/intrusion_monitor.sh';
$CRON_LINE = '* * * * * ' . $SH_PATH . ' >/dev/null 2>&1';
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = $_POST['act'] ?? '';
    // ---------------- 保存配置 ----------------
    if ($act === 'save') {
        $bools = ['intr_enabled','intr_block','intr_ssh','intr_ssh_newip','intr_web',
                  'intr_web_rule','intr_file','intr_suid','intr_cron','intr_proc',
                  'intr_sysfile','intr_mail'];
        foreach ($bools as $k) {
            setting_set($k, isset($_POST[$k]) ? '1' : '0');
        }
        $ints = ['intr_block_min' => [0, 525600], 'intr_ssh_max' => [1, 1000],
                 'intr_ssh_win' => [30, 86400],   'intr_web_max' => [5, 100000],
                 'intr_web_win' => [30, 86400],   'intr_scan_min' => [1, 10080],
                 'intr_suid_min' => [1, 10080],   'intr_proc_cpu' => [10, 100],
                 'intr_mail_cooldown' => [0, 86400]];
        foreach ($ints as $k => [$lo, $hi]) {
            $v = (int) ($_POST[$k] ?? 0);
            setting_set($k, (string) max($lo, min($hi, $v)));
        }
        // 路径类：只接受绝对路径，防止把相对路径丢给 root 脚本
        foreach (['intr_ssh_log', 'intr_web_log', 'intr_scan_dir'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '' && $v[0] !== '/') {
                $err = '路径必须是以 / 开头的绝对路径：' . $v;
                break;
            }
            if (strpos($v, '..') !== false || preg_match('/[;&|`$\n\r]/', $v)) {
                $err = '路径里有不允许的字符：' . $v;
                break;
            }
            setting_set($k, $v);
        }
        $lv = (string) ($_POST['intr_mail_level'] ?? 'mid');
        setting_set('intr_mail_level', isset(intrusion_levels()[$lv]) ? $lv : 'mid');
        // 收件邮箱：逐个校验，非法的直接提示，不静默丢弃
        $rawTo = trim((string) ($_POST['intr_mail_to'] ?? ''));
        if ($rawTo !== '') {
            $parts = preg_split('/[\s,;，；]+/u', $rawTo, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $bad = [];
            foreach ($parts as $p) {
                if (!filter_var(trim($p), FILTER_VALIDATE_EMAIL)) {
                    $bad[] = $p;
                }
            }
            if ($bad) {
                $err = $err ?: ('邮箱格式不对：' . implode('、', array_slice($bad, 0, 3)));
            }
        }
        if ($err === '') {
            setting_set('intr_mail_to', $rawTo);
        }
        // 白名单：校验每一条是 IP 或 CIDR
        $rawWl = trim((string) ($_POST['intr_whitelist'] ?? ''));
        $wlBad = [];
        $wlOk  = [];
        foreach (preg_split('/[\s,;，；]+/u', $rawWl, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $w = trim($w);
            if ($w === '') { continue; }
            $isIp   = filter_var($w, FILTER_VALIDATE_IP) !== false;
            $isCidr = preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $w)
                      && filter_var(explode('/', $w)[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                      && (int) explode('/', $w)[1] <= 32;
            if ($isIp || $isCidr) { $wlOk[] = $w; } else { $wlBad[] = $w; }
        }
        if ($wlBad) {
            $err = $err ?: ('白名单里这些不是合法 IP 或网段：' . implode('、', array_slice($wlBad, 0, 3)));
        } elseif ($err === '') {
            setting_set('intr_whitelist', implode("\n", array_unique($wlOk)));
            // 同步白名单到 nginx 配置
            exec("/usr/bin/php " . APP_ROOT . "/tools/sync_cdn_trust.php >/dev/null 2>&1 &");
        }
        // 邮件模板：允许空，空则回落到默认模板
        foreach (['intr_mail_subject', 'intr_mail_body'] as $k) {
            $v = (string) ($_POST[$k] ?? '');
            $v = str_replace("\r\n", "\n", $v);
            if (trim($v) === '') {
                $v = intrusion_conf_defaults()[$k];
            }
            setting_set($k, mb_substr($v, 0, $k === 'intr_mail_subject' ? 200 : 4000));
        }
        if ($err === '') {
            $msg = '设置已保存';
            audit_log((int) $me['id'], 'intr_conf_save', 0, 0, '入侵监控配置变更');
        }
    }
    // ---------------- 发测试邮件 ----------------
    if ($act === 'mailtest') {
        $rawTo = trim((string) ($_POST['to'] ?? ''));
        // 表单没填就用后台配置好的接收邮箱，避免测试按钮永远报「先填邮箱」
        if ($rawTo === '') { $rawTo = (string) setting_get('intr_mail_to', ''); }
        $to = intrusion_mail_list($rawTo);
        if (!$to) {
            $err = '先填一个合法的收件邮箱';
        } else {
            $conf = intrusion_conf();
            $vars = [
                'site'       => app_name(),
                'host'       => php_uname('n'),
                'time'       => date('Y-m-d H:i:s'),
                'type'       => 'ssh_brute',
                'type_name'  => 'SSH 暴力破解',
                'level'      => 'high',
                'level_name' => '高危',
                'ip'         => '203.0.113.45',
                'hits'       => '8',
                'blocked'    => '已自动封禁 1440 分钟',
                'target'     => 'root',
                'detail'     => "这是一封测试邮件，用的是示例数据，不代表真实入侵。\n"
                                . "Failed password for root from 203.0.113.45 port 51234 ssh2",
            ];
            $subject = intrusion_render($conf['intr_mail_subject'], $vars);
            $bodyTxt = intrusion_render($conf['intr_mail_body'], $vars);
            $bodyHtml = '<div style="font:14px/1.8 -apple-system,Segoe UI,Microsoft YaHei,sans-serif;color:#222">'
                      . '<p style="padding:8px 12px;background:#fff7e6;border-left:3px solid #f0a020;margin:0 0 14px">'
                      . '这是一封<strong>测试邮件</strong>，内容为示例数据。</p>'
                      . nl2br(h($bodyTxt)) . '</div>';
            $okAll = true;
            $lastErr = '';
            foreach ($to as $addr) {
                [$ok, $e] = email_send($addr, '[测试] ' . $subject, $bodyHtml, '入侵监控测试', 0);
                if (!$ok) { $okAll = false; $lastErr = $e; }
            }
            if ($okAll) {
                $msg = '测试邮件已发出，收件人：' . implode('、', $to);
            } else {
                $err = '发送失败：' . $lastErr . '（先检查后台邮件设置里的 SMTP 配置）';
            }
        }
    }
    // ---------------- 手动封禁 ----------------
    if ($act === 'block') {
        $ip  = trim((string) ($_POST['ip'] ?? ''));
        $min = (int) ($_POST['minutes'] ?? 1440);
        $rsn = trim((string) ($_POST['reason'] ?? '')) ?: '管理员手动封禁';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $err = 'IP 格式不对';
        } else {
            [$ok, $m] = intrusion_block_request($ip, $rsn, max(0, min(525600, $min)), (int) $me['id']);
            if ($ok) {
                $msg = $m;
                intrusion_event_add([
                    'etype' => 'manual', 'level' => 'mid', 'ip' => $ip,
                    'target' => '', 'detail' => '管理员手动封禁：' . $rsn,
                    'hits' => 1, 'blocked' => 1, 'notify' => false,
                ]);
                audit_log((int) $me['id'], 'intr_block', 0, 0, '手动封禁 ' . $ip);
            } else {
                $err = $m;
            }
        }
    }
    // ---------------- 解封 ----------------
    if ($act === 'unblock') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        [$ok, $m] = intrusion_unblock_request($ip);
        if ($ok) {
            $msg = $m;
            audit_log((int) $me['id'], 'intr_unblock', 0, 0, '解封 ' . $ip);
        } else {
            $err = $m;
        }
    }
    // ---------------- 删除封禁记录（不动防火墙，只清库） ----------------
    if ($act === 'bdel') {
        $id  = (int) ($_POST['id'] ?? 0);
        $row = db_one('SELECT ip, state FROM intrusion_blocks WHERE id = ?', [$id]);
        if (!$row) {
            $err = '记录不存在';
        } elseif ($row['state'] === 'active') {
            $err = '这条还在生效中，请先解封再删除，否则防火墙里会留下孤儿规则';
        } else {
            db_exec('DELETE FROM intrusion_blocks WHERE id = ?', [$id]);
            $msg = '已删除 ' . $row['ip'] . ' 的记录';
        }
    }
    // ---------------- 清理事件 ----------------
    if ($act === 'evclear') {
        $days = (int) ($_POST['days'] ?? 30);
        if ($days <= 0) {
            $n = db_exec('DELETE FROM intrusion_events');
            $msg = '已清空全部事件，共 ' . $n . ' 条';
        } else {
            $n = db_exec('DELETE FROM intrusion_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
            $msg = '已清理 ' . $days . ' 天前的事件，共 ' . $n . ' 条';
        }
        audit_log((int) $me['id'], 'intr_ev_clear', 0, 0, '清理入侵事件 ' . $days . ' 天');
    }
    flash_set($err !== '' ? 'error' : 'ok', $err !== '' ? $err : $msg);
    redirect_self();
}
[$flashT, $flashM] = flash_get();
if ($flashT === 'error') { $err = $flashM; } elseif ($flashT === 'ok') { $msg = $flashM; }
// ================= 以下为展示数据准备 =================
$conf  = intrusion_conf();
$stats = intrusion_stats();
$hb    = intrusion_heartbeat();
$types = intrusion_types();
$levels = intrusion_levels();
// 事件列表：筛选 + 分页
$fType = trim($_GET['etype'] ?? '');
$fLv   = trim($_GET['level'] ?? '');
$fIp   = trim($_GET['ip'] ?? '');
$page  = max(1, (int) ($_GET['p'] ?? 1));
$per   = 25;
$off   = ($page - 1) * $per;
$w = [];
$a = [];
if ($fType !== '' && isset($types[$fType])) { $w[] = 'etype = ?'; $a[] = $fType; }
if ($fLv !== '' && isset($levels[$fLv]))    { $w[] = '`level` = ?'; $a[] = $fLv; }
if ($fIp !== '')                            { $w[] = 'ip LIKE ?';  $a[] = '%' . $fIp . '%'; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$evTotal = (int) db_val('SELECT COUNT(*) FROM intrusion_events ' . $where, $a);
$evPages = max(1, (int) ceil($evTotal / $per));
$events  = db_all('SELECT * FROM intrusion_events ' . $where .
                  ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . $off, $a);
$blocks = db_all('SELECT * FROM intrusion_blocks ORDER BY
                  FIELD(state, "pending","unblock_req","active","failed","released"), updated_at DESC
                  LIMIT 200');
// 环境自检：脚本在不在、cron 装没装、防火墙什么状态
$shExists = is_file($SH_PATH);
$shExec   = $shExists && is_executable($SH_PATH);
$hbAt     = $hb['at'];
$hbAge    = $hbAt !== '' ? (time() - strtotime($hbAt)) : -1;
$hbFresh  = $hbAge >= 0 && $hbAge < 300;   // 5 分钟内跑过算正常
$smtpOk = trim((string) setting_get('email_smtp_host', '')) !== ''
          && trim((string) setting_get('email_smtp_user', '')) !== '';
require __DIR__ . '/_head.php';
?>
<div class="page-head">
  <div class="page-title">入侵监控</div>
</div>
<?php if ($msg !== ''): ?>
  <div class="alert alert-ok"><?= h($msg) ?></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
  <div class="alert alert-error"><?= h($err) ?></div>
<?php endif; ?>
<!-- ============ 运行状态 ============ -->
<div class="card mb-16">
  <div class="card-head">运行状态</div>
  <div class="card-body">
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
      <div style="flex:1;min-width:120px;padding:12px 14px;background:#f7f8fa;border-radius:8px">
        <div style="font-size:22px;font-weight:600"><?= (int) $stats['blocked_now'] ?></div>
        <div class="dim" style="font-size:12px">当前封禁 IP</div>
      </div>
      <div style="flex:1;min-width:120px;padding:12px 14px;background:#f7f8fa;border-radius:8px">
        <div style="font-size:22px;font-weight:600;color:<?= $stats['ev_high_24h'] > 0 ? '#d03050' : 'inherit' ?>">
          <?= (int) $stats['ev_high_24h'] ?></div>
        <div class="dim" style="font-size:12px">24 小时高危事件</div>
      </div>
      <div style="flex:1;min-width:120px;padding:12px 14px;background:#f7f8fa;border-radius:8px">
        <div style="font-size:22px;font-weight:600"><?= (int) $stats['ev_24h'] ?></div>
        <div class="dim" style="font-size:12px">24 小时事件总数</div>
      </div>
      <div style="flex:1;min-width:120px;padding:12px 14px;background:#f7f8fa;border-radius:8px">
        <div style="font-size:22px;font-weight:600"><?= (int) $stats['ev_total'] ?></div>
        <div class="dim" style="font-size:12px">累计事件</div>
      </div>
    </div>
    <table class="tbl">
      <tr>
        <td style="width:130px">总开关</td>
        <td>
          <?php if ($conf['intr_enabled'] === '1'): ?>
            <span class="badge badge-ok">已开启</span>
          <?php else: ?>
            <span class="badge badge-off">已关闭</span>
            <span class="dim">开关关着的时候脚本只记心跳，不检测、不拦截</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>检测脚本</td>
        <td>
          <?php if ($shExec): ?>
            <span class="badge badge-ok">就位</span>
          <?php elseif ($shExists): ?>
            <span class="badge badge-err">没有执行权限</span>
            <span class="dim">需要 chmod 700 <?= h($SH_PATH) ?></span>
          <?php else: ?>
            <span class="badge badge-err">文件不存在</span>
          <?php endif; ?>
          <code class="dim" style="margin-left:6px"><?= h($SH_PATH) ?></code>
        </td>
      </tr>
      <tr>
        <td>最近一次运行</td>
        <td>
          <?php if ($hbAt === ''): ?>
            <span class="badge badge-err">从未运行</span>
            <span class="dim">定时任务还没装，见下方「监控方式」</span>
          <?php elseif ($hbFresh): ?>
            <span class="badge badge-ok">正常</span>
            <span class="dim"><?= h($hbAt) ?>（<?= (int) $hbAge ?> 秒前）</span>
          <?php else: ?>
            <span class="badge badge-err">已停止</span>
            <span class="dim">最后一次 <?= h($hbAt) ?>，已超过
              <?= $hbAge > 3600 ? round($hbAge / 3600, 1) . ' 小时' : round($hbAge / 60) . ' 分钟' ?>
              没动静，检查 cron 是否还在</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($hb['msg'] !== ''): ?>
      <tr>
        <td>上次结果</td>
        <td class="dim"><?= h($hb['msg']) ?></td>
      </tr>
      <?php endif; ?>
      <tr>
        <td>拦截方式</td>
        <td>
          <?php if ($hb['mode'] === ''): ?>
            <span class="dim">等脚本首次运行后显示</span>
          <?php elseif ($hb['mode'] === 'none'): ?>
            <span class="badge badge-err"><?= h($hb['mode']) ?></span>
            <span class="dim">没找到可用的防火墙，无法执行拦截，只能记录事件</span>
          <?php else: ?>
            <span class="badge badge-ok"><?= h($hb['mode']) ?></span>
            <span class="dim">封禁通过 <?= h($hb['mode']) ?> 下发</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>邮件通道</td>
        <td>
          <?php if (!$smtpOk): ?>
            <span class="badge badge-err">未配置</span>
            <span class="dim">去「邮箱管理」填好 SMTP，这里才能发通知</span>
          <?php elseif ($conf['intr_mail'] === '1'): ?>
            <span class="badge badge-ok">已开启</span>
            <span class="dim">收件人：<?= h($conf['intr_mail_to'] !== '' ? $conf['intr_mail_to'] : '（未填，不会发）') ?></span>
          <?php else: ?>
            <span class="badge badge-off">通知关闭</span>
            <span class="dim">SMTP 可用，但入侵通知开关是关的</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ((int) $stats['pending'] > 0): ?>
      <tr>
        <td>待执行</td>
        <td><span class="badge badge-err"><?= (int) $stats['pending'] ?> 条</span>
          <span class="dim">封禁/解封指令已下发，等脚本下一分钟消费</span></td>
      </tr>
      <?php endif; ?>
    </table>
  </div>
</div>
<!-- ============ 监控方式 ============ -->
<div class="card mb-16">
  <div class="card-head">监控方式</div>
  <div class="card-body">
    <div class="muted mb-8">
      检测由 shell 脚本完成，cron 每分钟跑一次。PHP 页面只负责配置和展示，
      不直接操作防火墙——给 Web 用户开 root 权限本身就是个风险点。
    </div>
    <table class="tbl">
      <thead><tr><th style="width:150px">检测项</th><th>做法</th><th style="width:70px">状态</th></tr></thead>
      <tbody>
        <tr>
          <td>SSH 暴力破解</td>
          <td class="dim">增量读取 <code><?= h($conf['intr_ssh_log']) ?></code>，
            统计 Failed password / Invalid user，同一 IP 在
            <?= (int) $conf['intr_ssh_win'] ?> 秒内失败超过 <?= (int) $conf['intr_ssh_max'] ?> 次即封禁</td>
          <td><?= $conf['intr_ssh'] === '1' ? '<span class="badge badge-ok">开</span>' : '<span class="badge badge-off">关</span>' ?></td>
        </tr>
        <tr>
          <td>陌生 IP 登录成功</td>
          <td class="dim">Accepted password/publickey 的来源 IP 不在历史记录里就告警。
            这条只提醒不封禁，防止把自己关在门外</td>
          <td><?= $conf['intr_ssh_newip'] === '1' ? '<span class="badge badge-ok">开</span>' : '<span class="badge badge-off">关</span>' ?></td>
        </tr>
        <tr>
          <td>Web 高频探测</td>
          <td class="dim">增量读取 <code><?= h($conf['intr_web_log']) ?></code>，
            同一 IP 在 <?= (int) $conf['intr_web_win'] ?> 秒内 4xx/5xx 超过
            <?= (int) $conf['intr_web_max'] ?> 次即封禁</td>
          <td><?= $conf['intr_web'] === '1' ? '<span class="badge badge-ok">开</span>' : '<span class="badge badge-off">关</span>' ?></td>
        </tr>
        <tr>
          <td>Web 攻击特征</td>
          <td class="dim">匹配 SQL 注入、路径穿越、命令执行、敏感文件探测等请求特征，命中即封禁</td>
          <td><?= $conf['intr_web_rule'] === '1' ? '<span class="badge badge-ok">开</span>' : '<span class="badge badge-off">关</span>' ?></td>
        </tr>
        <tr>
          <td>可疑 PHP 文件</td>
          <td class="dim">扫描 <code><?= h($conf['intr_scan_dir']) ?></code> 下
            <?= (int) $conf['intr_scan_min'] ?> 分钟内新增或改动的 PHP，
            匹配 eval、assert、system 等 webshell 常见写法。只告警不删文件</td>
          <td><?= $conf['intr_file'] === '1' ? '<span class="badge badge-ok">开</span>' : '<span class="badge badge-off">关</span>' ?></td>
        </tr>
        <tr>
          <td>SUID 提权文件</td>
          <td class="dim">对比基线，发现新增的 SUID/SGID 可执行文件就告警。这是提权后留后门的常见手法</td>
          <td><?= $conf['intr_suid'] === '1' ? '<span class="badge badge-ok">开</span>' : '<span class="badge badge-off">关</span>' ?></td>
        </tr>
        <tr>
          <td>计划任务改动</td>
          <td class="dim">对 crontab 和 /etc/cron.* 做指纹，内容变了就告警</td>
          <td><?= $conf['intr_cron'] === '1' ? '<span class="badge badge-ok">开</span>' : '<span class="badge badge-off">关</span>' ?></td>
        </tr>
        <tr>
          <td>可疑进程</td>
          <td class="dim">CPU 持续超过 <?= (int) $conf['intr_proc_cpu'] ?>% 的进程，
            或从 /tmp、/dev/shm 这类目录启动的进程（挖矿木马的典型特征）</td>
          <td><?= $conf['intr_proc'] === '1' ? '<span class="badge badge-ok">开</span>' : '<span class="badge badge-off">关</span>' ?></td>
        </tr>
        <tr>
          <td>系统文件改动</td>
          <td class="dim">监控 /etc/passwd、/etc/shadow、authorized_keys 等关键文件的指纹变化</td>
          <td><?= $conf['intr_sysfile'] === '1' ? '<span class="badge badge-ok">开</span>' : '<span class="badge badge-off">关</span>' ?></td>
        </tr>
      </tbody>
    </table>
    <div class="field mt-16">
      <div class="field-label">定时任务</div>
      <?php if ($hbFresh): ?>
        <div class="hint">已在正常运行，无需重复安装。</div>
      <?php else: ?>
        <div class="hint">
          脚本需要 root 权限执行，PHP 装不了 root 的 crontab。请 SSH 登录服务器执行下面这行：
        </div>
        <textarea class="textarea" rows="2" readonly onclick="this.select()"
          style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px"
        >( crontab -l 2>/dev/null | grep -v intrusion_monitor.sh ; echo "<?= h($CRON_LINE) ?>" ) | crontab -</textarea>
        <div class="hint">点一下会全选，复制粘贴到终端即可。装好后一分钟内这里的状态会变成「正常」。</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<form method="post" class="card mb-16">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="act" value="save">
  <div class="card-head">设置</div>
  <div class="card-body">
    <div class="field">
      <label><input type="checkbox" name="intr_enabled" value="1" <?= $conf['intr_enabled'] === '1' ? 'checked' : '' ?>>
        <strong>启用入侵监控</strong></label>
      <div class="hint">总开关。关掉后脚本仍被 cron 唤起，但不做任何检测，已有封禁也不会自动解除。</div>
    </div>
    <div class="field mt-16">
      <label><input type="checkbox" name="intr_block" value="1" <?= $conf['intr_block'] === '1' ? 'checked' : '' ?>>
        自动拦截</label>
      <div class="hint">关掉就只记录、只发邮件，不动防火墙。刚上线建议先关几天观察误报，确认阈值合适再开。</div>
    </div>
    <div class="form-grid mt-16">
      <div class="field">
        <div class="field-label">封禁时长（分钟）</div>
        <input class="input" type="number" name="intr_block_min" min="0" max="525600"
               value="<?= (int) $conf['intr_block_min'] ?>">
        <div class="hint">0 表示永久封禁。默认 1440，即一天。</div>
      </div>
      <div class="field">
        <div class="field-label">SSH 日志路径</div>
        <input class="input" type="text" name="intr_ssh_log" value="<?= h($conf['intr_ssh_log']) ?>">
        <div class="hint">CentOS 系是 /var/log/secure，Debian 系是 /var/log/auth.log。</div>
      </div>
    </div>
    <div class="field mt-16">
      <div class="field-label">
        IP 白名单
        <button type="button" class="btn btn-sm" style="margin-left:8px;padding:2px 8px;font-size:12px" onclick="document.getElementById('cdnGuideModal').style.display='block'">
          nginx 真实 IP 配置
        </button>
      </div>
      <textarea class="textarea" name="intr_whitelist" rows="3"
        placeholder="一行一条，支持单个 IP 和 CIDR 网段，例如 1.2.3.4 或 10.0.0.0/8"><?= h($conf['intr_whitelist']) ?></textarea>
      <div class="hint">白名单里的 IP 永不封禁。你自己的办公固定 IP 建议加进来，避免误判把自己关在门外。
        内网段和回环地址已内置保护，不用重复填。<br>
        <strong>CDN 用户注意</strong>：保存白名单后会自动同步到 nginx 的 <code>set_real_ip_from</code> 配置。</div>
    </div>
    <div class="field mt-16" style="border-top:1px solid #eee;padding-top:14px">
      <div class="field-label"><strong>检测项</strong></div>
      <div class="hint mb-8">按需开关。每一项都可以单独关掉，误报多的先关掉再慢慢调阈值。</div>
    </div>
    <div class="field">
      <label><input type="checkbox" name="intr_ssh" value="1" <?= $conf['intr_ssh'] === '1' ? 'checked' : '' ?>>
        SSH 暴力破解</label>
      <div class="form-grid mt-8">
        <div class="field">
          <div class="field-label">失败次数阈值</div>
          <input class="input" type="number" name="intr_ssh_max" min="2" max="100"
                 value="<?= (int) $conf['intr_ssh_max'] ?>">
        </div>
        <div class="field">
          <div class="field-label">统计窗口（秒）</div>
          <input class="input" type="number" name="intr_ssh_win" min="30" max="86400"
                 value="<?= (int) $conf['intr_ssh_win'] ?>">
        </div>
      </div>
      <div class="hint">同一 IP 在窗口内失败超过阈值就封。默认 300 秒内 5 次。</div>
    </div>
    <div class="field mt-16">
      <label><input type="checkbox" name="intr_ssh_newip" value="1" <?= $conf['intr_ssh_newip'] === '1' ? 'checked' : '' ?>>
        陌生 IP 登录成功告警</label>
      <div class="hint">有从没见过的 IP 成功登录 SSH 就发邮件。这一项只告警不封禁，因为登录成功往往就是你自己。
        密码泄露的话，这封邮件是最早的信号。</div>
    </div>
    <div class="field mt-16">
      <label><input type="checkbox" name="intr_web" value="1" <?= $conf['intr_web'] === '1' ? 'checked' : '' ?>>
        Web 高频探测</label>
      <div class="form-grid mt-8">
        <div class="field">
          <div class="field-label">404/403 次数阈值</div>
          <input class="input" type="number" name="intr_web_max" min="10" max="5000"
                 value="<?= (int) $conf['intr_web_max'] ?>">
        </div>
        <div class="field">
          <div class="field-label">统计窗口（秒）</div>
          <input class="input" type="number" name="intr_web_win" min="30" max="86400"
                 value="<?= (int) $conf['intr_web_win'] ?>">
        </div>
      </div>
      <div class="hint">扫目录、爆后台路径的特征就是短时间内大量 404。默认 120 秒内 60 次。</div>
    </div>
    <div class="field mt-16">
      <label><input type="checkbox" name="intr_web_rule" value="1" <?= $conf['intr_web_rule'] === '1' ? 'checked' : '' ?>>
        Web 攻击特征</label>
      <div class="hint">匹配 SQL 注入、路径穿越、命令执行等特征串，命中即封，不等次数累积。</div>
    </div>
    <div class="field mt-16">
      <div class="field-label">Web 日志路径</div>
      <input class="input" type="text" name="intr_web_log" value="<?= h($conf['intr_web_log']) ?>">
      <div class="hint">宝塔默认在 /www/wwwlogs/域名.log。路径不对这两项检测就是空转。</div>
    </div>
    <div class="field mt-16">
      <label><input type="checkbox" name="intr_file" value="1" <?= $conf['intr_file'] === '1' ? 'checked' : '' ?>>
        可疑 PHP 文件（webshell）</label>
      <div class="form-grid mt-8">
        <div class="field">
          <div class="field-label">扫描目录</div>
          <input class="input" type="text" name="intr_scan_dir" value="<?= h($conf['intr_scan_dir']) ?>">
        </div>
        <div class="field">
          <div class="field-label">扫描间隔（分钟）</div>
          <input class="input" type="number" name="intr_scan_min" min="1" max="1440"
                 value="<?= (int) $conf['intr_scan_min'] ?>">
        </div>
      </div>
      <div class="field mt-8">
        <div class="field-label">排除路径</div>
        <input class="input" type="text" name="intr_scan_exclude" value="<?= h($conf['intr_scan_exclude']) ?>">
        <div class="hint">逗号分隔。vendor、node_modules 这类目录里的第三方代码容易误报，建议排除。</div>
      </div>
      <div class="hint">找新增或被改动的 PHP 文件里的 eval、assert、system 等危险调用。
        这一项只告警不封禁，因为攻击者的 IP 未必还在连着。</div>
    </div>
    <div class="field mt-16">
      <label><input type="checkbox" name="intr_suid" value="1" <?= $conf['intr_suid'] === '1' ? 'checked' : '' ?>>
        新增 SUID 提权文件</label>
      <div class="field mt-8">
        <div class="field-label">检查间隔（分钟）</div>
        <input class="input" type="number" name="intr_suid_min" min="5" max="1440"
               value="<?= (int) $conf['intr_suid_min'] ?>">
      </div>
      <div class="hint">SUID 位的文件能以 root 身份运行，凭空多出来一个基本就是留后门。这是高危信号。</div>
    </div>
    <div class="field mt-16">
      <label><input type="checkbox" name="intr_cron" value="1" <?= $conf['intr_cron'] === '1' ? 'checked' : '' ?>>
        计划任务改动</label>
      <div class="hint">挖矿木马最爱往 crontab 里塞定时拉取。这里记录所有用户的 crontab 指纹变化。</div>
    </div>
    <div class="field mt-16">
      <label><input type="checkbox" name="intr_proc" value="1" <?= $conf['intr_proc'] === '1' ? 'checked' : '' ?>>
        可疑进程</label>
      <div class="field mt-8">
        <div class="field-label">CPU 占用阈值（%）</div>
        <input class="input" type="number" name="intr_proc_cpu" min="30" max="100"
               value="<?= (int) $conf['intr_proc_cpu'] ?>">
      </div>
      <div class="hint">长期高 CPU 且进程名可疑（随机字符串、藏在 /tmp 下）会告警。挖矿程序的典型特征。</div>
    </div>
    <div class="field mt-16">
      <label><input type="checkbox" name="intr_sysfile" value="1" <?= $conf['intr_sysfile'] === '1' ? 'checked' : '' ?>>
        系统关键文件改动</label>
      <div class="hint">监控 /etc/passwd、/etc/shadow、SSH 的 authorized_keys 等。
        被加了新账号或新公钥，这里会第一时间报出来。</div>
    </div>
    <div class="field mt-16" style="border-top:1px solid #eee;padding-top:14px">
      <div class="field-label"><strong>邮件通知</strong></div>
      <?php if (!$smtpOk): ?>
        <div class="alert alert-error">后台邮箱还没配置好，通知发不出去。
          请先到<a href="/admin/email_settings.php">邮箱管理</a>填 SMTP 服务器和账号。</div>
      <?php endif; ?>
    </div>
    <div class="field">
      <label><input type="checkbox" name="intr_mail" value="1" <?= $conf['intr_mail'] === '1' ? 'checked' : '' ?>>
        开启邮件通知</label>
      <div class="hint">独立开关，跟总开关分开。关掉之后照样检测、照样拦截，只是不发邮件。</div>
    </div>
    <div class="field mt-16">
      <div class="field-label">接收邮箱</div>
      <input class="input" type="text" name="intr_mail_to" value="<?= h($conf['intr_mail_to']) ?>"
             placeholder="多个用逗号分隔，最多 5 个">
      <div class="hint">留空则不发。用的是后台邮箱管理里配好的 SMTP 发件账号。</div>
    </div>
    <div class="form-grid mt-16">
      <div class="field">
        <div class="field-label">告警等级下限</div>
        <select class="input" name="intr_mail_level">
          <?php foreach ($levels as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $conf['intr_mail_level'] === $k ? 'selected' : '' ?>>
              <?= h($v) ?>及以上</option>
          <?php endforeach; ?>
        </select>
        <div class="hint">低于这个等级的事件只入库，不发邮件。</div>
      </div>
      <div class="field">
        <div class="field-label">同类事件冷却（秒）</div>
        <input class="input" type="number" name="intr_mail_cooldown" min="0" max="86400"
               value="<?= (int) $conf['intr_mail_cooldown'] ?>">
        <div class="hint">同一 IP 同一类型在冷却期内只发一封，防止被刷爆邮箱。默认 600 秒。</div>
      </div>
    </div>
    <div class="field mt-16">
      <div class="field-label">邮件标题</div>
      <input class="input" type="text" name="intr_mail_subject" value="<?= h($conf['intr_mail_subject']) ?>">
    </div>
    <div class="field mt-16">
      <div class="field-label">邮件正文</div>
      <textarea class="textarea" name="intr_mail_body" rows="12"><?= h($conf['intr_mail_body']) ?></textarea>
      <div class="hint">
        可用占位符，发送时自动替换：<br>
        <code>{site}</code> 站点名称 ·
        <code>{host}</code> 服务器主机名 ·
        <code>{time}</code> 发生时间 ·
        <code>{type}</code> 类型代码 ·
        <code>{type_name}</code> 类型中文名 ·
        <code>{level}</code> 等级代码 ·
        <code>{level_name}</code> 等级中文名 ·
        <code>{ip}</code> 来源 IP ·
        <code>{hits}</code> 触发次数 ·
        <code>{blocked}</code> 是否已拦截 ·
        <code>{target}</code> 涉及对象 ·
        <code>{detail}</code> 原始详情
      </div>
    </div>
    <div class="form-actions mt-16">
      <button class="btn btn-primary" type="submit">保存设置</button>
    </div>
  </div>
</form>
<form method="post" class="card mb-16">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="act" value="mailtest">
  <div class="card-head">发送测试邮件</div>
  <div class="card-body">
    <div class="hint mb-8">用当前的标题和正文模板发一封假事件到「接收邮箱」，占位符会用示例数据填充。
      改完模板先测一封，确认收得到再上线。</div>
    <div class="field-label">收件邮箱</div>
    <input class="input mb-8" type="text" name="to"
           value="<?= h((string) setting_get('intr_mail_to', '')) ?>"
           placeholder="留空则用上面配置的接收邮箱">
    <button class="btn" type="submit" <?= $smtpOk ? '' : 'disabled' ?>>发送测试邮件</button>
    <?php if (!$smtpOk): ?>
      <div class="hint">SMTP 没配好，按钮已禁用。</div>
    <?php endif; ?>
  </div>
</form>
<div class="card mb-16">
  <div class="card-head">封禁名单 <span class="head-count"><?= count($blocks) ?></span></div>
  <div class="card-body">
    <form method="post" class="rule-form" style="margin-bottom:14px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="act" value="block">
      <div class="rule-field">
        <div class="field-label">手动封禁 IP</div>
        <input class="input" type="text" name="ip" placeholder="1.2.3.4" required>
      </div>
      <div class="rule-field rule-field-wide">
        <div class="field-label">原因</div>
        <input class="input" type="text" name="reason" placeholder="选填，会记进事件">
      </div>
      <div class="rule-field">
        <div class="field-label">时长（分钟）</div>
        <input class="input" type="number" name="minutes" min="0" max="525600"
               value="<?= (int) $conf['intr_block_min'] ?>">
      </div>
      <div class="rule-field" style="display:flex;align-items:flex-end">
        <button class="btn btn-primary btn-sm" type="submit">封禁</button>
      </div>
    </form>
    <div class="hint mb-8">封禁和解封都是下发给 shell 脚本执行的，最多等一分钟生效。
      状态列显示的就是执行进度。</div>
    <?php if (!$blocks): ?>
      <div class="empty">还没有封禁记录。</div>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>IP</th><th>原因</th><th>来源</th><th>次数</th>
            <th>状态</th><th>到期</th><th>更新时间</th><th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($blocks as $b):
          $st = (string) $b['state'];
          $stMap = [
            'pending'     => ['待执行',  'badge'],
            'active'      => ['已封禁',  'badge badge-err'],
            'unblock_req' => ['待解封',  'badge'],
            'released'    => ['已解封',  'badge badge-off'],
            'failed'      => ['执行失败', 'badge badge-err'],
          ];
          [$stText, $stCls] = $stMap[$st] ?? [$st, 'badge'];
          $exp = (string) ($b['expires_at'] ?? '');
        ?>
          <tr>
            <td style="font-family:ui-monospace,Menlo,Consolas,monospace"><?= h((string) $b['ip']) ?></td>
            <td><?= h((string) $b['reason']) ?>
              <?php if (!empty($b['err'])): ?>
                <div class="dim" style="color:#d03050;font-size:12px"><?= h((string) $b['err']) ?></div>
              <?php endif; ?>
            </td>
            <td class="dim"><?= $b['source'] === 'manual' ? '手动' : '自动' ?></td>
            <td><?= (int) $b['hits'] ?></td>
            <td><span class="<?= $stCls ?>"><?= h($stText) ?></span></td>
            <td class="dim"><?= $exp === '' || $exp === '0000-00-00 00:00:00' ? '永久' : h($exp) ?></td>
            <td class="dim"><?= h((string) $b['updated_at']) ?></td>
            <td class="acts">
              <?php if ($st === 'active' || $st === 'pending'): ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="act" value="unblock">
                  <input type="hidden" name="ip" value="<?= h((string) $b['ip']) ?>">
                  <button class="btn btn-sm" type="submit">解封</button>
                </form>
              <?php endif; ?>
              <form method="post" class="inline-form"
                    onsubmit="return confirm('删掉这条记录？如果还在封禁中，请先解封，否则防火墙规则会留在系统里。')">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="act" value="bdel">
                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">删除</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<div class="card mb-16">
  <div class="card-head">事件记录 <span class="head-count"><?= $evTotal ?></span></div>
  <div class="card-body">
    <form method="get" class="rule-form" style="margin-bottom:14px">
      <div class="rule-field">
        <div class="field-label">类型</div>
        <select class="input" name="etype">
          <option value="">全部</option>
          <?php foreach ($types as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $fType === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rule-field">
        <div class="field-label">等级</div>
        <select class="input" name="level">
          <option value="">全部</option>
          <?php foreach ($levels as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $fLv === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rule-field">
        <div class="field-label">IP</div>
        <input class="input" type="text" name="ip" value="<?= h($fIp) ?>" placeholder="支持部分匹配">
      </div>
      <div class="rule-field" style="display:flex;align-items:flex-end;gap:6px">
        <button class="btn btn-sm btn-primary" type="submit">筛选</button>
        <a class="btn btn-sm" href="/admin/intrusion.php">重置</a>
      </div>
    </form>
    <?php if (!$events): ?>
      <div class="empty"><?= $evTotal === 0 && $fType === '' && $fLv === '' && $fIp === ''
        ? '还没有事件记录。监控跑起来之后异常会显示在这里。' : '没有符合条件的记录。' ?></div>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>时间</th><th>等级</th><th>类型</th><th>IP</th>
            <th>次数</th><th>详情</th><th>拦截</th><th>通知</th><th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $e):
          $lv    = (string) $e['level'];
          $lvCls = $lv === 'high' ? 'badge badge-err' : ($lv === 'mid' ? 'badge' : 'badge badge-off');
          $ip    = (string) $e['ip'];
        ?>
          <tr>
            <td class="dim" style="white-space:nowrap"><?= h((string) $e['created_at']) ?></td>
            <td><span class="<?= $lvCls ?>"><?= h($levels[$lv] ?? $lv) ?></span></td>
            <td><?= h($types[(string) $e['etype']] ?? (string) $e['etype']) ?></td>
            <td style="font-family:ui-monospace,Menlo,Consolas,monospace"><?= $ip === '' ? '—' : h($ip) ?></td>
            <td><?= (int) $e['hits'] ?></td>
            <td style="max-width:360px">
              <?php if (!empty($e['target'])): ?>
                <div style="font-size:12px;word-break:break-all"><?= h((string) $e['target']) ?></div>
              <?php endif; ?>
              <?php if (!empty($e['detail'])): ?>
                <div class="dim" style="font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:80px;overflow:auto"><?= h((string) $e['detail']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= (int) $e['blocked'] === 1
                  ? '<span class="badge badge-err">已拦截</span>'
                  : '<span class="badge badge-off">未拦截</span>' ?></td>
            <td>
              <?php if ((int) $e['notified'] === 1): ?>
                <span class="badge badge-ok">已发</span>
              <?php elseif (!empty($e['notify_err'])): ?>
                <span class="badge badge-err" title="<?= h((string) $e['notify_err']) ?>">失败</span>
              <?php else: ?>
                <span class="dim">—</span>
              <?php endif; ?>
            </td>
            <td class="acts">
              <?php if ($ip !== '' && (int) $e['blocked'] !== 1): ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="act" value="block">
                  <input type="hidden" name="ip" value="<?= h($ip) ?>">
                  <input type="hidden" name="reason" value="从事件记录手动封禁">
                  <input type="hidden" name="minutes" value="<?= (int) $conf['intr_block_min'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">封禁</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($evPages > 1):
        $qs = function (int $p) use ($fType, $fLv, $fIp) {
            return '?' . http_build_query(array_filter([
                'etype' => $fType, 'level' => $fLv, 'ip' => $fIp, 'p' => $p,
            ], fn($v) => $v !== '' && $v !== 0));
        };
      ?>
        <div class="mt-16" style="display:flex;gap:8px;align-items:center">
          <?php if ($page > 1): ?>
            <a class="btn btn-sm" href="<?= h($qs($page - 1)) ?>">上一页</a>
          <?php endif; ?>
          <span class="dim">第 <?= $page ?> / <?= $evPages ?> 页</span>
          <?php if ($page < $evPages): ?>
            <a class="btn btn-sm" href="<?= h($qs($page + 1)) ?>">下一页</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <form method="post" class="rule-form mt-16"
          style="border-top:1px solid #eee;padding-top:14px"
          onsubmit="return confirm('确认清理？删掉的记录找不回来。')">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="act" value="evclear">
      <div class="rule-field">
        <div class="field-label">清理历史事件</div>
        <select class="input" name="days">
          <option value="90">90 天前</option>
          <option value="30" selected>30 天前</option>
          <option value="7">7 天前</option>
          <option value="0">全部清空</option>
        </select>
      </div>
      <div class="rule-field" style="display:flex;align-items:flex-end">
        <button class="btn btn-sm btn-danger" type="submit">清理</button>
      </div>
    </form>
  </div>
</div>
<!-- CDN 配置说明弹窗 -->
<div id="cdnGuideModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:9999;overflow:auto" onclick="if(event.target===this) this.style.display='none'">
  <div style="max-width:800px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.3)" onclick="event.stopPropagation()">
    <div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between">
      <h3 style="margin:0;font-size:18px;font-weight:600">迁移服务器时如何配置 nginx 获取真实访客 IP</h3>
      <button type="button" onclick="document.getElementById('cdnGuideModal').style.display='none'" style="border:none;background:none;font-size:24px;color:#999;cursor:pointer;padding:0;width:32px;height:32px;line-height:1">&times;</button>
    </div>
    <div style="padding:24px;line-height:1.7;color:#374151">
      
      <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;margin-bottom:20px;border-radius:4px">
        <strong>⚠️ 为什么需要配置</strong><br>
        使用 CDN 时，nginx 默认记录的是 CDN 节点 IP，不是真实访客。攻击者可以伪造 <code>X-Forwarded-For</code> 头绕过检测。必须明确告诉 nginx 哪些 IP 是可信的 CDN 节点。
      </div>

      <h4 style="margin:24px 0 12px;font-size:15px;font-weight:600">第一步：确认本站已配置的 CDN IP 段</h4>
      <p>当前入侵监控白名单里的 CDN IP 段会自动同步到 nginx。查看当前配置：</p>
      <pre style="background:#f3f4f6;padding:12px;border-radius:6px;overflow-x:auto;font-size:13px">cat /www/server/panel/vhost/nginx/includes/cdn_trust.conf</pre>

      <h4 style="margin:24px 0 12px;font-size:15px;font-weight:600">第二步：新服务器上的 nginx 配置</h4>
      <p>迁移到新服务器时，需要在 nginx 站点配置文件里加入以下内容（放在 <code>server {</code> 块内，<code>location</code> 之前）：</p>
      <pre style="background:#f3f4f6;padding:12px;border-radius:6px;overflow-x:auto;font-size:13px"># CDN 真实 IP 配置（自动同步）
include /www/server/panel/vhost/nginx/includes/cdn_trust.conf;</pre>

      <h4 style="margin:24px 0 12px;font-size:15px;font-weight:600">第三步：创建自动同步脚本</h4>
      <p>在新服务器的项目 <code>tools/</code> 目录下，创建 <code>sync_cdn_trust.php</code>（内容见下方），并赋予执行权限：</p>
      <pre style="background:#f3f4f6;padding:12px;border-radius:6px;overflow-x:auto;font-size:13px">chmod +x /www/wwwroot/你的站点目录/tools/sync_cdn_trust.php
php /www/wwwroot/你的站点目录/tools/sync_cdn_trust.php</pre>

      <h4 style="margin:24px 0 12px;font-size:15px;font-weight:600">第四步：修改后台保存逻辑</h4>
      <p>在 <code>admin/intrusion.php</code> 里保存白名单后（<code>setting_set('intr_whitelist', ...)</code> 那一行），加上自动同步调用：</p>
      <pre style="background:#f3f4f6;padding:12px;border-radius:6px;overflow-x:auto;font-size:13px">// 同步白名单到 nginx 配置
exec("/usr/bin/php " . APP_ROOT . "/tools/sync_cdn_trust.php >/dev/null 2>&1 &");</pre>

      <h4 style="margin:24px 0 12px;font-size:15px;font-weight:600">完整的 sync_cdn_trust.php 脚本</h4>
      <textarea readonly onclick="this.select()" style="width:100%;height:320px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;padding:12px;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb;resize:vertical">#!/usr/bin/env php
&lt;?php
require_once dirname(__DIR__) . '/inc/helpers.php';

$OUTPUT_FILE = '/www/server/panel/vhost/nginx/includes/cdn_trust.conf';
$NGINX_BIN   = '/www/server/nginx/sbin/nginx';

$raw = db_val("SELECT v FROM settings WHERE k = 'intr_whitelist'") ?: '';
$lines = preg_split('/[\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

$ips = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (preg_match('/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/', $line)) {
        $ips[] = $line;
    }
}

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

$dir = dirname($OUTPUT_FILE);
if (!is_dir($dir)) mkdir($dir, 0755, true);

file_put_contents($OUTPUT_FILE, $content);
exec("$NGINX_BIN -t 2>&1", $output, $code);
if ($code !== 0) exit(2);
exec("$NGINX_BIN -s reload 2>&1");
echo "nginx 已重载\n";</textarea>

      <div style="background:#dbeafe;border-left:4px solid #3b82f6;padding:12px 16px;margin-top:20px;border-radius:4px">
        <strong>✅ 配置完成后</strong><br>
        以后在后台「入侵监控」页修改白名单并保存，nginx 的 <code>set_real_ip_from</code> 会自动更新，入侵检测和真实 IP 获取始终保持一致。
      </div>

      <h4 style="margin:24px 0 12px;font-size:15px;font-weight:600">如何验证配置生效</h4>
      <ol style="margin:0;padding-left:24px">
        <li>保存白名单后，检查自动生成的配置：<code>cat /www/server/panel/vhost/nginx/includes/cdn_trust.conf</code></li>
        <li>查看 nginx 访问日志，确认记录的是真实访客 IP，而不是 CDN 节点 IP</li>
        <li>测试入侵检测是否能正确识别攻击来源</li>
      </ol>

    </div>
    <div style="padding:16px 24px;border-top:1px solid #e5e7eb;text-align:right">
      <button type="button" class="btn btn-primary" onclick="document.getElementById('cdnGuideModal').style.display='none'">知道了</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
