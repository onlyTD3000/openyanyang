<?php
/**
 * 用量接口：余额/配额概览、调用明细分页。
 * 逻辑对齐网页端 usage.php，供安卓端等客户端调用。
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();
$me = require_login_api();
$act = (string) ($_POST['act'] ?? $_GET['act'] ?? 'summary');
// ---------- 概览：余额、配额、今日消费、累计统计 ----------
if ($act === 'summary') {
    $sum = db_one('SELECT COALESCE(SUM(tokens_in),0) ti, COALESCE(SUM(tokens_out),0) to_,
                          COALESCE(SUM(cost),0) c, COUNT(*) n
                     FROM usage_logs WHERE user_id = ?', [$me['id']]);
    $today = db_one('SELECT COALESCE(SUM(cost),0) c, COUNT(*) n FROM usage_logs
                      WHERE user_id = ? AND created_at >= CURDATE()', [$me['id']]);
    $quotaLeft = (int) $me['token_quota'] > 0
        ? max(0, (int) $me['token_quota'] - (int) $me['used_tokens']) : null;
    json_out([
        'ok'            => 1,
        'balance'       => money($me['balance']),
        'total_cost'    => money($me['total_cost']),
        'token_quota'   => (int) $me['token_quota'],
        'used_tokens'   => (int) $me['used_tokens'],
        'quota_left'    => $quotaLeft, // null 表示不限
        'today_cost'    => money($today['c']),
        'today_calls'   => (int) $today['n'],
        'total_tokens'  => (int) $sum['ti'] + (int) $sum['to_'],
        'total_calls'   => (int) $sum['n'],
    ]);
}
// ---------- 调用明细分页 ----------
if ($act === 'logs') {
    $page  = max(1, (int) ($_GET['p'] ?? $_POST['p'] ?? 1));
    $per   = 20;
    $off   = ($page - 1) * $per;
    $total = (int) db_val('SELECT COUNT(*) FROM usage_logs WHERE user_id = ?', [$me['id']]);
    $rows  = db_all('SELECT * FROM usage_logs WHERE user_id = ?
                      ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . $off, [$me['id']]);
    json_out([
        'ok'    => 1,
        'page'  => $page,
        'pages' => max(1, (int) ceil($total / $per)),
        'total' => $total,
        'list'  => array_map(fn($r) => [
            'id'          => (int) $r['id'],
            'model_name'  => $r['model_name'],
            'tokens_in'   => (int) $r['tokens_in'],
            'tokens_out'  => (int) $r['tokens_out'],
            'cost'        => money($r['cost']),
            'status'      => $r['status'],
            'is_estimated'=> (int) $r['is_estimated'],
            'created_at'  => $r['created_at'],
        ], $rows),
    ]);
}
json_out(['error' => '未知操作'], 400);
