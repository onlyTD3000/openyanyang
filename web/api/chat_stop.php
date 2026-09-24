<?php
/**
 * 暂停正在生成的回答。
 *
 * 只是把 chat_runs.stop_req 置 1，真正的断流由 chat.php 那边完成：
 * 它每秒写增量时会顺手读这个标记，读到就返回 false 让 curl 断掉上游连接。
 * 不能在这里直接杀进程——那是另一个 PHP-FPM worker，这边碰不到它。
 *
 * 已经生成的半截回答保留（照旧落进 messages），用户可以接着往下聊，
 * 也可以把刚才那句话改一改重新发。
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();

$me = require_login_api();
csrf_check();

$runId  = (int) ($_POST['run_id'] ?? 0);
$convId = (int) ($_POST['conv_id'] ?? 0);

// 锁死 user_id，别人的任务停不了
if ($runId > 0) {
    $run = db_one('SELECT id, status FROM chat_runs WHERE id = ? AND user_id = ? LIMIT 1',
        [$runId, $me['id']]);
} elseif ($convId > 0) {
    $run = db_one('SELECT id, status FROM chat_runs WHERE conv_id = ? AND user_id = ?
                    ORDER BY id DESC LIMIT 1', [$convId, $me['id']]);
} else {
    json_out(['error' => '参数不完整'], 400);
}

// 前端拿着的 run_id 可能是过期的：切后台重连、卡片自动执行连着发了新一轮，
// 都可能让它停在上一轮的编号上。那一轮早收尾了，照它停等于什么都没停——
// 用户看到按钮闪一下又弹回来，内容还在刷。
// 所以拿到的这轮要是已经不在跑了，就退回「这个会话最新那个还在跑的任务」。
if ($convId > 0 && (!$run || (string) $run['status'] !== 'running')) {
    $活的 = db_one('SELECT id, status FROM chat_runs
                     WHERE conv_id = ? AND user_id = ? AND status = \'running\'
                     ORDER BY id DESC LIMIT 1', [$convId, $me['id']]);
    if ($活的) {
        $run = $活的;
    }
}

if (!$run) {
    json_out(['error' => '没有找到生成任务'], 404);
}
// 已经收尾的就不用停了。回 ok 但把真实状态带上，
// 前端据此把按钮复位，而不是一直停在「停止中」。
if ((string) $run['status'] !== 'running') {
    json_out(['ok' => 1, 'status' => (string) $run['status'], 'run_id' => (int) $run['id']]);
}

db_exec('UPDATE chat_runs SET stop_req = 1 WHERE id = ? AND user_id = ?',
    [(int) $run['id'], $me['id']]);

json_out(['ok' => 1, 'status' => 'stopping', 'run_id' => (int) $run['id']]);
