<?php
require_once __DIR__ . '/inc/helpers.php';

$me = current_user();
if (!$me) {
    header('Location: /index.php');
    exit;
}

$siteName = app_name();
$csrf = csrf_token();
$navOn = 'models';
$showSideToggle = false;

// 取启用渠道下的启用模型
$models = db_all(
    'SELECT m.id, m.display_name, m.model_name, m.price_in, m.price_out,
            m.price_cache, m.price_cache_create, m.vision, m.max_context, m.max_tokens
       FROM models m JOIN channels c ON c.id = m.channel_id
      WHERE m.status = 1 AND c.status = 1
      ORDER BY m.sort DESC, m.id ASC'
);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var ls=localStorage.getItem('theme');if(ls==='dark'||(!ls&&<?= (int)($me['dark_mode'] ?? 0) ?>))document.documentElement.classList.add('dark');})();</script>
<title>模型广场 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<style>
.models-page { max-width: 1200px; margin: 0 auto; padding: 24px 16px 60px; }
.models-header { text-align: center; margin-bottom: 32px; }
.models-header h1 { font-size: 28px; margin: 0 0 8px; }
.models-header p { color: var(--text2, #888); font-size: 14px; margin: 0; }
.models-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap; }
.models-search { flex: 1; min-width: 200px; max-width: 360px; }
.models-search input {
  width: 100%; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border, #ddd);
  background: var(--bg, #fff); color: var(--text, #333); font-size: 14px; outline: none;
  transition: border-color .2s;
}
.models-search input:focus { border-color: var(--primary, #6366f1); }
.models-doc-link {
  display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
  border-radius: 8px; background: var(--primary, #6366f1); color: #fff;
  font-size: 14px; text-decoration: none; white-space: nowrap; transition: opacity .2s;
}
.models-doc-link:hover { opacity: .88; }
.models-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border, #e0e0e0); }
.models-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 760px; }
.models-table th {
  background: var(--bg2, #f5f5f5); color: var(--text2, #666); font-weight: 600;
  padding: 12px 16px; text-align: left; white-space: nowrap; border-bottom: 1px solid var(--border, #e0e0e0);
}
.models-table th:first-child { border-top-left-radius: 12px; }
.models-table th:last-child { border-top-right-radius: 12px; }
.models-table td {
  padding: 14px 16px; border-bottom: 1px solid var(--border, #f0f0f0);
  color: var(--text, #333); vertical-align: middle;
}
.models-table tr:last-child td { border-bottom: none; }
.models-table tr:hover td { background: var(--bg2, #fafafa); }
.model-display { font-weight: 600; font-size: 15px; }
.model-name-cell { display: flex; align-items: center; gap: 8px; }
.model-name-text {
  font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace; font-size: 13px;
  color: var(--text2, #666); background: var(--bg2, #f5f5f5); padding: 3px 8px;
  border-radius: 5px; user-select: all; cursor: text;
}
.model-copy-btn {
  background: none; border: 1px solid var(--border, #ddd); border-radius: 6px;
  padding: 3px 10px; font-size: 12px; cursor: pointer; color: var(--text2, #666);
  transition: all .2s; white-space: nowrap;
}
.model-copy-btn:hover { border-color: var(--primary, #6366f1); color: var(--primary, #6366f1); }
.model-copy-btn.copied { background: #22c55e; border-color: #22c55e; color: #fff; }
.model-vision-badge {
  display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 10px;
  background: rgba(99,102,241,.12); color: #6366f1; margin-left: 6px; vertical-align: middle;
}
.price-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.price-zero { color: var(--text3, #aaa); }
.price-unit { font-size: 11px; color: var(--text3, #aaa); margin-left: 2px; }
.models-empty { text-align: center; padding: 60px 20px; color: var(--text2, #888); }
.dark .models-search input { background: var(--bg, #1a1a2e); border-color: var(--border, #333); color: var(--text, #e0e0e0); }
.dark .models-table th { background: var(--bg2, #1e1e32); border-color: var(--border, #333); color: var(--text2, #999); }
.dark .models-table td { border-color: var(--border, #2a2a3e); color: var(--text, #e0e0e0); }
.dark .models-table tr:hover td { background: var(--bg2, #1a1a2e); }
.dark .model-name-text { background: var(--bg2, #1e1e32); color: var(--text2, #999); }
.dark .model-copy-btn { border-color: var(--border, #333); color: var(--text2, #999); }
.dark .model-copy-btn:hover { border-color: var(--primary, #6366f1); color: var(--primary, #818cf8); }
@media (max-width: 640px) {
  .models-page { padding: 16px 12px 40px; }
  .models-header h1 { font-size: 22px; }
  .models-toolbar { flex-direction: column; align-items: stretch; }
  .models-search { max-width: none; }
}
</style>
</head>
<body>
<?php require __DIR__ . '/inc/topbar.php'; ?>

<div class="models-page">
  <div class="models-header">
    <h1>模型广场</h1>
    <p>所有可用模型及价格一览 · 价格单位为 CNY / 1M tokens（1M = 百万 token）</p>
  </div>

  <div class="models-toolbar">
    <div class="models-search">
      <input type="text" id="modelSearch" placeholder="搜索模型名称…" autocomplete="off">
    </div>
    <a class="models-doc-link" href="/v1/docs.php">📄 开发文档</a>
  </div>

  <?php if ($models): ?>
  <div class="models-table-wrap">
    <table class="models-table" id="modelsTable">
      <thead>
        <tr>
          <th>模型</th>
          <th>模型名称</th>
          <th>输入</th>
          <th>缓存读取</th>
          <th>缓存写入</th>
          <th>输出</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($models as $m): ?>
        <tr class="model-row" data-name="<?= h(strtolower($m['display_name'] . ' ' . $m['model_name'])) ?>">
          <td>
            <span class="model-display"><?= h($m['display_name']) ?></span>
            <?php if ((int)$m['vision'] === 1): ?><span class="model-vision-badge">视觉</span><?php endif; ?>
          </td>
          <td>
            <div class="model-name-cell">
              <span class="model-name-text" data-copy="<?= h($m['display_name']) ?>"><?= h($m['display_name']) ?></span>
              <button class="model-copy-btn" type="button" data-copy-target="<?= h($m['display_name']) ?>">复制</button>
            </div>
          </td>
          <td class="price-cell">
            <?php $pin = (float)$m['price_in']; ?>
            <span class="<?= $pin == 0 ? 'price-zero' : '' ?>">￥<?= number_format($pin, 2) ?></span>
            <span class="price-unit">/1M</span>
          </td>
          <td class="price-cell">
            <?php $pc = (float)$m['price_cache']; ?>
            <span class="<?= $pc == 0 ? 'price-zero' : '' ?>"><?= $pc > 0 ? '￥' . number_format($pc, 2) : '—' ?></span>
            <?php if ($pc > 0): ?><span class="price-unit">/1M</span><?php endif; ?>
          </td>
          <td class="price-cell">
            <?php $pcc = (float)$m['price_cache_create']; ?>
            <span class="<?= $pcc == 0 ? 'price-zero' : '' ?>"><?= $pcc > 0 ? '￥' . number_format($pcc, 2) : '—' ?></span>
            <?php if ($pcc > 0): ?><span class="price-unit">/1M</span><?php endif; ?>
          </td>
          <td class="price-cell">
            <?php $pout = (float)$m['price_out']; ?>
            <span class="<?= $pout == 0 ? 'price-zero' : '' ?>">￥<?= number_format($pout, 2) ?></span>
            <span class="price-unit">/1M</span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="models-empty">暂无可用模型，请等待管理员在后台配置。</div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.model-copy-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var name = this.getAttribute('data-copy-target');
    var fallback = function() {
      var ta = document.createElement('textarea');
      ta.value = name;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch(e) {}
      document.body.removeChild(ta);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(name).catch(fallback);
    } else {
      fallback();
    }
    var orig = this.textContent;
    this.textContent = '已复制 ✓';
    this.classList.add('copied');
    var self = this;
    setTimeout(function() {
      self.textContent = orig;
      self.classList.remove('copied');
    }, 1500);
  });
});
document.getElementById('modelSearch').addEventListener('input', function() {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('.model-row').forEach(function(row) {
    var name = row.getAttribute('data-name');
    row.style.display = (!q || name.indexOf(q) !== -1) ? '' : 'none';
  });
});
</script>
</body>
</html>
