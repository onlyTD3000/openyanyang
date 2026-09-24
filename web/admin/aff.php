<?php
/**
 * 后台 - AFF 推介。
 *
 * 三块内容：返现参数、推广人列表（点开看他邀请了谁）、全站返现流水。
 * 参数改动只影响之后产生的返现，已发出的按当时比例入账，不追溯。
 */
$adminOn = 'aff';
$pageTitle = 'AFF 推介';
// POST 要在 _head.php 之前处理完，否则重定向发不出去，刷新会重发表单
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();
require_once __DIR__ . '/../inc/aff.php';
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    if (($_POST['act'] ?? '') === 'save') {
        setting_set('aff_enabled', isset($_POST['aff_enabled']) ? '1' : '0');
        $rate = (float) ($_POST['aff_rate'] ?? 0);
        setting_set('aff_rate', number_format(max(0, min(100, $rate)), 3, '.', ''));
        setting_set('aff_max_per_order',
            number_format(max(0, (float) ($_POST['aff_max_per_order'] ?? 0)), 2, '.', ''));
        audit_log((int) $me['id'], 'aff_settings', 0, 0,
            '返现比例=' . $rate . '% 单笔上限=' . ($_POST['aff_max_per_order'] ?? 0));
        flash_set('ok', '推介设置已保存');
        header('Location: /admin/aff.php');
        exit;
    }
}
$view = (string) ($_GET['view'] ?? 'promoters');
if (!in_array($view, ['promoters', 'commissions'], true)) {
    $view = 'promoters';
}
$overview = aff_admin_overview();
$kw = trim((string) ($_GET['kw'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 20;
$promoters = $commissions = [];
$pages = 1;
if ($view === 'promoters') {
    $total = aff_admin_promoter_count($kw);
    $pages = max(1, (int) ceil($total / $per));
    if ($page > $pages) { $page = $pages; }
    $promoters = aff_admin_promoters($kw, $per, ($page - 1) * $per);
} else {
    $total = aff_admin_commission_count();
    $pages = max(1, (int) ceil($total / $per));
    if ($page > $pages) { $page = $pages; }
    $commissions = aff_admin_commissions($per, ($page - 1) * $per);
}
// 展开某个推广人时，把他的下级列出来
$detailUid = (int) ($_GET['u'] ?? 0);
$detailUser = $detailInvitees = null;
if ($detailUid > 0) {
    $detailUser = db_one('SELECT id, username, email, invite_code, balance FROM users WHERE id = ?', [$detailUid]);
    if ($detailUser) {
        $detailInvitees = aff_my_invitees($detailUid, 200, 0, true);
        $detailStat = aff_my_stat($detailUid);
    }
}
// 异步请求只要下级详情这一块：渲染片段后直接结束，不输出后台整页框架
if (($_GET['partial'] ?? '') === 'detail') {
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/_aff_detail.php';
    exit;
}
require __DIR__ . '/_head.php';
$csrf = csrf_token();
?>
<div class="stat-grid">
  <div class="stat">
    <div class="stat-label">已建立绑定关系</div>
    <div class="stat-value"><?= fmt_int($overview['bound']) ?></div>
    <div class="stat-sub"><?= fmt_int($overview['promoters']) ?> 人成功邀请过下级</div>
  </div>
  <div class="stat">
    <div class="stat-label">累计发出返现</div>
    <div class="stat-value">￥<?= money($overview['commission_sum']) ?></div>
    <div class="stat-sub">共 <?= fmt_int($overview['commission_n']) ?> 笔</div>
  </div>
  <div class="stat">
    <div class="stat-label">被推介用户充值额</div>
    <div class="stat-value">￥<?= money($overview['recharge_sum']) ?></div>
    <div class="stat-sub"><?= fmt_int($overview['recharge_n']) ?> 笔已支付订单</div>
  </div>
  <div class="stat">
    <div class="stat-label">返现占充值比</div>
    <div class="stat-value"><?= $overview['recharge_sum'] > 0
        ? number_format($overview['commission_sum'] / $overview['recharge_sum'] * 100, 2) . '%'
        : '—' ?></div>
    <div class="stat-sub">实际发出 / 下级充值</div>
  </div>
</div>
<div class="card mb-16">
  <div class="card-head">返现设置</div>
  <div class="card-body">
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="save">
      <label class="field field-inline">
        <span class="field-label">功能开关</span>
        <span>
          <input type="checkbox" name="aff_enabled" value="1" <?= aff_enabled() ? 'checked' : '' ?>>
          启用 AFF 推介
        </span>
      </label>
      <label class="field field-inline">
        <span class="field-label">返现比例 (%)</span>
        <input class="input" type="number" name="aff_rate" step="0.01" min="0" max="100"
               value="<?= h(rtrim(rtrim(number_format(aff_rate(), 3, '.', ''), '0'), '.')) ?>">
      </label>
      <label class="field field-inline">
        <span class="field-label">单笔返现上限</span>
        <input class="input" type="number" name="aff_max_per_order" step="0.01" min="0"
               value="<?= h(number_format(aff_max_per_order(), 2, '.', '')) ?>">
      </label>
      <div class="hint">
        按下级<strong>实付金额</strong>计算，充值到账时自动打进上级余额，同时写入余额流水。
        上限填 0 表示不限。改动只影响之后的返现，已发出的按当时比例算，不会追溯调整。
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">保存设置</button>
      </div>
    </form>
  </div>
</div>
<div id="aff-detail"><?php require __DIR__ . '/_aff_detail.php'; ?></div>
<div class="card">
  <div class="card-head">
    <span>
      <a href="/admin/aff.php?view=promoters" class="<?= $view === 'promoters' ? '' : 'muted' ?>">推广人列表</a>
      ·
      <a href="/admin/aff.php?view=commissions" class="<?= $view === 'commissions' ? '' : 'muted' ?>">返现流水</a>
      ·
      <a href="/admin/aff_backfill.php" class="muted">回溯记录</a>
    </span>
    <span class="hint" style="margin:0">共 <?= fmt_int($total) ?> 条</span>
  </div>
  <?php if ($view === 'promoters'): ?>
  <div class="card-body" style="padding-bottom:0">
    <form method="get" class="form-inline">
      <input type="hidden" name="view" value="promoters">
      <input class="input" type="text" name="kw" value="<?= h($kw) ?>"
             placeholder="搜账号 / 邮箱 / 邀请码" style="max-width:260px">
      <button class="btn" type="submit">搜索</button>
      <?php if ($kw !== ''): ?><a class="btn" href="/admin/aff.php">清空</a><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr><th>推广人</th><th class="nowrap">邀请码</th><th class="nowrap">邀请人数</th>
            <th class="nowrap">下级充值额</th><th class="nowrap">已得返现</th>
            <th class="nowrap">当前余额</th><th class="nowrap">操作</th></tr>
      </thead>
      <tbody>
      <?php if (!$promoters): ?>
        <tr><td colspan="7" class="empty"><?= $kw !== '' ? '没有匹配的推广人' : '还没有人成功邀请过下级' ?></td></tr>
      <?php else: foreach ($promoters as $p): ?>
        <tr>
          <td>
            <?= h($p['username']) ?>
            <div class="stat-sub"><?= h($p['email'] !== '' ? $p['email'] : '—') ?></div>
          </td>
          <td class="nowrap" style="font-family:monospace"><?= h($p['invite_code']) ?></td>
          <td><?= fmt_int($p['invited']) ?></td>
          <td>￥<?= money($p['sub_recharge']) ?></td>
          <td style="color:var(--ok,#16a34a)">￥<?= money($p['commission_sum']) ?></td>
          <td>￥<?= money($p['balance']) ?></td>
          <td class="nowrap"><a class="btn btn-sm" href="/admin/aff.php?u=<?= (int) $p['id'] ?>">看下级</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr><th class="nowrap">时间</th><th>返给谁</th><th>来自谁充值</th>
            <th class="nowrap">对方实付</th><th class="nowrap">比例</th>
            <th class="nowrap">返现金额</th><th>订单号</th></tr>
      </thead>
      <tbody>
      <?php if (!$commissions): ?>
        <tr><td colspan="7" class="empty">还没有产生返现</td></tr>
      <?php else: foreach ($commissions as $c): ?>
        <tr>
          <td class="nowrap"><?= h($c['created_at']) ?></td>
          <td><?= h($c['up_username'] ?? ('#' . $c['user_id'])) ?></td>
          <td><?= h($c['from_username'] ?? ('#' . $c['from_user_id'])) ?></td>
          <td>￥<?= money($c['recharge_amount']) ?></td>
          <td><?= rtrim(rtrim(number_format((float) $c['rate'], 2, '.', ''), '0'), '.') ?>%</td>
          <td style="color:var(--ok,#16a34a)">+￥<?= money($c['amount']) ?></td>
          <td class="nowrap" style="font-family:monospace;font-size:12px"><?= h($c['order_no']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
      <?php if ($i === $page): ?><span class="on"><?= $i ?></span>
      <?php else: ?><a href="?view=<?= h($view) ?>&kw=<?= urlencode($kw) ?>&p=<?= $i ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<script>
(function () {
  var box = document.getElementById('aff-detail');
  if (!box || !window.fetch || !window.URLSearchParams) { return; }
  // 在当前地址上改写 u 参数，保留 view / kw / p，刷新后仍是同一屏
  function buildUrl(uid) {
    var params = new URLSearchParams(location.search);
    if (uid) { params.set('u', uid); } else { params.delete('u'); }
    params.delete('partial');
    var qs = params.toString();
    return '/admin/aff.php' + (qs ? '?' + qs : '');
  }
  function load(url, push) {
    box.style.opacity = '0.5';
    fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + 'partial=detail', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
      .then(function (html) {
        box.innerHTML = html;
        box.style.opacity = '';
        if (push) { history.pushState({ affDetail: 1 }, '', url); }
        if (html.trim() !== '') { box.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      })
      .catch(function () { location.href = url; });   // 异步失败就退回整页跳转
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a') : null;
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank') { return; }
    var href = a.getAttribute('href') || '';
    if (href.indexOf('/admin/aff.php') !== 0) { return; }
    var m = href.match(/[?&]u=(\d+)/);
    var isClose = !m && box.contains(a) && href === '/admin/aff.php';
    if (!m && !isClose) { return; }   // 换视图、翻页这些链接照常整页跳转
    e.preventDefault();
    load(buildUrl(m ? m[1] : ''), true);
  });
  window.addEventListener('popstate', function () {
    load(location.pathname + location.search, false);
  });
})();
</script>
<?php require __DIR__ . '/_foot.php';

