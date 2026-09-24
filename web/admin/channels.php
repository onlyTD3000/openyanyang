<?php
/**
 * 重写 admin/channels.php → 新的「父分类 + 子分类」界面
 *
 * 操作：
 *   父分类 (api_platforms)：新增 / 编辑 / 删除 / 启停 / 查询余额
 *   子分类 (channels)    ：新增 / 编辑（只填 name+api_key+sort+status）/ 删除 / 启停 / 测试连通
 *   点击父分类标题行 → 展开/收缩子分类
 *
 * POST act:
 *   platform_save / platform_toggle / platform_delete / platform_balance
 *   channel_save  / channel_toggle  / channel_delete  / test
 */
$ROOT_RUNTIME = __DIR__ . '/..';
?>
<?php
$adminOn = 'channels';
$pageTitle = 'API 渠道';

require_once $ROOT_RUNTIME . '/inc/helpers.php';
$me = require_admin();
require_once $ROOT_RUNTIME . '/inc/upstream.php';

$msg = $err = '';
$editPlat = null;     // 正在编辑的父分类
$editChan = null;     // 正在编辑的子分类
$余额结果 = null;      // 查余额返回的结果

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = $_POST['act'] ?? '';
    $id  = (int) ($_POST['id'] ?? 0);

    // ====== 父分类 ======
    if ($act === 'platform_save') {
        $name   = trim($_POST['name'] ?? '');
        $base   = trim($_POST['base_url'] ?? '');
        $token  = trim($_POST['access_token'] ?? '');
        $userId = trim($_POST['api_user_id'] ?? '');
        $proto  = (($_POST['protocol'] ?? 'claude') === 'openai') ? 'openai' : 'claude';
        $默认路径 = $proto === 'claude' ? '/messages' : '/chat/completions';
        $path   = trim($_POST['chat_path'] ?? '') ?: $默认路径;
        $ver    = trim($_POST['api_version'] ?? '') ?: '2023-06-01';
        $to     = max(30, min(600, (int)($_POST['timeout'] ?? 120)));
        $sort   = (int)($_POST['sort'] ?? 0);
        $st     = isset($_POST['status']) ? 1 : 0;
        $rm     = trim($_POST['remark'] ?? '');
        if ($name === '' || $base === '') $err = '平台名称和接口地址必填';
        elseif (!preg_match('#^https?://#i', $base)) $err = '接口地址需 http(s):// 开头';
        else {
            if ($id > 0) {
                if ($token === '') {
                    db_exec('UPDATE api_platforms SET name=?,base_url=?,api_user_id=?,protocol=?,chat_path=?,api_version=?,timeout=?,sort=?,status=?,remark=? WHERE id=?',
                        [$name, rtrim($base,'/'), $userId, $proto, $path, $ver, $to, $sort, $st, $rm, $id]);
                } else {
                    db_exec('UPDATE api_platforms SET name=?,base_url=?,access_token=?,api_user_id=?,protocol=?,chat_path=?,api_version=?,timeout=?,sort=?,status=?,remark=? WHERE id=?',
                        [$name, rtrim($base,'/'), $token, $userId, $proto, $path, $ver, $to, $sort, $st, $rm, $id]);
                }
                $msg = '父分类已更新';
            } else {
                db_insert('INSERT INTO api_platforms (name,base_url,access_token,api_user_id,protocol,chat_path,api_version,timeout,sort,status,remark,created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
                    [$name, rtrim($base,'/'), $token, $userId, $proto, $path, $ver, $to, $sort, $st, $rm]);
                $msg = '父分类已添加';
            }
        }
    } elseif ($act === 'platform_toggle') {
        db_exec('UPDATE api_platforms SET status = 1 - status WHERE id = ?', [$id]);
        $msg = '父分类状态已切换';
    } elseif ($act === 'platform_delete') {
        $n = (int) db_val('SELECT COUNT(*) FROM channels WHERE parent_id = ?', [$id]);
        if ($n > 0) $err = "该父分类下还有 $n 个子分类，请先删除或转移子分类";
        else { db_exec('DELETE FROM api_platforms WHERE id = ?', [$id]); $msg = '父分类已删除'; }
    } elseif ($act === 'platform_balance') {
        $余额结果 = platform_balance_check($id);
        $plat = db_one('SELECT * FROM api_platforms WHERE id=?', [$id]);
        if ($plat) {
            if (!empty($余额结果['ok'])) {
                $msg = "【{$plat['name']}】累计消费 {$余额结果['total_usage_usd']} USD (￥".round($余额结果['total_usage_usd']*7.2,2).") · 上限 {$余额结果['soft_limit_usd']} USD · {$余额结果['access_until_str']}";
                if ($余额结果['remaining_usd'] !== null) {
                    $msg .= " · 剩余 ≈ {$余额结果['remaining_usd']} USD (￥".round($余额结果['remaining_usd']*7.2,2).")";
                }
            } else {
                $err = "【{$plat['name']}】余额查询失败：{$余额结果['err']}";
            }
        }
    }

    // ====== 子分类 ======
    elseif ($act === 'channel_save') {
        $pid  = (int)($_POST['parent_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $key  = trim($_POST['api_key'] ?? '');
        $sort = (int)($_POST['sort'] ?? 0);
        $st   = isset($_POST['status']) ? 1 : 0;
        $rm   = trim($_POST['remark'] ?? '');
        if ($pid <= 0) $err = '请选择所属父分类';
        elseif ($name === '') $err = '子分类名称必填';
        elseif ($id === 0 && $key === '') $err = '请填写 API Key';
        else {
            if ($id > 0) {
                if ($key === '') {
                    db_exec('UPDATE channels SET parent_id=?,name=?,sort=?,status=?,remark=? WHERE id=?',
                        [$pid, $name, $sort, $st, $rm, $id]);
                } else {
                    db_exec('UPDATE channels SET parent_id=?,name=?,api_key=?,sort=?,status=?,remark=? WHERE id=?',
                        [$pid, $name, $key, $sort, $st, $rm, $id]);
                }
                $msg = '子分类已更新';
            } else {
                // 新增子分类：base_url/protocol/chat_path/api_version/timeout 先留空，运行时从父继承
                $plat = db_one('SELECT base_url,protocol,chat_path,api_version,timeout FROM api_platforms WHERE id=?', [$pid]);
                db_insert('INSERT INTO channels (parent_id,name,base_url,api_key,protocol,chat_path,api_version,timeout,sort,status,remark,created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
                    [$pid, $name, $plat['base_url']??'', $key, $plat['protocol']??'openai', $plat['chat_path']??'/chat/completions',
                     $plat['api_version']??'2023-06-01', (int)($plat['timeout']??120), $sort, $st, $rm]);
                $msg = '子分类已添加';
            }
        }
    } elseif ($act === 'channel_toggle') {
        db_exec('UPDATE channels SET status = 1 - status WHERE id = ?', [$id]);
        $msg = '子分类状态已切换';
    } elseif ($act === 'channel_delete') {
        $n = (int) db_val('SELECT COUNT(*) FROM models WHERE channel_id = ?', [$id]);
        if ($n > 0) $err = "该子分类下还有 $n 个模型，请先删除模型";
        else { db_exec('DELETE FROM channels WHERE id = ?', [$id]); $msg = '子分类已删除'; }
    } elseif ($act === 'test') {
        $ch = db_one('SELECT * FROM channels WHERE id = ?', [$id]);
        if ($ch) $ch = channel_merge_platform($ch);
        $mn = trim($_POST['model_name'] ?? '');
        if (!$ch) $err = '子分类不存在';
        elseif ($mn === '') $err = '请填写用于测试的模型名';
        else {
            $r = upstream_test($ch, $mn);
            $r['ok'] ? $msg = '测试成功：' . $r['msg'] : $err = '测试失败：' . $r['msg'];
        }
    }

    flash_set($err !== '' ? 'error' : 'ok', $err !== '' ? $err : $msg);
    redirect_self();
}

[$flash类型, $flash文本] = flash_get();
if ($flash类型 === 'error') $err = $flash文本;
elseif ($flash类型 === 'ok') $msg = $flash文本;

require __DIR__ . '/_head.php';

// 编辑态
if (($_GET['edit_platform'] ?? '') !== '') {
    $editPlat = db_one('SELECT * FROM api_platforms WHERE id=?', [(int)$_GET['edit_platform']]);
}
if (($_GET['edit_channel'] ?? '') !== '') {
    $editChan = db_one('SELECT * FROM channels WHERE id=?', [(int)$_GET['edit_channel']]);
}

// 查询：父分类 + 每个父分类下的子分类数量和模型数量
$plats = db_all('SELECT p.*,
    (SELECT COUNT(*) FROM channels c WHERE c.parent_id = p.id) ch_cnt,
    (SELECT COUNT(*) FROM models m JOIN channels c ON c.id=m.channel_id WHERE c.parent_id=p.id) md_cnt
    FROM api_platforms p ORDER BY p.sort DESC, p.id DESC');

// 独立渠道（parent_id=0，还未挂到父分类下的遗留）
$孤立 = db_all('SELECT c.*, (SELECT COUNT(*) FROM models m WHERE m.channel_id=c.id) md_cnt
    FROM channels c WHERE c.parent_id=0 ORDER BY c.sort DESC, c.id DESC');

// 每个父分类下的子分类列表（按父分类ID索引）
$channelsByPid = [];
foreach (db_all('SELECT c.*, (SELECT COUNT(*) FROM models m WHERE m.channel_id=c.id) md_cnt
    FROM channels c WHERE c.parent_id>0 ORDER BY c.parent_id, c.sort DESC, c.id DESC') as $c) {
    $channelsByPid[(int)$c['parent_id']][] = $c;
}

// 所有父分类（给子分类下拉框用）
$allPlats = db_all('SELECT id, name FROM api_platforms ORDER BY sort DESC, id');
?>
<div class="page-head">
  <h1 class="page-title">API 渠道（平台 / 子分类）</h1>
  <div class="spacer"></div>
  <?php if ($editPlat || $editChan): ?><a class="btn" href="/admin/channels.php">取消编辑</a><?php endif; ?>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<div class="alert alert-info">
  <b>父分类（NewAPI平台）</b>：填访问令牌后可「一键查余额」，统一维护 base_url、协议、默认超时。<br>
  <b>子分类</b>：只填「名称 + SK」，自动继承父分类的地址和协议。一台中转平台建一个父分类，下面挂 N 个分组Key。
</div>

<?php
// ============ 父分类编辑表单 ============
if ($editPlat || !$editChan): ?>
<div class="card mb-16">
  <div class="card-head"><?= $editPlat ? '编辑父分类 #'.(int)$editPlat['id'] : '添加父分类（NewAPI平台）' ?></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="platform_save">
      <input type="hidden" name="id" value="<?= $editPlat ? (int)$editPlat['id'] : 0 ?>">
      <div class="form-grid">
        <label class="field"><span class="field-label">平台名称（方便记忆）</span>
          <input class="input" name="name" required value="<?= h($editPlat['name'] ?? '') ?>" placeholder="例：一元Token、某鱼API"></label>
        <label class="field"><span class="field-label">接口地址 base_url</span>
          <input class="input" name="base_url" required value="<?= h($editPlat['base_url'] ?? '') ?>" placeholder="https://api.example.com/v1"></label>
        <label class="field"><span class="field-label">访问令牌（余额查询用）<?= $editPlat ? '（留空不修改）' : '' ?></span>
          <input class="input" name="access_token" type="password" autocomplete="new-password" placeholder="sk-...（Bearer认证）"></label>
        <label class="field"><span class="field-label">用户ID（部分NewAPI站点查余额需要）</span>
          <input class="input" name="api_user_id" value="<?= h($editPlat['api_user_id'] ?? '') ?>" placeholder="可留空，站点报401/用户不存在时填这里"></label>
        <label class="field"><span class="field-label">上游协议</span>
          <select class="select" name="protocol" id="plat_proto">
            <?php $pp = ($editPlat['protocol'] ?? 'claude') === 'openai' ? 'openai' : 'claude'; ?>
            <option value="claude" <?= $pp === 'claude' ? 'selected' : '' ?>>Anthropic 原生（/messages）</option>
            <option value="openai" <?= $pp === 'openai' ? 'selected' : '' ?>>OpenAI 兼容（/chat/completions）</option>
          </select></label>
        <label class="field"><span class="field-label">请求路径</span>
          <input class="input" name="chat_path" id="plat_path" value="<?= h($editPlat['chat_path'] ?? '/messages') ?>"></label>
        <label class="field"><span class="field-label">API Version（Claude用）</span>
          <input class="input" name="api_version" value="<?= h($editPlat['api_version'] ?? '2023-06-01') ?>"></label>
        <label class="field"><span class="field-label">超时(秒)</span>
          <input class="input" name="timeout" type="number" min="30" max="600" value="<?= (int)($editPlat['timeout'] ?? 120) ?>"></label>
        <label class="field"><span class="field-label">排序(大在前)</span>
          <input class="input" name="sort" type="number" value="<?= (int)($editPlat['sort'] ?? 0) ?>"></label>
        <label class="field"><span class="field-label">备注</span>
          <input class="input" name="remark" value="<?= h($editPlat['remark'] ?? '') ?>"></label>
        <label class="field"><span class="field-label">启用</span>
          <span><input type="checkbox" name="status" value="1"
            <?= (!$editPlat || (int)$editPlat['status'] === 1) ? 'checked' : '' ?>> 启用该平台</span></label>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $editPlat ? '保存父分类' : '添加父分类' ?></button>
      </div>
    </form>
    <script>
      (function(){
        var s=document.getElementById('plat_proto'),p=document.getElementById('plat_path');
        if(!s||!p) return;
        var D={openai:'/chat/completions',claude:'/messages'};
        s.addEventListener('change',function(){
          var v=(p.value||'').trim();
          if(v===''||v===D.openai||v===D.claude)p.value=D[s.value];
        });
      })();
    </script>
  </div>
</div>
<?php endif;

// ============ 子分类编辑表单 ============
if ($editChan || !$editPlat):
$选pid = (int)($editChan['parent_id'] ?? ($_GET['pid'] ?? 0));
?>
<div class="card mb-16">
  <div class="card-head"><?= $editChan ? '编辑子分类 #'.(int)$editChan['id'] : '添加子分类（新建SK）' ?></div>
  <div class="card-body">
    <?php if (!$allPlats): ?>
      <div class="alert alert-error">还没有父分类，先在上面「添加父分类」填好平台信息。</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="channel_save">
      <input type="hidden" name="id" value="<?= $editChan ? (int)$editChan['id'] : 0 ?>">
      <div class="form-grid">
        <label class="field"><span class="field-label">所属父分类</span>
          <select class="select" name="parent_id" required>
            <option value="">--请选择--</option>
            <?php foreach ($allPlats as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $选pid === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="field"><span class="field-label">子分类名称</span>
          <input class="input" name="name" required value="<?= h($editChan['name'] ?? '') ?>" placeholder="例：满血版、GPT分组、国产模型等"></label>
        <label class="field"><span class="field-label">API Key（SK）<?= $editChan ? '（留空不修改）' : '' ?></span>
          <input class="input" name="api_key" type="password" autocomplete="new-password"
                 <?= $editChan ? '' : 'required' ?> placeholder="sk-..."></label>
        <label class="field"><span class="field-label">排序(大在前)</span>
          <input class="input" name="sort" type="number" value="<?= (int)($editChan['sort'] ?? 0) ?>"></label>
        <label class="field"><span class="field-label">备注</span>
          <input class="input" name="remark" value="<?= h($editChan['remark'] ?? '') ?>"></label>
        <label class="field"><span class="field-label">启用</span>
          <span><input type="checkbox" name="status" value="1"
            <?= (!$editChan || (int)$editChan['status'] === 1) ? 'checked' : '' ?>> 启用该子分类</span></label>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $editChan ? '保存子分类' : '添加子分类' ?></button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
// ============ 父分类列表（可展开子分类） ============
if (!$plats): ?>
  <div class="card"><div class="card-body empty">还没有父分类，先在上方新建。</div></div>
<?php else: foreach ($plats as $p): ?>
<div class="card mb-16">
  <div class="card-head plat-head" data-pid="<?= (int)$p['id'] ?>">
    <span class="plat-toggle">▸</span>
    <span class="plat-title">
      <span class="badge <?= (int)$p['status'] === 1 ? 'badge-ok' : 'badge-off' ?>"><?= (int)$p['status'] ? '启用' : '停用' ?></span>
      <b>#<?= (int)$p['id'] ?> <?= h($p['name']) ?></b>
      <?php if ($p['remark']): ?><span class="hint">· <?= h($p['remark']) ?></span><?php endif; ?>
    </span>
    <span class="spacer"></span>
    <span class="plat-meta hint">
      <?= h($p['base_url'] . $p['chat_path']) ?>
      ·
      子分类<?= (int)$p['ch_cnt'] ?>
      ·
      模型<?= (int)$p['md_cnt'] ?>
      ·
      Token: <?= $p['access_token'] !== '' ? h(mb_substr($p['access_token'],0,6)).'••••' : '<span class="badge badge-off">未填（不能查余额）</span>' ?>
    </span>
    <form method="post" class="inline-form" onsubmit="return confirm('查余额？');">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="platform_balance">
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <button class="btn btn-sm" type="submit" title="用access_token查剩余额度/累计消费">💰 查余额</button>
    </form>
    <a class="btn btn-sm" href="/admin/channels.php?edit_platform=<?= (int)$p['id'] ?>">编辑</a>
    <a class="btn btn-sm" href="/admin/channels.php?pid=<?= (int)$p['id'] ?>#channel-form" title="快速在该父分类下新建SK">+子分类</a>
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="platform_toggle">
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <button class="btn btn-sm" type="submit"><?= (int)$p['status'] ? '停用' : '启用' ?></button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('删除父分类<?= (int)$p['id'] ?>？要求下面没有子分类');">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="platform_delete">
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <button class="btn btn-sm btn-danger" type="submit">删除</button>
    </form>
  </div>

  <div class="table-wrap plat-children" id="children-<?= (int)$p['id'] ?>" style="display:none">
    <table class="tbl">
      <thead><tr>
        <th>ID</th><th>名称</th><th>SK</th><th>模型数</th><th>状态</th>
        <th>连通测试</th><th>操作</th>
      </tr></thead>
      <tbody>
      <?php $children = $channelsByPid[(int)$p['id']] ?? []; if (!$children): ?>
        <tr><td colspan="7" class="empty">还没有子分类。点上面「+子分类」在该平台下新建一个SK。</td></tr>
      <?php else: foreach ($children as $c): ?>
        <tr>
          <td><?= (int)$c['id'] ?></td>
          <td><?= h($c['name']) ?><?php if ($c['remark']): ?><div class="hint"><?= h($c['remark']) ?></div><?php endif; ?></td>
          <td>
            <?php $skList = channel_sk_list($c['api_key']); $skN = count($skList); ?>
            <span class="badge badge-off"><?= $skN ? h(mb_substr($skList[0],0,6)).'••••' : '未填' ?></span>
            <?php if ($skN > 1): ?>
              <span class="badge <?= (int)$c['rotate'] === 1 ? 'badge-ok' : 'badge-off' ?>"
                    title="共 <?= $skN ?> 个 SK<?= (int)$c['rotate'] === 1 ? '，当前轮询到第 '.((int)$c['rotate_idx'] % $skN + 1).' 个' : '，轮询未开启，只用第 1 个' ?>">
                <?= $skN ?>个<?= (int)$c['rotate'] === 1 ? ' ⟳轮询' : ' 未轮询' ?>
              </span>
            <?php endif; ?>
          </td>
          <td><?= (int)$c['md_cnt'] ?></td>
          <td><?= (int)$c['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-off">停用</span>' ?></td>
          <td>
            <form method="post" class="inline-form">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="act" value="test">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <input class="input" style="width:160px" name="model_name" placeholder="claude-sonnet-5等" required>
              <button class="btn btn-sm" type="submit">测试</button>
            </form>
          </td>
          <td class="acts">
            <a class="btn btn-sm" href="/admin/channel_rotate.php?id=<?= (int)$c['id'] ?>">设置轮询</a>
            <a class="btn btn-sm" href="/admin/channels.php?edit_channel=<?= (int)$c['id'] ?>">编辑</a>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="act" value="channel_toggle">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= (int)$c['status'] ? '停用' : '启用' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('删除该子分类？模型会一起空出来报错，先删模型。');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="act" value="channel_delete">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; endif;

// ============ 孤立子分类（parent_id=0，旧数据兜底） ============
if ($孤立): ?>
<div class="card mb-16">
  <div class="card-head"><b>⚠️ 未分配父分类的遗留渠道</b>（parent_id=0，请编辑这些渠道挂到上面的父分类下）</div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>ID</th><th>名称</th><th>Key</th><th>base_url</th><th>模型数</th><th>状态</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($孤立 as $c): ?>
        <tr>
          <td><?= (int)$c['id'] ?></td>
          <td><?= h($c['name']) ?></td>
          <td><span class="badge badge-off"><?= h(mb_substr($c['api_key'],0,6)) ?>••••</span></td>
          <td class="hint"><?= h($c['base_url']) ?></td>
          <td><?= (int)$c['md_cnt'] ?></td>
          <td><?= (int)$c['status'] ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-off">停用</span>' ?></td>
          <td class="acts">
            <a class="btn btn-sm" href="/admin/channel_rotate.php?id=<?= (int)$c['id'] ?>">设置轮询</a>
            <a class="btn btn-sm" href="/admin/channels.php?edit_channel=<?= (int)$c['id'] ?>">分配父分类</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  // 父分类标题点击 → 展开/收缩子分类
  var heads=document.querySelectorAll('.plat-head');
  heads.forEach(function(h){
    var pid=h.getAttribute('data-pid');
    var box=document.getElementById('children-'+pid);
    var tog=h.querySelector('.plat-toggle');
    function 应用(开){
      box.style.display=开?'':'none';
      tog.textContent=开?'▾':'▸';
      try{ localStorage.setItem('plat_open_'+pid, 开?'1':'0'); }catch(e){}
    }
    // 读取本地状态（默认展开第1个）
    var 默认开 = heads[0] === h;
    var 开 = true;
    try{
      var s = localStorage.getItem('plat_open_'+pid);
      开 = s === null ? 默认开 : s === '1';
    }catch(e){ 开 = 默认开; }
    应用(开);
    h.addEventListener('click', function(e){
      // 点按钮/链接/表单时不切换
      if (e.target.closest('button, a, form, input, select, label')) return;
      应用(box.style.display === 'none');
    });
  });
})();
</script>
<style>
.plat-head{cursor:pointer;user-select:none;display:flex;align-items:center;gap:8px;padding:12px 16px}
.plat-toggle{font-size:18px;color:#555;transition:transform .15s}
.plat-title{display:inline-flex;align-items:center;gap:8px}
.plat-meta{margin-right:12px}
#channel-form{scroll-margin-top:80px}
.card{margin-bottom:16px}
</style>

<?php require __DIR__ . '/_foot.php';
