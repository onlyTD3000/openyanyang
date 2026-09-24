<?php
$apiKeys = db_all('SELECT id, name, created_at, last_used_at FROM user_api_keys WHERE user_id = ? AND status = 1 ORDER BY created_at DESC', [$me['id']]);
?>
<div class="card" id="api-keys">
    <div class="card-head">API 密钥管理</div>
    <div class="card-body">
      <p>使用 API 密钥可以在外部应用中访问本平台。请在生成时立即复制保存。</p>
      <?php if ($apiKeys): ?>
      <table class="tbl">
        <thead><tr><th>名称</th><th>创建时间</th><th>最后使用</th><th>状态</th></tr></thead>
        <tbody>
          <?php foreach ($apiKeys as $k): ?>
          <tr>
            <td><?= h($k['name']) ?></td>
            <td><?= h($k['created_at']) ?></td>
            <td><?= h($k['last_used_at'] ?? '从未') ?></td>
            <td><span class="badge badge-ok">生效中</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <p>当前没有活跃的 API 密钥。</p>
      <?php endif; ?>
      <div style="margin-top:12px">
        <form method="post" style="display:inline-block; margin-right:8px">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="act" value="api_key_generate">
          <button class="btn btn-primary" type="submit">生成新密钥</button>
        </form>
        <?php if ($apiKeys): ?>
        <form method="post" style="display:inline-block" onsubmit="return confirm('确定要重置所有 API 密钥吗？所有旧密钥将立即失效。')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="act" value="api_key_reset">
          <button class="btn btn-warning" type="submit">重置全部密钥</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
</div>
EOF
