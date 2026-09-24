<?php
/**
 * 「我的服务器」页面：登记自己的服务器、测试连通、查看执行记录。
 * 所有数据都限定在当前登录用户名下。
 */
require_once __DIR__ . '/inc/helpers.php';
$me    = require_login();
$navOn = 'servers';

$hosts = db_all('SELECT id, name, host, port, username, auth_type, status,
                        fingerprint, last_ok_at, last_error, created_at
                 FROM ssh_hosts WHERE user_id=? ORDER BY id DESC', [$me['id']]);
$logs = db_all('SELECT l.id, l.command, l.risk, l.deny_reason, l.exit_code,
                       l.duration_ms, l.created_at, h.name host_name
                FROM ssh_logs l LEFT JOIN ssh_hosts h ON h.id=l.host_id
                WHERE l.user_id=? ORDER BY l.id DESC LIMIT 30', [$me['id']]);
$siteName = app_name();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var ls=localStorage.getItem('theme');if(ls==='dark'||(!ls&&<?= (int)($me['dark_mode'] ?? 0) ?>))document.documentElement.classList.add('dark');})();</script>
<title>我的服务器 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>
<div class="app">
<?php require __DIR__ . '/inc/topbar.php'; ?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title">我的服务器</h1>
    <button class="btn btn-primary" id="btnAdd" type="button">登记服务器</button>
  </div>

  <div class="notice notice-warn" role="note">
    <strong>安全说明</strong>
    <ul class="notice-list">
      <li>登记后，对话中的 AI 可以提出要执行的命令，<b>但必须你点确认才会真正执行</b>。</li>
      <li>删除数据、格式化磁盘、改系统账号、反弹 shell 等危险命令<b>永久拒绝</b>，确认也不执行。</li>
      <li>凭据采用 AES-256-GCM 加密存储。建议使用<b>权限受限的专用账号</b>，不要用 root。</li>
      <li>每条命令都会记录在下方执行记录里，包含时间、命令原文与返回结果。</li>
      <li>不支持连接内网地址。只能操作你自己登记的机器。</li>
    </ul>
  </div>

  <div class="card">
    <div class="card-head"><h2 class="card-title">已登记的服务器</h2></div>
    <?php if (!$hosts): ?>
      <div class="empty">还没有登记服务器。点右上角「登记服务器」添加。</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr>
          <th>备注名</th><th>地址</th><th>登录用户</th><th>方式</th>
          <th>状态</th><th>最近连通</th><th>操作</th>
        </tr></thead>
        <tbody>
        <?php foreach ($hosts as $x): ?>
          <tr>
            <td><b><?= h($x['name']) ?></b></td>
            <td class="mono"><?= h($x['host']) ?>:<?= (int) $x['port'] ?></td>
            <td class="mono"><?= h($x['username']) ?></td>
            <td><?= $x['auth_type'] === 'key' ? '密钥' : '密码' ?></td>
            <td>
              <?php if ($x['last_error'] !== ''): ?>
                <span class="badge badge-red" title="<?= h($x['last_error']) ?>">异常</span>
              <?php elseif ($x['last_ok_at']): ?>
                <span class="badge badge-green">正常</span>
              <?php else: ?>
                <span class="badge">未测试</span>
              <?php endif; ?>
            </td>
            <td class="dim"><?= $x['last_ok_at'] ? h($x['last_ok_at']) : '—' ?></td>
            <td class="nowrap">
              <button class="btn btn-sm" type="button"
                      data-test="<?= (int) $x['id'] ?>">测试连接</button>
              <button class="btn btn-sm" type="button"
                      data-term="<?= (int) $x['id'] ?>"
                      data-tname="<?= h($x['name']) ?>">网页终端</button>
              <button class="btn btn-sm" type="button"
                      data-edit="<?= (int) $x['id'] ?>"
                      data-name="<?= h($x['name']) ?>"
                      data-host="<?= h($x['host']) ?>"
                      data-port="<?= (int) $x['port'] ?>"
                      data-user="<?= h($x['username']) ?>"
                      data-auth="<?= h($x['auth_type']) ?>">编辑</button>
              <button class="btn btn-sm btn-danger" type="button"
                      data-del="<?= (int) $x['id'] ?>"
                      data-dname="<?= h($x['name']) ?>">删除</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card mt-16">
    <div class="card-head"><h2 class="card-title">执行记录（最近 30 条）</h2></div>
    <?php if (!$logs): ?>
      <div class="empty">还没有执行记录。</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr>
          <th>时间</th><th>服务器</th><th>命令</th><th>结果</th><th>耗时</th>
        </tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="dim nowrap"><?= h($l['created_at']) ?></td>
            <td><?= h($l['host_name'] ?? '—') ?></td>
            <td class="mono cmd-cell" title="<?= h($l['command']) ?>"><?= h(mb_substr($l['command'], 0, 70)) ?></td>
            <td>
              <?php if ($l['risk'] === 'denied'): ?>
                <span class="badge badge-red">已拒绝</span>
                <span class="dim"><?= h($l['deny_reason']) ?></span>
              <?php elseif ((int) $l['exit_code'] === 0): ?>
                <span class="badge badge-green">成功</span>
              <?php else: ?>
                <span class="badge badge-orange">退出码 <?= (int) $l['exit_code'] ?></span>
              <?php endif; ?>
            </td>
            <td class="dim nowrap"><?= (int) $l['duration_ms'] ?> ms</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
</div>

<?php require __DIR__ . '/inc/servers_dialog.php'; ?>

<!-- 网页终端弹层。默认隐藏，点「网页终端」才建连接。 -->
<div class="term-mask" id="termMask" hidden>
  <div class="term-box" role="dialog" aria-modal="true" aria-label="网页终端">
    <div class="term-head">
      <span class="term-title" id="termTitle">网页终端</span>
      <span class="term-stat" id="termStat">连接中…</span>
      <button class="btn btn-sm" type="button" id="termClose">关闭</button>
    </div>
    <div class="term-body" id="termBody" tabindex="0"></div>
    <div class="term-foot">
      <input class="term-input" id="termInput" type="text" autocomplete="off"
             spellcheck="false" placeholder="输入命令后回车执行，↑↓ 翻历史">
      <button class="btn btn-sm" type="button" id="termSend">发送</button>
    </div>
  </div>
</div>

<script>window.CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="<?= asset('/assets/js/servers.js') ?>"></script>
<script src="<?= asset('/assets/js/web_term.js') ?>"></script>
</body>
</html>
