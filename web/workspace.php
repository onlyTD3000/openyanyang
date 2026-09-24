<?php
/**
 * 工作中心：用户自己的文件库（文件 + 图片同一个列表）+ 项目代码仓。
 *
 * 所有数据按 user_id 隔离，看不到也碰不到别人的东西。
 * 文件库里的文本文件可以在线查看和编辑，AI 也通过同一套接口读写。
 */
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/project.php';
require_once __DIR__ . '/inc/ws_files.php';

$me   = require_login();
$csrf = csrf_token();

$navOn     = 'workspace';
$pageTitle = '工作中心';
$siteName  = app_name();

$maxMb = max(1, (int) setting_get('upload_max_mb', 5));

// 空间：账号单独配额优先，没设就用后台全局默认
$配额字节 = 工作区配额((int) $me['id']);
$用量     = 工作区用量((int) $me['id']);
$fileBytes = $用量['bytes'];
$fileCount = $用量['count'];
$百分比   = $配额字节 > 0 ? round($fileBytes / $配额字节 * 100, 1) : 0;

// 按类型分别计数，标签页上要显示
$分类 = ['image' => 0, 'text' => 0, 'bin' => 0];
try {
    foreach (db_all('SELECT kind, COUNT(*) AS n FROM uploads WHERE user_id = ? GROUP BY kind',
        [$me['id']]) as $r) {
        $分类[(string) $r['kind']] = (int) $r['n'];
    }
} catch (Throwable $e) {
    // uploads 还没扩字段，提示去执行 sql/07_工作中心.sql
}
$需要升级 = false;
try {
    db_val('SELECT kind FROM uploads LIMIT 1');
} catch (Throwable $e) {
    $需要升级 = true;
}

// 代码仓概览（仅本人）。表可能还没建，用 try 兜住，别让整页 500。
$代码文件数 = 0;
$代码字节   = 0;
$代码改动数 = 0;
$仓项目数   = 0;
try {
    $c = db_one('SELECT COUNT(*) AS n, COALESCE(SUM(size),0) AS s FROM repo_files WHERE user_id = ?',
        [$me['id']]);
    $代码文件数 = (int) ($c['n'] ?? 0);
    $代码字节   = (int) ($c['s'] ?? 0);
    $代码改动数 = (int) db_val(
        'SELECT COUNT(*) FROM repo_files WHERE user_id = ? AND state IN (\'edited\',\'new\')',
        [$me['id']]);
    $仓项目数 = (int) db_val('SELECT COUNT(*) FROM repos WHERE user_id = ? AND file_count > 0',
        [$me['id']]);
} catch (Throwable $e) {
    // 代码仓表未安装，代码标签页会提示去执行 sql/04_代码仓.sql
}

// 项目下拉：只列绑定了服务器和部署目录的，其它拉不了代码
$项目表 = [];
foreach (project_list((int) $me['id']) as $p) {
    $项目表[] = [
        'id'         => (int) $p['id'],
        'name'       => (string) $p['name'],
        'host_id'    => (int) $p['host_id'],
        'deploy_dir' => (string) $p['deploy_dir'],
        'host_name'  => (string) ($p['host_name'] ?? ''),
        'ready'      => ((int) $p['host_id'] > 0 && trim((string) $p['deploy_dir']) !== '') ? 1 : 0,
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var ls=localStorage.getItem('theme');if(ls==='dark'||(!ls&&<?= (int)($me['dark_mode'] ?? 0) ?>))document.documentElement.classList.add('dark');})();</script>
<title><?= h($pageTitle) ?> - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>
<div class="app">
<?php require __DIR__ . '/inc/topbar.php'; ?>
<div class="page">

  <div class="page-head">
    <h1 class="page-title">工作中心</h1>
    <p class="hint">你的专属空间，只有你能看到。AI 产出的文件、你上传的文件和图片都存在这里，
      文本文件可以直接在线编辑；代码从项目绑定的服务器拉取后存在「项目代码」里。</p>
  </div>

<?php if ($需要升级): ?>
  <div class="card mb-16">
    <div class="card-body">
      <strong>需要先升级数据库</strong>
      <p class="hint">文件库功能需要执行一次 <code>sql/07_工作中心.sql</code>，
        执行后本页会自动显示文件和图片的合并列表。</p>
    </div>
  </div>
<?php endif; ?>

  <div class="ws-tabs" role="tablist">
    <button class="ws-tab on" type="button" role="tab" aria-selected="true"
            id="tabBtnFile" aria-controls="paneFile" data-pane="file">
      文件库 <span class="ws-tab-n"><?= fmt_int($fileCount) ?></span>
    </button>
    <button class="ws-tab" type="button" role="tab" aria-selected="false"
            id="tabBtnCode" aria-controls="paneCode" data-pane="code">
      项目代码 <span class="ws-tab-n"><?= fmt_int($代码文件数) ?></span>
    </button>
  </div>

<div class="ws-pane" id="paneFile" role="tabpanel" aria-labelledby="tabBtnFile">

  <div class="stat-grid mb-16">
    <div class="stat-card">
      <div class="stat-label">文件总数</div>
      <div class="stat-value"><?= fmt_int($fileCount) ?></div>
      <div class="hint" style="margin:6px 0 0">
        文档 <?= fmt_int($分类['text']) ?> · 图片 <?= fmt_int($分类['image']) ?>
        <?= $分类['bin'] > 0 ? ' · 其它 ' . fmt_int($分类['bin']) : '' ?>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-label">占用空间</div>
      <div class="stat-value" id="wsUsedText"><?= h(size_text($fileBytes)) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">空间配额</div>
      <div class="stat-value"><?= h(size_text($配额字节)) ?></div>
      <div class="hint" style="margin:6px 0 0">
        已用 <span id="wsPct"><?= $百分比 ?></span>%
        <?php if ($百分比 >= 90): ?>
          <strong class="text-err">快满了，请清理</strong>
        <?php endif; ?>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-label">单文件上限</div>
      <div class="stat-value"><?= (int) $maxMb ?> MB</div>
      <div class="hint" style="margin:6px 0 0">文本编辑上限 2 MB</div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      我的文件
      <span class="hint" style="margin:0">AI 写的文件会自动出现在这里，改完记得保存</span>
    </div>
    <div class="card-body">

      <div class="ws-bar">
        <select id="wsKind" class="input" style="max-width:130px" aria-label="按类型筛选">
          <option value="all">全部类型</option>
          <option value="text">文档 / 代码</option>
          <option value="image">图片</option>
          <option value="bin">其它文件</option>
        </select>
        <input type="search" id="wsSearch" class="input" style="max-width:200px"
               placeholder="搜文件名…" aria-label="搜索文件名">
        <button class="btn btn-sm" type="button" id="wsUpBtn">上传文件</button>
        <button class="btn btn-sm" type="button" id="wsNewBtn">新建文本</button>
        <input type="file" id="wsUpInput" hidden multiple>
        <span class="hint" style="margin:0" id="wsTip"></span>
      </div>

      <div id="wsMsg" class="ws-msg" hidden></div>

      <div class="repo-split">
        <div class="repo-list" id="wsList">
          <div class="ws-loading">正在加载…</div>
        </div>
        <div class="repo-view" id="wsView">
          <div class="ws-empty">点左侧文件名查看内容，文本文件可以直接改</div>
        </div>
      </div>

      <div class="pager" id="wsPager"></div>
    </div>
  </div>

</div><!-- /paneFile -->

<div class="ws-pane" id="paneCode" role="tabpanel" aria-labelledby="tabBtnCode" hidden>

  <div class="stat-grid mb-16">
    <div class="stat-card">
      <div class="stat-label">代码文件</div>
      <div class="stat-value"><?= fmt_int($代码文件数) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">占用空间</div>
      <div class="stat-value"><?= h(size_text($代码字节)) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">本地已改动</div>
      <div class="stat-value"><?= fmt_int($代码改动数) ?></div>
      <div class="hint" style="margin:6px 0 0">改完要回传才会生效</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">已拉取项目</div>
      <div class="stat-value"><?= fmt_int($仓项目数) ?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      项目代码
      <span class="hint" style="margin:0" id="repoHead">选一个项目查看</span>
    </div>
    <div class="card-body">

      <div class="ws-bar">
        <label class="hint" style="margin:0" for="repoProj">项目</label>
        <select id="repoProj" class="input" style="max-width:260px">
          <option value="">请选择项目…</option>
          <?php foreach ($项目表 as $p): ?>
            <option value="<?= (int) $p['id'] ?>" data-ready="<?= (int) $p['ready'] ?>"
                    data-dir="<?= h($p['deploy_dir']) ?>" data-host="<?= h($p['host_name']) ?>">
              <?= h($p['name']) ?><?= $p['ready'] ? '' : '（未绑定服务器）' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" type="button" id="repoPull" disabled>从服务器拉取</button>
        <button class="btn btn-sm" type="button" id="repoUpBtn" disabled>上传代码</button>
        <label class="hint" style="margin:0;display:inline-flex;align-items:center;gap:4px">
          <input type="checkbox" id="repoUploadExtract" checked>
          压缩包自动解压
        </label>
        <span class="hint" style="margin:0">取消后原样入仓；压缩包内容完整保留</span>
        <button class="btn btn-sm" type="button" id="repoOnlyDirty" hidden>只看改动</button>
        <label class="hint" style="margin:0;display:none;align-items:center;gap:4px" id="repoPickAllWrap">
          <input type="checkbox" id="repoPickAll">
          全选
        </label>
        <button class="btn btn-sm btn-danger" type="button" id="repoDelBtn" hidden>删除所选</button>
        <input type="file" id="repoUpInput" hidden multiple>
        <span class="hint" style="margin:0" id="repoTip"></span>
      </div>

      <div id="repoMsg" class="ws-msg" hidden></div>

      <div class="repo-split">
        <div class="repo-list" id="repoList">
          <div class="ws-empty">选一个项目，然后点「从服务器拉取」</div>
        </div>
        <div class="repo-view" id="repoView">
          <div class="ws-empty">点左侧文件名查看内容</div>
        </div>
      </div>

    </div>
  </div>

</div><!-- /paneCode -->

</div>
</div>

<!-- 大图查看 -->
<div class="ws-viewer" id="wsViewer" hidden>
  <button class="ws-viewer-close" type="button" id="wsViewerClose" aria-label="关闭">×</button>
  <img src="" alt="预览大图" id="wsViewerImg">
</div>

<script>
window.CSRF = <?= json_encode($csrf) ?>;
window.WS_MAX_MB = <?= (int) $maxMb ?>;
window.WS_QUOTA = <?= (int) $配额字节 ?>;
window.WS_PROJECTS = <?= json_encode($项目表, JSON_UNESCAPED_UNICODE) ?>;
window.WS_REPO_READY = <?= $代码文件数 > 0 || $仓项目数 > 0 ? 1 : 0 ?>;
</script>
<script src="<?= asset('/assets/js/ws_files.js') ?>"></script>
<script src="<?= asset('/assets/js/workspace_code.js') ?>"></script>
</body>
</html>
