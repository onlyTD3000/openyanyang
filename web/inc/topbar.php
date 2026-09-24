<?php
/** 公共顶栏，需先定义 $me（当前用户）与 $navOn */
$navOn = $navOn ?? '';
$siteName = app_name();
$isAdmin  = ($me['role'] ?? '') === 'admin';

// 模拟登录状态：顶栏上方挂一条常驻横幅，避免管理员忘记自己正用别人的号操作
$模拟中 = function_exists('impersonating') && impersonating();
$原管理员 = $模拟中 ? impersonator() : null;

// 余额预警
$warnOn = (string) (setting_get('balance_warn_on', '0')) === '1';
$warnThreshold = (float) (setting_get('balance_warn_threshold', '1.00'));
$balanceLow = $warnOn && (float) ($me['balance'] ?? 0) < $warnThreshold;

/** 下拉菜单项：路径、标题、图标（行内 svg 名）、是否仅管理员可见 */
/** 每项：路径、标题、图标名、是否仅管理员、对应的 $navOn 值（用于高亮） */
$menuItems = [
    ['/index.php',       '首页',      'grid',   false, 'home'],
    ['/chat.php',        '对话',      'grid',   false, 'chat'],
    ['/workspace.php',   '工作中心',  'grid',   false, 'workspace'],
    ['/servers.php',     '我的服务器', 'server', false, 'servers'],
    ['/usage.php',       '用量',      'chart',  false, 'usage'],
    ['/recharge.php',    '余额充值',   'wallet', false, 'recharge'],
    ['/profile.php',     '个人资料',   'user',   false, 'profile'],
    ['/admin/index.php', '后台管理',   'shield', true,  'admin'],
];
?>
<script>(function(){var ls=localStorage.getItem('theme');if(ls==='dark'||(!ls&&<?= (int)($me['dark_mode'] ?? 0) ?>))document.documentElement.classList.add('dark');})();</script>
<?php if ($模拟中): ?>
<div class="imp-bar">
  <span class="imp-dot"></span>
  <span>
    你正以 <b><?= h($me['username']) ?></b> 的身份浏览
    <?php if ($原管理员): ?>（管理员 <?= h($原管理员['username']) ?> 的会话已暂存）<?php endif; ?>
    · 此时的操作都会记在该用户名下
  </span>
  <span class="imp-spacer"></span>
  <form method="post" action="/impersonate_exit.php" style="display:inline">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button class="imp-btn" type="submit">← 返回管理员账号</button>
  </form>
</div>
<style>
.imp-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  padding:8px 16px;background:#7c3f00;color:#ffe9c7;font-size:13px;line-height:1.5}
.imp-bar b{color:#fff}
.imp-spacer{flex:1}
.imp-dot{width:8px;height:8px;border-radius:50%;background:#ffb020;flex:none;
  box-shadow:0 0 0 3px rgba(255,176,32,.25)}
.imp-btn{cursor:pointer;border:1px solid rgba(255,255,255,.45);background:rgba(255,255,255,.12);
  color:#fff;border-radius:6px;padding:4px 12px;font-size:13px}
.imp-btn:hover{background:rgba(255,255,255,.24)}
</style>
<?php endif; ?>
<header class="topbar">
  <?php if (($showSideToggle ?? false)): ?>
    <button class="btn btn-sm side-toggle" id="sideToggle" type="button" aria-label="切换会话列表">☰</button>
  <?php endif; ?>
  <?php /* 标题做成回首页的链接。后台各页也用这个顶栏，点站名即可回首页 */ ?>
  <a class="topbar-brand" href="/index.php" title="返回首页"><span class="logo-dot"></span><?= h($siteName) ?></a>
  <a class="topbar-models-link" href="/models.php" title="模型广场">模型广场</a>

  <!-- 桌面端保留横向导航，窄屏用 CSS 隐藏，改走头像菜单 -->
  <nav class="topbar-nav">
    <a href="/chat.php" class="<?= $navOn === 'chat' ? 'on' : '' ?>">对话</a>
    <a href="/workspace.php" class="<?= $navOn === 'workspace' ? 'on' : '' ?>">工作中心</a>
    <a href="/servers.php" class="<?= $navOn === 'servers' ? 'on' : '' ?>">我的服务器</a>
    <a href="/usage.php" class="<?= $navOn === 'usage' ? 'on' : '' ?>">用量</a>
    <?php if ($isAdmin): ?>
      <a href="/admin/index.php" class="<?= $navOn === 'admin' ? 'on' : '' ?>">后台</a>
    <?php endif; ?>
  </nav>

  <div class="topbar-right">
    <button class="theme-toggle" id="themeToggle" type="button" title="切换深色模式" aria-label="切换深色模式">
      <span class="theme-icon-dark">🌙</span>
      <span class="theme-icon-light">☀️</span>
    </button>
    <!-- 余额胶囊直接做成充值入口：余额不够时用户第一反应就是点这里 -->
    <a class="balance-chip<?= $balanceLow ? ' balance-warn' : '' ?>" href="/recharge.php" title="点击充值">余额 <b>￥<?= money($me['balance']) ?></b>
      <span class="balance-plus">+</span></a>

    <div class="user-menu" id="userMenu">
      <button class="user-chip" type="button" id="userMenuBtn"
              aria-haspopup="true" aria-expanded="false" aria-label="账户菜单">
        <?php $display_name = $me['nickname'] ?: $me['username']; ?>
        <?php if (!empty($me['avatar_qq'])): ?>
        <span class="avatar avatar-img"><img src="https://q1.qlogo.cn/g?b=qq&amp;nk=<?= h($me['avatar_qq']) ?>&amp;s=40" alt=""></span>
        <?php else: ?>
        <span class="avatar"><?= h(mb_substr($display_name, 0, 1)) ?></span>
        <?php endif; ?>
        <span class="user-chip-name"><?= h($display_name) ?></span>
      </button>

      <div class="user-pop" id="userPop" role="menu" hidden>
        <div class="user-pop-head">
          <?php if (!empty($me['avatar_qq'])): ?>
          <span class="avatar avatar-lg avatar-img"><img src="https://q1.qlogo.cn/g?b=qq&amp;nk=<?= h($me['avatar_qq']) ?>&amp;s=100" alt=""></span>
          <?php else: ?>
          <span class="avatar avatar-lg"><?= h(mb_substr($display_name, 0, 1)) ?></span>
          <?php endif; ?>
          <div class="user-pop-id">
            <b><?= h($display_name) ?></b>
            <small><?= $isAdmin ? '管理员' : '用户' ?> · 余额 ￥<?= money($me['balance']) ?></small>
          </div>
        </div>

        <div class="user-pop-list">
          <?php foreach ($menuItems as [$href, $text, $icon, $adminOnly, $on]): ?>
            <?php if ($adminOnly && !$isAdmin) { continue; } ?>
            <a href="<?= h($href) ?>" role="menuitem"
               class="<?= $navOn === $on ? 'on' : '' ?> <?= $adminOnly ? 'is-admin' : '' ?>">
              <?= nav_icon($icon) ?><span><?= h($text) ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="user-pop-list user-pop-foot">
          <a href="/logout.php" role="menuitem" class="is-danger">
            <?= nav_icon('logout') ?><span>退出登录</span>
          </a>
        </div>
      </div>
    </div>

    <a class="btn btn-sm topbar-logout" href="/logout.php">退出</a>
  </div>
</header>

<script>
/* 头像下拉菜单：点击开合、点外部关闭、Esc 关闭、方向键可导航 */
(function () {
  var btn = document.getElementById('userMenuBtn');
  var pop = document.getElementById('userPop');
  if (!btn || !pop) return;

  function 开(是否) {
    pop.hidden = !是否;
    btn.setAttribute('aria-expanded', 是否 ? 'true' : 'false');
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    开(pop.hidden);
  });

  // 点菜单外部关闭
  document.addEventListener('click', function (e) {
    if (!pop.hidden && !pop.contains(e.target) && e.target !== btn) 开(false);
  });

  // Esc 关闭并把焦点还给按钮
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !pop.hidden) { 开(false); btn.focus(); }
  });
})();
</script>

<script>
/* 深色模式切换：点击按钮在 light/dark 之间切换，偏好同时存 localStorage 和数据库 */
(function () {
  var btn = document.getElementById('themeToggle');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    // 异步保存到数据库，不阻塞 UI
    var fd = new FormData();
    fd.append('dark_mode', isDark ? '1' : '0');
    fd.append('csrf', '<?= h(csrf_token()) ?>');
    fetch('/api/theme.php', { method: 'POST', body: fd, keepalive: true }).catch(function(){});
  });
})();
</script>

<?php if ($balanceLow): ?>
<div class="balance-toast" id="balanceToast" hidden>
  <div class="balance-toast-icon">⚠️</div>
  <div class="balance-toast-body">
    <b>余额不足提醒</b>
    <span>您的余额仅剩 ￥<?= money($me['balance']) ?>，低于预警线 ￥<?= number_format($warnThreshold, 2) ?>，建议及时充值。</span>
  </div>
  <a href="/recharge.php" class="balance-toast-btn">去充值</a>
  <button class="balance-toast-close" type="button" onclick="document.getElementById('balanceToast').remove()">×</button>
</div>
<script>
(function(){
  var toast = document.getElementById('balanceToast');
  if (!toast) return;
  if (sessionStorage.getItem('balanceWarnShown')) { toast.remove(); return; }
  sessionStorage.setItem('balanceWarnShown', '1');
  toast.hidden = false;
  toast.classList.add('show');
  setTimeout(function(){
    toast.classList.remove('show');
    setTimeout(function(){ toast.remove(); }, 300);
  }, 10000);
})();
</script>
<?php endif; ?>
