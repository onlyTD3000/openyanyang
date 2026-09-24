<?php
$adminOn = 'users';
$pageTitle = '用户详情';
require __DIR__ . '/_head.php';

$uid = (int) ($_GET['id'] ?? 0);
$u = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
if (!$u) {
    echo '<div class="alert alert-error">用户不存在</div>';
    require __DIR__ . '/_foot.php';
    exit;
}

$stat = db_one('SELECT COALESCE(SUM(tokens_in),0) ti, COALESCE(SUM(tokens_out),0) to_,
                       COALESCE(SUM(cost),0) c, COUNT(*) n FROM usage_logs WHERE user_id = ?', [$uid]);
$convN = (int) db_val('SELECT COUNT(*) FROM conversations WHERE user_id = ?', [$uid]);
$blogs = db_all('SELECT b.*, a.username AS admin_name FROM balance_logs b
                  LEFT JOIN users a ON a.id = b.admin_id
                 WHERE b.user_id = ? ORDER BY b.id DESC LIMIT 20', [$uid]);
$ulogs = db_all('SELECT l.*, COALESCE((SELECT m.display_name FROM models m WHERE m.model_name = l.model_name AND m.channel_id = l.channel_id LIMIT 1), (SELECT m.display_name FROM models m WHERE m.model_name = l.model_name LIMIT 1), \'\') AS model_display_name FROM usage_logs l WHERE l.user_id = ? ORDER BY id DESC LIMIT 20', [$uid]);
$isSelf = (int) $u['id'] === (int) $me['id'];
$banLogs = db_all(
    'SELECT l.*, a.username AS admin_name FROM user_ban_logs l
       LEFT JOIN users a ON a.id = l.admin_id
      WHERE l.user_id = ?
      ORDER BY l.id DESC LIMIT 10',
    [$uid]
);
?>
<div class="page-head">
  <h1 class="page-title">用户：<?= h($u['username']) ?></h1>
  <div class="spacer"></div>
  <a class="btn" href="/admin/users.php">返回列表</a>
</div>

<!-- 基本信息卡片 -->
<div class="card mb-16">
  <div class="card-head">基本信息</div>
  <div class="card-body">
    <table class="tbl" style="margin-bottom:0">
      <tbody>
        <tr>
          <td style="width:100px;font-weight:600">用户 ID</td>
          <td><?= (int) $u['id'] ?></td>
          <td style="width:100px;font-weight:600">账号</td>
          <td><?= h($u['username']) ?></td>
        </tr>
        <tr>
          <td style="font-weight:600">邮箱</td>
          <td>
            <?php if ($u['email'] !== ''): ?>
              <?= h($u['email']) ?>
              <?php if ($u['email_verified_at']): ?>
                <span class="badge badge-ok" style="margin-left:6px">已验证</span>
              <?php else: ?>
                <span class="badge badge-off" style="margin-left:6px">未验证</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">未填写</span>
            <?php endif; ?>
          </td>
          <td style="font-weight:600">角色</td>
          <td><?= $u['role'] === 'admin' ? '<span class="badge badge-admin">管理员</span>' : '<span class="badge badge-off">用户</span>' ?></td>
        </tr>
        <tr>
          <td style="font-weight:600">状态</td>
          <td><?= (int) $u['status'] === 1 ? '<span class="badge badge-ok">正常</span>' : '<span class="badge badge-off">禁用</span>' ?></td>
          <td style="font-weight:600">注册时间</td>
          <td><?= h($u['created_at'] ?: '—') ?></td>
        </tr>
        <tr>
          <td style="font-weight:600">注册 IP</td>
          <td><?= h($u['register_ip'] ?: '—') ?></td>
          <td style="font-weight:600">最近登录</td>
          <td><?= h($u['last_login_at'] ?: '—') ?></td>
        </tr>
        <tr>
          <td style="font-weight:600">登录 IP</td>
          <td><?= h($u['last_login_ip'] ?: '—') ?></td>
          <td style="font-weight:600">备注</td>
          <td><?= h($u['remark'] ?: '—') ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat-label">当前余额</div>
    <div class="stat-value">￥<?= money($u['balance']) ?></div>
    <div class="stat-sub">累计消费 ￥<?= money($u['total_cost']) ?></div></div>
  <div class="stat"><div class="stat-label">已用 Tokens</div>
    <div class="stat-value"><?= fmt_int($u['used_tokens']) ?></div>
    <div class="stat-sub">配额 <?= (int) $u['token_quota'] > 0 ? fmt_int($u['token_quota']) : '不限' ?></div></div>
  <div class="stat"><div class="stat-label">调用次数</div>
    <div class="stat-value"><?= fmt_int($stat['n']) ?></div>
    <div class="stat-sub"><?= fmt_int($convN) ?> 个会话</div></div>
  <div class="stat"><div class="stat-label">状态</div>
    <div class="stat-value" style="font-size:17px">
      <?= (int) $u['status'] === 1 ? '<span class="badge badge-ok">正常</span>' : '<span class="badge badge-off">禁用</span>' ?>
      <?= $u['role'] === 'admin' ? '<span class="badge badge-admin">管理员</span>' : '' ?>
    </div>
    <div class="stat-sub">注册 <?= h(substr($u['created_at'], 0, 10)) ?></div></div>
</div>

<div class="form-grid mb-16" style="align-items:start">
  <div class="card">
    <div class="card-head">余额调整</div>
    <div class="card-body">
      <form method="post" action="/admin/users.php">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="recharge">
        <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
        <label class="field"><span class="field-label">金额(元，负数为扣减)</span>
          <input class="input" name="amount" type="number" step="0.0001" value="10" required></label>
        <label class="field mt-16"><span class="field-label">类型</span>
          <select class="input" name="withdrawable">
            <option value="1">可提现（在线充值，客户可申请提现）</option>
            <option value="0">不可提现（活动赠送，仅可消费使用）</option>
          </select></label>
        <label class="field mt-16"><span class="field-label">备注</span>
          <input class="input" name="note" placeholder="管理员充值"></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">提交调整</button></div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head">Token 配额</div>
    <div class="card-body">
      <form method="post" action="/admin/users.php">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="quota">
        <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
        <label class="field"><span class="field-label">总 Token 上限（0 = 不限）</span>
          <input class="input" name="token_quota" type="number" min="0" value="<?= (int) $u['token_quota'] ?>"></label>
        <div class="hint">达到上限后该用户无法继续调用，需提高上限或清零计数。</div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存配额</button></div>
      </form>
      <form method="post" action="/admin/users.php" class="mt-16"
            onsubmit="return confirm('确认将已用 token 计数清零？')">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="reset_used">
        <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
        <button class="btn" type="submit">清零已用 token</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head">账号操作</div>
    <div class="card-body">
      <form method="post" action="/admin/users.php">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="passwd">
        <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
        <label class="field"><span class="field-label">重置密码</span>
          <input class="input" name="password" type="text" minlength="6" required placeholder="新密码"></label>
        <div class="form-actions"><button class="btn" type="submit">重置密码</button></div>
      </form>
      <div class="form-actions" style="flex-wrap:wrap">
        <?php if ((int) $u['status'] === 1): ?>
        <!-- 禁用：点击后弹出填写理由的浮层 -->
        <button class="btn" <?= $isSelf ? 'disabled' : '' ?>onclick="document.getElementById('modal-ban').style.display='flex'">禁用账号</button><div id="modal-ban" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center">
          <div style="background:#fff;border-radius:8px;padding:28px 32px;width:420px;max-width:92vw;box-shadow:0 8px 32px rgba(0,0,0,.18)">
            <h3 style="margin:0 0 14px">禁用账号：<?= h($u['username']) ?></h3>
            <form method="post" action="/admin/users.php">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="act" value="toggle">
              <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
              <label style="display:block;margin-bottom:8px;font-weight:600">禁用理由 <span style="color:#e55">*</span></label>
              <textarea name="ban_reason" rows="4" required minlength="2" maxlength="500"
                        style="width:100%;box-sizing:border-box;border:1px solid #d0d0d0;border-radius:5px;padding:8px;font-size:14px;resize:vertical"
                        placeholder="请简要说明禁用原因，便于后续查阅和申诉处理"></textarea>
              <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn"
                        onclick="document.getElementById('modal-ban').style.display='none'">取消</button>
                <button type="submit" class="btn btn-danger">确认禁用</button>
              </div>
            </form>
          </div>
        </div>

        <?php else: ?>
        <!-- 启用：先展示历史封禁记录，再确认 -->
        <button class="btn" <?= $isSelf ? 'disabled' : '' ?>
                onclick="document.getElementById('modal-unban').style.display='flex'">启用账号</button>

        <div id="modal-unban" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center">
          <div style="background:#fff;border-radius:8px;padding:28px 32px;width:520px;max-width:94vw;box-shadow:0 8px 32px rgba(0,0,0,.18);max-height:80vh;overflow-y:auto">
            <h3 style="margin:0 0 14px">启用账号：<?= h($u['username']) ?></h3>
            <?php if ($banLogs): ?>
            <p style="margin:00 10px;font-weight:600">封禁历史（最近10 条）</p>
            <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">
              <thead><tr style="background:#f5f5f5">
                <th style="padding:5px 8px;text-align:left">时间</th>
                <th style="padding:5px 8px;text-align:left">操作</th>
                <th style="padding:5px 8px;text-align:left">操作人</th>
                <th style="padding:5px 8px;text-align:left">来源</th>
                <th style="padding:5px 8px;text-align:left">理由</th>
              </tr></thead>
              <tbody><?php foreach ($banLogs as $bl): ?>
              <tr style="border-top:1px solid #eee">
                <td style="padding:5px 8px;white-space:nowrap"><?= h($bl['created_at']) ?></td>
                <td style="padding:5px 8px"><?= $bl['action'] === 'ban' ? '<span style="color:#e55">禁用</span>' : '<span style="color:#2a9">启用</span>' ?></td>
                <td style="padding:5px 8px"><?= $bl['admin_id'] ==0 ? '系统' : h($bl['admin_name'] ?? '—') ?></td>
                <td style="padding:5px 8px"><?= h($bl['source']) ?></td>
                <td style="padding:5px 8px"><?= h($bl['reason']?: '—') ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php else: ?>
            <p style="color:#888;margin-bottom:16px">暂无封禁历史记录。</p>
            <?php endif; ?>
            <form method="post" action="/admin/users.php">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="act" value="toggle">
              <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
              <label style="display:block;margin-bottom:8px;font-weight:600">启用备注（选填）</label>
              <input name="ban_reason" type="text" maxlength="500"
                     style="width:100%;box-sizing:border-box;border:1px solid #d0d0d0;border-radius:5px;padding:7px 10px;font-size:14px"
                     placeholder="如：申诉核实无误，解除封禁">
              <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn"
                        onclick="document.getElementById('modal-unban').style.display='none'">取消</button>
                <button type="submit" class="btn">确认启用</button>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>
        <form method="post" action="/admin/users.php">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="act" value="role">
          <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
          <button class="btn" type="submit" <?= $isSelf ? 'disabled' : '' ?>>
            <?= $u['role'] === 'admin' ? '降为普通用户' : '设为管理员' ?></button>
        </form>
        <form method="post" action="/admin/users.php"
              onsubmit="return confirm('删除该用户及其所有会话、日志？此操作不可恢复！')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="act" value="delete">
          <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
          <button class="btn btn-danger" type="submit" <?= $isSelf ? 'disabled' : '' ?>>删除用户</button>
        </form>
      </div>
      <?php if ($isSelf): ?><div class="hint">当前登录账号，禁用/角色/删除操作已锁定。</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-head">权限管理</div>
  <div class="card-body">
    <form method="post" action="/admin/users.php">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="capability">
      <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_ssh_exec" value="1" <?= (int) ($u['cap_ssh_exec'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>SSH 命令执行</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_sftp_read" value="1" <?= (int) ($u['cap_sftp_read'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>SFTP 读取</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_sftp_write" value="1" <?= (int) ($u['cap_sftp_write'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>SFTP 写入</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_sftp_patch" value="1" <?= (int) ($u['cap_sftp_patch'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>SFTP 补丁</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_sftp_list" value="1" <?= (int) ($u['cap_sftp_list'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>SFTP 列表</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_sftp_delete" value="1" <?= (int) ($u['cap_sftp_delete'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>SFTP 删除</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_file_list" value="1" <?= (int) ($u['cap_file_list'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>代码仓列表</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_file_read" value="1" <?= (int) ($u['cap_file_read'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>代码仓读取</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_file_write" value="1" <?= (int) ($u['cap_file_write'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>代码仓写入</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_file_patch" value="1" <?= (int) ($u['cap_file_patch'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>代码仓补丁</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_file_delete" value="1" <?= (int) ($u['cap_file_delete'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>代码仓删除</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_file_push" value="1" <?= (int) ($u['cap_file_push'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>代码仓推送</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_ws_list" value="1" <?= (int) ($u['cap_ws_list'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>工作中心列表</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_ws_read" value="1" <?= (int) ($u['cap_ws_read'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>工作中心读取</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_ws_write" value="1" <?= (int) ($u['cap_ws_write'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>工作中心写入</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_ws_patch" value="1" <?= (int) ($u['cap_ws_patch'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>工作中心补丁</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_ws_zip" value="1" <?= (int) ($u['cap_ws_zip'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>工作中心打包</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_ws_delete" value="1" <?= (int) ($u['cap_ws_delete'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>工作中心删除</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_web_open" value="1" <?= (int) ($u['cap_web_open'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>网页访问</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_web_search" value="1" <?= (int) ($u['cap_web_search'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>网页搜索</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="cap_ppt_generate" value="1" <?= (int) ($u['cap_ppt_generate'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>PPT 生成</span>
        </label>
      </div>
      <div class="hint" style="margin-bottom:12px">管理员权限控制：取消勾选后，该用户在对话中将无法使用对应工具，即使用户自己的工具开关是开启状态。</div>
      <div class="form-actions"><button class="btn" type="submit">保存权限设置</button></div>
    </form>
  </div>
</div>

<div class="form-grid" style="align-items:start">
  <div class="card">
    <div class="card-head">余额流水</div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>时间</th><th>变动</th><th>余额</th><th>说明</th></tr></thead>
        <tbody>
        <?php if (!$blogs): ?><tr><td colspan="4" class="empty">暂无记录</td></tr>
        <?php else: foreach ($blogs as $b): ?>
          <tr>
            <td class="nowrap"><?= h(substr($b['created_at'], 5, 11)) ?></td>
            <td class="<?= (float) $b['amount'] >= 0 ? 'text-ok' : 'text-err' ?>">
              <?= (float) $b['amount'] >= 0 ? '+' : '' ?><?= money($b['amount']) ?></td>
            <td>￥<?= money($b['balance_after']) ?></td>
            <td><?= h($b['note']) ?><?= $b['admin_name'] ? '<div class="hint">by ' . h($b['admin_name']) . '</div>' : '' ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head">最近调用</div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>时间</th><th>模型</th><th>输入</th><th>输出</th><th>费用</th></tr></thead>
        <tbody>
        <?php if (!$ulogs): ?><tr><td colspan="6" class="empty">暂无记录</td></tr>
        <?php else: foreach ($ulogs as $l): ?>
          <tr>
            <td class="nowrap"><?= h(substr($l['created_at'], 5, 11)) ?></td>
            <td><?= h($l['model_name']) ?></td>
            <td><?= fmt_int($l['tokens_in']) ?></td>
            <td><?= fmt_int($l['tokens_out']) ?></td>
            <td>￥<?= money($l['cost']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_foot.php'; ?>