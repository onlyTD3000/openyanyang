<?php
/** 消息收藏接口：add / remove / list。严格按 user_id 隔离。 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();

$me  = require_login_api();
$uid = (int) $me['id'];
$act = $_GET['act'] ?? $_POST['act'] ?? '';

// ---- 收藏列表（GET） ----
if ($act === 'list') {
    $rows = db_all(
        'SELECT f.id, f.msg_id, f.conv_id, f.role, f.content, f.created_at,
                c.title AS conv_title
           FROM message_favorites f
           LEFT JOIN conversations c ON c.id = f.conv_id
          WHERE f.user_id = ?
          ORDER BY f.created_at DESC
          LIMIT 200',
        [$uid]
    );
    foreach ($rows as &$r) {
        // 内容截取前 500 字做预览，完整内容单独取
        $r['preview'] = mb_substr((string) $r['content'], 0, 500);
        $r['content_length'] = mb_strlen((string) $r['content']);
        unset($r['content']);
    }
    unset($r);
    json_out(['ok' => 1, 'list' => $rows]);
}

// 以下为写操作
csrf_check();

// ---- 添加收藏 ----
if ($act === 'add') {
    $msgId = (int) ($_POST['msg_id'] ?? 0);
    $convId = (int) ($_POST['conv_id'] ?? 0);
    if ($msgId <= 0 || $convId <= 0) {
        json_out(['error' => '参数不合法'], 400);
    }
    // 校验消息归属：必须属于当前用户且属于该会话
    $msg = db_one(
        'SELECT id, conv_id, role, content FROM messages
          WHERE id = ? AND user_id = ? AND conv_id = ? AND hidden = 0
            AND role IN ("user","assistant") LIMIT 1',
        [$msgId, $uid, $convId]
    );
    if (!$msg) {
        json_out(['error' => '消息不存在'], 404);
    }
    // 检查是否已收藏（UNIQUE 索引兜底，这里先查让提示更友好）
    $exists = (int) db_val(
        'SELECT COUNT(*) FROM message_favorites WHERE user_id = ? AND msg_id = ?',
        [$uid, $msgId]
    );
    if ($exists > 0) {
        json_out(['ok' => 1, 'msg' => '已收藏']);
    }
    db_insert(
        'INSERT INTO message_favorites (user_id, msg_id, conv_id, role, content, created_at)
         VALUES (?,?,?,?,?,NOW())',
        [$uid, $msgId, $convId, $msg['role'], $msg['content']]
    );
    json_out(['ok' => 1]);
}

// ---- 取消收藏 ----
if ($act === 'remove') {
    $favId = (int) ($_POST['fav_id'] ?? 0);
    $msgId = (int) ($_POST['msg_id'] ?? 0);
    if ($favId > 0) {
        $n = db_exec(
            'DELETE FROM message_favorites WHERE id = ? AND user_id = ?',
            [$favId, $uid]
        );
    } elseif ($msgId > 0) {
        $n = db_exec(
            'DELETE FROM message_favorites WHERE msg_id = ? AND user_id = ?',
            [$msgId, $uid]
        );
    } else {
        json_out(['error' => '参数不合法'], 400);
    }
    json_out(['ok' => 1, 'removed' => $n]);
}

// ---- 检查收藏状态（批量） ----
if ($act === 'check') {
    $ids = $_GET['ids'] ?? '';
    $idArr = array_filter(array_map('intval', explode(',', $ids)));
    if (!$idArr) {
        json_out(['ok' => 1, 'ids' => []]);
    }
    $placeholders = implode(',', array_fill(0, count($idArr), '?'));
    $rows = db_all(
        "SELECT msg_id FROM message_favorites WHERE user_id = ? AND msg_id IN ($placeholders)",
        array_merge([$uid], $idArr)
    );
    $favIds = array_map('intval', array_column($rows, 'msg_id'));
    json_out(['ok' => 1, 'ids' => $favIds]);
}

// ---- 获取完整内容 ----
if ($act === 'get') {
    $favId = (int) ($_GET['fav_id'] ?? 0);
    $row = db_one(
        'SELECT * FROM message_favorites WHERE id = ? AND user_id = ? LIMIT 1',
        [$favId, $uid]
    );
    if (!$row) {
        json_out(['error' => '收藏不存在'], 404);
    }
    json_out(['ok' => 1, 'fav' => $row]);
}

json_out(['error' => '未知操作'], 400);
