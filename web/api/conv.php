<?php
/** 会话管理接口：list / messages / new / del / rename / move。严格按 user_id 隔离。 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();   // 让未捕获异常返回可读 JSON，而不是空的 500
require_once __DIR__ . '/../inc/project.php';
require_once __DIR__ . '/../inc/tool_results.php';

$me  = require_login_api();
$uid = (int) $me['id'];
$act = $_GET['act'] ?? $_POST['act'] ?? '';

if ($act === 'list') {
    // 传 project_id 就只列该项目的对话；不传则列全部（兼容旧调用）
    $pid = (int) ($_GET['project_id'] ?? 0);
    if ($pid > 0) {
        if (!project_of($pid, $uid)) {
            json_out(['error' => '项目不存在']);
        }
        $rows = db_all('SELECT id, title, project_id, msg_count, updated_at
                          FROM conversations
                         WHERE user_id = ? AND project_id = ?
                         ORDER BY updated_at DESC LIMIT 200', [$uid, $pid]);
    } else {
        $rows = db_all('SELECT id, title, project_id, msg_count, updated_at
                          FROM conversations
                         WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100', [$uid]);
    }
    json_out(['ok' => 1, 'list' => $rows]);
}

if ($act === 'messages') {
    $cid = (int) ($_GET['conv_id'] ?? 0);
    $conv = db_one('SELECT id, title, model_id, project_id, context_limit FROM conversations
                     WHERE id = ? AND user_id = ? LIMIT 1', [$cid, $uid]);
    if (!$conv) {
        json_out(['error' => '会话不存在']);
    }
    // hidden=1 的是工具回执（SFTP/命令/工作中心结果），只给 AI 看，不在界面上渲染
    //
    // 首屏只取这么多条，往上翻由 before_id 游标继续拉。
    // 原来写死 400：长会话（实测某会话 694 条、112 万字）一次全渲染，浏览器要吃
    // 掉几百 MB 内存，打开会话明显卡顿。降到 60 够铺满一屏还有余量。
    //
    // 注意这只影响界面显示，不影响发给上游的上下文——那个由 history_char_budget
    // 单独控制。想省 token 得调那个，改这里没有任何效果。
    $limit = max(10, min(400, (int) setting_get('ui_page_size', 60)));
    // before_id 是游标：传了就取这条消息之前的更早记录，不传则取最新一批
    $before = (int) ($_GET['before_id'] ?? 0);
    $sql = 'SELECT id, role, content, images, tokens_in, tokens_out, tokens_cache,
                   tokens_cache_create, cost, created_at
              FROM messages WHERE conv_id = ? AND user_id = ? AND role <> \'system\'
               AND hidden = 0';
    // id 列就是消息 id，前端用它来标记收藏
    $args = [$cid, $uid];
    if ($before > 0) {
        $sql .= ' AND id < ?';
        $args[] = $before;
    }
    // 多取一条只为判断上面还有没有更早的，返回前会切掉，不给前端
    $sql .= ' ORDER BY id DESC LIMIT ' . ($limit + 1);
    $rows = db_all($sql, $args);
    $has_more = count($rows) > $limit;
    if ($has_more) { array_pop($rows); }
    $rows = array_reverse($rows);   // 上面取的是 DESC，这里翻回时间正序再渲染
    // 把 images 里的 id 换成带鉴权的读取地址，前端直接用
    foreach ($rows as &$r) {
        $urls = [];
        if (trim((string) $r['images']) !== '') {
            $ids = json_decode((string) $r['images'], true);
            if (is_array($ids)) {
                foreach (array_slice($ids, 0, 8) as $iid) {
                    $iid = (int) $iid;
                    if ($iid > 0) {
                        $urls[] = '/api/img.php?id=' . $iid;
                    }
                }
            }
        }
        $r['img_urls'] = $urls;
        unset($r['images']);
    }
    unset($r);
    json_out([
        'ok'       => 1,
        'conv'     => $conv,
        'list'     => $rows,
        'has_more' => $has_more ? 1 : 0,
        // first_id 是这批里最早那条的 id，前端拿它当下一次翻页的游标
        'first_id' => $rows ? (int) $rows[0]['id'] : 0,
    ]);
}

// 以下为写操作
csrf_check();

if ($act === 'new') {
    // 在指定项目下建一条空对话
    $pid = (int) ($_POST['project_id'] ?? 0);
    if (!project_of($pid, $uid)) {
        json_out(['error' => '请先选择一个项目'], 400);
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $title = $title === '' ? '新对话' : mb_substr($title, 0, 60);
    $n = (int) db_val('SELECT COUNT(*) FROM conversations WHERE project_id=?', [$pid]);
    if ($n >= 200) {
        json_out(['error' => '单个项目最多 200 条对话'], 400);
    }
    $cid = db_insert('INSERT INTO conversations (user_id, project_id, title, created_at, updated_at)
                      VALUES (?,?,?,NOW(),NOW())', [$uid, $pid, $title]);
    project_touch($pid, $uid);
    json_out(['ok' => 1, 'conv_id' => $cid, 'title' => $title]);
}

if ($act === 'del') {
    $cid = (int) ($_POST['conv_id'] ?? 0);
    $conv = db_one('SELECT id, project_id FROM conversations WHERE id = ? AND user_id = ? LIMIT 1',
        [$cid, $uid]);
    if (!$conv) {
        json_out(['error' => '会话不存在']);
    }
    db_exec('DELETE FROM messages WHERE conv_id = ? AND user_id = ?', [$cid, $uid]);
    // 回执已改存 DATA_DIR/toolresults/<user>/<conv>.txt，删会话时连文件一起清
    tool_result_drop($uid, $cid);
    db_exec('DELETE FROM conversations WHERE id = ? AND user_id = ?', [$cid, $uid]);
    if ((int) $conv['project_id'] > 0) {
        project_touch((int) $conv['project_id'], $uid);
    }
    json_out(['ok' => 1]);
}

if ($act === 'rename') {
    $cid = (int) ($_POST['conv_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        json_out(['error' => '标题不能为空'], 400);
    }
    $n = db_exec('UPDATE conversations SET title = ? WHERE id = ? AND user_id = ?',
        [mb_substr($title, 0, 60), $cid, $uid]);
    json_out($n ? ['ok' => 1] : ['error' => '会话不存在']);
}

if ($act === 'move') {
    // 把对话挪到另一个项目
    $cid = (int) ($_POST['conv_id'] ?? 0);
    $pid = (int) ($_POST['project_id'] ?? 0);
    $conv = db_one('SELECT id, project_id FROM conversations WHERE id=? AND user_id=?', [$cid, $uid]);
    if (!$conv) {
        json_out(['error' => '会话不存在']);
    }
    if (!project_of($pid, $uid)) {
        json_out(['error' => '目标项目不存在']);
    }
    db_exec('UPDATE conversations SET project_id=?, updated_at=NOW() WHERE id=? AND user_id=?',
        [$pid, $cid, $uid]);
    $旧 = (int) $conv['project_id'];
    if ($旧 > 0 && $旧 !== $pid) {
        project_touch($旧, $uid);
    }
    project_touch($pid, $uid);
    json_out(['ok' => 1]);
}

if ($act === 'set_model') {
    // 切换当前会话使用的模型，立即落库。下一轮提问即按该模型计费。
    $cid = (int) ($_POST['conv_id'] ?? 0);
    $mid = (int) ($_POST['model_id'] ?? 0);
    // 校验模型可用（同时要求所属渠道正常），避免存进一个已下架的模型
    $mo = db_one('SELECT m.id FROM models m JOIN channels c ON c.id = m.channel_id
                   WHERE m.id = ? AND m.status = 1 AND c.status = 1 LIMIT 1', [$mid]);
    if (!$mo) {
        json_out(['error' => '模型不可用，请重新选择'], 400);
    }
    // conv_id 为 0 表示还没建会话（新对话），此时不用落库，前端记本地即可
    if ($cid <= 0) {
        json_out(['ok' => 1, 'saved' => 0]);
    }
    $n = db_exec('UPDATE conversations SET model_id = ? WHERE id = ? AND user_id = ?',
        [$mid, $cid, $uid]);
    json_out($n !== false ? ['ok' => 1, 'saved' => 1] : ['error' => '会话不存在'], 200);
}

if ($act === 'set_context_limit') {
    // 会话级上下文条数落库：0 = 跟随模型默认，2..60 = 自定义。
    // 与 chat.php 读取侧同口径校验，防越界值把 token 账单撑爆。
    $cid = (int) ($_POST['conv_id'] ?? 0);
    $v   = (int) ($_POST['context_limit'] ?? 0);
    if ($v !== 0 && ($v < 2 || $v > 60)) {
        json_out(['error' => '上下文条数请填 2 到 60，或留空跟随模型默认'], 400);
    }
    if ($cid <= 0) {
        // 新对话还没落库，前端先存内存，首条消息建会话时由 chat.php 一并写入
        json_out(['ok' => 1, 'saved' => 0]);
    }
    $conv = db_one('SELECT id FROM conversations WHERE id = ? AND user_id = ? LIMIT 1',
        [$cid, $uid]);
    if (!$conv) {
        json_out(['error' => '会话不存在']);
    }
    db_exec('UPDATE conversations SET context_limit = ? WHERE id = ? AND user_id = ?',
        [$v, $cid, $uid]);
    json_out(['ok' => 1, 'saved' => 1]);
}

// set_lang_follow 接口已随「跟随提问语言」功能一起删除。
// 旧客户端调用会落到下面的「未知操作」，由强制更新覆盖掉旧客户端。
json_out(['error' => '未知操作'], 400);
