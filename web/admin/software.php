<?php
/**
 * 后台 - 软件控制。
 *
 * 管客户端 exe 的版本与下载地址。放在站点设置旁边，跟支付设置一样单独一页。
 *
 * 为什么不塞进站点设置：这里既要填版本号又要传安装包，还得存 sha256、
 * 更新日志、强制更新开关，配置项和文件上传混在普通设置里会很挤。
 *
 * 版本号统一用「点分数字」，比较时按段转整数，不做字符串比大小——
 * 否则 1.10.0 会被判成比 1.9.0 旧。
 */
$adminOn = 'software';
$pageTitle = '软件控制';
// POST 要在 _head.php 之前处理完，否则重定向发不出去，刷新会重发表单。
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();
require_once __DIR__ . '/../inc/version.php';
$msg = $err = '';
// POST 处理完会 302 跳回本页，提示语没法靠变量传过来，只能先寄存在 session 里。
// 取出来就立刻删掉，否则下次进这个页面还会再显示一遍同样的提示。
if (isset($_SESSION['软件页提示'])) {
    $msg = (string) $_SESSION['软件页提示'];
    unset($_SESSION['软件页提示']);
}
if (isset($_SESSION['软件页错误'])) {
    $err = (string) $_SESSION['软件页错误'];
    unset($_SESSION['软件页错误']);
}
/** 安装包实际存放目录。放在 download/ 下，用户可直接下载。 */
$包目录 = dirname(__DIR__) . '/download';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $act = (string) ($_POST['act'] ?? '');
    // ---- 保存版本信息 ----
    // 端别决定用哪套 settings 键：pc 用 client_ 前缀，android 用 android_。
    // 两端各存一份，安卓发版不会牵动电脑端的更新提示。
    if ($act === 'save') {
        $端 = (string) ($_POST['side'] ?? 'pc');
        if ($端 !== 'android') { $端 = 'pc'; }
        $前 = ver_prefix($端);
        $表 = $端 === 'android' ? android_platforms() : client_platforms();
        $ver = ver_normalize((string) ($_POST[$前 . 'version'] ?? ''));
        if ($ver === '') {
            $err = '版本号格式不对，要写成 1.0.0 这样的点分数字';
        } else {
            setting_set($前 . 'version', $ver);
            setting_set($前 . 'min_version', ver_normalize((string) ($_POST[$前 . 'min_version'] ?? '')));
            setting_set($前 . 'notes', trim((string) ($_POST[$前 . 'notes'] ?? '')));
            setting_set($前 . 'force', isset($_POST[$前 . 'force']) ? '1' : '0');
            setting_set($前 . 'update_on', isset($_POST[$前 . 'update_on']) ? '1' : '0');
            foreach ($表 as $k => $_) {
                $地址 = trim((string) ($_POST[$k] ?? ''));
                setting_set($k, $地址);

                /*
                 * 下载地址如果是本站绝对路径，就按站点根目录定位实际文件。
                 * 外部对象存储地址无法在本机计算，保留原有元数据不动。
                 * realpath 边界检查用于阻止通过软链或 .. 读取站点目录之外的文件。
                 */
                $平台 = substr($k, strlen($前 . 'dl_'));
                $本地路径 = '';
                if ($地址 !== '' && $地址[0] === '/' && !str_starts_with($地址, '//')) {
                    $网址路径 = parse_url($地址, PHP_URL_PATH);
                    if (is_string($网址路径) && $网址路径 !== '') {
                        $本地路径 = dirname(__DIR__) . rawurldecode($网址路径);
                    }
                }
                $站点根 = realpath(dirname(__DIR__));
                $实际路径 = ($本地路径 !== '' && is_file($本地路径))
                    ? realpath($本地路径)
                    : false;
                $站点内 = $实际路径 !== false && $站点根 !== false
                    && ($实际路径 === $站点根
                        || strpos($实际路径, $站点根 . DIRECTORY_SEPARATOR) === 0);
                if ($站点内) {
                    $新哈希 = hash_file('sha256', $实际路径);
                    $新大小 = filesize($实际路径);
                    if (is_string($新哈希) && $新大小 !== false) {
                        setting_set($前 . 'sha_' . $平台, $新哈希);
                        setting_set($前 . 'size_' . $平台, (string) $新大小);
                    }
                } elseif ($本地路径 !== '') {
                    // 本站路径已不存在时清空旧元数据，避免客户端继续校验过期值。
                    setting_set($前 . 'sha_' . $平台, '');
                    setting_set($前 . 'size_' . $平台, '');
                }
            }
            $msg = ($端 === 'android' ? '安卓端' : '电脑端') . '版本信息已保存';
        }
    }
    // ---- 上传安装包 ----
    // 上传成功后自动算 sha256 并回填下载地址，省得管理员手填算错。
    if ($act === 'upload') {
        $平台 = (string) ($_POST['plat'] ?? 'win');
        // 平台名同时决定端别：apk 归安卓，其余归电脑端。
        // 这样上传表单只需选平台，不用再多一个端别下拉框。
        $端 = $平台 === 'apk' ? 'android' : 'pc';
        $前 = ver_prefix($端);
        $表 = $端 === 'android' ? android_platforms() : client_platforms();
        $键 = $前 . 'dl_' . $平台;
        if (!isset($表[$键])) {
            $err = '未知平台';
        } elseif (!isset($_FILES['pkg']) || $_FILES['pkg']['error'] !== UPLOAD_ERR_OK) {
            $err = '没收到文件，或超出 php.ini 的 upload_max_filesize';
        } else {
            $原名 = (string) $_FILES['pkg']['name'];
            $后缀 = strtolower((string) pathinfo($原名, PATHINFO_EXTENSION));
            // 只放行安装包类型。不允许 php/html 之类，否则等于开了个上传后门。
            if (!in_array($后缀, ['exe', 'zip', 'dmg', 'appimage', 'deb', 'apk'], true)) {
                $err = '只允许上传 exe / zip / dmg / AppImage / deb / apk';
            } elseif ($端 === 'android' && $后缀 !== 'apk') {
                $err = '安卓平台只能传 apk';
            } elseif ($端 === 'pc' && $后缀 === 'apk') {
                $err = 'apk 请在平台里选 Android';
            } else {
                if (!is_dir($包目录)) { @mkdir($包目录, 0755, true); }
                // 文件名带版本号，新旧版本可以并存，老客户端的下载链接不会突然 404
                $版 = ver_normalize((string) setting_get($前 . 'version', '1.0.0'));
                $存名 = 'yanyang-' . $平台 . '-' . ($版 ?: '1.0.0') . '.' . $后缀;
                $目标 = $包目录 . '/' . $存名;
                if (!move_uploaded_file($_FILES['pkg']['tmp_name'], $目标)) {
                    $err = '写入失败，检查 download 目录是否可写';
                } else {
                    @chmod($目标, 0644);
                    setting_set($键, '/download/' . $存名);
                    setting_set($前 . 'sha_' . $平台, hash_file('sha256', $目标));
                    setting_set($前 . 'size_' . $平台, (string) filesize($目标));
                    $msg = '安装包已上传：' . $存名;
                }
            }
        }
    }
    // ---- 删除安装包 ----
    // 文件名只从 POST 拿基名，且必须真实存在于 $包目录 下，
    // 否则 ../../inc/config.php 这种入参就能删站点文件。
    if ($act === 'del') {
        $名 = basename((string) ($_POST['file'] ?? ''));
        $目标 = $包目录 . '/' . $名;
        if ($名 === '' || $名 === '.' || $名 === '..') {
            $err = '文件名不合法';
        } elseif (!is_file($目标)) {
            $err = '文件不存在，可能已经被删了';
        } else {
            // realpath 兜第二道：解析软链和 .. 之后仍须落在包目录内
            $真 = realpath($目标);
            $根 = realpath($包目录);
            if ($真 === false || $根 === false || strpos($真, $根 . DIRECTORY_SEPARATOR) !== 0) {
                $err = '越界路径，已拒绝';
            } elseif (!@unlink($真)) {
                $err = '删除失败，检查 download 目录权限';
            } else {
                $msg = '已删除：' . $名;
                // 这个包正被某平台当作下载地址时，一并清掉那几项设置，
                // 不然客户端会一直拿到 404 链接，还以为是自己网络问题。
                $链 = '/download/' . $名;
                // 两端的平台表都要扫：删的可能是 apk，只扫电脑端会漏掉，
                // 安卓那边的下载地址就会一直指向已经不存在的文件。
                $全表 = client_platforms() + android_platforms();
                foreach (array_keys($全表) as $键2) {
                    if ((string) setting_get($键2, '') !== $链) { continue; }
                    // 从键名里切出前缀和平台名：android_dl_apk 拆成 android_ 和 apk
                    $前2 = strpos($键2, 'android_') === 0 ? 'android_' : 'client_';
                    $平2 = substr($键2, strlen($前2 . 'dl_'));
                    setting_set($键2, '');
                    setting_set($前2 . 'sha_' . $平2, '');
                    setting_set($前2 . 'size_' . $平2, '');
                    $msg .= '（它是该平台当前的下载地址，已同时清空版本信息里的链接）';
                }
            }
        }
    }
    // Post/Redirect/Get：处理完必须 302 跳回本页，不能直接往下渲染页面。
    // 直接渲染的话，浏览器地址栏停在一个 POST 结果上，用户按 F5 或者
    // 页面里的 location.reload() 都会把这次 POST 原样重发一遍——
    // 删除操作因此被反复执行，刚上传的包会被上一次的删除请求删掉。
    $_SESSION['软件页提示'] = $msg;
    $_SESSION['软件页错误'] = $err;
    header('Location: software.php', true, 302);
    exit;
}
require __DIR__ . '/_head.php';
?>
<div class="page-head"><h1 class="page-title">软件控制</h1></div>
<p class="muted mb-16">
  电脑端和安卓端的版本号、安装包都在这里管，两端各自独立。
  客户端启动时会问一次 <code>/api/version.php</code>（安卓端带
  <code>side=android</code>），发现有新版就提示用户更新。
</p>
<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>
<!-- 端别切换：电脑端和安卓端各一套版本信息，点标签切换。
     两套表单都渲染在页面里，靠 hidden 属性切显示，不用往服务器多跑一趟。 -->
<div class="side-tabs mb-16" role="tablist" aria-label="选择端别">
  <button type="button" class="side-tab on" data-side="pc"
          role="tab" aria-selected="true" aria-controls="版本-pc">电脑端</button>
  <button type="button" class="side-tab" data-side="android"
          role="tab" aria-selected="false" aria-controls="版本-android">安卓端</button>
</div>
<?php
/* 两端共用同一套字段结构，只是键前缀和平台表不同，所以用循环渲染，
   免得复制两份 HTML——将来加字段要改两处，很容易漏。 */
$端配置 = [
    'pc'      => ['名' => '电脑端', '前' => 'client_',  '表' => client_platforms(), '例' => '.exe'],
    'android' => ['名' => '安卓端', '前' => 'android_', '表' => android_platforms(), '例' => '.apk'],
];
?>
<?php foreach ($端配置 as $端键 => $配): ?>
  <?php $前 = $配['前']; ?>
  <div class="card mb-16 版本面板" id="版本-<?= h($端键) ?>"
       role="tabpanel" data-side="<?= h($端键) ?>"
       <?= $端键 === 'pc' ? '' : 'hidden' ?>>
    <div class="card-head"><?= h($配['名']) ?>版本信息</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="side" value="<?= h($端键) ?>">
        <label class="field"><span class="field-label">当前最新版本号</span>
          <input class="input" type="text" name="<?= h($前) ?>version"
                 value="<?= h((string) setting_get($前 . 'version', '1.0.0')) ?>"
                 placeholder="1.0.0">
          <span class="hint"><?= h($配['名']) ?>拿自己的版本跟这个比，小于就提示更新。改版本号之前先传好新安装包，否则用户点更新会下到旧包。</span>
        </label>
        <label class="field mt-16"><span class="field-label">最低可用版本（可选）</span>
          <input class="input" type="text" name="<?= h($前) ?>min_version"
                 value="<?= h((string) setting_get($前 . 'min_version', '')) ?>"
                 placeholder="留空表示不限制">
          <span class="hint">低于这个版本会被判定为必须更新，用于接口有不兼容改动时逼旧版升级。</span>
        </label>
        <label class="field mt-16"><span class="field-label">更新日志</span>
          <textarea class="textarea" name="<?= h($前) ?>notes" rows="5"
                    placeholder="一行一条，更新提示里会原样显示"><?= h((string) setting_get($前 . 'notes', '')) ?></textarea>
        </label>
        <label class="field mt-16"><span class="field-label">开启更新检测</span>
          <span><input type="checkbox" name="<?= h($前) ?>update_on" value="1"
                 <?= (string) setting_get($前 . 'update_on', '1') === '1' ? 'checked' : '' ?>> 启用</span>
          <span class="hint">关掉之后不再提示更新，用于临时下线有问题的版本。</span>
        </label>
        <label class="field mt-16"><span class="field-label">强制更新</span>
          <span><input type="checkbox" name="<?= h($前) ?>force" value="1"
                 <?= (string) setting_get($前 . 'force', '0') === '1' ? 'checked' : '' ?>> 启用</span>
          <span class="hint">勾上之后更新提示不能关闭，用户必须更新才能继续用。平时别开，有严重问题时再开。</span>
        </label>
        <div class="mt-16" style="border-top:1px solid #eef0f3;padding-top:14px">
          <div class="field-label mb-8">下载地址</div>
          <p class="muted mb-8">用下面的上传功能会自动回填。也可以手填外链，比如放在对象存储上。</p>
          <?php foreach ($配['表'] as $键 => $名): ?>
            <?php $平台 = substr($键, strlen($前 . 'dl_')); ?>
            <label class="field"><span class="field-label"><?= h($名) ?></span>
              <input class="input" type="text" name="<?= h($键) ?>"
                     value="<?= h((string) setting_get($键, '')) ?>"
                     placeholder="/download/yanyang-<?= h($平台) ?>-1.0.0<?= h($配['例']) ?>">
              <?php $sha = (string) setting_get($前 . 'sha_' . $平台, ''); ?>
              <?php if ($sha !== ''): ?>
                <span class="hint">sha256：<code><?= h($sha) ?></code>
                  <?php $sz = (int) setting_get($前 . 'size_' . $平台, 0); ?>
                  <?php if ($sz > 0): ?>　大小：<?= h(number_format($sz / 1048576, 1)) ?> MB<?php endif; ?>
                </span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="form-actions mt-16">
          <button class="btn btn-primary" type="submit">保存<?= h($配['名']) ?>设置</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
<div class="card mb-16">
  <div class="card-head">上传安装包</div>
  <div class="card-body">
    <p class="muted mb-16">
      传完自动算 sha256 并回填上面的下载地址。文件名会带上当前版本号，
      所以<strong>先在上面填好新版本号并保存，再传包</strong>。
      单文件大小受 php.ini 的 upload_max_filesize 限制，当前上限
      <code><?= h((string) ini_get('upload_max_filesize')) ?></code>。
    </p>
    <form method="post" enctype="multipart/form-data" id="上传表单">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="upload">
      <label class="field"><span class="field-label">平台</span>
        <select class="input" name="plat">
          <?php foreach (client_platforms() as $键 => $名): ?>
            <option value="<?= h(substr($键, strlen('client_dl_'))) ?>"><?= h($名) ?></option>
          <?php endforeach; ?>
          <?php /* 安卓平台一起列出来：后端按平台名反推端别，apk 自动归安卓那套键 */ ?>
          <?php foreach (android_platforms() as $键 => $名): ?>
            <option value="<?= h(substr($键, strlen('android_dl_'))) ?>"><?= h($名) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">选 Android 时只能传 apk，版本号取安卓端那一套。</span>
      </label>
      <div class="field mt-16"><span class="field-label">安装包文件</span>
        <!-- 拖拉框：点一下等于点 file 输入框，拖进来也走同一条路。
             真正的 input 藏起来但不用 display:none——那样键盘 Tab 聚焦不到，
             用 sr-only 式的定位挪出视野，无障碍读屏和键盘操作都还能用。 -->
        <div id="拖拉框" class="pkg-drop" tabindex="0" role="button"
             aria-label="点击选择安装包，或把文件拖到这里">
          <input type="file" name="pkg" id="包输入" accept=".exe,.zip,.dmg,.AppImage,.deb,.apk"
                 style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none">
          <div class="pkg-drop-main">点击选择文件，或把安装包拖到这里</div>
          <div class="pkg-drop-sub">支持 exe / zip / dmg / AppImage / deb / apk</div>
          <div id="已选文件" class="pkg-pick" hidden></div>
        </div>
      </div>
      <!-- 进度条：aria 属性齐全，读屏器能播报百分比 -->
      <div id="进度区" class="pkg-prog" hidden>
        <div class="pkg-prog-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"
             aria-valuenow="0" aria-label="上传进度">
          <div id="进度条" class="pkg-prog-fill"></div>
        </div>
        <div id="进度字" class="pkg-prog-txt" aria-live="polite">准备上传…</div>
      </div>
      <div class="form-actions mt-16">
        <button class="btn btn-primary" type="submit" id="上传按钮">上传</button>
      </div>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-head">download 目录里的包</div>
  <div class="card-body">
    <?php
    $包 = [];
    if (is_dir($包目录)) {
        foreach (scandir($包目录) ?: [] as $f) {
            if ($f === '.' || $f === '..' || is_dir($包目录 . '/' . $f)) { continue; }
            $包[] = $f;
        }
    }
    rsort($包);
    /* 按后缀分到两端：apk 归安卓，其余归电脑端。
       用后缀判而不是用文件名里的平台段——手动放进目录的包不一定守命名规则。 */
    $包分端 = ['pc' => [], 'android' => []];
    foreach ($包 as $f) {
        $后 = strtolower((string) pathinfo($f, PATHINFO_EXTENSION));
        $包分端[$后 === 'apk' ? 'android' : 'pc'][] = $f;
    }
    ?>
    <!-- 下载列表也跟着端别切，跟上面的版本卡片用同一组标签联动 -->
    <div class="side-tabs mb-16" role="tablist" aria-label="选择端别">
      <button type="button" class="side-tab on" data-side="pc" role="tab"
              aria-selected="true" aria-controls="包表-pc">电脑端</button>
      <button type="button" class="side-tab" data-side="android" role="tab"
              aria-selected="false" aria-controls="包表-android">安卓端</button>
    </div>
    <?php
    // 两端在用的下载地址合起来算：标「在用」是为了防误删，
    // 不该因为当前看的是安卓面板就漏标电脑端在用的包。
    $在用 = [];
    foreach (array_keys(client_platforms() + android_platforms()) as $键3) {
        $v = (string) setting_get($键3, '');
        if ($v !== '') { $在用[$v] = true; }
    }
    ?>
    <?php foreach (['pc' => '电脑端', 'android' => '安卓端'] as $端键 => $端名): ?>
      <div class="包面板" id="包表-<?= h($端键) ?>" role="tabpanel"
           data-side="<?= h($端键) ?>" <?= $端键 === 'pc' ? '' : 'hidden' ?>>
        <?php $此组 = $包分端[$端键]; ?>
        <?php if (!$此组): ?>
          <p class="muted">还没有<?= h($端名) ?>安装包。用上面的表单传一个，或者手动放到
            <code>/download/</code> 目录里。</p>
        <?php else: ?>
          <table class="tbl">
            <thead><tr><th>文件名</th><th>大小</th><th>修改时间</th><th>链接</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($此组 as $f): ?>
              <?php $p = $包目录 . '/' . $f; ?>
              <?php $此在用 = isset($在用['/download/' . $f]); ?>
              <tr>
                <td><?= h($f) ?>
                  <?php if ($此在用): ?><span class="badge badge-blue">在用</span><?php endif; ?>
                </td>
                <td><?= h(number_format(filesize($p) / 1048576, 1)) ?> MB</td>
                <td><?= h(date('Y-m-d H:i', filemtime($p))) ?></td>
                <td><a href="/download/<?= h(rawurlencode($f)) ?>" target="_blank">下载</a></td>
                <td>
                  <!-- 文件名走 data 属性，不往 JS 字符串里拼：
                       名字带引号时拼字符串会把 confirm 的参数截断。 -->
                  <form method="post" class="inline-form 删包表单" data-name="<?= h($f) ?>">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="act" value="del">
                    <input type="hidden" name="file" value="<?= h($f) ?>">
                    <button type="submit" class="btn btn-sm btn-danger">删除</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<style>
/* 端别切换标签。两组标签（版本卡片上方、下载列表上方）共用这套样式，
   点任意一组都会联动切换，因为用户心里只有一个「当前在看哪端」。 */
.side-tabs { display:flex; gap:8px; }
.side-tab {
  padding:8px 20px; border:1px solid #dfe3e8; background:#fff;
  border-radius:8px; cursor:pointer; font-size:14px; color:#5b6470;
}
.side-tab:hover { border-color:#b9c0c9; }
.side-tab.on { background:#2f6fed; border-color:#2f6fed; color:#fff; }
/* 键盘聚焦要看得见，否则只用键盘的人不知道焦点在哪 */
.side-tab:focus-visible { outline:2px solid #2f6fed; outline-offset:2px; }
</style>
<script>
/* 端别切换：两组标签联动，版本面板和包列表一起切。
   用 hidden 属性而不是 display:none —— hidden 对读屏器同样生效，
   被隐藏的面板不会被念出来。 */
(function () {
  var 标签 = document.querySelectorAll('.side-tab');
  function 切到(端) {
    for (var i = 0; i < 标签.length; i++) {
      var 中 = 标签[i].getAttribute('data-side') === 端;
      标签[i].classList.toggle('on', 中);
      标签[i].setAttribute('aria-selected', 中 ? 'true' : 'false');
    }
    var 面板 = document.querySelectorAll('.版本面板, .包面板');
    for (var j = 0; j < 面板.length; j++) {
      面板[j].hidden = 面板[j].getAttribute('data-side') !== 端;
    }
    // 记住选择：保存表单会跳回本页，不记的话又弹回电脑端，
    // 连续调安卓版本号时每次都要重新点一下标签。
    try { localStorage.setItem('软件页端别', 端); } catch (e) {}
  }
  for (var k = 0; k < 标签.length; k++) {
    标签[k].addEventListener('click', function () {
      切到(this.getAttribute('data-side'));
    });
  }
  try {
    var 存 = localStorage.getItem('软件页端别');
    if (存 === 'android') { 切到('android'); }
  } catch (e) {}
})();
/* 删除前确认。文件名从 data-name 读，不在 PHP 里拼 JS 字符串。
   没有 JS 时表单照样能提交，只是少一道确认——功能不丢。 */
(function () {
  var 表单 = document.querySelectorAll('.删包表单');
  for (var i = 0; i < 表单.length; i++) {
    表单[i].addEventListener('submit', function (e) {
      var 名 = this.getAttribute('data-name') || '这个文件';
      if (!confirm('确定删除 ' + 名 + ' 吗？删除后不可恢复。')) {
        e.preventDefault();
      }
    });
  }
})();
/* 安装包上传：拖拉 + 点击 + 进度条。
   为什么用 XMLHttpRequest 而不是 fetch：fetch 至今拿不到上传进度，
   只有 xhr.upload.onprogress 能报「已发出多少字节」。这是唯一原因。
   没有 JS 时表单照旧走普通 POST 提交，功能不丢，只是没有进度显示。 */
(function () {
  var 框 = document.getElementById('拖拉框');
  var 输入 = document.getElementById('包输入');
  var 表单 = document.getElementById('上传表单');
  var 按钮 = document.getElementById('上传按钮');
  var 选中显示 = document.getElementById('已选文件');
  var 进度区 = document.getElementById('进度区');
  var 进度条 = document.getElementById('进度条');
  var 进度字 = document.getElementById('进度字');
  if (!框 || !输入 || !表单) { return; }
  var 允许 = ['exe', 'zip', 'dmg', 'appimage', 'deb', 'apk'];
  var 待传 = null;   // 当前选中的 File 对象
  function 好看的大小(字节) {
    if (字节 >= 1048576) { return (字节 / 1048576).toFixed(1) + ' MB'; }
    return (字节 / 1024).toFixed(0) + ' KB';
  }
  function 说(话, 类) {
    进度字.textContent = 话;
    进度字.className = 'pkg-prog-txt' + (类 ? ' ' + 类 : '');
  }
  /* 后缀校验放前端只是为了早点告诉用户，后端那道检查才是真正的防线。 */
  function 后缀合法(名) {
    var i = 名.lastIndexOf('.');
    if (i < 0) { return false; }
    return 允许.indexOf(名.slice(i + 1).toLowerCase()) !== -1;
  }
  function 选定(文件) {
    if (!文件) { return; }
    if (!后缀合法(文件.name)) {
      进度区.hidden = false;
      说('不支持这个格式，只能传 ' + 允许.join(' / '), 'err');
      return;
    }
    待传 = 文件;
    选中显示.hidden = false;
    选中显示.textContent = '已选择：' + 文件.name + '（' + 好看的大小(文件.size) + '）';
    进度区.hidden = true;
    进度条.style.width = '0';
  }
  // ---- 点击选文件 ----
  框.addEventListener('click', function () { 输入.click(); });
  // 键盘操作：回车和空格都当成点击，跟原生按钮行为一致
  框.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); 输入.click(); }
  });
  输入.addEventListener('change', function () { 选定(输入.files[0]); });
  // ---- 拖拉 ----
  // dragover 必须 preventDefault，否则浏览器会把文件当成导航请求直接打开
  ['dragenter', 'dragover'].forEach(function (名) {
    框.addEventListener(名, function (e) {
      e.preventDefault(); e.stopPropagation();
      框.classList.add('over');
    });
  });
  ['dragleave', 'drop'].forEach(function (名) {
    框.addEventListener(名, function (e) {
      e.preventDefault(); e.stopPropagation();
      框.classList.remove('over');
    });
  });
  框.addEventListener('drop', function (e) {
    var 文件 = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (文件) {
      // 一并塞回 input，这样即使 JS 后续出错、走了原生提交，文件也在
      try { 输入.files = e.dataTransfer.files; } catch (_) {}
      选定(文件);
    }
  });
  // 拖到页面别处松手时不要让浏览器打开文件
  window.addEventListener('dragover', function (e) { e.preventDefault(); });
  window.addEventListener('drop', function (e) { e.preventDefault(); });
  // ---- 提交 ----
  表单.addEventListener('submit', function (e) {
    // 没选文件就别发，让浏览器自己提示
    if (!待传 && !(输入.files && 输入.files[0])) {
      e.preventDefault();
      进度区.hidden = false;
      说('先选一个安装包', 'err');
      return;
    }
    e.preventDefault();
    var 文件 = 待传 || 输入.files[0];
    var 数据 = new FormData();
    数据.append('csrf', 表单.querySelector('[name=csrf]').value);
    数据.append('plat', 表单.querySelector('[name=plat]').value);
    数据.append('pkg', 文件);
    var 条 = 进度区.querySelector('[role=progressbar]');
    进度区.hidden = false;
    按钮.disabled = true;
    按钮.textContent = '上传中…';
    说('准备上传…');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'software_upload.php', true);
    // 进度：lengthComputable 为假时拿不到总长度，只能报已传字节
    xhr.upload.onprogress = function (ev) {
      if (!ev.lengthComputable) { 说('已上传 ' + 好看的大小(ev.loaded)); return; }
      var 百 = Math.round(ev.loaded / ev.total * 100);
      进度条.style.width = 百 + '%';
      条.setAttribute('aria-valuenow', String(百));
      说(百 + '%　' + 好看的大小(ev.loaded) + ' / ' + 好看的大小(ev.total));
    };
    // 文件发完了但服务端还在算 sha256，这段时间要给个说法，否则看着像卡住
    xhr.upload.onload = function () {
      进度条.style.width = '100%';
      条.setAttribute('aria-valuenow', '100');
      说('上传完成，服务端正在校验…');
    };
    xhr.onload = function () {
      按钮.disabled = false;
      按钮.textContent = '上传';
      var 回;
      try { 回 = JSON.parse(xhr.responseText); } catch (_) {
        // 解析失败通常是 PHP 报了错或 Nginx 返回 413，把状态码带出来便于排查
        说('返回内容异常（HTTP ' + xhr.status + '），请看服务器日志', 'err');
        return;
      }
      if (!回 || !回.ok) {
        说((回 && 回.err) || '上传失败', 'err');
        return;
      }
      说('已上传：' + 回.name + '　1.5 秒后刷新页面', 'ok');
      // 刷新一次，让上面的下载地址、sha256、包列表都显示成新的
      setTimeout(function () { location.reload(); }, 1500);
    };
    xhr.onerror = function () {
      按钮.disabled = false;
      按钮.textContent = '上传';
      说('网络中断，上传失败', 'err');
    };
    xhr.send(数据);
  });
})();
</script>
<style>
/* 拖拉框。虚线边框是「这里能放东西」的通用暗示，比纯文字提示直观。 */
.pkg-drop{position:relative;border:2px dashed #cfd6e0;border-radius:8px;
  padding:26px 16px;text-align:center;cursor:pointer;background:#fafbfc;
  transition:border-color .15s,background .15s}
.pkg-drop:hover{border-color:#8fa3bf;background:#f5f8fc}
/* 键盘聚焦必须有可见轮廓，否则只用键盘的人不知道焦点在哪 */
.pkg-drop:focus-visible{outline:2px solid #2f6fed;outline-offset:2px}
/* 文件拖到上方时的高亮态，靠 JS 加类 */
.pkg-drop.over{border-color:#2f6fed;background:#eef4ff}
.pkg-drop-main{font-size:14px;color:#334}
.pkg-drop-sub{font-size:12px;color:#8a94a6;margin-top:6px}
.pkg-pick{margin-top:12px;font-size:13px;color:#2f6fed;word-break:break-all}
.pkg-prog{margin-top:14px}
.pkg-prog-bar{height:8px;border-radius:99px;background:#eceff3;overflow:hidden}
.pkg-prog-fill{height:100%;width:0;border-radius:99px;background:#2f6fed;
  transition:width .15s linear}
.pkg-prog-txt{margin-top:8px;font-size:12px;color:#667}
.pkg-prog-txt.err{color:#d33}
.pkg-prog-txt.ok{color:#1a8a4a}
</style>
<?php require __DIR__ . '/_foot.php'; ?>
