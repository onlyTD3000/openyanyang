<?php
$adminOn = 'email';
$pageTitle = '邮箱设置';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/email.php';
$me = require_admin();

$msg = $err = '';

// 邮件配置项
$emailKeys = [
    'email_verify_enabled' => ['启用邮箱验证', 'bool', '注册时必须通过邮箱验证才能使用'],
    'email_smtp_host'      => ['SMTP 服务器地址', 'text', '如 smtp.qq.com、smtp.gmail.com'],
    'email_smtp_port'      => ['SMTP 端口', 'int', '465(SSL) / 587(TLS) / 25(无加密)'],
    'email_smtp_encrypt'   => ['加密方式', 'select', ''],
    'email_smtp_user'      => ['SMTP 账号', 'text', '一般为邮箱地址'],
    'email_smtp_pass'      => ['SMTP 密码', 'password', 'QQ邮箱用授权码，非登录密码'],
    'email_from'           => ['发件人地址', 'text', '留空则使用 SMTP 账号'],
    'email_from_name'      => ['发件人名称', 'text', '显示在邮件"发件人"处'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();

    if (($_POST['act'] ?? '') === 'save') {
        foreach ($emailKeys as $k => $conf) {
            $type = $conf[1];
            if ($type === 'bool') {
                setting_set($k, isset($_POST[$k]) ? '1' : '0');
            } elseif ($type === 'int') {
                setting_set($k, (string) max(1, (int) ($_POST[$k] ?? 1)));
            } else {
                setting_set($k, trim((string) ($_POST[$k] ?? '')));
            }
        }
        $msg = '邮件设置已保存';
    } elseif (($_POST['act'] ?? '') === 'test') {
        // 发送测试邮件
        $testTo = trim((string) ($_POST['test_email'] ?? ''));
        if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $err = '请输入有效的测试邮箱地址';
        } else {
            $siteName = setting_get('site_name', '云智 AI');
            $body = '<h2>测试邮件</h2><p>这是一封来自 <strong>' . h($siteName) . '</strong> 的测试邮件。</p>'
                  . '<p>如果你收到此邮件，说明 SMTP 配置正确，邮件服务已就绪。</p>'
                  . '<p style="color:#9ca3af;font-size:13px">发送时间：' . date('Y-m-d H:i:s') . '</p>';
            [$ok, $sendErr] = email_send($testTo, '[' . $siteName . '] 测试邮件', $body, '后台测试', (int) $me['id']);
            if ($ok) {
                $msg = '测试邮件已发送至 ' . h($testTo) . '，请检查收件箱';
            } else {
                $err = '发送失败：' . $sendErr;
            }
        }
    }

    flash_set($err !== '' ? 'error' : 'ok', $err !== '' ? $err : $msg);
    redirect_self();
}

[$flashT, $flashM] = flash_get();
if ($flashT === 'error') $err = $flashM;
elseif ($flashT === 'ok') $msg = $flashM;

$csrf = csrf_token();
require __DIR__ . '/_head.php';
$cur = settings_all();
?>
<div class="page-head">
  <h1 class="page-title">邮箱设置</h1>
  <div class="page-actions">
    <a href="/admin/email_logs.php" class="btn btn-secondary">查看邮件日志</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<div class="form-grid" style="align-items:start">

  <!-- SMTP 配置 -->
  <div class="card" style="grid-column: 1 / -1;">
    <div class="card-head">SMTP 邮件服务器配置</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="save">
        <div class="form-grid">
          <?php foreach ($emailKeys as $k => $conf): ?>
            <?php $label = $conf[0]; $type = $conf[1]; $note = $conf[2] ?? ''; ?>
            <label class="field">
              <span class="field-label"><?= h($label) ?></span>
              <?php if ($type === 'bool'): ?>
                <span><input type="checkbox" name="<?= h($k) ?>" value="1"
                  <?= ($cur[$k] ?? '0') === '1' ? 'checked' : '' ?>> <?= h($note) ?></span>
              <?php elseif ($type === 'select'): ?>
                <select class="input" name="<?= h($k) ?>">
                  <option value="ssl"  <?= ($cur[$k] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                  <option value="tls"  <?= ($cur[$k] ?? '') === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
                  <option value="none" <?= ($cur[$k] ?? '') === 'none' ? 'selected' : '' ?>>无加密 (25)</option>
                </select>
              <?php elseif ($type === 'password'): ?>
                <input class="input" type="password" name="<?= h($k) ?>" value="<?= h($cur[$k] ?? '') ?>"
                       placeholder="<?= h($note) ?>" autocomplete="off">
              <?php elseif ($type === 'int'): ?>
                <input class="input" type="number" min="1" max="65535" name="<?= h($k) ?>"
                       value="<?= h($cur[$k] ?? '465') ?>">
                <?php if ($note): ?><div class="hint"><?= h($note) ?></div><?php endif; ?>
              <?php else: ?>
                <input class="input" name="<?= h($k) ?>" value="<?= h($cur[$k] ?? '') ?>"
                       placeholder="<?= h($note) ?>">
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存设置</button></div>
      </form>
    </div>
  </div>

  <!-- 测试发送 -->
  <div class="card">
    <div class="card-head">发送测试邮件</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="test">
        <label class="field">
          <span class="field-label">测试收件邮箱</span>
          <input class="input" type="email" name="test_email" required placeholder="your@email.com">
        </label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">发送测试邮件</button></div>
      </form>
    </div>
  </div>

  <!-- 说明 -->
  <div class="card">
    <div class="card-head">常见邮箱 SMTP 配置参考</div>
    <div class="card-body">
      <!-- 手机端屏幕窄，表格列多会被裁切，包一层横向滚动容器，可左右滑动查看完整内容 -->
      <div style="overflow-x:auto; -webkit-overflow-scrolling:touch;">
        <table class="tbl" style="min-width:560px;">
          <thead><tr><th>邮箱</th><th>SMTP 地址</th><th>端口</th><th>加密</th><th>说明</th></tr></thead>
          <tbody>
            <tr><td>QQ 邮箱</td><td>smtp.qq.com</td><td>465</td><td>SSL</td><td>需开启 SMTP 服务，使用授权码</td></tr>
            <tr><td>163 邮箱</td><td>smtp.163.com</td><td>465</td><td>SSL</td><td>需开启 SMTP 服务，使用授权码</td></tr>
            <tr><td>Gmail</td><td>smtp.gmail.com</td><td>587</td><td>TLS</td><td>需开启两步验证 + 应用专用密码</td></tr>
            <tr><td>Outlook</td><td>smtp-mail.outlook.com</td><td>587</td><td>TLS</td><td>使用账号密码登录</td></tr>
            <tr><td>阿里企业邮</td><td>smtp.qiye.aliyun.com</td><td>465</td><td>SSL</td><td>使用账号密码登录</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
<?php require __DIR__ . '/_foot.php'; ?>