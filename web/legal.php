<?php
/**
 * 隐私条款 / 服务条款展示页。
 *
 * 正文存在 settings 表里，由后台「站点设置 → 页脚与合规信息」填写，
 * 不做成静态 HTML 是为了让客户自己随时改，不用碰代码。
 *
 * 只认 ?t=privacy 和 ?t=service 两种，其他值一律当隐私条款处理，
 * 避免拿参数去拼别的 setting key 把无关配置读出来。
 */
require_once __DIR__ . '/inc/helpers.php';

$类型 = ($_GET['t'] ?? '') === 'service' ? 'service' : 'privacy';
$标题 = $类型 === 'service' ? '服务条款' : '隐私条款';
$正文 = trim((string) setting_get('terms_' . $类型, ''));
$站名 = app_name();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($标题) ?> - <?= h($站名) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<style>
  .legal-wrap { max-width: 800px; margin: 0 auto; padding: 40px 20px 60px; }
  .legal-nav { margin-bottom: 24px; font-size: 14px; }
  .legal-nav a { color: #6366f1; text-decoration: none; }
  .legal-nav a:hover { text-decoration: underline; }
  .legal-title { font-size: 28px; font-weight: 600; color: #111827; margin: 0 0 20px; }
  /* pre-wrap 保留后台填的换行和空行，同时长行还能自动折行 */
  .legal-body { font-size: 15px; line-height: 1.9; color: #374151;
    white-space: pre-wrap; word-break: break-word; }
  .legal-empty { color: #9ca3af; font-size: 15px; }
</style>
</head>
<body style="background:#f8fafc;margin:0">
<div class="legal-wrap">
  <div class="legal-nav"><a href="/">← 返回首页</a></div>
  <h1 class="legal-title"><?= h($标题) ?></h1>
  <?php if ($正文 === ''): ?>
    <div class="legal-empty">管理员还没有填写<?= h($标题) ?>内容。</div>
  <?php else: ?>
    <div class="legal-body"><?= h($正文) ?></div>
  <?php endif; ?>
</div>
</body>
</html>
