<?php
/**
 * 后台提现申请管理。
 *
 * 状态流转：待处理 → 处理中 → 已处理，任一状态都可驳回。
 * 驳回会把金额退回用户余额并写一条流水，这个动作在 wd_set_status
 * 里用事务保证，页面这层只管收参数。
 */
$adminOn   = 'withdraw';
$pageTitle = '提现申请';

// POST 必须在 _head.php 之前处理完。_head.php 一进来就输出 HTML，
// 之后再调 header('Location') 就没用了——保存虽然成功，但重定向发不出去，
// 浏览器只收到半截页面，看着像空白，得手动刷新才恢复。
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();
require_once __DIR__ . '/../inc/withdraw.php';
$msg = '';
$msgType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = $_POST['act'] ?? '';
    if ($act === 'set_status') {
        $r = wd_set_status(
            (int) ($_POST['id'] ?? 0),
            (string) ($_POST['status'] ?? ''),
            (int) ($me['id'] ?? 0),
            (string) ($_POST['note'] ?? '')
        );
        flash_set($r['ok'] ? 'ok' : 'error', $r['msg']);
        header('Location: /admin/withdrawals.php?' . http_build_query([
            'st' => $_POST['back_st'] ?? '',
            'kw' => $_POST['back_kw'] ?? '',
            'p'  => $_POST['back_p'] ?? 1,
        ]), true, 303);
        exit;
    }
    // 参数设置
    if ($act === 'save_cfg') {
        setting_set('wd_enabled', empty($_POST['wd_enabled']) ? '0' : '1');
        // 金额类配置一律取整，页面上不允许出现小数额度
        setting_set('wd_min', (string) max(1, (int) ($_POST['wd_min'] ?? 10)));
        setting_set('wd_max', (string) max(0, (int) ($_POST['wd_max'] ?? 0)));
        setting_set('wd_daily_limit', (string) max(0, (int) ($_POST['wd_daily_limit'] ?? 0)));
        // 费率允许小数（1.5% 这类），手续费下限也允许
        setting_set('wd_fee_rate', (string) max(0, min(100, (float) ($_POST['wd_fee_rate'] ?? 0))));
        setting_set('wd_fee_min', (string) max(0, (float) ($_POST['wd_fee_min'] ?? 0)));
        flash_set('ok', '提现参数已保存');
        header('Location: /admin/withdrawals.php', true, 303);
        exit;
    }
}
require __DIR__ . '/_head.php';
[$flashType, $flashMsg] = flash_get();
if ($flashMsg !== '') {
    $msg = $flashMsg;
    $msgType = $flashType;
}
$st = trim($_GET['st'] ?? '');
$kw = trim($_GET['kw'] ?? '');
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 30;
$total = wd_admin_count($st, $kw);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$rows = wd_admin_list($st, $kw, $per, ($page - 1) * $per);
$stats = wd_admin_stats();
$csrf = csrf_token();
$stClass = [
    'pending'    => 'badge-off',
    'processing' => 'badge-ok',
    'done'       => 'badge-ok',
    'rejected'   => 'badge-red',
];
$qs = fn(array $ex = []) => http_build_query(array_merge(['st' => $st, 'kw' => $kw], $ex));
?>
<div class="page-head"><h1 class="page-title">提现申请</h1></div>
<?php if ($msg !== ''): ?>
  <div class="alert alert-<?= $msgType === 'ok' ? 'ok' : 'error' ?>"><?= h($msg) ?></div>
<?php endif; ?>
<div class="stat-grid">
  <div class="stat">
    <div class="stat-label">待处理金额</div>
    <div class="stat-value">￥<?= wd_money($stats['todo']['amt']) ?></div>
    <div class="stat-sub">共 <?= (int) $stats['todo']['n'] ?> 笔（含处理中）</div>
  </div>
  <div class="stat">
    <div class="stat-label">已处理金额</div>
    <div class="stat-value">￥<?= wd_money($stats['done']['amt']) ?></div>
    <div class="stat-sub">共 <?= (int) $stats['done']['n'] ?> 笔，实付 ￥<?= wd_money($stats['done']['act']) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">手续费收入</div>
    <div class="stat-value">￥<?= wd_money($stats['done']['fee']) ?></div>
    <div class="stat-sub">仅统计已处理的申请</div>
  </div>
  <div class="stat">
    <div class="stat-label">已驳回</div>
    <div class="stat-value"><?= (int) $stats['rejected']['n'] ?> 笔</div>
    <div class="stat-sub">￥<?= wd_money($stats['rejected']['amt']) ?> 已退回余额</div>
  </div>
</div>
<div class="card mb-16">
  <div class="card-head">提现参数</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="save_cfg">
      <div class="inline-form" style="flex-wrap:wrap;gap:14px">
        <label class="check">
          <input type="checkbox" name="wd_enabled" value="1" <?= wd_enabled() ? 'checked' : '' ?>>
          开启提现功能
        </label>
        <label>单笔最低
          <input class="input" style="width:100px" type="number" min="1" step="1"
                 name="wd_min" value="<?= wd_min() ?>"> 元
        </label>
        <label>单笔最高
          <input class="input" style="width:100px" type="number" min="0" step="1"
                 name="wd_max" value="<?= wd_max() ?>"> 元
        </label>
        <label>每日限
          <input class="input" style="width:80px" type="number" min="0" step="1"
                 name="wd_daily_limit" value="<?= wd_daily_limit() ?>"> 次
        </label>
        <label>手续费率
          <input class="input" style="width:90px" type="number" min="0" max="100" step="0.01"
                 name="wd_fee_rate" value="<?= h(rtrim(rtrim(number_format(wd_fee_rate(), 2, '.', ''), '0'), '.')) ?>"> %
        </label>
        <label>手续费最低
          <input class="input" style="width:100px" type="number" min="0" step="0.01"
                 name="wd_fee_min" value="<?= h(rtrim(rtrim(number_format(wd_fee_min(), 2, '.', ''), '0'), '.')) ?>"> 元
        </label>
        <button class="btn btn-primary" type="submit">保存参数</button>
      </div>
      <div class="hint mt-8">
        最高填 0 表示不限；每日限次填 0 表示不限。提现金额只接受整数元。<br>
        注册赠送的余额不计入可提现额度，这条是写在代码里的规则，不受这里的参数影响。
      </div>
    </form>
  </div>
</div>
<div class="card mb-16">
  <div class="card-body">
    <form class="inline-form" method="get" style="flex-wrap:wrap">
      <input class="input" style="width:240px" name="kw" value="<?= h($kw) ?>"
             placeholder="账号 / 用户 ID / 姓名 / 手机号">
      <select class="select" style="width:140px" name="st">
        <option value="">全部状态</option>
        <?php foreach (['pending', 'processing', 'done', 'rejected'] as $s): ?>
          <option value="<?= h($s) ?>" <?= $st === $s ? 'selected' : '' ?>>
            <?= h(wd_status_text($s)) ?>（<?= (int) $stats[$s]['n'] ?>）
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary" type="submit">筛选</button>
      <a class="btn" href="/admin/withdrawals.php">重置</a>
    </form>
  </div>
</div>
<div class="card">
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th style="width:56px">#</th>
          <th>用户</th>
          <th>金额</th>
          <th>手续费</th>
          <th>实付</th>
          <th>收款信息</th>
          <th>收款码</th>
          <th>理由</th>
          <th>状态</th>
          <th>申请时间</th>
          <th style="width:230px">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="11" class="empty">没有符合条件的提现申请</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php $rst = (string) $r['status']; ?>
          <tr>
            <td><?= (int) $r['id'] ?></td>
            <td>
              <?= h($r['username'] ?? '已删除') ?><br>
              <span class="hint">ID <?= (int) $r['user_id'] ?> / 余额 ￥<?= money($r['balance'] ?? 0) ?></span>
            </td>
            <td>￥<?= wd_money($r['amount']) ?></td>
            <td><?= (float) $r['fee'] > 0 ? '￥' . wd_money($r['fee']) : '免' ?></td>
            <td><strong>￥<?= wd_money($r['actual']) ?></strong></td>
            <td>
              <?= h(wd_method_text($r['method'])) ?><br>
              <?= h($r['real_name']) ?><br>
              <span class="hint"><?= h($r['phone']) ?></span>
            </td>
            <td>
              <?php if ((int) $r['qr_upload_id'] > 0): ?>
                <a href="/api/img.php?id=<?= (int) $r['qr_upload_id'] ?>" target="_blank">
                  <img src="/api/img.php?id=<?= (int) $r['qr_upload_id'] ?>" alt="收款码"
                       style="width:52px;height:52px;object-fit:cover;border-radius:6px">
                </a>
              <?php else: ?>
                <span class="hint">无</span>
              <?php endif; ?>
            </td>
            <td style="max-width:200px">
              <div style="white-space:pre-wrap;word-break:break-word;font-size:12.5px"><?= h($r['reason']) ?></div>
              <?php if ($r['admin_note'] !== ''): ?>
                <div class="hint mt-8">备注：<?= h($r['admin_note']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= h($stClass[$rst] ?? '') ?>"><?= h(wd_status_text($rst)) ?></span></td>
            <td class="hint"><?= h($r['created_at']) ?><?php if ($r['handled_at']): ?><br>处理 <?= h($r['handled_at']) ?><?php endif; ?></td>
            <td>
              <?php if ($rst === 'rejected'): ?>
                <span class="hint">已驳回并退款，不可再改</span>
              <?php else: ?>
                <form method="post" style="display:flex;flex-direction:column;gap:6px">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="act" value="set_status">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="back_st" value="<?= h($st) ?>">
                  <input type="hidden" name="back_kw" value="<?= h($kw) ?>">
                  <input type="hidden" name="back_p" value="<?= (int) $page ?>">
                  <input class="input" style="width:100%" name="note" maxlength="255"
                         placeholder="备注（驳回时建议填原因）" value="">
                  <div style="display:flex;gap:5px;flex-wrap:wrap">
                    <?php if ($rst !== 'pending'): ?>
                      <button class="btn btn-sm" type="submit" name="status" value="pending">待处理</button>
                    <?php endif; ?>
                    <?php if ($rst !== 'processing'): ?>
                      <button class="btn btn-sm" type="submit" name="status" value="processing">处理中</button>
                    <?php endif; ?>
                    <?php if ($rst !== 'done'): ?>
                      <button class="btn btn-sm btn-primary" type="submit" name="status" value="done">已处理</button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-danger" type="submit" name="status" value="rejected"
                            onclick="return confirm('驳回后金额会退回用户余额，且这笔申请不能再改状态。确定驳回？')">驳回</button>
                  </div>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="card-body">
    <div class="pager">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <?php if ($i === $page): ?>
          <span class="on"><?= $i ?></span>
        <?php else: ?>
          <a href="?<?= h($qs(['p' => $i])) ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_foot.php'; ?>
