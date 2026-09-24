<?php
/**
 * 对话页。原先和首页挤在 index.php 里——未登录显示首页、登录后同一地址变成对话页，
 * 导致登录后再也看不到首页。现在拆开：index.php 只做首页，对话页独立到这里。
 */
require_once __DIR__ . '/inc/helpers.php';

$me = require_login();
$csrf = csrf_token();

// 仅取启用渠道下的启用模型
$models = db_all(
    'SELECT m.id, m.display_name, m.model_name, m.price_in, m.price_out,
            m.price_cache, m.price_cache_create, m.vision
       FROM models m JOIN channels c ON c.id = m.channel_id
      WHERE m.status = 1 AND c.status = 1
      ORDER BY m.sort DESC, m.id ASC'
);

// 项目列表按用户隔离。侧栏第一层是项目，点开才显示该项目下的对话。
require_once __DIR__ . '/inc/project.php';
$projects = project_list((int) $me['id']);

// 供「新建项目」弹窗里选择部署服务器
$myHosts = db_all('SELECT id, name, host FROM ssh_hosts
                    WHERE user_id = ? AND status = 1 ORDER BY id', [$me['id']]);

// 用户的工具开关配置
$userTools = db_one(
    'SELECT tool_ssh_exec, tool_sftp_read, tool_sftp_write, tool_sftp_list, tool_sftp_delete, tool_sftp_patch,
            tool_file_list, tool_file_read, tool_file_write, tool_file_delete, tool_file_push, tool_file_patch,
            tool_ws_list, tool_ws_read, tool_ws_write, tool_ws_delete, tool_ws_patch, tool_ws_zip,
            tool_web_open, tool_web_search, tool_ppt_generate
       FROM users WHERE id = ? LIMIT 1',
    [$me['id']]
);

$navOn = 'chat';
$showSideToggle = true;
$siteName = app_name();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var ls=localStorage.getItem('theme');if(ls==='dark'||(!ls&&<?= (int)($me['dark_mode'] ?? 0) ?>))document.documentElement.classList.add('dark');})();</script>
<title>我的项目 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/chat.css') ?>?v=<?= time() ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/highlight-theme.css') ?>?v=11.9.0">
</head>
<body class="chat-page">
<div class="app">
<?php require __DIR__ . '/inc/topbar.php'; ?>

<div class="chat-wrap">
  <aside class="conv-side" id="convSide">
    <div class="conv-side-head">
      <button class="btn btn-primary btn-block btn-sm" id="btnNewProj" type="button">+ 新建项目</button>
    </div>
    <div class="proj-list" id="projList">
      <?php if (!$projects): ?>
        <div class="conv-empty">还没有项目，点上面新建一个</div>
      <?php else: foreach ($projects as $p): ?>
        <div class="proj-item" data-pid="<?= (int) $p['id'] ?>">
          <div class="proj-row">
            <span class="caret" aria-hidden="true">▸</span>
            <span class="proj-name" title="<?= h($p['name']) ?>"><?= h($p['name']) ?></span>
            <?php if ((int) $p['pinned']): ?><span class="proj-pin" title="已置顶">★</span><?php endif; ?>
            <span class="proj-n"><?= (int) $p['conv_count'] ?></span>
            <button class="proj-menu" type="button" title="项目设置"
                    data-menu="<?= (int) $p['id'] ?>" aria-label="项目设置">⋯</button>
          </div>
          <?php if (trim((string) $p['host_name']) !== '' || trim((string) $p['deploy_dir']) !== ''): ?>
            <div class="proj-meta">
              <?php if (trim((string) $p['host_name']) !== ''): ?>
                <span class="tag-mini" title="部署服务器"><?= h($p['host_name']) ?></span>
              <?php endif; ?>
              <?php if (trim((string) $p['deploy_dir']) !== ''): ?>
                <span class="tag-mini dir" title="<?= h($p['deploy_dir']) ?>"><?= h($p['deploy_dir']) ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="proj-convs" hidden></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </aside>

  <main class="chat-main">
    <div class="chat-head">
      <div class="cur-proj" id="curProj" hidden>
        <span class="cur-proj-name" id="curProjName"></span>
        <span class="cur-proj-meta" id="curProjMeta"></span>
        <button class="btn btn-sm" type="button" id="btnNewConv" title="在当前项目里新建一条对话">+ 新对话</button>
      </div>
      <?php if ($models): ?>
        <select class="select" id="modelSel">
          <?php foreach ($models as $m): ?>
            <option value="<?= (int) $m['id'] ?>"
                    data-in="<?= h($m['price_in']) ?>" data-out="<?= h($m['price_out']) ?>"
                    data-cache="<?= h($m['price_cache']) ?>"
                    data-cache-create="<?= h($m['price_cache_create']) ?>"
                    data-vision="<?= (int) $m['vision'] ?>">
              <?= h($m['display_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="model-price" id="priceTip"></span>
      <?php else: ?>
        <span class="alert alert-info" style="margin:0">暂无可用模型，请等待管理员在后台配置 API 接口。</span>
      <?php endif; ?>
    </div>

    <div class="msgs" id="msgs">
      <div class="msgs-inner" id="msgsInner">
        <div class="chat-welcome" id="welcome">
          <h2>你好，<?= h($me['username']) ?></h2>
          <p id="welcomeTip">
            <?php if (!$projects): ?>
              左侧新建一个项目开始。每个项目是独立的工作空间，可以绑定自己的服务器和部署目录，
              项目下能开多条对话分别推进不同的事情。
            <?php else: ?>
              在左侧选择一个项目，点开后新建或继续对话。按 Enter 发送，Shift + Enter 换行。
            <?php endif; ?>
          </p>
          <?php $notice = trim((string) setting_get('site_notice', '')); ?>
          <?php if ($notice !== ''): ?>
            <div class="alert alert-info" style="text-align:left;margin-top:18px">
              <?= nl2br(h($notice)) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <!-- 上翻查看历史时出现，点一下回到最新内容并恢复自动跟随 -->
      <button type="button" class="to-bottom" id="btnToBottom" hidden
              title="回到底部" aria-label="回到底部">↓</button>
    </div>

    <div class="composer">
      <div class="composer-inner">
        <!-- 待发送图片的缩略图，有图才显示 -->
        <div class="img-tray" id="imgTray" hidden></div>
        <!-- 输入框上方的工具行。
             「上一条需求」：长任务刷了满屏后往回找自己说过什么很费劲，点一下跳到上一个提问。
             「工具」：14 个 Function Calling 工具的独立开关，用户可关闭不需要的。 -->
        <div class="composer-tools">
          <button class="btn-locate" id="btnPrevAsk" type="button"
                  title="跳到上一条我发的消息，连续点继续往上翻">↑ 上一条需求</button>
          <!-- 上下文条数（会话级，已持久化）：设置随会话保存，切换/重开页面自动恢复。
               复用 sound-wrap 的定位样式，弹层视觉也走 sound-pop 一套 -->
          <span class="sound-wrap">
            <button class="btn-ctx" id="btnCtx" type="button"
                    aria-haspopup="true" aria-expanded="false"
                    title="上下文条数：跟随模型默认，点击设置">⚙ 上下文</button>
            <div class="sound-pop ctx-pop" id="ctxPop" hidden>
              <div class="sound-row">
                <span>上下文条数</span>
                <input type="number" id="ctxNum" min="2" max="60" step="1"
                       placeholder="默认" aria-label="上下文条数"
                       style="width:72px;margin-left:8px">
              </div>
              <div class="sound-row" style="justify-content:space-between;margin-top:6px">
                <button type="button" class="sound-test" id="ctxApply">应用</button>
                <button type="button" class="sound-test" id="ctxReset">恢复默认</button>
              </div>
              <p class="sound-note">会话级设置：保存后只对当前会话生效，切换会话自动恢复各自的设置。</p>
            </div>
          </span>
          <!-- 工具开关 -->
          <span class="sound-wrap">
            <button class="btn-tools" id="btnTools" type="button"
                    title="Function Calling 工具开关">⚙ 工具</button>
          </span>
        </div>
        <!-- 工具弹窗：fixed 定位，脱离父元素约束 -->
        <div class="tools-overlay" id="toolsOverlay"></div>
        <div class="tools-pop" id="toolsPop" data-show="0">
          <div class="tools-pop-head">
            <span>工具开关</span>
            <button type="button" class="tools-pop-close" id="btnCloseTools">✕</button>
          </div>
          <div class="tools-pop-body">
            <div class="tools-group">
              <div class="tools-group-title">SSH / SFTP</div>
              <div class="tools-grid">
                <button type="button" class="tool-btn" data-key="tool_ssh_exec">执行命令</button>
                <button type="button" class="tool-btn" data-key="tool_sftp_read">读文件</button>
                <button type="button" class="tool-btn" data-key="tool_sftp_write">写文件</button>
                <button type="button" class="tool-btn" data-key="tool_sftp_list">列目录</button>
                <button type="button" class="tool-btn" data-key="tool_sftp_delete">删文件</button>
                <button type="button" class="tool-btn" data-key="tool_sftp_patch">补丁改</button>
              </div>
            </div>
            <div class="tools-group">
              <div class="tools-group-title">代码仓</div>
              <div class="tools-grid">
                <button type="button" class="tool-btn" data-key="tool_file_list">列文件</button>
                <button type="button" class="tool-btn" data-key="tool_file_read">读文件</button>
                <button type="button" class="tool-btn" data-key="tool_file_write">写文件</button>
                <button type="button" class="tool-btn" data-key="tool_file_delete">删文件</button>
                <button type="button" class="tool-btn" data-key="tool_file_push">推送</button>
                <button type="button" class="tool-btn" data-key="tool_file_patch">补丁改</button>
              </div>
            </div>
            <div class="tools-group">
              <div class="tools-group-title">工作中心</div>
              <div class="tools-grid">
                <button type="button" class="tool-btn" data-key="tool_ws_list">列文件</button>
                <button type="button" class="tool-btn" data-key="tool_ws_read">读文件</button>
                <button type="button" class="tool-btn" data-key="tool_ws_write">写文件</button>
                <button type="button" class="tool-btn" data-key="tool_ws_delete">删文件</button>
                <button type="button" class="tool-btn" data-key="tool_ws_patch">补丁改</button>
                <button type="button" class="tool-btn" data-key="tool_ws_zip">打包</button>
              </div>
            </div>
            <div class="tools-group">
              <div class="tools-group-title">其他</div>
              <div class="tools-grid">
                <button type="button" class="tool-btn" data-key="tool_web_open">抓网页</button>
                <button type="button" class="tool-btn" data-key="tool_web_search">实时搜索</button>
                <button type="button" class="tool-btn" data-key="tool_ppt_generate">生成PPT</button>
              </div>
            </div>
          </div><!-- /tools-pop-body -->
          <div class="tools-pop-foot">
            <button type="button" class="btn-save" id="btnSaveTools">保存</button>
          </div>
        </div>
        </div>
        <!-- 引用回复预览条：点了消息上的「引用」按钮后出现，发送或点 ✕ 消失 -->
        <div class="quote-bar" id="quoteBar" hidden>
          <span class="quote-bar-label">📌 引用</span>
          <span class="quote-bar-text" id="quoteBarText"></span>
          <button type="button" class="quote-bar-close" id="quoteBarClose" aria-label="取消引用" title="取消引用">✕</button>
        </div>
        <div class="composer-box">
          <input type="file" id="fileInput" accept="image/jpeg,image/png,image/gif,image/webp"
                 multiple hidden>
          <button class="btn-img" id="btnImg" type="button" title="上传图片"
                  aria-label="上传图片" <?= $models ? '' : 'disabled' ?> hidden>
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
              <path fill="currentColor" d="M21 5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5zm-2 0v9.2l-3.3-3.3a1 1 0 0 0-1.4 0L9 16.2l-2.3-2.3a1 1 0 0 0-1.4 0L5 14.2V5h14zM8.5 9.5A1.5 1.5 0 1 1 10 8a1.5 1.5 0 0 1-1.5 1.5z"/>
            </svg>
          </button>
          <textarea id="input" rows="1" placeholder="输入你的问题…"
                    <?= $models ? '' : 'disabled' ?>></textarea>
          <button class="btn btn-primary" id="btnSend" type="button" <?= $models ? '' : 'disabled' ?>>发送</button>
          <!-- 生成中才显示：停下当前回答，刚发的那句话会回填到输入框方便改了重发 -->
          <button class="btn btn-stop" id="btnStop" type="button" hidden>暂停</button>
        </div>
        <div class="composer-foot">
          <span id="statusTip">内容由 AI 生成，请自行判断准确性</span>
          <!-- 提示音：点喇叭弹出面板，可开关 + 调音量。设置按用户存在本地 -->
          <span class="sound-wrap">
            <button class="sound-toggle" id="btnSound" type="button"
                    aria-haspopup="true" aria-expanded="false"
                    title="任务完成提示音">🔔 提示音</button>
            <div class="sound-pop" id="soundPop" hidden>
              <label class="sound-row">
                <input type="checkbox" id="soundOn">
                <span>任务完成后提示</span>
              </label>
              <div class="sound-row sound-vol">
                <input type="range" id="soundVol" min="5" max="100" step="5"
                       aria-label="提示音音量">
                <b id="soundVolNum">45%</b>
              </div>
              <button type="button" class="sound-test" id="soundTest">试听</button>
              <!-- 声音容易被音乐盖住，再给两条不靠听觉的通道 -->
              <div class="sound-sep"></div>
              <label class="sound-row">
                <input type="checkbox" id="notifyOn">
                <span>桌面通知</span>
              </label>
              <label class="sound-row">
                <input type="checkbox" id="titleOn">
                <span>标签页标题闪烁</span>
              </label>
              <p class="sound-note" id="soundNote">切到别的窗口时才会提醒</p>
            </div>
          </span>
          <span>余额 ￥<b id="balTip"><?= money($me['balance']) ?></b></span>
        </div>
      </div>
    </div>
  </main>
</div>
</div>

<!-- 项目弹窗：新建与编辑共用 -->
<div class="pj-mask" id="pjMask" hidden>
  <div class="pj-dlg" role="dialog" aria-modal="true" aria-labelledby="pjTitle">
    <div class="pj-head">
      <h3 id="pjTitle">新建项目</h3>
      <button class="pj-x" type="button" id="pjClose" aria-label="关闭">×</button>
    </div>
    <div class="pj-body">
      <input type="hidden" id="pjId" value="0">
      <label class="pj-f">
        <span class="pj-lb">项目名称 <i>*</i></span>
        <input class="input" id="pjName" maxlength="40" placeholder="例如：公司官网改版">
      </label>
      <label class="pj-f">
        <span class="pj-lb">项目说明</span>
        <textarea class="input" id="pjIntro" rows="3" maxlength="500"
                  placeholder="这个项目要做什么，有什么要求。会作为背景告诉 AI，不用每次重复交代。"></textarea>
      </label>
      <label class="pj-f">
        <span class="pj-lb">技术栈</span>
        <input class="input" id="pjStack" maxlength="100" placeholder="例如：PHP 7.4 + MySQL + 原生 JS">
      </label>
      <label class="pj-f">
        <span class="pj-lb">部署服务器</span>
        <select class="select" id="pjHost">
          <option value="0">暂不绑定</option>
          <?php foreach ($myHosts as $hh): ?>
            <option value="<?= (int) $hh['id'] ?>"><?= h($hh['name']) ?>（<?= h($hh['host']) ?>）</option>
          <?php endforeach; ?>
        </select>
        <span class="pj-tip">
          绑定后，这个项目里让 AI 部署代码、查日志时会默认用这台机器。
          <a href="/servers.php">去登记服务器</a>
        </span>
      </label>
      <label class="pj-f">
        <span class="pj-lb">部署目录</span>
        <input class="input" id="pjDir" maxlength="200" placeholder="/www/wwwroot/我的站点">
        <span class="pj-tip">绝对路径。AI 会把这个项目的文件操作限定在这个目录内。</span>
      </label>
      <label class="pj-f">
        <span class="pj-lb">站点地址</span>
        <input class="input" id="pjUrl" maxlength="200" placeholder="http://example.com">
      </label>
      <div class="pj-err" id="pjErr" hidden></div>
    </div>
    <div class="pj-foot">
      <button class="btn" type="button" id="pjCancel">取消</button>
      <button class="btn btn-primary" type="button" id="pjSave">保存</button>
    </div>
  </div>
</div>

<!-- 项目操作菜单 -->
<div class="pj-pop" id="pjPop" hidden>
  <button type="button" data-do="edit">项目设置</button>
  <button type="button" data-do="newconv">在此项目新建对话</button>
  <button type="button" data-do="pin">置顶 / 取消置顶</button>
  <button type="button" data-do="archive">归档项目</button>
  <button type="button" data-do="del" class="danger">删除项目</button>
</div>

<script>
window.CSRF = <?= json_encode($csrf) ?>;
// 当前用户 ID：用于把「上次打开的会话」按用户隔离存到 localStorage
window.UID = <?= (int) $me['id'] ?>;
window.UPLOAD_MAX_IMAGE_SIZE_KB = <?= (int) setting_get('upload_max_image_size', 300) ?>;
// 用户工具开关配置：前端读取后填到面板，保存时提交到 /api/me.php?act=set_tools
window.USER_TOOLS = <?= json_encode($userTools ?: [
    'tool_ssh_exec' => 1, 'tool_sftp_read' => 1, 'tool_sftp_write' => 1,
    'tool_sftp_list' => 1, 'tool_sftp_delete' => 1, 'tool_sftp_patch' => 1,
    'tool_file_list' => 1, 'tool_file_read' => 1, 'tool_file_write' => 1,
    'tool_file_delete' => 1, 'tool_file_push' => 1, 'tool_file_patch' => 1,
    'tool_ws_list' => 1, 'tool_ws_read' => 1, 'tool_ws_write' => 1, 'tool_ws_delete' => 1,
    'tool_ws_patch' => 1, 'tool_ws_zip' => 1,
    'tool_web_open' => 1, 'tool_ppt_generate' => 1
]) ?>;
</script>
<script src="<?= asset('/assets/js/sftp_card.js') ?>"></script>
<script src="<?= asset('/assets/js/ssh_card.js') ?>"></script>
<script src="<?= asset('/assets/js/repo_card.js') ?>"></script>
<script src="<?= asset('/assets/js/ws_card.js') ?>"></script>
<script src="<?= asset('/assets/js/ppt_card.js') ?>"></script>
<script src="<?= asset('/assets/js/web_card.js') ?>"></script>
<script src="<?= asset('/assets/js/highlight.min.js') ?>?v=11.9.0"></script>
<script src="<?= asset('/assets/js/project.js') ?>"></script>
<script src="<?= asset('/assets/js/project_ui.js') ?>?force=<?= time() ?>"></script>
<script src="<?= asset('/assets/js/chat.js') ?>?force=<?= time() ?>"></script>
<script>
// 移动端侧栏切换
(function() {
  var btn = document.getElementById('sideToggle');
  var sidebar = document.getElementById('convSide');

  if (!btn || !sidebar) return;

  btn.addEventListener('click', function(e) {
    e.stopPropagation();
    sidebar.classList.toggle('open');
  });

  // 点击外部关闭
  document.addEventListener('click', function(e) {
    if (sidebar.classList.contains('open') &&
        !sidebar.contains(e.target) &&
        e.target !== btn) {
      sidebar.classList.remove('open');
    }
  });
})();
</script>
</body>
</html>