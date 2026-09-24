<?php
// 行内改权重走 AJAX，必须在 _head.php 之前处理完并退出：
// _head.php 一进来就往外打 HTML，之后再输出 JSON 就成了 HTML+JSON 的混合响应。
// 走整页 POST 的话浏览器会重新加载并跳回页面顶部，模型多的时候每改一个权重
// 都得重新往下滚，所以这里单独开一条只回 JSON 的分支。
if (($_POST['act'] ?? '') === 'sort' && ($_POST['ajax'] ?? '') === '1') {
    require_once __DIR__ . '/../inc/helpers.php';
    // 权限校验不能因为走了 AJAX 就跳过。这里不用 require_admin()：
    // 它对未登录会 302 跳登录页，fetch 拿到的是登录页 HTML，前端只能报「格式异常」。
    // 用接口式校验直接回 JSON，会话过期时能明确提示。
    $当前 = require_login_api();
    if ($当前['role'] !== 'admin') {
        json_out(['ok' => false, 'error' => '无权访问'], 403);
    }
    csrf_check();
    $id   = (int) ($_POST['id'] ?? 0);
    $sort = (int) ($_POST['sort'] ?? 0);
    if ($id < 1) {
        json_out(['ok' => false, 'error' => '模型 ID 无效'], 400);
    }
    db_exec('UPDATE models SET sort = ? WHERE id = ?', [$sort, $id]);
    json_out(['ok' => true, 'sort' => $sort]);
}

$adminOn = 'models';
$pageTitle = '模型与定价';

// 整页 POST 也要在 _head.php 之前处理完，理由同上面那条 AJAX 分支：
// HTML 一旦开始输出，重定向就发不出去，刷新会重发表单。
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();

$msg = $err = '';
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = $_POST['act'] ?? '';
    $id  = (int) ($_POST['id'] ?? 0);

    if ($act === 'save') {
        $cid  = (int) ($_POST['channel_id'] ?? 0);
        $dn   = trim($_POST['display_name'] ?? '');
        $mn   = trim($_POST['model_name'] ?? '');
        $pin  = max(0, (float) ($_POST['price_in'] ?? 0));
        $pout = max(0, (float) ($_POST['price_out'] ?? 0));
        $pca  = max(0, (float) ($_POST['price_cache'] ?? 0));
        $pcc  = max(0, (float) ($_POST['price_cache_create'] ?? 0));
        // 上下文条数已改为对话级设置（聊天页输入框上方「⚙ 上下文」面板），
        // 后台不再逐模型配置。models.max_context 列保留作隐藏默认值：
        // 用户在对话里没设时后端回落它，新模型统一给 20。
        // 单次回复的输出上限（token）。上限放到 400000 以覆盖 384K 输出的模型，
        // 填 0 表示不向上游指定该参数、由上游自己的默认值决定。
        $mt   = max(0, min(400000, (int) ($_POST['max_tokens'] ?? 0)));
        $sp   = trim($_POST['system_prompt'] ?? '');
        $sort = (int) ($_POST['sort'] ?? 0);
        $st   = isset($_POST['status']) ? 1 : 0;
        $vis  = isset($_POST['vision']) ? 1 : 0;

        if (!db_one('SELECT id FROM channels WHERE id = ?', [$cid])) {
            $err = '请选择有效渠道';
        } elseif ($dn === '' || $mn === '') {
            $err = '显示名与上游模型名必填';
        } else {
            if ($id > 0) {
                // 不更新 max_context：对话级设置接管后，编辑模型不应把默认值盖回库里
                db_exec('UPDATE models SET channel_id=?, display_name=?, model_name=?, price_in=?, price_out=?,
                                price_cache=?, price_cache_create=?, max_tokens=?, vision=?, system_prompt=?, sort=?, status=? WHERE id=?',
                    [$cid, $dn, $mn, number_format($pin, 6, '.', ''), number_format($pout, 6, '.', ''),
                     number_format($pca, 6, '.', ''), number_format($pcc, 6, '.', ''),
                     $mt, $vis, $sp, $sort, $st, $id]);
                $msg = '模型已更新';
            } else {
                db_insert('INSERT INTO models (channel_id, display_name, model_name, price_in, price_out,
                                  price_cache, price_cache_create, max_context, max_tokens, vision, system_prompt, sort, status, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
                    [$cid, $dn, $mn, number_format($pin, 6, '.', ''), number_format($pout, 6, '.', ''),
                     number_format($pca, 6, '.', ''), number_format($pcc, 6, '.', ''),
                     20, $mt, $vis, $sp, $sort, $st]);
                $msg = '模型已添加';
            }
        }
    } elseif ($act === 'sort') {
        // 列表页行内改权重，改完前台下拉顺序同步跟着变（前台也是 sort DESC 排序）
        db_exec('UPDATE models SET sort = ? WHERE id = ?', [(int) ($_POST['sort'] ?? 0), $id]);
        $msg = '权重已更新，前台下拉顺序同步生效';
    } elseif ($act === 'toggle') {
        db_exec('UPDATE models SET status = 1 - status WHERE id = ?', [$id]);
        $msg = '状态已切换';
    } elseif ($act === 'delete') {
        db_exec('DELETE FROM models WHERE id = ?', [$id]);
        $msg = '模型已删除';
    }

    flash_set($err !== '' ? 'error' : 'ok', $err !== '' ? $err : $msg);
    redirect_self();
}

[$flash类型, $flash文本] = flash_get();
if ($flash类型 === 'error') {
    $err = $flash文本;
} elseif ($flash类型 === 'ok') {
    $msg = $flash文本;
}

require __DIR__ . '/_head.php';

if (($_GET['edit'] ?? '') !== '') {
    $edit = db_one('SELECT * FROM models WHERE id = ?', [(int) $_GET['edit']]);
}
$channels = db_all(
    'SELECT c.id, c.status,
            CASE WHEN p.name IS NULL OR p.name = "" THEN c.name
                 ELSE CONCAT(p.name, " / ", c.name) END AS name
       FROM channels c LEFT JOIN api_platforms p ON p.id = c.parent_id
      ORDER BY p.sort DESC, p.id DESC, c.sort DESC, c.id DESC');
// 排序规则和前台下拉保持一致（index.php 也是 sort DESC, id ASC），
// 否则后台看到的顺序和用户实际看到的不一样，调权重时会调错。
$list = db_all('SELECT m.*, c.name AS ch_name, c.status AS ch_status
                  FROM models m LEFT JOIN channels c ON c.id = m.channel_id
                 ORDER BY m.sort DESC, m.id ASC');
?>
<div class="page-head">
  <h1 class="page-title">模型与定价</h1>
  <div class="spacer"></div>
  <?php if ($edit): ?><a class="btn" href="/admin/models.php">取消编辑</a><?php endif; ?>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<?php if (!$channels): ?>
  <div class="alert alert-info">请先到 <a href="/admin/channels.php">API 渠道</a> 添加一个渠道。</div>
<?php else: ?>
<div class="alert alert-info">
  价格单位为 <b>元 / 百万 tokens</b>。用户每次对话按上游返回的实际 token 数结算；
  上游未返回用量时按字符估算（明细中标记「估算」）。
  缓存价只对上游<b>明确报告命中缓存</b>的那部分输入生效，缓存创建价(5m)对上游<b>报告缓存写入</b>的输入生效，填 0 就不区分、全按输入价收；
  走估算的请求也一律按输入价收。缓存开关在
  <a href="/admin/settings.php">站点设置</a>。
</div>

<div class="card mb-16">
  <div class="card-head"><?= $edit ? '编辑模型 #' . (int) $edit['id'] : '添加模型' ?></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
      <div class="form-grid">
        <label class="field"><span class="field-label">所属渠道</span>
          <select class="select" name="channel_id" required>
            <?php foreach ($channels as $c): ?>
              <option value="<?= (int) $c['id'] ?>"
                <?= ($edit && (int) $edit['channel_id'] === (int) $c['id']) ? 'selected' : '' ?>>
                <?= h($c['name']) ?><?= (int) $c['status'] === 1 ? '' : '（已停用）' ?>
              </option>
            <?php endforeach; ?>
          </select></label>
        <label class="field"><span class="field-label">前台显示名</span>
          <input class="input" name="display_name" required value="<?= h($edit['display_name'] ?? '') ?>" placeholder="例：GPT-4o mini"></label>
        <label class="field"><span class="field-label">上游模型名</span>
          <input class="input" name="model_name" required value="<?= h($edit['model_name'] ?? '') ?>" placeholder="gpt-4o-mini"></label>
        <label class="field"><span class="field-label">输入价（元/百万tokens）</span>
          <input class="input" name="price_in" type="number" step="0.000001" min="0"
                 value="<?= h($edit['price_in'] ?? '1.000000') ?>"></label>
        <label class="field"><span class="field-label">输出价（元/百万tokens）</span>
          <input class="input" name="price_out" type="number" step="0.000001" min="0"
                 value="<?= h($edit['price_out'] ?? '4.000000') ?>"></label>
        <label class="field"><span class="field-label">缓存价（元/百万tokens，0=不区分）</span>
          <input class="input" name="price_cache" type="number" step="0.000001" min="0"
                 value="<?= h($edit['price_cache'] ?? '0.000000') ?>">
          <span class="field-hint">上游报告命中提示词缓存的那部分输入按这个价结算，通常是输入价的 1/10。</span></label>
        <label class="field"><span class="field-label">缓存创建价(5m)（元/百万tokens，0=不区分）</span>
          <input class="input" name="price_cache_create" type="number" step="0.000001" min="0"
                 value="<?= h($edit['price_cache_create'] ?? '0.000000') ?>">
          <span class="field-hint">上游报告缓存写入(5分钟缓存)的那部分输入按这个价结算，留空或填0则按输入价收。</span></label>
        <label class="field"><span class="field-label">上下文条数</span>
          <span class="field-hint" style="display:block">已改为对话级设置：用户在聊天输入框上方「⚙ 上下文」面板里自行设置，只对当前对话生效，切换对话恢复默认。此处不可再配，用户未设置时按本模型默认值 <?= (int) ($edit['max_context'] ?? 20) ?> 条。</span></label>
        <label class="field"><span class="field-label">单次输出上限（tokens，0=不限制）</span>
          <input class="input" name="max_tokens" type="number" min="0" max="400000" step="1"
                 value="<?= (int) ($edit['max_tokens'] ?? 0) ?>">
          <span class="field-hint">模型单次回复最多输出多少 token。写大文件时这个值太小会导致回复被截断、SSE 中断。填 0 表示不向上游指定，由上游默认值决定。</span></label>
        <label class="field"><span class="field-label">排序(大在前)</span>
          <input class="input" name="sort" type="number" value="<?= (int) ($edit['sort'] ?? 0) ?>"></label>
        <label class="field"><span class="field-label">启用</span>
          <span><input type="checkbox" name="status" value="1"
            <?= (!$edit || (int) $edit['status'] === 1) ? 'checked' : '' ?>> 对用户可见</span></label>
        <label class="field"><span class="field-label">图片输入</span>
          <span><input type="checkbox" name="vision" value="1"
            <?= (int) ($edit['vision'] ?? 0) === 1 ? 'checked' : '' ?>> 支持上传图片（视觉模型）</span></label>
      </div>
      <label class="field mt-16"><span class="field-label">系统提示词（可选，追加在平台准则之后）</span>
        <textarea class="textarea" name="system_prompt" rows="3"><?= h($edit['system_prompt'] ?? '') ?></textarea>
        <span class="field-hint">平台准则 inc/soul.md 已自动应用到所有模型，这里只填该模型的额外要求。留空即只用平台准则。</span></label>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $edit ? '保存修改' : '添加模型' ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">模型列表</div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>ID</th><th>权重</th><th>显示名</th><th>上游模型</th><th>渠道</th>
                 <th>输入价</th><th>输出价</th><th>缓存价</th><th>缓存创建价(5m)</th><th>输出上限</th><th>状态</th><th>操作</th></tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="12" class="empty">还没有模型</td></tr>
      <?php else: foreach ($list as $m): ?>
        <tr>
          <td><?= (int) $m['id'] ?></td>
          <td>
            <form method="post" class="js-sort" data-id="<?= (int) $m['id'] ?>"
                  style="display:flex;gap:4px;align-items:center">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="act" value="sort">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <input class="input" name="sort" type="number" style="width:66px;padding:4px 6px"
                     value="<?= (int) $m['sort'] ?>">
              <button class="btn btn-sm" type="submit" title="保存权重">存</button>
              <span class="js-sort-tip hint" style="min-width:2em"></span>
            </form>
          </td>
          <td><?= h($m['display_name']) ?>
            <?= trim((string) $m['system_prompt']) !== '' ? '<span class="badge badge-blue">含人设</span>' : '' ?>
            <?= (int) ($m['vision'] ?? 0) === 1 ? ' <span class="badge badge-ok">图</span>' : '' ?></td>
          <td><span class="hint"><?= h($m['model_name']) ?></span></td>
          <td><?= h($m['ch_name'] ?? '—') ?>
            <?= (int) $m['ch_status'] !== 1 ? '<span class="badge badge-off">渠道停用</span>' : '' ?></td>
          <td>￥<?= money($m['price_in']) ?></td>
          <td>￥<?= money($m['price_out']) ?></td>
          <td><?= (float) $m['price_cache'] > 0
                ? '￥' . money($m['price_cache'])
                : '<span class="hint">按输入价</span>' ?></td>
          <td><?= (float) $m['price_cache_create'] > 0
                ? '￥' . money($m['price_cache_create'])
                : '<span class="hint">按输入价</span>' ?></td>
          <td><?= (int) $m['max_context'] ?> 条<?= (int) ($m['vision'] ?? 0) === 1 ? ' <span class="badge badge-ok">图</span>' : '' ?></td>
          <td><?= (int) ($m['max_tokens'] ?? 0) > 0
                ? number_format((int) $m['max_tokens'])
                : '<span class="hint">不限制</span>' ?></td>
          <td><?= (int) $m['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-off">停用</span>' ?></td>
          <td class="acts">
            <a class="btn btn-sm" href="/admin/models.php?edit=<?= (int) $m['id'] ?>">编辑</a>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="act" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= (int) $m['status'] === 1 ? '停用' : '启用' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('删除该模型？')">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// 权重保存改成 fetch，页面不刷新、滚动位置不动。
// 表单本身保留 method="post" 和那几个 hidden 字段：JS 出错或被拦时
// 还能退回整页提交，功能不至于失效。
document.querySelectorAll('form.js-sort').forEach(function (表单) {
  var 提示 = 表单.querySelector('.js-sort-tip');
  var 按钮 = 表单.querySelector('button');
  var 计时器 = null;

  表单.addEventListener('submit', function (e) {
    e.preventDefault();
    if (按钮.disabled) { return; }   // 防连点，避免同一行发出多个请求
    按钮.disabled = true;
    提示.textContent = '…';
    提示.style.color = '';

    var 数据 = new FormData(表单);
    数据.append('ajax', '1');

    fetch(location.pathname, {
      method: 'POST',
      body: 数据,
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: '返回格式异常' }; });
    }).then(function (j) {
      if (j && j.ok) {
        提示.textContent = '已存';
        提示.style.color = '#16a34a';
      } else {
        提示.textContent = (j && (j.error || j.err)) || '失败';
        提示.style.color = '#dc2626';
      }
    }).catch(function () {
      提示.textContent = '网络错误';
      提示.style.color = '#dc2626';
    }).finally(function () {
      按钮.disabled = false;
      // 提示留一会儿就淡掉，免得一排「已存」一直挂着
      clearTimeout(计时器);
      计时器 = setTimeout(function () { 提示.textContent = ''; }, 2500);
    });
  });
});
</script>
<?php require __DIR__ . '/_foot.php'; ?>
