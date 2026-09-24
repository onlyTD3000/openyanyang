<?php
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();
$csrf = csrf_token();
$navOn = 'admin';
$showSideToggle = true; // 启用 topbar 汉堡按钮
$siteName = app_name();
$adminOn = $adminOn ?? '';
$pageTitle = $pageTitle ?? '后台';

// 检测 AJAX 请求：只输出主体内容，跳过 head 和侧边栏
// 修复：Via 浏览器会带 X-Requested-With: mark.via，必须严格匹配 XMLHttpRequest
$_ajax_mode = !empty($_GET['ajax']) || 
              (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
if (!$_ajax_mode):
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> - <?= h($siteName) ?></title>
<script>!function(){var ls=localStorage.getItem('theme');if(ls==='dark'||(!ls&&<?= (int)($me['dark_mode'] ?? 0) ?>))document.documentElement.classList.add('dark')}()</script>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<style>
/* 汉堡按钮：默认隐藏，窄屏显示 */
.side-toggle{display:none}

/* 遮罩层 */
.sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:39;
  opacity:0;pointer-events:none;transition:opacity .22s}
.sidebar-overlay.show{opacity:1;pointer-events:auto}

/* 后台布局容器 */
.admin-layout{display:flex;flex:1;position:relative}

/* 侧边栏：白色，对齐 chat.php 的 conv-side 风格 */
.admin-sidebar{
  position:fixed;top:56px;left:0;bottom:0;
  width:240px;background:#fff;border-right:1px solid var(--c-border);
  display:flex;flex-direction:column;min-width:0;min-height:0;
  overflow-y:auto;transition:transform .22s;z-index:10;
}

/* 侧边栏导航 */
.sidebar-nav{padding:8px;flex:1 1 0;min-height:0;}
.side-item{
  display:flex;align-items:center;gap:8px;
  padding:9px 10px;border-radius:var(--radius-sm);
  cursor:pointer;color:var(--c-text-2);font-size:13px;
  margin-bottom:2px;text-decoration:none;
}
.side-item:hover{background:#f5f6f8;color:var(--c-text)}
.side-item.on{background:var(--c-primary-soft);color:var(--c-primary);font-weight:500}

/* 分组标题 */
.side-group-title{
  display:flex;align-items:center;justify-content:space-between;
  padding:9px 10px;border-radius:var(--radius-sm);
  color:var(--c-text-2);font-size:13px;cursor:pointer;
  margin-bottom:2px;user-select:none;
}
.side-group-title:hover{background:#f5f6f8;color:var(--c-text)}
.side-group-title .arrow{
  font-size:14px;color:var(--c-muted);
  transition:transform .2s;
}
.side-group.expanded .side-group-title .arrow{transform:rotate(90deg)}
.side-group.expanded .side-group-title{color:var(--c-text);font-weight:500}

/* 分组子项容器：展开收起 */
.side-sub{max-height:0;overflow:hidden;transition:max-height .25s ease}
.side-group.expanded .side-sub{max-height:260px}
.side-sub .side-item{padding-left:24px;margin-bottom:1px}

/* 主内容区：占据剩余空间 */
.admin-main{flex:1;overflow-y:auto;margin-left:240px;padding:8px}

/* 手机端：侧边栏脱离文档流，滑入滑出 */
@media(max-width:860px){
  .side-toggle{display:inline-flex}
  .admin-main{margin-left:0}
  .admin-sidebar{position:fixed;top:56px;bottom:0;left:0;width:260px;z-index:40;
    transform:translateX(-100%);box-shadow:0 10px 25px rgba(0,0,0,.3)}
  .admin-sidebar.open{transform:translateX(0)}
  .sidebar-nav{padding:12px 8px}
}

/* 后台暗黑模式 */
html.dark .admin-sidebar{background:#1e1f22;border-right-color:#2e2f33}
html.dark .side-item{color:#a8abb3}
html.dark .side-item:hover{background:#2e2f33;color:#e4e6eb}
html.dark .side-item.on{background:#1a2844;color:#4a8aff}
html.dark .side-group-title{color:#a8abb3}
html.dark .side-group-title:hover{background:#2e2f33;color:#e4e6eb}
html.dark .side-group.expanded .side-group-title{color:#e4e6eb}
html.dark .side-group-title .arrow{color:#767a82}
html.dark .admin-main{background:#1a1b1e}
html.dark .page{background:#1a1b1e}
html.dark .sidebar-overlay{background:rgba(0,0,0,.6)}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
  var toggle=document.getElementById('sideToggle'); // topbar 的汉堡按钮
  var sidebar=document.getElementById('adminSidebar');
  var overlay=document.getElementById('sidebarOverlay');
  if(!toggle||!sidebar||!overlay)return;
  
  toggle.addEventListener('click',function(){
    sidebar.classList.toggle('open');
    overlay.classList.toggle('show');
  });
  
  overlay.addEventListener('click',function(){
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
  });
  
  // 分组标题点击：展开/收起，手风琴效果
  var groups=sidebar.querySelectorAll('.side-group');
  groups.forEach(function(group){
    var title=group.querySelector('.side-group-title');
    if(!title)return;
    title.addEventListener('click',function(e){
      e.preventDefault();
      var isOpen=group.classList.contains('expanded');
      // 收起其他组
      groups.forEach(function(g){g.classList.remove('expanded')});
      if(!isOpen)group.classList.add('expanded');
    });
  });
  
  // 无刷新页面切换
  var main=document.getElementById('adminMain');
  var links=sidebar.querySelectorAll('a[data-page]');
  
  links.forEach(function(link){
    link.addEventListener('click',function(e){
      e.preventDefault();
      var page=link.getAttribute('data-page');
      var url='/admin/'+page;
      
      // 手机端收起侧边栏
      if(window.innerWidth<=860){
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
      }
      
      // 更新菜单高亮
      links.forEach(function(l){l.classList.remove('on')});
      link.classList.add('on');
      
      // 确保所属分组展开
      var grp=link.closest('.side-group');
      if(grp){
        groups.forEach(function(g){g.classList.remove('expanded')});
        grp.classList.add('expanded');
      }
      
      // 加载内容
      main.innerHTML='<div class="page" style="padding:40px;text-align:center;color:#94a3b8">加载中...</div>';
      
      fetch(url+'?ajax=1',{
        headers:{'X-Requested-With':'XMLHttpRequest'},
        credentials:'same-origin'
      })
      .then(function(res){
        if(!res.ok)throw new Error('HTTP '+res.status);
        return res.text();
      })
      .then(function(html){
        main.innerHTML=html;
        history.pushState({page:link.dataset.page},document.title,url);
        
        // 重新执行页面内的 script 标签
        var scripts=main.querySelectorAll('script');
        scripts.forEach(function(oldScript){
          var newScript=document.createElement('script');
          if(oldScript.src)newScript.src=oldScript.src;
          else newScript.textContent=oldScript.textContent;
          oldScript.parentNode.replaceChild(newScript,oldScript);
        });
      })
      .catch(function(err){
        main.innerHTML='<div class="page" style="padding:40px;text-align:center;color:#ef4444">加载失败：'+err.message+'</div>';
      });
    });
  });
  
  // 浏览器前进后退
  window.addEventListener('popstate',function(e){
    if(e.state&&e.state.page){
      location.reload(); // 简化处理：后退时刷新页面
    }
  });
});
</script>
</head>
<body>
<div class="app">
<?php require __DIR__ . '/../inc/topbar.php'; ?>
<div class="admin-layout">
  <!-- 遮罩层：侧边栏打开时显示，点击关闭 -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <!-- 侧边栏 -->
  <aside class="admin-sidebar" id="adminSidebar">
    <nav class="sidebar-nav">
      <a href="/admin/index.php" class="side-item <?= $adminOn === 'dash' ? 'on' : '' ?>" data-page="index.php">概览</a>
      <a href="/admin/users.php" class="side-item <?= $adminOn === 'users' ? 'on' : '' ?>" data-page="users.php">用户管理</a>

      <!-- 渠道与模型 -->
      <div class="side-group <?= in_array($adminOn, ['channels','models','servers','ip_rules']) ? 'expanded' : '' ?>">
        <div class="side-group-title">渠道与模型<span class="arrow">›</span></div>
        <div class="side-sub">
          <a href="/admin/channels.php" class="side-item <?= $adminOn === 'channels' ? 'on' : '' ?>" data-page="channels.php">API 渠道</a>
          <a href="/admin/models.php" class="side-item <?= $adminOn === 'models' ? 'on' : '' ?>" data-page="models.php">模型与定价</a>
          <a href="/admin/servers.php" class="side-item <?= $adminOn === 'servers' ? 'on' : '' ?>" data-page="servers.php">服务器管理</a>
          <a href="/admin/ip_rules.php" class="side-item <?= $adminOn === 'ip_rules' ? 'on' : '' ?>" data-page="ip_rules.php">服务器名单</a>
        </div>
      </div>

      <!-- 交易与财务 -->
      <div class="side-group <?= in_array($adminOn, ['recharge','withdraw','pay','aff']) ? 'expanded' : '' ?>">
        <div class="side-group-title">交易与财务<span class="arrow">›</span></div>
        <div class="side-sub">
          <a href="/admin/recharge_orders.php" class="side-item <?= $adminOn === 'recharge' ? 'on' : '' ?>" data-page="recharge_orders.php">充值订单</a>
          <a href="/admin/withdrawals.php" class="side-item <?= $adminOn === 'withdraw' ? 'on' : '' ?>" data-page="withdrawals.php">提现申请</a>
          <a href="/admin/pay_settings.php" class="side-item <?= $adminOn === 'pay' ? 'on' : '' ?>" data-page="pay_settings.php">支付设置</a>
          <a href="/admin/aff.php" class="side-item <?= $adminOn === 'aff' ? 'on' : '' ?>" data-page="aff.php">AFF 推介</a>
        </div>
      </div>

      <!-- 日志与监控 -->
      <div class="side-group <?= in_array($adminOn, ['chats','logs','audit','email']) ? 'expanded' : '' ?>">
        <div class="side-group-title">日志与监控<span class="arrow">›</span></div>
        <div class="side-sub">
          <a href="/admin/chats.php" class="side-item <?= $adminOn === 'chats' ? 'on' : '' ?>" data-page="chats.php">聊天记录</a>
          <a href="/admin/logs.php" class="side-item <?= $adminOn === 'logs' ? 'on' : '' ?>" data-page="logs.php">调用日志</a>
          <a href="/admin/audit.php" class="side-item <?= $adminOn === 'audit' ? 'on' : '' ?>" data-page="audit.php">审计日志</a>
          <a href="/admin/email_settings.php" class="side-item <?= $adminOn === 'email' ? 'on' : '' ?>" data-page="email_settings.php">邮箱管理</a>
        </div>
      </div>

      <!-- 中转与管控 -->
      <div class="side-group <?= in_array($adminOn, ['sk_admin']) ? 'expanded' : '' ?>">
        <div class="side-group-title">中转与管控<span class="arrow">›</span></div>
        <div class="side-sub">
          <a href="/admin/sk_admin.php" class="side-item <?= $adminOn === 'sk_admin' ? 'on' : '' ?>" data-page="sk_admin.php">卡密管控</a>
        </div>
      </div>

      <!-- 系统设置 -->
      <div class="side-group <?= in_array($adminOn, ['settings','software','intrusion']) ? 'expanded' : '' ?>">
        <div class="side-group-title">系统设置<span class="arrow">›</span></div>
        <div class="side-sub">
          <a href="/admin/settings.php" class="side-item <?= $adminOn === 'settings' ? 'on' : '' ?>" data-page="settings.php">站点设置</a>
          <a href="/admin/software.php" class="side-item <?= $adminOn === 'software' ? 'on' : '' ?>" data-page="software.php">软件控制</a>
          <a href="/admin/intrusion.php" class="side-item <?= $adminOn === 'intrusion' ? 'on' : '' ?>" data-page="intrusion.php">入侵监控</a>
        </div>
      </div>
    </nav>
  </aside>
  <!-- 主内容区 -->
  <main class="admin-main" id="adminMain">
<?php endif; // 非 AJAX 模式的 HTML 输出结束 ?>
    <div class="page">
<?php // AJAX 和完整模式都从这里开始输出页面内容 ?>