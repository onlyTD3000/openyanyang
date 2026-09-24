<?php
$adminOn = 'servers';
$pageTitle = '服务器管理';

// POST 要在 _head.php 之前处理完，否则重定向发不出去，刷新会重发表单。
// 这一页的删除是不可逆的，重复执行虽然删不掉第二次，但会多记一条审计日志。
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();
require_once __DIR__ . '/../inc/crypto.php';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = $_POST['act'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'del' && $id > 0) {
        $host = db_one('SELECT * FROM ssh_hosts WHERE id = ? LIMIT 1', [$id]);
        if ($host) {
            db_exec('DELETE FROM ssh_hosts WHERE id = ?', [$id]);
            audit_log($me['id'], 'delete_server', (int) $host['user_id'], 0, '服务器: ' . $host['name'] . ' (' . $host['host'] . ')');
            $msg = '服务器已删除';
        } else {
            $err = '服务器不存在';
        }
    } elseif ($act === 'del_bad') {
        // 批量删除连接异常的主机。
        // 判定口径和列表里显示的「异常」必须一致：last_error 非空。
        // 从没测过的（last_error 为空、last_ok_at 也为空）不算异常，不能删——
        // 那批只是还没测，删掉就是误删。
        $bad = db_all('SELECT id, name, host, user_id FROM ssh_hosts
                        WHERE last_error IS NOT NULL AND last_error <> \'\'');
        if (!$bad) {
            $err = '没有状态为异常的服务器';
        } else {
            $ids = array_column($bad, 'id');
            // 用 IN 一次删完。id 全部来自上面查出的整数主键，拼进 SQL 安全。
            db_exec('DELETE FROM ssh_hosts WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
            foreach ($bad as $b) {
                audit_log($me['id'], 'delete_server_batch', (int) $b['user_id'], 0,
                    '批量删除异常服务器: ' . $b['name'] . ' (' . $b['host'] . ')');
            }
            $msg = '已删除 ' . count($bad) . ' 台异常服务器';
        }
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

// 放在重定向之后：POST 走的是删除分支，不该再记一条「查看列表」。
audit_log($me['id'], 'view_servers_list');

// ---- 搜索 ----
// 一个关键词同时匹配三列：所属用户账号、备注名、IP。
// 用户提的「搜索用户账号备注名IP」是三者任一命中即可，不是三个独立输入框。
$kw = trim($_GET['kw'] ?? '');
$where = '';
$args  = [];
if ($kw !== '') {
    $where = 'WHERE (u.username LIKE ? OR h.name LIKE ? OR h.host LIKE ?)';
    $like  = '%' . $kw . '%';
    $args  = [$like, $like, $like];
}

$hosts = db_all('SELECT h.*, u.username AS user_username
                 FROM ssh_hosts h LEFT JOIN users u ON u.id = h.user_id
                 ' . $where . '
                 ORDER BY h.id DESC', $args);

// 异常台数：给批量删除按钮显示数量，口径与上面 del_bad 完全一致
$badCount = (int) db_val('SELECT COUNT(*) FROM ssh_hosts
                           WHERE last_error IS NOT NULL AND last_error <> \'\'');
?>
<div class="page-head">
  <h1 class="page-title">服务器管理</h1>
  <div class="spacer"></div>
  <form class="inline-form" method="get">
    <input class="input" style="width:230px" type="text" name="kw" value="<?= h($kw) ?>"
           placeholder="搜索用户账号 / 备注名 / IP">
    <button class="btn" type="submit">搜索</button>
    <?php if ($kw !== ''): ?>
      <a class="btn" href="servers.php">清空</a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="margin-bottom:16px;">
  <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;">
    <h2 class="card-title">WebSocket 终端服务</h2>
    <span id="wsStatusBadge" class="badge badge-red">未连接</span>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:16px;">
      <div style="background:#f9fafb;padding:12px;border-radius:8px;">
        <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">服务状态</div>
        <div id="wsStatusText" style="font-size:18px;font-weight:bold;color:#1f2937;">--</div>
      </div>
      <div style="background:#f9fafb;padding:12px;border-radius:8px;">
        <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">连接方式</div>
        <div style="font-size:18px;font-weight:bold;color:#1f2937;">Nginx代理</div>
      </div>
      <div style="background:#f9fafb;padding:12px;border-radius:8px;">
        <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">进程 PID</div>
        <div id="wsPid" style="font-size:18px;font-weight:bold;color:#1f2937;">--</div>
      </div>
      <div style="background:#f9fafb;padding:12px;border-radius:8px;">
        <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">在线会话</div>
        <div id="wsOnlineCount" style="font-size:18px;font-weight:bold;color:#1f2937;">--</div>
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
      <button class="btn btn-primary btn-sm" type="button" onclick="startWsService()" id="btnStartWs">启动服务</button>
      <button class="btn btn-sm" type="button" onclick="stopWsService()" id="btnStopWs">停止服务</button>
      <button class="btn btn-sm" type="button" onclick="restartWsService()" id="btnRestartWs">重启服务</button>
      <button class="btn btn-sm" type="button" onclick="checkWsStatus()" id="btnRefreshWs">刷新状态</button>
    </div>
    <div id="wsSessionList" style="font-size:13px;"></div>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <h2 class="card-title">
      <?= $kw !== '' ? '搜索结果' : '全部服务器' ?>（共 <?= count($hosts) ?> 台）
    </h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button class="btn btn-sm" type="button" id="btnTestAll">批量测试状态</button>
      <button class="btn btn-sm btn-danger" type="button" id="btnDelBad"
              <?= $badCount === 0 ? 'disabled' : '' ?>>
        删除异常服务器<?= $badCount > 0 ? '（' . $badCount . ' 台）' : '' ?>
      </button>
    </div>
  </div>
  <div id="testProgress" class="card-body" hidden
       style="padding-top:0;font-size:13px;color:#6b7280;"></div>
  <?php if (!$hosts): ?>
    <div class="empty"><?= $kw !== '' ? '没有匹配的服务器' : '暂无服务器数据' ?></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th>ID</th>
        <th>所属用户</th>
        <th>备注名</th>
        <th>IP 地址</th>
        <th>端口</th>
        <th>登录用户</th>
        <th>认证方式</th>
        <th>密码/密钥</th>
        <th>状态</th>
        <th>最后连通</th>
        <th>操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($hosts as $h): ?>
        <tr data-row="<?= (int) $h['id'] ?>">
          <td><?= (int) $h['id'] ?></td>
          <td><?= h($h['user_username'] ?? '—') ?></td>
          <td><b><?= h($h['name']) ?></b></td>
          <td class="mono"><?= h($h['host']) ?></td>
          <td class="mono"><?= (int) $h['port'] ?></td>
          <td class="mono"><?= h($h['username']) ?></td>
          <td><?= $h['auth_type'] === 'password' ? '密码' : '密钥' ?></td>
          <td>
            <?php if ($h['auth_type'] === 'password'): ?>
              <span class="mono" id="pwd_<?= (int) $h['id'] ?>" style="filter: blur(4px); cursor: pointer;" onclick="togglePwd(<?= (int) $h['id'] ?>, '<?= h(addslashes(dec_secret($h['secret_enc']))) ?>')">点击查看</span>
            <?php else: ?>
              <span class="muted">密钥认证</span>
              <button class="btn btn-sm" type="button" onclick="showKey(<?= (int) $h['id'] ?>, '<?= h(addslashes(dec_secret($h['secret_enc']))) ?>')">查看私钥</button>
            <?php endif; ?>
          </td>
          <td class="cell-state">
            <?php
            // 三态判定，口径与前台 servers.php 保持一致：
            //   last_error 非空        → 异常（上次连接失败）
            //   last_ok_at 有值        → 正常（曾经连通过）
            //   两者都空              → 未测试
            // 注意不要用 status 字段判断健康：那个字段是「启用/停用」开关，
            // 只要主机是启用状态就恒为 1，从没测过也会显示成正常。
            $错 = trim((string) ($h['last_error'] ?? ''));
            if ($错 !== ''):
            ?>
              <span class="badge badge-red" title="<?= h($错) ?>">异常</span>
            <?php elseif (!empty($h['last_ok_at'])): ?>
              <span class="badge badge-green">正常</span>
            <?php else: ?>
              <span class="badge">未测试</span>
            <?php endif; ?>
          </td>
          <td class="mono cell-okat" style="font-size:12px;">
            <?= !empty($h['last_ok_at']) ? h((string) $h['last_ok_at']) : '—' ?>
          </td>
          <td class="nowrap">
            <button class="btn btn-sm" type="button" data-test="<?= (int) $h['id'] ?>">测试</button>
            <button class="btn btn-sm" type="button" onclick="openTerm(<?= (int) $h['id'] ?>, '<?= h(addslashes($h['name'])) ?>')">网页终端</button>
            <button class="btn btn-sm btn-danger" type="button" onclick="delHost(<?= (int) $h['id'] ?>, '<?= h(addslashes($h['name'])) ?>')">删除</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* xterm 走本地资源：终端页要能在内网/断外网环境用，CDN 挂了整个终端就打不开 */ ?>
<link rel="stylesheet" href="<?= asset('/assets/vendor/xterm.css') ?>">
<script src="<?= asset('/assets/vendor/xterm.js') ?>"></script>
<script src="<?= asset('/assets/vendor/xterm-addon-fit.js') ?>"></script>

<div id="termModal" class="pj-mask" hidden style="z-index:300;">
  <div class="pj-dlg" role="dialog" style="max-width:1000px;width:95%;height:85vh;display:flex;flex-direction:column;padding:0;overflow:hidden;">
    <div class="pj-head" style="flex-shrink:0;">
      <h3 id="termTitle">网页终端</h3>
      <div style="display:flex;align-items:center;gap:10px;">
        <span id="termStatus" style="font-size:12px;color:#6b7280;">未连接</span>
        <button class="pj-x" type="button" onclick="closeTerm()" style="position:static;">×</button>
      </div>
    </div>
    <div id="termContainer" style="flex:1;background:#1e1e1e;padding:8px;overflow:hidden;min-height:0;"></div>
  </div>
</div>

<div id="keyModal" class="pj-mask" hidden>
  <div class="pj-dlg" role="dialog" style="max-width: 600px;">
    <div class="pj-head"><h3>私钥内容</h3><button class="pj-x" type="button" onclick="document.getElementById('keyModal').hidden=true">×</button></div>
    <div class="pj-body">
      <textarea id="keyText" class="textarea" rows="12" readonly style="font-family: monospace; font-size: 12px;"></textarea>
    </div>
    <div class="pj-foot">
      <button class="btn" type="button" onclick="document.getElementById('keyModal').hidden=true">关闭</button>
      <button class="btn btn-primary" type="button" onclick="copyKey()">复制</button>
    </div>
  </div>
</div>

<form method="post" id="delForm" style="display:none;">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="act" value="del">
  <input type="hidden" name="id" id="delId" value="0">
</form>

<form method="post" id="delBadForm" style="display:none;">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="act" value="del_bad">
</form>

<script>
var CSRF_TOKEN = '<?= h($csrf) ?>';

/* 测一台，把结果写回那一行。
   逐台调接口而不是后端循环：单台建连最长 20 秒，几十台串起来会超时。 */
function 测一台(id) {
  var 行 = document.querySelector('tr[data-row="' + id + '"]');
  if (!行) { return Promise.resolve(null); }
  var 态 = 行.querySelector('.cell-state');
  var 时 = 行.querySelector('.cell-okat');
  var 原 = 态.innerHTML;
  态.innerHTML = '<span class="muted">测试中…</span>';

  var fd = new FormData();
  fd.append('csrf', CSRF_TOKEN);
  fd.append('id', id);

  return fetch('server_test.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (j.ok) {
        态.innerHTML = '<span class="badge badge-green">正常</span>';
        if (时) { 时.textContent = j.ok_at || '刚刚'; }
      } else {
        态.innerHTML = '<span class="badge badge-red" title="' +
          String(j.error || '').replace(/"/g, '&quot;') + '">异常</span>';
      }
      return j;
    })
    .catch(function () {
      态.innerHTML = 原;   // 网络出错就还原，别让它停在「测试中」
      return { ok: false, error: '请求失败' };
    });
}

document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-test]');
  if (b) { 测一台(parseInt(b.getAttribute('data-test'), 10)); }
});

document.getElementById('btnTestAll').addEventListener('click', function () {
  var 按钮 = this;
  var 行组 = Array.prototype.slice.call(document.querySelectorAll('tr[data-row]'));
  if (!行组.length) { return; }
  if (!confirm('将逐台测试 ' + 行组.length + ' 台服务器的连通性。\n' +
               '单台最长等 20 秒，全部测完需要一些时间，期间请不要关闭页面。')) { return; }

  按钮.disabled = true;
  var 进度 = document.getElementById('testProgress');
  进度.hidden = false;
  var 成 = 0, 败 = 0, i = 0;

  // 串行跑：并发几十个 SSH 建连会把本机端口和上游都打满，
  // 而且每个连接都占一个 PHP-FPM 进程，容易把站点其他请求挤掉。
  function 下一台() {
    if (i >= 行组.length) {
      进度.innerHTML = '<b>测试完毕</b>：正常 ' + 成 + ' 台，异常 ' + 败 + ' 台。' +
        '<a href="servers.php<?= $kw !== '' ? '?kw=' . urlencode($kw) : '' ?>" ' +
        'style="margin-left:8px;">刷新页面</a>';
      按钮.disabled = false;
      return;
    }
    var id = parseInt(行组[i].getAttribute('data-row'), 10);
    i++;
    进度.textContent = '正在测试第 ' + i + ' / ' + 行组.length + ' 台…（正常 ' + 成 + '，异常 ' + 败 + '）';
    测一台(id).then(function (j) {
      if (j && j.ok) { 成++; } else { 败++; }
      下一台();
    });
  }
  下一台();
});

document.getElementById('btnDelBad').addEventListener('click', function () {
  var n = <?= $badCount ?>;
  if (n === 0) { alert('当前没有状态为异常的服务器。'); return; }
  if (!confirm('将删除 ' + n + ' 台状态为异常的服务器。\n\n' +
               '只删「上次连接失败」的，从未测试过的不会被删。\n' +
               '删除后无法恢复，确定继续？')) { return; }
  document.getElementById('delBadForm').submit();
});

var _termWs = null;
var _termXterm = null;
var _termFitAddon = null;
var _termHostId = 0;
var _termHostName = '';

function togglePwd(id, pwd) {
  var el = document.getElementById('pwd_' + id);
  if (el.getAttribute('data-shown') === '1') {
    el.textContent = '点击查看';
    el.style.filter = 'blur(4px)';
    el.setAttribute('data-shown', '0');
  } else {
    el.textContent = pwd;
    el.style.filter = 'none';
    el.setAttribute('data-shown', '1');
  }
}

function showKey(id, key) {
  document.getElementById('keyText').value = key;
  document.getElementById('keyModal').hidden = false;
}

function copyKey() {
  var ta = document.getElementById('keyText');
  ta.select();
  document.execCommand('copy');
  alert('已复制到剪贴板');
}

function delHost(id, name) {
  if (!confirm('确定要删除服务器 "' + name + '" 吗？此操作不可撤销。')) return;
  document.getElementById('delId').value = id;
  document.getElementById('delForm').submit();
}

function openTerm(id, name) {
  _termHostId = id;
  _termHostName = name;
  document.getElementById('termTitle').textContent = '网页终端 - ' + name;
  document.getElementById('termStatus').textContent = '连接中...';
  document.getElementById('termStatus').style.color = '#f59e0b';
  document.getElementById('termModal').hidden = false;
  if (!_termXterm) {
    _termFitAddon = new FitAddon.FitAddon();
    _termXterm = new Terminal({
      fontSize: 14,
      fontFamily: 'Consolas, Monaco, monospace',
      cursorBlink: true,
      convertEol: true
    });
    _termXterm.loadAddon(_termFitAddon);
    _termXterm.open(document.getElementById('termContainer'));
    _termFitAddon.fit();
    _termXterm.onData(function(data) {
      if (_termWs && _termWs.readyState === 1) {
        _termWs.send(JSON.stringify({ action: 'input', data: data }));
      }
    });
    window.addEventListener('resize', function() {
      if (!document.getElementById('termModal').hidden && _termFitAddon) {
        _termFitAddon.fit();
        if (_termWs && _termWs.readyState === 1) {
          _termWs.send(JSON.stringify({
            action: 'resize',
            cols: _termXterm.cols,
            rows: _termXterm.rows
          }));
        }
      }
    });
  }
  _termXterm.clear();
  termConnect();
}

/* 先换一张一次性令牌再连 ws。
   ws 服务端现在要求先 auth 才允许 connect——之前直接发 connect 会被拒，
   报的就是「未鉴权，请先发送 auth」。 */
function termConnect() {
  var st = document.getElementById('termStatus');
  st.textContent = '正在取握手令牌...';
  st.style.color = '';

  var fd = new FormData();
  fd.append('csrf', CSRF_TOKEN);
  fd.append('host_id', String(_termHostId));

  fetch('/api/wsssh_token_admin.php', {
    method: 'POST', body: fd, credentials: 'same-origin'
  }).then(function(r) {
    return r.json().catch(function() { return { error: '返回格式异常' }; });
  }).then(function(r) {
    if (!r || r.error) {
      termSetError('取令牌失败: ' + ((r && r.error) || '未知错误'));
      return;
    }
    termOpenWs(r.token);
  }).catch(function(e) {
    termSetError('取令牌请求出错: ' + (e && e.message ? e.message : e));
  });
}

function termOpenWs(token) {
  var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
  var wsUrl = proto + '//' + location.host + '/ws/';
  try {
    _termWs = new WebSocket(wsUrl);
  } catch(e) {
    termSetError('WebSocket 创建失败: ' + e.message);
    return;
  }
  _termWs.onopen = function() {
    document.getElementById('termStatus').textContent = 'WebSocket已连接，正在鉴权...';
    document.getElementById('termStatus').style.color = '#10b981';
    // 顺序不能反：先 auth 拿到身份，服务端才放行 connect
    _termWs.send(JSON.stringify({ action: 'auth', token: token }));
  };
  _termWs.onmessage = function(e) {
    try {
      var msg = JSON.parse(e.data);
      if (msg.type === 'authed') {
        // 鉴权过了才发 connect
        document.getElementById('termStatus').textContent = '鉴权通过，正在登录SSH...';
        _termWs.send(JSON.stringify({ action: 'connect', host_id: _termHostId }));
      } else if (msg.type === 'connected') {
        document.getElementById('termStatus').textContent = '已连接 - ' + msg.user + '@' + msg.host;
        document.getElementById('termStatus').style.color = '#10b981';
        _termXterm.clear();
        setTimeout(function() {
          if (_termFitAddon) {
            _termFitAddon.fit();
            _termWs.send(JSON.stringify({
              action: 'resize',
              cols: _termXterm.cols,
              rows: _termXterm.rows
            }));
          }
        }, 100);
      } else if (msg.type === 'output') {
        _termXterm.write(msg.data);
      } else if (msg.type === 'error') {
        termSetError(msg.msg);
      }
    } catch(err) {
      console.error('parse error:', err);
    }
  };
  _termWs.onclose = function() {
    document.getElementById('termStatus').textContent = '已断开';
    document.getElementById('termStatus').style.color = '#ef4444';
  };
  _termWs.onerror = function(e) {
    termSetError('WebSocket 连接失败，请确认服务已启动');
  };
}

function termSetError(msg) {
  document.getElementById('termStatus').textContent = '连接失败';
  document.getElementById('termStatus').style.color = '#ef4444';
  if (_termXterm) {
    _termXterm.writeln('[错误] ' + msg);
  }
}

function closeTerm() {
  document.getElementById('termModal').hidden = true;
  if (_termWs) {
    try { _termWs.close(); } catch(e) {}
    _termWs = null;
  }
}

function checkWsStatus() {
  var badge = document.getElementById('wsStatusBadge');
  if (!badge) return;
  badge.textContent = '检查中...';
  badge.className = 'badge badge-orange';
  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'wsssh_ctrl.php?action=status', true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      try {
        var data = JSON.parse(xhr.responseText);
        if (data && data.ok) {
          if (data.running) {
            badge.textContent = '运行中';
            badge.className = 'badge badge-green';
            var st = document.getElementById('wsStatusText');
            if (st) { st.textContent = '正常运行'; st.style.color = '#10b981'; }
            var pid = document.getElementById('wsPid');
            if (pid) { pid.textContent = data.pid > 0 ? data.pid : '未知'; }
            var cnt = document.getElementById('wsOnlineCount');
            if (cnt) { cnt.textContent = data.session_count; }
            setWsButtons(true);
            renderSessions(data.sessions);
          } else {
            badge.textContent = '未启动';
            badge.className = 'badge badge-red';
            var st2 = document.getElementById('wsStatusText');
            if (st2) { st2.textContent = '未运行'; st2.style.color = '#ef4444'; }
            var pid2 = document.getElementById('wsPid');
            if (pid2) { pid2.textContent = '--'; }
            var cnt2 = document.getElementById('wsOnlineCount');
            if (cnt2) { cnt2.textContent = '0'; }
            setWsButtons(false);
            var lst = document.getElementById('wsSessionList');
            if (lst) { lst.innerHTML = '<div style="color:#ef4444;padding:8px 0;">WebSocket 服务未启动</div>'; }
          }
        } else {
          throw new Error((data && data.error) || '查询失败');
        }
      } catch(err) {
        badge.textContent = '查询失败';
        badge.className = 'badge badge-red';
        var st3 = document.getElementById('wsStatusText');
        if (st3) { st3.textContent = '查询失败'; st3.style.color = '#ef4444'; }
        setWsButtons(false);
        console.error('WS status error:', err);
      }
    }
  };
  xhr.send();
}

function setWsButtons(running) {
  var s = document.getElementById('btnStartWs');
  var t = document.getElementById('btnStopWs');
  var r = document.getElementById('btnRestartWs');
  if (!s || !t || !r) return;
  if (running) {
    s.disabled = true;
    s.style.opacity = '0.5';
    t.disabled = false;
    t.style.opacity = '1';
    r.disabled = false;
    r.style.opacity = '1';
  } else {
    s.disabled = false;
    s.style.opacity = '1';
    t.disabled = true;
    t.style.opacity = '0.5';
    r.disabled = true;
    r.style.opacity = '0.5';
  }
}

function renderSessions(sessions) {
  var c = document.getElementById('wsSessionList');
  if (!c) return;
  if (!sessions || sessions.length === 0) {
    c.innerHTML = '<div style="color:#6b7280;padding:8px 0;">当前没有活跃会话</div>';
    return;
  }
  var html = '<div style="font-weight:600;color:#374151;margin-bottom:8px;">活跃会话（' + sessions.length + ' 个）：</div>';
  html += '<div style="display:flex;flex-direction:column;gap:6px;">';
  for (var i = 0; i < sessions.length; i++) {
    var s = sessions[i];
    html += '<div style="background:#f9fafb;padding:10px 12px;border-radius:6px;display:flex;justify-content:space-between;align-items:center;">';
    html += '<div>';
    html += '<div style="font-family:monospace;font-weight:500;color:#1f2937;">' + (s.user || '?') + '@' + (s.host || '?') + '</div>';
    html += '<div style="font-size:12px;color:#6b7280;margin-top:2px;">会话ID: ' + s.id + '</div>';
    html += '</div></div>';
  }
  html += '</div>';
  c.innerHTML = html;
}

function startWsService() {
  var btn = document.getElementById('btnStartWs');
  if (!btn) return;
  btn.disabled = true;
  btn.textContent = '启动中...';
  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'wsssh_ctrl.php?action=start', true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      try {
        var data = JSON.parse(xhr.responseText);
        if (data && data.ok) {
          alert('服务启动成功！PID: ' + data.pid);
          setTimeout(checkWsStatus, 1500);
        } else {
          alert('启动失败: ' + ((data && data.error) || '未知错误'));
          btn.disabled = false;
          btn.textContent = '启动服务';
        }
      } catch(err) {
        alert('请求失败: ' + err.message);
        btn.disabled = false;
        btn.textContent = '启动服务';
      }
    }
  };
  xhr.send();
}

function stopWsService() {
  if (!confirm('确定要停止 WebSocket 服务吗？所有连接将被断开。')) return;
  var btn = document.getElementById('btnStopWs');
  if (!btn) return;
  btn.disabled = true;
  btn.textContent = '停止中...';
  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'wsssh_ctrl.php?action=stop', true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      try {
        var data = JSON.parse(xhr.responseText);
        if (data && data.ok) {
          alert('服务已停止');
          setTimeout(checkWsStatus, 500);
        } else {
          alert('停止失败: ' + ((data && data.error) || '未知错误'));
          btn.disabled = false;
          btn.textContent = '停止服务';
        }
      } catch(err) {
        alert('请求失败: ' + err.message);
        btn.disabled = false;
        btn.textContent = '停止服务';
      }
    }
  };
  xhr.send();
}

function restartWsService() {
  if (!confirm('确定要重启 WebSocket 服务吗？所有连接将被断开。')) return;
  var btn = document.getElementById('btnRestartWs');
  if (!btn) return;
  btn.disabled = true;
  btn.textContent = '重启中...';
  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'wsssh_ctrl.php?action=restart', true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      try {
        var data = JSON.parse(xhr.responseText);
        if (data && data.ok) {
          alert('服务重启成功！新 PID: ' + data.pid);
          setTimeout(checkWsStatus, 1500);
        } else {
          alert('重启失败: ' + ((data && data.error) || '未知错误'));
          btn.disabled = false;
          btn.textContent = '重启服务';
        }
      } catch(err) {
        alert('请求失败: ' + err.message);
        btn.disabled = false;
        btn.textContent = '重启服务';
      }
    }
  };
  xhr.send();
}

checkWsStatus();
setInterval(checkWsStatus, 30000);
</script>

<?php require __DIR__ . '/_foot.php'; ?>
