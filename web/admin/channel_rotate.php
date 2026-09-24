<?php
/**
 * 后台 - 子分类 SK 轮询设置（单独一页）。
 *
 * 单独一页而不是在渠道列表弹窗里改，原因：
 * 一是多 SK 要用大文本框贴，弹窗撑不开也不好检查；
 * 二是这里要展示每个 SK 的掩码和当前轮询位置，信息量比一个输入框大得多。
 *
 * 入口：/admin/channel_rotate.php?id=子分类ID
 *
 * 存储约定：多个 SK 用换行拼进 channels.api_key，解析统一交给 channel_sk_list()。
 * 这样旧的单 SK 数据天然兼容（就是「只有一行」的特例），不用做数据迁移。
 */
$adminOn = 'channels';
$pageTitle = 'SK 轮询设置';

// POST 要在 _head.php 之前处理完，否则重定向发不出去，刷新会重发表单。
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/upstream.php';
$me = require_admin();

$msg = $err = '';
$chId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();
    $chId = (int) ($_POST['id'] ?? 0);

    if (($_POST['act'] ?? '') === 'rotate_save') {
        $ch = db_one('SELECT id,name FROM channels WHERE id=?', [$chId]);
        if (!$ch) {
            $err = '子分类不存在';
        } else {
            $轮询开 = isset($_POST['rotate']) ? 1 : 0;
            $skList = channel_sk_list($_POST['sk_text'] ?? '');
            if (!$skList) {
                $err = '请至少填写 1 个 SK';
            } elseif ($轮询开 === 1 && count($skList) < 2) {
                $err = '开启轮询至少需要 2 个 SK，只有 1 个的话请关掉轮询开关';
            } else {
                // 游标不归零：每天要往列表里补几次 SK，一归零就永远停在前几个，
                // 后面新加的那批一直分不到对话。只在游标越界时才收敛回范围内。
                $当前游标 = (int) db_val('SELECT rotate_idx FROM channels WHERE id=?', [$chId]);
                $n = count($skList);
                $新游标 = $n > 0 ? $当前游标 % $n : 0;
                db_exec('UPDATE channels SET api_key=?, `rotate`=?, rotate_idx=? WHERE id=?',
                    [implode("\n", $skList), $轮询开, $新游标, $chId]);
                $msg = "已保存 {$n} 个 SK，轮询" . ($轮询开 ? '已开启' : '已关闭');
                $msg .= $新游标 === $当前游标
                    ? '，游标保持在第 ' . ($新游标 + 1) . ' 个'
                    : '，SK 数量变少，游标收敛到第 ' . ($新游标 + 1) . ' 个';
            }
        }
    } elseif (($_POST['act'] ?? '') === 'rotate_reset') {
        db_exec('UPDATE channels SET rotate_idx=0 WHERE id=?', [$chId]);
        $msg = '轮询游标已归零，后续新对话从第 1 个 SK 开始分配';
    } elseif (($_POST['act'] ?? '') === 'sk_unbind') {
        // 解除该渠道下所有对话的 SK 绑定，下次请求各自重新分配。
        // 代价：已积累的 prompt 缓存全部作废，短期上游费用会涨一波。
        $n = db_exec('UPDATE conversations cv JOIN models m ON m.id = cv.model_id
                         SET cv.sk_index = NULL
                       WHERE m.channel_id = ? AND cv.sk_index IS NOT NULL', [$chId]);
        $msg = '已解除 ' . (int)$n . ' 条对话的 SK 绑定，它们下次请求会重新分配';
    } elseif (($_POST['act'] ?? '') === 'sk_health_reset') {
        // 手动解除熔断：换过新 key 或上游恢复后不必干等冷却到点
        $n = db_exec('DELETE FROM channel_sk_health WHERE channel_id = ?', [$chId]);
        $msg = '已清空 ' . (int)$n . ' 条 SK 健康记录，全部 SK 立即可用';
    } elseif (($_POST['act'] ?? '') === 'sk_test_one') {
        // AJAX 单个SK探测接口，供前端进度条逐个调用。
        // 走 csrf_check()（支持 X-CSRF-Token 头）而不是 csrf_check_page()。
        // csrf_check_page() 失败时直接 exit 文本，AJAX 收到会报错，所以用这个。
        csrf_check();
        $ch3 = db_one('SELECT c.*, p.name AS plat_name, p.base_url AS plat_base
                         FROM channels c LEFT JOIN api_platforms p ON p.id = c.parent_id
                        WHERE c.id = ?', [$chId]);
        $idx = (int) ($_POST['sk_idx'] ?? -1);
        $探测模型 = $ch3 ? db_val('SELECT model_name FROM models WHERE channel_id = ? ORDER BY id LIMIT 1', [$chId]) : null;
        if (!$ch3) {
            json_out(['ok' => false, 'msg' => '子分类不存在'], 400);
        } elseif (!$探测模型) {
            json_out(['ok' => false, 'msg' => '尚未配置模型，请先去「模型管理」加一个'], 400);
        } elseif ($idx < 0) {
            json_out(['ok' => false, 'msg' => '缺少 sk_idx'], 400);
        } else {
            $列表 = channel_sk_list($ch3['api_key']);
            if (!isset($列表[$idx])) {
                json_out(['ok' => false, 'skipped' => true, 'msg' => '下标不存在']);
            }
            // 熔断中的跳过探测。批量测试保持这个行为，避免把冷却期的 key 反复打一遍；
            // 但手动单测传 force=1 时照测不误——手动复测的目的就是看它恢复没有。
            $强制 = ($_POST['force'] ?? '') === '1';
            $熔断 = $强制 ? null : db_val(
                'SELECT 1 FROM channel_sk_health WHERE channel_id=? AND sk_index=? AND cooled_until > NOW()',
                [$chId, $idx]
            );
            if ($熔断) {
                json_out(['ok' => false, 'skipped' => true, 'msg' => '熔断中，已跳过']);
            }
            $合并后 = channel_merge_platform($ch3, null, null, true);
            $单测 = $合并后;
            $单测['api_key'] = $列表[$idx];
            // 手动单测留 20 秒，等慢通道把话说完；批量压到 8 秒，
            // 判断一个 key 能不能用 8 秒足够，再等下去只是让坏 key 拖住一个 worker。
            $r = upstream_test($单测, $探测模型, $强制 ? 20 : 8);
            if ($r['ok']) {
                channel_sk_ok($chId, $idx);} else {
                channel_sk_fail($chId, $idx, (int) ($r['http'] ?? 0), $r['msg']);
            }
            json_out([
                'ok'      => $r['ok'],
                'skipped' => false,
                'http'    => $r['http'] ?? 0,
                'msg'     => $r['msg'] ?? '',
            ]);
        }} elseif (($_POST['act'] ?? '') === 'sk_delete_by_code') {
        // 按故障码批量删：只删「当前连续失败中」且 last_http 命中指定码的 SK。
        // 用 fail_count > 0 而不是 cooled_until > NOW()：429 冷却很短，过期后反复失败的
        // SK 不再算「熔断中」，但它们最后一次仍是失败、还没成功过，同样是坏 key。
        // channel_sk_ok() 只在成功时清零 fail_count，所以 fail_count > 0 才是准确的「还没恢复」，
        // 不会误删掉曾经报过这个码、但后来已经成功过（fail_count 已归零）的 SK。
        $ch4 = db_one('SELECT id,api_key,rotate FROM channels WHERE id=?', [$chId]);
        $码提交 = ($_POST['http_code'] ?? '');
        if (!$ch4) {
            $err = '子分类不存在';
        } elseif ($码提交 === '') {
            $err = '请选择要删除的故障码';
        } else {
            $码 = (int) $码提交;
            $列表 = channel_sk_list($ch4['api_key']);
            $命中下标 = array_column(
                db_all('SELECT sk_index FROM channel_sk_health
                         WHERE channel_id = ? AND fail_count > 0 AND last_http = ?',
                       [$chId, $码]),
                'sk_index'
            );
            // 下标要限定在当前列表范围内，且按从大到小删，避免前面删除导致后面下标错位
            $命中下标 = array_values(array_unique(array_filter($命中下标, fn($i) => isset($列表[$i]))));
            rsort($命中下标);
            if (!$命中下标) {
                $err = "没有找到 HTTP {$码} 且当前连续失败中的 SK";
            } elseif (count($命中下标) >= count($列表)) {
                $err = '这个故障码命中了全部 SK，不能删空，请至少保留 1 个（可以先手动删剩 1 个，或改用整份替换）';
            } else {
                foreach ($命中下标 as $idx) {
                    unset($列表[$idx]);
                }
                $列表 = array_values($列表);
                $新轮询 = (int) $ch4['rotate'];
                if ($新轮询 === 1 && count($列表) < 2) {
                    $新轮询 = 0;
                }
                // 游标随删除位置平移：被删掉的 SK 里有几个排在游标前面，游标就往前挪几格，
                // 这样它仍然指向原来那个 SK，不会因为一次清理就退回队首。
                $当前游标 = (int) db_val('SELECT rotate_idx FROM channels WHERE id=?', [$chId]);
                $游标前删除数 = count(array_filter($命中下标, fn($i) => $i < $当前游标));
                $新游标 = max(0, $当前游标 - $游标前删除数);
                $新游标 = count($列表) > 0 ? $新游标 % count($列表) : 0;
                db_exec('UPDATE channels SET api_key=?, rotate=?, rotate_idx=? WHERE id=?',
                    [implode("\n", $列表), $新轮询, $新游标, $chId]);
                // 被删SK的健康记录直接删掉；剩余SK的健康记录按新下标重新映射。
                // 先删命中的，再把旧下标→新下标的对应关系算出来，批量UPDATE。
                db_exec('DELETE FROM channel_sk_health WHERE channel_id=? AND sk_index IN (' .
                    implode(',', array_fill(0, count($命中下标), '?')) . ')',
                    array_merge([$chId], $命中下标));
                // 构建旧下标→新下标映射：$命中下标已经rsort过，从大到小删，
                // 用同样逻辑推算删除后每个旧下标对应的新下标。
                $旧列表原始 = channel_sk_list($ch4['api_key']); // 删除前的列表
                $命中集合 = array_flip($命中下标);
                $新下标计数 = 0;
                $映射 = []; // 旧下标 => 新下标
                foreach ($旧列表原始 as $旧idx => $sk) {
                    if (isset($命中集合[$旧idx])) continue; // 被删的跳过
                    $映射[$旧idx] = $新下标计数++;
                }
                foreach ($映射 as $旧 => $新) {
                    if ($旧 !== $新) {
                        db_exec('UPDATE channel_sk_health SET sk_index=? WHERE channel_id=? AND sk_index=?',
                            [$新, $chId, $旧]);
                    }
                }
                $msg = "已删除 " . count($命中下标) . " 个 HTTP {$码} 的 SK，剩余 " . count($列表) . ' 个';
            }
        }
    } elseif (($_POST['act'] ?? '') === 'sk_delete') {
        // 删单个 SK。下标只用来定位「大概是哪一行」，真正认定删哪个还要核对
        // 原文完全一致——页面打开期间列表可能被并发改过，光凭下标删会删错行。
        $ch2 = db_one('SELECT id,api_key,rotate FROM channels WHERE id=?', [$chId]);
        if (!$ch2) {
            $err = '子分类不存在';
        } else {
            $列表 = channel_sk_list($ch2['api_key']);
            $del下标 = (int) ($_POST['sk_idx'] ?? -1);
            $del原文 = (string) ($_POST['sk_val'] ?? '');
            if (!isset($列表[$del下标]) || $列表[$del下标] !== $del原文) {
                $err = '要删除的 SK 已发生变化，请刷新页面后重试';
            } elseif (count($列表) <= 1) {
                $err = '只剩最后 1 个 SK，不能删空';
            } else {
                unset($列表[$del下标]);
                $列表 = array_values($列表);
                $新轮询 = (int) $ch2['rotate'];
                if ($新轮询 === 1 && count($列表) < 2) {
                    $新轮询 = 0;   // 删到只剩 1 个了，轮询开关自动关掉，跟保存时的校验口径一致
                }
                // 删的那个排在游标前面时游标往前挪一格，让它继续指向原来的 SK
                $当前游标 = (int) db_val('SELECT rotate_idx FROM channels WHERE id=?', [$chId]);
                $新游标 = $del下标 < $当前游标 ? $当前游标 - 1 : $当前游标;
                $新游标 = count($列表) > 0 ? max(0, $新游标) % count($列表) : 0;
                db_exec('UPDATE channels SET api_key=?, rotate=?, rotate_idx=? WHERE id=?',
                    [implode("\n", $列表), $新轮询, $新游标, $chId]);
                // 这个 SK 的健康记录也一并清掉，免得后面新增的 SK 顶到同一个下标却带着旧的熔断状态
                db_exec('DELETE FROM channel_sk_health WHERE channel_id=? AND sk_index=?', [$chId, $del下标]);
                $msg = '已删除第 ' . ($del下标 + 1) . ' 个 SK，剩余 ' . count($列表) . ' 个';
            }
        }
    }

    flash_set($err !== '' ? 'error' : 'ok', $err !== '' ? $err : $msg);
    redirect_self();
}

[$flash类型, $flash文本] = flash_get();
if ($flash类型 === 'error') $err = $flash文本;
elseif ($flash类型 === 'ok') $msg = $flash文本;

require __DIR__ . '/_head.php';

// 带出父分类名字，方便确认改的是哪个平台下的子分类
$ch = db_one('SELECT c.*, p.name AS plat_name, p.base_url AS plat_base
              FROM channels c LEFT JOIN api_platforms p ON p.id = c.parent_id
              WHERE c.id = ?', [$chId]);

$skList = $ch ? channel_sk_list($ch['api_key']) : [];
$skN    = count($skList);
$轮询开  = $ch ? (int)$ch['rotate'] === 1 : false;
// 游标指向第几个（取模防止 SK 减少后越界）。
// 会话粘性模式下游标只用于「给新对话分配」，不代表下一次请求一定用它。
$起点 = ($轮询开 && $skN > 0) ? ((int)$ch['rotate_idx'] % $skN) : 0;
// 每个 SK 实际承载多少条对话：这才是会话粘性下真正的负载分布。
// 按 channel_id 过滤，避免把别的渠道的对话算进来。
$负载 = [];
$绑定总数 = 0;
if ($skN > 0) {
    $rows = db_all('SELECT cv.sk_index AS idx, COUNT(*) AS n, MAX(cv.updated_at) AS 最近
                       FROM conversations cv JOIN models m ON m.id = cv.model_id
                      WHERE m.channel_id = ? AND cv.sk_index IS NOT NULL
                      GROUP BY cv.sk_index', [$chId]);
    foreach ($rows as $r) {
        // 取模兜底：SK 列表缩短过的话，旧下标可能越界
        $k = ((int)$r['idx']) % $skN;
        $负载[$k]['n']    = ($负载[$k]['n'] ?? 0) + (int)$r['n'];
        $绑定总数 += (int)$r['n'];
        $旧 = $负载[$k]['最近'] ?? '';
        if ($r['最近'] > $旧) {
            $负载[$k]['最近'] = $r['最近'];
        }
    }
}

// 各 SK 的健康度与熔断状态，用于表格里展示
$健康 = [];
if ($skN > 0) {
    $hr = db_all("SELECT sk_index AS idx, fail_count, last_http, last_error,
                          cooled_until,
                          (cooled_until IS NOT NULL AND cooled_until > NOW()) AS 熔断中,
                          TIMESTAMPDIFF(SECOND, NOW(), cooled_until) AS 剩余秒
                     FROM channel_sk_health WHERE channel_id = ?", [$chId]);
    foreach ($hr as $r) {
        $健康[((int) $r["idx"]) % $skN] = $r;
    }
}
$熔断数 = 0;
foreach ($健康 as $h) {
    if ((int) $h["熔断中"] === 1) { $熔断数++; }
}
// 当前「连续失败中」各故障码的数量，用于批量删除下拉框——只列真实存在的码，不让用户瞎填。
// 用 fail_count > 0 而不是 cooled_until > NOW()：429 冷却只有几十秒，过期后这些反复失败的
// SK 会从「熔断」退成「近期失败」，批量删除下拉框就查不到它们，导致坏 key 删不掉。
// channel_sk_ok() 只在成功时清零 fail_count，所以 fail_count > 0 才是「最后一次失败、还没恢复」的准确定义。
$故障码统计 = [];
if ($skN > 0) {
    foreach (db_all('SELECT last_http, COUNT(*) AS n FROM channel_sk_health
                       WHERE channel_id = ? AND fail_count > 0 GROUP BY last_http ORDER BY n DESC',
                     [$chId]) as $r) {
        $故障码统计[(int) $r['last_http']] = (int) $r['n'];
    }
}

// 「下一个新对话将使用的 SK」要跟 channel_pick_sk() 的新对话分支算法一致：
// 负载均衡——选「没熔断 + 承载对话数最少」的 SK；负载相同时优先靠近游标位。
$当前位 = $起点;
if ($轮询开 && $skN > 0 && $熔断数 < $skN) {
    $最少负载 = PHP_INT_MAX;
    for ($k = 0; $k < $skN; $k++) {
        $试 = ($起点 + $k) % $skN;
        if (!empty($健康[$试]['熔断中'])) {
            continue;   // 熔断中跳过
        }
        $load = (int)($负载[$试]['n'] ?? 0);
        if ($load < $最少负载) {
            $最少负载 = $load;
            $当前位 = $试;
        }
    }
}
?>
<div class="page-head">
  <h1 class="page-title">SK 轮询设置<?= $ch ? ' · ' . h($ch['name']) : '' ?></h1>
  <div class="spacer"></div>
  <a class="btn" href="/admin/channels.php">返回渠道列表</a>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<?php if (!$ch): ?>
  <div class="card"><div class="card-body empty">子分类不存在，请从渠道列表进入。</div></div>
<?php else: ?>

<div class="alert alert-info">
  一行填一个 SK，保存后按<b>会话粘性</b>分配：一条对话首次请求时分到一个 SK，之后固定用它。<br>
  这是为了 prompt 缓存——换 SK 等于换缓存空间，中途换 key 缓存会整体失效。<br>
  所属父分类：<b><?= h($ch['plat_name'] ?? '（未分配）') ?></b>
  <?php if (!empty($ch['plat_base'])): ?><span class="hint">· <?= h($ch['plat_base']) ?></span><?php endif; ?>
</div>

<div class="card mb-16">
  <div class="card-head">
    SK 列表（子分类 #<?= (int)$ch['id'] ?>）
    <span class="hint"> · 共 <?= $skN ?> 个，轮询<?= $轮询开 ? '已开启' : '已关闭' ?></span>
    <span class="spacer"></span>
    <button type="button" class="btn btn-primary btn-sm" onclick="skModalOpen()">批量编辑 SK</button>
  </div>
  <div class="card-body">
    <div class="hint">批量替换整份 SK 列表、或开关轮询，点右上角「批量编辑 SK」。单个 SK 要删，直接在下面表格里对应行点「删除」即可，不用整段重贴。</div>
  </div>
</div>

<!-- 批量编辑弹窗：整份替换 SK 列表 + 轮询开关，跟原来页面顶部的表单是同一个 -->
<div class="modal" id="skModal" hidden>
  <div class="modal-box" style="max-width:640px">
    <div class="modal-head">
      <h3>批量编辑 SK</h3>
      <button type="button" class="modal-x" onclick="skModalClose()">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="rotate_save">
        <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">

        <label class="field">
          <span class="field-label">SK 列表（一行一个，逗号分号也能识别，重复的会自动去掉）</span>
          <textarea class="input" name="sk_text" id="sk_text" rows="10"
                    style="font-family:ui-monospace,Menlo,Consolas,monospace;line-height:1.7"
                    placeholder="sk-xxxxxxxxxxxxxxxx&#10;sk-yyyyyyyyyyyyyyyy&#10;sk-zzzzzzzzzzzzzzzz"><?= h(implode("\n", $skList)) ?></textarea>
        </label>
        <div class="hint" style="margin:6px 0 14px">
          已识别 <b id="sk_count"><?= $skN ?></b> 个 SK
          <span id="sk_warn" style="color:#c00;display:none">· 开启轮询至少需要 2 个</span>
        </div>

        <label class="field">
          <span class="field-label">轮询开关</span>
          <span>
            <input type="checkbox" name="rotate" value="1" id="rotate_cb" <?= $轮询开 ? 'checked' : '' ?>>
            开启后多个 SK 顺序轮换；关闭则只用第 1 个 SK
          </span>
        </label>

        <div class="modal-foot">
          <button type="button" class="btn" onclick="skModalClose()">取消</button>
          <button class="btn btn-primary" type="submit">保存轮询设置</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($故障码统计): ?>
<div class="modal" id="delCodeModal" hidden>
  <div class="modal-box">
    <div class="modal-head">
      <h3>按故障码批量删除</h3>
      <button type="button" class="modal-x" onclick="delCodeModalClose()">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post"
            onsubmit="var sel=document.getElementById('http_code_sel');
                      if(!sel.value) return false;
                      return confirm('删除全部 HTTP '+sel.value+' 且当前连续失败中的 SK（共 '+sel.options[sel.selectedIndex].dataset.n+' 个）？\n\n删除后这些 SK 从列表里彻底移除，不是解除熔断，操作不可逆。');">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="sk_delete_by_code">
        <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
        <label class="field">
          <span class="field-label">选择故障码（只列出当前连续失败中的）</span>
          <div class="dd" id="http_code_dd">
            <input type="hidden" name="http_code" id="http_code_sel" value="" data-n="">
            <button type="button" class="input" style="width:100%;text-align:left;cursor:pointer;background:#fff"
                    onclick="var m=document.getElementById('http_code_menu');m.hidden=!m.hidden;">
              <span id="http_code_sel_text">请选择…</span>
            </button>
            <div id="http_code_menu" hidden
                 style="margin-top:6px;background:#fff;border:1px solid #ddd;border-radius:6px;box-shadow:0 2px 6px rgba(0,0,0,.08)">
              <?php foreach ($故障码统计 as $码 => $n): ?>
                <div class="dd-item" style="padding:10px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0"
                     onclick="document.getElementById('http_code_sel').value='<?= (int) $码 ?>';
                              document.getElementById('http_code_sel').dataset.n='<?= $n ?>';
                              document.getElementById('http_code_sel_text').textContent='HTTP <?= (int) $码 ?>（<?= $n ?> 个）';
                              document.getElementById('http_code_menu').hidden=true;">
                  HTTP <?= (int) $码 ?>（<?= $n ?> 个）
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </label>
        <div class="modal-foot">
          <button type="button" class="btn" onclick="delCodeModalClose()">取消</button>
          <button class="btn btn-primary" type="submit" style="background:#c00;border-color:#c00">删除</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($skN > 0): ?>
<div class="card mb-16">
  <div class="card-head">
    当前状态<?php if ($绑定总数 > 0): ?><span class="hint"> · 共 <?= (int)$绑定总数 ?> 条对话已绑定</span><?php endif; ?>
    <span class="spacer"></span>
    <?php if ($轮询开 && $skN > 1 && $绑定总数 > 0): ?>
    <form method="post" style="display:inline;margin-right:8px" onsubmit="return confirm('解除全部 <?= (int)$绑定总数 ?> 条对话的 SK 绑定？

这些对话下次请求会重新分配 SK，已积累的 prompt 缓存全部作废，短期上游费用会上涨。

通常只在换了 SK 列表、或某个 key 失效时才需要。');">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="sk_unbind">
      <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
      <button class="btn btn-sm" type="submit">解除所有绑定</button>
    </form>
    <?php endif; ?>
    <form method="post" style="display:inline;margin-right:8px"
    <span style="display:inline-block;margin-right:8px;vertical-align:middle">
      <button type="button" class="btn btn-sm" id="btnTestAll"
              onclick="testAllModalOpen(<?= (int)$ch['id'] ?>, <?= $skN ?>, '<?= h($csrf) ?>')">批量测试全部 SK</button>
      <div id="testProgressWrap" style="display:none;margin-top:6px;font-size:12px;color:#555;min-width:240px"></div>
    </span>

    <!-- 批量测试设置弹窗 -->
    <div id="testAllModal" hidden style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center">
      <div style="background:#fff;border-radius:8px;padding:28px 32px;min-width:300px;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,.18)">
        <div style="font-size:15px;font-weight:600;margin-bottom:18px">批量测试设置</div>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:13px;margin-bottom:4px;color:#444">并发数
            <span style="font-size:12px;color:#9ca3af">（同时探测几个 SK）</span>
          </label>
          <input type="number" id="testConcurrency" value="3" min="1" max="20" step="1"
                 style="width:100%;box-sizing:border-box;padding:6px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:14px">
        </div>
        <div style="margin-bottom:22px">
          <label style="display:block;font-size:13px;margin-bottom:4px;color:#444">请求间隔（毫秒）
            <span style="font-size:12px;color:#9ca3af">（每测完一个等多久再领下一个，防上游限流；不限流可填 0）</span>
          </label>
          <input type="number" id="testInterval" value="300" min="0" max="10000" step="100"
                 style="width:100%;box-sizing:border-box;padding:6px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:14px">
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
          <button type="button" class="btn btn-sm" onclick="testAllModalClose()" style="color:#666">取消</button>
          <button type="button" class="btn btn-sm" id="btnTestAllConfirm" onclick="startBatchTestConfirm()" style="background:#4f8ef7;color:#fff;border-color:#4f8ef7">开始测试</button>
        </div>
      </div>
    </div>
    <?php if ($故障码统计): ?>
    <button type="button" class="btn btn-sm" style="color:#c00;margin-right:8px" onclick="delCodeModalOpen()">批量删除</button>
    <?php endif; ?>
    <?php if ($轮询开 && $skN > 1): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('把游标归零？后续新对话从第 1 个 SK 开始分配，已绑定的对话不受影响。');">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="rotate_reset">
      <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
      <button class="btn btn-sm" type="submit">游标归零</button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('清空所有 SK 的失败计数与熔断状态？被熔断的 key 会立即重新参与轮询。')">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="sk_health_reset">
      <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
      <button class="btn btn-sm" type="submit">解除熔断</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th style="width:56px">序号</th><th>SK（掩码）</th><th style="width:84px">绑定对话</th><th style="width:104px">最近活跃</th><th style="width:132px">状态</th><th style="width:168px">健康度</th><th style="width:128px">操作</th></tr></thead>
      <tbody>
      <?php foreach ($skList as $i => $sk):
            // 只露头 8 尾 4，中间打码，避免后台页面泄露完整 SK
            $掩码 = mb_strlen($sk) > 14
                ? mb_substr($sk, 0, 8) . '••••••' . mb_substr($sk, -4)
                : mb_substr($sk, 0, 4) . '••••';
      ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td style="font-family:ui-monospace,Menlo,Consolas,monospace"><?= h($掩码) ?></td>
          <?php $本条数 = (int)($负载[$i]['n'] ?? 0); $本条最近 = $负载[$i]['最近'] ?? ''; ?>
          <td><?= $本条数 > 0 ? $本条数 . ' 条' : '<span class="hint">—</span>' ?></td>
          <td><span class="hint"><?= $本条最近 !== '' ? h(mb_substr($本条最近, 5, 11)) : '—' ?></span></td>
          <td>
            <?php if (!$轮询开): ?>
              <?= $i === 0 ? '<span class="badge badge-ok">使用中</span>' : '<span class="badge badge-off">未启用</span>' ?>
            <?php else: ?>
              <?php if ($本条数 > 0): ?><span class="badge badge-ok">承载中</span> <?php endif; ?>
              <?php if ($i === $当前位): ?><span class="badge">⟳ 新对话下一个</span><?php endif; ?>
              <?php if ($本条数 === 0 && $i !== $当前位): ?><span class="badge badge-off">空闲</span><?php endif; ?>
            <?php endif; ?>
          </td>
          <?php $健 = $健康[$i] ?? null;
                $失败数 = $健 ? (int) $健['fail_count'] : 0;
                $在熔断 = $健 && (int) $健['熔断中'] === 1;
                $剩余 = $健 ? max(0, (int) $健['剩余秒']) : 0;
          ?>
          <td>
            <?php if ($失败数 > 0): ?>
              <span class="badge badge-off" title="<?= h($健['last_error']) ?>">熔断 · HTTP <?= (int) $健['last_http'] ?></span>
              <?php if ($在熔断): ?>
                <span class="hint"><?= $剩余 >= 60 ? intdiv($剩余, 60) . ' 分钟后恢复' : $剩余 . ' 秒后恢复' ?></span>
              <?php else: ?>
                <span class="hint">冷却已过，待重试</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge badge-ok">正常</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:4px;align-items:center">
              <button class="btn btn-sm" type="button"
                      title="单独复测这个 SK，熔断中的也照测"
                      onclick="testSingleSk(this, <?= (int)$ch['id'] ?>, <?= $i ?>, '<?= h($csrf) ?>')">测试</button>
              <?php if ($skN > 1): ?>
                <form method="post" style="display:inline;margin:0" onsubmit="return confirm('删除第 <?= $i + 1 ?> 个 SK（<?= h($掩码) ?>）？<?= $本条数 > 0 ? "\n\n它目前承载 {$本条数} 条对话，删除后这些对话下次请求会重新分配到其他 SK，缓存作废。" : '' ?>');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="act" value="sk_delete">
                  <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                  <input type="hidden" name="sk_idx" value="<?= $i ?>">
                  <input type="hidden" name="sk_val" value="<?= h($sk) ?>">
                  <button class="btn btn-sm" type="submit" style="color:#c00">删除</button>
                </form>
              <?php else: ?>
                <span class="hint" title="只剩最后 1 个，不能删空">—</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
var _testCtx = null;

function testAllModalOpen(chId, total, csrf) {
  var btn = document.getElementById('btnTestAll');
  if (btn.disabled) return;
  _testCtx = { chId: chId, total: total, csrf: csrf };
  document.getElementById('testAllModal').hidden = false;
  document.getElementById('testConcurrency').focus();
}

function testAllModalClose() {
  document.getElementById('testAllModal').hidden = true;
}

function startBatchTestConfirm() {
  var concurrency = Math.max(1, Math.min(20, parseInt(document.getElementById('testConcurrency').value) || 3));
  var interval    = Math.max(0, Math.min(10000, parseInt(document.getElementById('testInterval').value) || 300));
  testAllModalClose();
  startBatchTest(_testCtx.chId, _testCtx.total, _testCtx.csrf, concurrency, interval);
}

// 单个 SK 复测：复用批量测试的后端接口 sk_test_one，额外传 force=1 让熔断中的也真测。
// 结果由后端写回健康度表，按钮上先显示即时结果，再刷新整页让状态列和健康度列跟着更新。
function testSingleSk(btn, chId, idx, csrf) {
  if (btn.disabled) return;
  var 原文 = btn.textContent;
  btn.disabled    = true;
  btn.textContent = '测试中…';
  btn.style.color = '';
  btn.title       = '';

  var fd = new FormData();
  fd.append('act',    'sk_test_one');
  fd.append('id',     chId);
  fd.append('sk_idx', idx);
  fd.append('force',  '1');
  fd.append('csrf',   csrf);

  fetch(location.pathname + '?id=' + chId, {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrf },
    body: fd
  })
  .then(function (res) { return res.json(); })
  .then(function (d) {
    if (d.ok) {
      btn.textContent = '✓ 通过';
      btn.style.color = '#16a34a';
    } else {
      btn.textContent = d.http ? '✗ ' + d.http : '✗ 失败';
      btn.style.color = '#dc2626';
    }
    btn.title = d.msg || '';
    // 不强制刷新页面，3 秒后恢复按钮可重新测试
    setTimeout(function () {
      btn.disabled = false;
      btn.textContent = 原文;
      btn.style.color = '';
      btn.title = '';
    }, 3000);
  })
  .catch(function () {
    btn.disabled    = false;
    btn.textContent = 原文;
    btn.style.color = '#dc2626';
    btn.title       = '请求发送失败，请重试';
  });
}

function startBatchTest(chId, total, csrf, concurrency, interval) {
  concurrency = concurrency || 3;
  interval    = interval != null ? interval : 300;

  var btn = document.getElementById('btnTestAll');
  btn.disabled = true;
  btn.textContent = '测试中…';

  var wrap = document.getElementById('testProgressWrap');
  wrap.style.display = 'block';

  var ok = 0, fail = 0, skip = 0, done = 0;

  function render() {
    var pct = total > 0 ? Math.round(done / total * 100) : 0;
    wrap.innerHTML =
      '<div style="display:flex;align-items:center;gap:6px;margin-bottom:3px">' +
        '<div style="flex:1;height:5px;background:#e5e7eb;border-radius:3px;overflow:hidden">' +
          '<div style="width:' + pct + '%;height:100%;background:#4f8ef7;border-radius:3px;transition:width .15s"></div>' +
        '</div>' +
        '<span style="white-space:nowrap;font-weight:500">已完成 ' + done + ' / ' + total + '</span>' +'</div>' +
      '<span style="color:#16a34a">✓ 通过 ' + ok + '</span>&emsp;' +
      '<span style="color:#dc2626">✗ 失败 ' + fail + '</span>&emsp;' +
      '<span style="color:#9ca3af">⊘ 跳过 ' + skip + '</span>';
  }

  render();

  // 单次探测：成功失败都 resolve，交给外层滑动窗口继续领取下一个
  function testOne(idx) {
    return new Promise(function (resolve) {
      var fd = new FormData();
      fd.append('act',    'sk_test_one');
      fd.append('id',     chId);
      fd.append('sk_idx', idx);
      fd.append('csrf',   csrf);
      fetch(location.pathname + '?id=' + chId, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf },
        body: fd
      })
      .then(function (res) { return res.json(); })
      .then(function (d) {
        done++;
        if (d.skipped) skip++;
        else if (d.ok)  ok++;
        else            fail++;
        render();
        resolve();
      })
      .catch(function () {
        done++; fail++;
        render();
        resolve();
      });
    });
  }

  // 滑动窗口：开 concurrency 个 worker，每个跑完一个立刻领下一个下标，
  // 不必等同批其他请求，慢的 SK 只占住一个 worker，不拖住整体进度。
  var 下一个 = 0, 运行中 = 0;

  function 完成收尾() {
    btn.textContent = '完成，刷新中…';
    setTimeout(function () { location.reload(); }, 800);
  }

  function 领取() {
    if (下一个 >= total) {
      if (运行中 === 0) 完成收尾();
      return;
    }
    var idx = 下一个++;
    运行中++;
    testOne(idx).then(function () {
      运行中--;
      // interval 是给上游限流留的喘息，0 时直接领下一个
      if (interval > 0) setTimeout(领取, interval);
      else 领取();
    });
  }

  if (total === 0) {
    完成收尾();
  } else {
    for (var w = 0; w < Math.min(concurrency, total); w++) 领取();
  }
}

function skModalOpen(){ document.getElementById('skModal').hidden = false; }
function skModalClose(){ document.getElementById('skModal').hidden = true; }
function delCodeModalOpen(){ document.getElementById('delCodeModal').hidden = false; }
function delCodeModalClose(){ document.getElementById('delCodeModal').hidden = true; }
(function(){
  // 实时统计 SK 条数，规则跟后端 channel_sk_list() 保持一致
  var ta=document.getElementById('sk_text'),
      cnt=document.getElementById('sk_count'),
      warn=document.getElementById('sk_warn'),
      cb=document.getElementById('rotate_cb');
  if(!ta||!cnt) return;
  function 统计(){
    var 行=(ta.value||'').replace(/[,;，；]/g,'\n').split('\n'),
         去重={}, n=0;
    行.forEach(function(l){
      l=l.trim();
      if(l!==''&&!去重[l]){去重[l]=1;n++;}
    });
    cnt.textContent=n;
    if(warn) warn.style.display=(cb&&cb.checked&&n<2)?'':'none';
  }
  ta.addEventListener('input',统计);
  if(cb) cb.addEventListener('change',统计);
  统计();
})();
</script>

<?php endif; ?>

<?php require __DIR__ . '/_foot.php';
