<?php
// POST 处理必须放在 _head.php 之前，否则 HTML 已输出、header() 重定向失败导致空白页
require_once __DIR__ . '/../inc/helpers.php';
start_session();

$adminOn = 'sk_admin';
$pageTitle = '卡密管控';

// AJAX：查看调用IP
if (($_GET['ajax'] ?? '') === 'ips' && isset($_GET['uid'])) {
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int) $_GET['uid'];
    $me = current_user();
    if (!$me || $me['role'] !== 'admin') {
        echo json_encode(['error' => '无权限']);
        exit;
    }
    $ips = db_all(
        "SELECT ip, MAX(created_at) AS last_call, COUNT(*) AS call_count
         FROM usage_logs
         WHERE user_id = ? AND sk_card_id > 0 AND ip != ''
         GROUP BY ip
         ORDER BY last_call DESC
         LIMIT 30",
        [$uid]
    );
    echo json_encode(['ips' => $ips]);
    exit;
}

// AJAX：查看消耗数据
if (($_GET['ajax'] ?? '') === 'consumption' && isset($_GET['uid'])) {
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int) $_GET['uid'];
    $me = current_user();
    if (!$me || $me['role'] !== 'admin') {
        echo json_encode(['error' => '无权限']);
        exit;
    }

    // 近24H消耗
    $r24 = db_one(
        "SELECT COALESCE(SUM(cost),0) AS cost,
                COALESCE(SUM(tokens_in),0) AS tokens_in,
                COALESCE(SUM(tokens_out),0) AS tokens_out,
                COUNT(*) AS calls
         FROM usage_logs
         WHERE user_id = ? AND sk_card_id > 0
           AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        [$uid]
    );
    // 近30天消耗
    $r30 = db_one(
        "SELECT COALESCE(SUM(cost),0) AS cost,
                COALESCE(SUM(tokens_in),0) AS tokens_in,
                COALESCE(SUM(tokens_out),0) AS tokens_out,
                COUNT(*) AS calls
         FROM usage_logs
         WHERE user_id = ? AND sk_card_id > 0
           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        [$uid]
    );

    echo json_encode([
        'h24' => [
            'cost'   => (float) $r24['cost'],
            'tokens' => (int) $r24['tokens_in'] + (int) $r24['tokens_out'],
            'calls'  => (int) $r24['calls'],
        ],
        'd30' => [
            'cost'   => (float) $r30['cost'],
            'tokens' => (int) $r30['tokens_in'] + (int) $r30['tokens_out'],
            'calls'  => (int) $r30['calls'],
        ],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = $_POST['act'] ?? '';
    $uid = (int) ($_POST['uid'] ?? 0);

    $me = current_user();
    if (!$me) {
        flash_set('error', '会话已过期，请重新登录');
    } elseif ($me['role'] !== 'admin') {
        flash_set('error', '无权限执行此操作');
    } elseif ($uid <= 0) {
        flash_set('error', '参数错误：用户ID无效');
    } else {
        $target = db_one('SELECT id, username, role FROM users WHERE id = ? LIMIT 1', [$uid]);
        if (!$target) {
            flash_set('error', '用户不存在');
        } elseif ($target['role'] === 'admin') {
            flash_set('error', "管理员账号 {$target['username']} 无法限制");
        } elseif ($act === 'blacklist') {
            db_exec('DELETE FROM sk_cards WHERE user_id = ? AND status = 1', [$uid]);
            db_exec('UPDATE users SET sk_blacklisted = 1 WHERE id = ?', [$uid]);
            audit_log((int) $me['id'], 'sk_blacklist', $uid, 0, "拉黑用户 {$target['username']} 的SK卡密功能");
            flash_set('ok', "已拉黑用户 {$target['username']}，其所有SK卡密已失效");
        } elseif ($act === 'unblacklist') {
            db_exec('UPDATE users SET sk_blacklisted = 0 WHERE id = ?', [$uid]);
            audit_log((int) $me['id'], 'sk_unblacklist', $uid, 0, "取消拉黑用户 {$target['username']} 的SK卡密功能");
            flash_set('ok', "已取消拉黑用户 {$target['username']}");
        } else {
            flash_set('error', '未知操作');
        }
    }
    redirect_self();
}

require __DIR__ . '/_head.php';

[$flashType, $flashMsg] = flash_get();

// 查询所有有 SK 卡密的用户（含已删除的卡密也算）
$rows = db_all(
    "SELECT u.id, u.username, u.role, u.sk_blacklisted,
            SUM(CASE WHEN sc.status = 1 THEN 1 ELSE 0 END) AS card_count,
            COALESCE(SUM(CASE WHEN sc.status = 1 THEN sc.balance ELSE 0 END), 0) AS total_balance,
            COALESCE(SUM(sc.total_cost), 0) AS total_cost
     FROM users u
     LEFT JOIN sk_cards sc ON sc.user_id = u.id
     WHERE u.sk_blacklisted = 1 OR sc.id IS NOT NULL
     GROUP BY u.id
     ORDER BY u.sk_blacklisted ASC, total_balance DESC"
);

// 为每个用户查询今日调用次数和平均调用频率
$users = [];
foreach ($rows as $r) {
    $uid = (int) $r['id'];

    // 今日调用次数（只统计 SK 卡密中转调用）
    $todayCalls = (int) db_val(
        "SELECT COUNT(*) FROM usage_logs
         WHERE user_id = ? AND sk_card_id > 0
           AND created_at >= CURDATE()",
        [$uid]
    );

    // 总调用次数和最早调用时间，算平均频率
    $stat = db_one(
        "SELECT COUNT(*) AS cnt, MIN(created_at) AS first_call, MAX(created_at) AS last_call
         FROM usage_logs
         WHERE user_id = ? AND sk_card_id > 0",
        [$uid]
    );
    $totalCalls = (int) ($stat['cnt'] ?? 0);
    $avgFreq = '—';
    if ($totalCalls > 0 && !empty($stat['first_call'])) {
        $hours = (time() - strtotime($stat['first_call'])) / 3600;
        if ($hours > 0) {
            $avgFreq = round($totalCalls / $hours, 1) . ' 次/时';
        } else {
            $avgFreq = $totalCalls . ' 次（不足1时）';
        }
    }

    // 最近调用IP
    $lastIP = db_val(
        "SELECT ip FROM usage_logs
         WHERE user_id = ? AND sk_card_id > 0 AND ip != ''
         ORDER BY created_at DESC LIMIT 1",
        [$uid]
    );

    $r['today_calls'] = $todayCalls;
    $r['total_calls'] = $totalCalls;
    $r['avg_freq'] = $avgFreq;
    $r['last_call'] = $stat['last_call'] ?? null;
    $r['last_ip'] = $lastIP ?: '';
    $users[] = $r;
}
?>
<?php if ($flashType): ?>
<div class="alert" style="margin:12px 16px;border-radius:8px;padding:12px 16px;background:<?= $flashType === 'ok' ? '#f0fdf4' : '#fef2f2' ?>;color:<?= $flashType === 'ok' ? '#15803d' : '#dc2626' ?>;border:1px solid <?= $flashType === 'ok' ? '#bbf7d0' : '#fecaca' ?>"><?= h($flashMsg) ?></div>
<?php endif; ?>

<div class="page-head"><h1 class="page-title">卡密管控</h1></div>

<div class="card">
  <div class="card-head">SK 卡密用户列表</div>
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>账号ID</th>
          <th>用户名</th>
          <th>卡密数</th>
          <th>剩余余额</th>
          <th>累计消费</th>
          <th>今日调用</th>
          <th class="nowrap">平均频率</th>
          <th>总调用</th>
          <th>最后调用</th>
          <th>调用IP</th>
          <th>状态</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="12" class="empty">暂无数据</td></tr>
      <?php else: foreach ($users as $u): ?>
        <tr<?= (int)$u['sk_blacklisted'] === 1 ? ' style="opacity:.5"' : '' ?>>
          <td><?= (int) $u['id'] ?></td>
          <td><?= h($u['username']) ?><?php if ($u['role'] === 'admin'): ?> <span style="font-size:11px;color:#f59e0b;background:#fef3c7;padding:1px 5px;border-radius:3px;white-space:nowrap;">管理员</span><?php endif; ?></td>
          <td><?= (int) $u['card_count'] ?></td>
          <td>￥<?= money($u['total_balance']) ?></td>
          <td>￥<?= money($u['total_cost']) ?></td>
          <td><?= $u['today_calls'] ?></td>
          <td class="nowrap"><?= h($u['avg_freq']) ?></td>
          <td><?= (int) $u['total_calls'] ?></td>
          <td class="nowrap"><?= $u['last_call'] ? h(substr($u['last_call'], 5, 11)) : '—' ?></td>
          <td class="nowrap"><?php if ($u['last_ip']): ?>
            <button type="button" class="sk-ip-btn" data-uid="<?= (int) $u['id'] ?>" data-name="<?= h($u['username']) ?>"
              style="background:none;border:1px solid var(--c-border,#e2e8f0);color:var(--c-text-2,#64748b);padding:2px 8px;border-radius:4px;cursor:pointer;font-size:12px;"><?= h($u['last_ip']) ?></button>
          <?php else: ?>—<?php endif; ?></td>
          <td>
            <?php if ((int) $u['sk_blacklisted'] === 1): ?>
              <span style="color:#ef4444;font-weight:500">已拉黑</span>
            <?php else: ?>
              <span style="color:#10b981">正常</span>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="sk-consume-btn" data-uid="<?= (int) $u['id'] ?>" data-name="<?= h($u['username']) ?>"
                style="background:#6366f1;color:#fff;border:none;padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px">查账</button>
              <?php if ($u['role'] === 'admin'): ?>
                <span style="display:inline-block;padding:4px 10px;font-size:12px;color:#94a3b8;background:#f1f5f9;border-radius:4px;">无法限制</span>
              <?php elseif ((int) $u['sk_blacklisted'] === 1): ?>
                <button type="button" class="sk-act-btn" data-act="unblacklist" data-uid="<?= (int) $u['id'] ?>" data-name="<?= h($u['username']) ?>"
                  style="background:#10b981;color:#fff;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px">取消拉黑</button>
              <?php else: ?>
                <button type="button" class="sk-act-btn" data-act="blacklist" data-uid="<?= (int) $u['id'] ?>" data-name="<?= h($u['username']) ?>"
                  style="background:#ef4444;color:#fff;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px">拉黑</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div style="margin-top:12px;color:#94a3b8;font-size:13px;line-height:1.6">
  <p>· 拉黑后：自动删除该账号下所有 SK 卡密，且禁止生成新卡密</p>
  <p>· 取消拉黑：恢复生成卡密的功能，但已删除的卡密不会恢复</p>
  <p>· 调用统计仅统计通过 SK 卡密的 API 中转调用（每次请求算一次）</p>
  <p>· 管理员账号无法被拉黑</p>
</div>

<!-- 拉黑/取消拉黑确认弹窗 -->
<div id="skConfirmModal" style="display:none;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;">
  <div class="sk-modal-overlay" style="position:absolute;inset:0;background:rgba(0,0,0,.5);"></div>
  <div class="sk-modal-box" style="position:relative;background:var(--card-bg,#fff);border-radius:12px;padding:28px 32px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div id="skModalIcon" style="width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;"></div>
    <h3 id="skModalTitle" style="text-align:center;margin:0 0 12px;font-size:18px;color:var(--c-text,#1a1a2e);"></h3>
    <p id="skModalBody" style="text-align:center;margin:0 0 24px;color:var(--c-text-2,#64748b);font-size:14px;line-height:1.6;"></p>
    <div style="display:flex;gap:12px;justify-content:center;">
      <button type="button" id="skModalCancel" style="padding:8px 24px;border-radius:8px;border:1px solid var(--c-border,#e2e8f0);background:transparent;color:var(--c-text-2,#64748b);cursor:pointer;font-size:14px;">取消</button>
      <button type="button" id="skModalConfirm" style="padding:8px 24px;border-radius:8px;border:none;color:#fff;cursor:pointer;font-size:14px;font-weight:500;">确认</button>
    </div>
  </div>
</div>

<!-- 消耗详情弹窗 -->
<div id="skConsumeModal" style="display:none;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;">
  <div class="sk-consume-overlay" style="position:absolute;inset:0;background:rgba(0,0,0,.5);"></div>
  <div class="sk-consume-box" style="position:relative;border-radius:12px;padding:28px 32px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
      <div style="width:40px;height:40px;border-radius:50%;background:#6366f1;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;">📊</div>
      <h3 id="skConsumeTitle" style="margin:0;font-size:18px;">消耗详情</h3>
    </div>
    <div id="skConsumeLoading" style="text-align:center;padding:40px 0;">加载中...</div>
    <div id="skConsumeBody" style="display:none;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <!-- 近24H -->
        <div class="sk-stat-card">
          <div class="sk-stat-label">近 24 小时</div>
          <div style="margin-bottom:10px;">
            <div class="sk-stat-sub">消耗余额</div>
            <div id="h24_cost" class="sk-stat-cost">—</div>
          </div>
          <div style="margin-bottom:6px;">
            <div class="sk-stat-sub">Token 总量</div>
            <div id="h24_tokens" class="sk-stat-tokens">—</div>
          </div>
          <div>
            <div class="sk-stat-sub">调用次数</div>
            <div id="h24_calls" class="sk-stat-calls">—</div>
          </div>
        </div>
        <!-- 近30天 -->
        <div class="sk-stat-card">
          <div class="sk-stat-label">近 30 天</div>
          <div style="margin-bottom:10px;">
            <div class="sk-stat-sub">消耗余额</div>
            <div id="d30_cost" class="sk-stat-cost">—</div>
          </div>
          <div style="margin-bottom:6px;">
            <div class="sk-stat-sub">Token 总量</div>
            <div id="d30_tokens" class="sk-stat-tokens">—</div>
          </div>
          <div>
            <div class="sk-stat-sub">调用次数</div>
            <div id="d30_calls" class="sk-stat-calls">—</div>
          </div>
        </div>
      </div>
    </div>
    <div style="display:flex;justify-content:center;margin-top:20px;">
      <button type="button" id="skConsumeClose" class="sk-btn-close">关闭</button>
    </div>
  </div>
</div>

<!-- 调用IP详情弹窗 -->
<div id="skIpModal" style="display:none;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;">
  <div class="sk-ip-overlay" style="position:absolute;inset:0;background:rgba(0,0,0,.5);"></div>
  <div class="sk-ip-box" style="position:relative;border-radius:12px;padding:28px 32px;max-width:520px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
      <div style="width:40px;height:40px;border-radius:50%;background:#0ea5e9;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;">🌐</div>
      <h3 id="skIpTitle" style="margin:0;font-size:18px;">调用IP记录</h3>
    </div>
    <div id="skIpLoading" style="text-align:center;padding:40px 0;color:var(--c-text-2,#64748b);">加载中...</div>
    <div id="skIpBody" style="display:none;max-height:480px;overflow-y:auto;"></div>
    <div style="display:flex;justify-content:center;margin-top:20px;">
      <button type="button" id="skIpClose" class="sk-btn-close">关闭</button>
    </div>
  </div>
</div>

<style>
/* 亮色模式 */
.sk-consume-box{background:#fff}
#skConsumeTitle{color:#1a1a2e}
.sk-stat-card{border:1px solid #e2e8f0;border-radius:10px;padding:16px;background:#f8fafc}
.sk-stat-label{font-size:13px;color:#64748b;margin-bottom:8px}
.sk-stat-sub{font-size:12px;color:#64748b}
.sk-stat-cost{font-size:20px;font-weight:600;color:#6366f1}
.sk-stat-tokens{font-size:16px;font-weight:500;color:#1a1a2e}
.sk-stat-calls{font-size:14px;color:#64748b}
#skConsumeLoading{color:#64748b}
.sk-btn-close{padding:8px 24px;border-radius:8px;border:1px solid #e2e8f0;background:transparent;color:#64748b;cursor:pointer;font-size:14px}
#skConsumeClose:hover{background:#f8fafc}

/* 暗黑模式 */
html.dark .sk-modal-box{background:#1e1f22 !important}
html.dark #skModalTitle{color:#e4e6eb !important}
html.dark #skModalBody{color:#94a3b8 !important}
html.dark #skModalCancel{border-color:#3a3b3e !important;color:#94a3b8 !important;background:#2a2b2e !important}

html.dark .sk-consume-box{background:#1e1f22 !important}
html.dark #skConsumeTitle{color:#e4e6eb !important}
html.dark .sk-stat-card{border-color:#3a3b3e !important;background:#2a2b2e !important}
html.dark .sk-stat-label{color:#94a3b8 !important}
html.dark .sk-stat-sub{color:#767a82 !important}
html.dark .sk-stat-cost{color:#818cf8 !important}
html.dark .sk-stat-tokens{color:#e4e6eb !important}
html.dark .sk-stat-calls{color:#94a3b8 !important}
html.dark #skConsumeLoading{color:#94a3b8 !important}
html.dark .sk-btn-close{border-color:#3a3b3e !important;color:#94a3b8 !important;background:#2a2b2e !important}
html.dark #skConsumeClose:hover{background:#353639 !important}

/* IP弹窗 */
html.dark .sk-ip-box{background:#1e1f22 !important}
html.dark #skIpTitle{color:#e4e6eb !important}
html.dark #skIpLoading{color:#94a3b8 !important}
html.dark .sk-ip-item{background:#2a2b2e !important;border-color:#3a3b3e !important}
html.dark .sk-ip-addr{color:#e4e6eb !important}
html.dark .sk-ip-meta{color:#94a3b8 !important}
html.dark .sk-ip-count{color:#818cf8 !important}
</style>

<script>
(function(){
  var modal = document.getElementById('skConfirmModal');
  var overlay = modal.querySelector('.sk-modal-overlay');
  var btnCancel = document.getElementById('skModalCancel');
  var btnConfirm = document.getElementById('skModalConfirm');
  var iconEl = document.getElementById('skModalIcon');
  var titleEl = document.getElementById('skModalTitle');
  var bodyEl = document.getElementById('skModalBody');
  var pendingForm = null;

  function openModal(act, uid, name) {
    if (act === 'blacklist') {
      iconEl.style.background = '#fef2f2';
      iconEl.style.color = '#ef4444';
      iconEl.textContent = '⚠';
      titleEl.textContent = '确认拉黑';
      bodyEl.innerHTML = '拉黑后将：<br>① 删除用户 <b>' + name + '</b> 的所有 SK 卡密<br>② 禁止该用户生成新卡密<br>③ 已生成的卡密立即失效<br><br>确定要继续吗？';
      btnConfirm.style.background = '#ef4444';
    } else {
      iconEl.style.background = '#f0fdf4';
      iconEl.style.color = '#10b981';
      iconEl.textContent = '✓';
      titleEl.textContent = '取消拉黑';
      bodyEl.innerHTML = '取消拉黑后，用户 <b>' + name + '</b> 将恢复生成 SK 卡密的功能。<br>已删除的卡密不会恢复。<br><br>确定要继续吗？';
      btnConfirm.style.background = '#10b981';
    }
    if (document.documentElement.classList.contains('dark')) {
      iconEl.style.background = act === 'blacklist' ? '#3a1a1a' : '#1a2e1a';
    }

    pendingForm = document.createElement('form');
    pendingForm.method = 'POST';
    pendingForm.style.display = 'none';
    pendingForm.innerHTML =
      '<input type="hidden" name="csrf" value="<?= h($csrf ?? '') ?>">' +
      '<input type="hidden" name="act" value="' + act + '">' +
      '<input type="hidden" name="uid" value="' + uid + '">';

    modal.style.display = 'flex';
  }

  function closeModal() {
    modal.style.display = 'none';
    pendingForm = null;
  }

  document.querySelectorAll('.sk-act-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openModal(btn.dataset.act, btn.dataset.uid, btn.dataset.name);
    });
  });

  btnConfirm.addEventListener('click', function() {
    if (pendingForm) {
      document.body.appendChild(pendingForm);
      pendingForm.submit();
    }
  });

  btnCancel.addEventListener('click', closeModal);
  overlay.addEventListener('click', closeModal);
})();

// 消耗详情弹窗
(function(){
  var consumeModal = document.getElementById('skConsumeModal');
  var consumeOverlay = consumeModal.querySelector('.sk-consume-overlay');
  var consumeClose = document.getElementById('skConsumeClose');
  var consumeLoading = document.getElementById('skConsumeLoading');
  var consumeBody = document.getElementById('skConsumeBody');
  var consumeTitle = document.getElementById('skConsumeTitle');

  function fmtMoney(v) {
    return '￥' + parseFloat(v).toFixed(6).replace(/\.?0+$/, '');
  }
  function fmtTokens(v) {
    if (v >= 1000000) return (v / 1000000).toFixed(2) + 'M';
    if (v >= 1000) return (v / 1000).toFixed(1) + 'K';
    return v.toString();
  }

  window.openConsumeModal = function(uid, name) {
    consumeTitle.textContent = name + ' 的消耗详情';
    consumeLoading.style.display = 'block';
    consumeBody.style.display = 'none';
    consumeModal.style.display = 'flex';

    fetch('?ajax=consumption&uid=' + uid)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.error) {
          consumeLoading.textContent = data.error;
          return;
        }
        document.getElementById('h24_cost').textContent = fmtMoney(data.h24.cost);
        document.getElementById('h24_tokens').textContent = fmtTokens(data.h24.tokens);
        document.getElementById('h24_calls').textContent = data.h24.calls + ' 次';
        document.getElementById('d30_cost').textContent = fmtMoney(data.d30.cost);
        document.getElementById('d30_tokens').textContent = fmtTokens(data.d30.tokens);
        document.getElementById('d30_calls').textContent = data.d30.calls + ' 次';
        consumeLoading.style.display = 'none';
        consumeBody.style.display = 'block';
      })
      .catch(function() {
        consumeLoading.textContent = '加载失败，请重试';
      });
  }

  window.closeConsumeModal = function() {
    consumeModal.style.display = 'none';
  }

  consumeClose.addEventListener('click', closeConsumeModal);
  consumeOverlay.addEventListener('click', closeConsumeModal);

  document.querySelectorAll('.sk-consume-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openConsumeModal(btn.dataset.uid, btn.dataset.name);
    });
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      if (consumeModal.style.display === 'flex') closeConsumeModal();
    }
  });
})();

// 调用IP详情弹窗
(function(){
  var ipModal = document.getElementById('skIpModal');
  var ipOverlay = ipModal.querySelector('.sk-ip-overlay');
  var ipClose = document.getElementById('skIpClose');
  var ipLoading = document.getElementById('skIpLoading');
  var ipBody = document.getElementById('skIpBody');
  var ipTitle = document.getElementById('skIpTitle');

  window.openIpModal = function(uid, name) {
    ipTitle.textContent = name + ' 的调用IP记录';
    ipLoading.style.display = 'block';
    ipBody.style.display = 'none';
    ipModal.style.display = 'flex';

    fetch('?ajax=ips&uid=' + uid)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.error) {
          ipLoading.textContent = data.error;
          return;
        }
        var ips = data.ips || [];
        if (ips.length === 0) {
          ipLoading.textContent = '暂无调用记录';
          return;
        }
        var html = '<div style="margin-bottom:12px;font-size:12px;color:var(--c-text-2,#64748b);">最多显示 30 条不同 IP</div>';
        html += '<div style="display:flex;flex-direction:column;gap:8px;">';
        ips.forEach(function(item, idx) {
          var dt = item.last_call ? item.last_call.replace('T', ' ').substring(0, 19) : '—';
          html += '<div class="sk-ip-item" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">';
          html += '<div>';
          html += '<div class="sk-ip-addr" style="font-size:14px;font-weight:500;color:#1a1a2e;">' + (idx + 1) + '. ' + item.ip + '</div>';
          html += '<div class="sk-ip-meta" style="font-size:12px;color:#64748b;margin-top:2px;">最后调用: ' + dt + '</div>';
          html += '</div>';
          html += '<div class="sk-ip-count" style="font-size:13px;color:#6366f1;font-weight:500;">' + item.call_count + ' 次</div>';
          html += '</div>';
        });
        html += '</div>';
        ipBody.innerHTML = html;
        ipLoading.style.display = 'none';
        ipBody.style.display = 'block';
      })
      .catch(function() {
        ipLoading.textContent = '加载失败，请重试';
      });
  }

  window.closeIpModal = function() {
    ipModal.style.display = 'none';
  }

  ipClose.addEventListener('click', closeIpModal);
  ipOverlay.addEventListener('click', closeIpModal);

  document.querySelectorAll('.sk-ip-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openIpModal(btn.dataset.uid, btn.dataset.name);
    });
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      if (ipModal.style.display === 'flex') closeIpModal();
    }
  });
})();
</script>
<?php require __DIR__ . '/_foot.php'; ?>
