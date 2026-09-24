<?php
/**
 * 断线重连接口（SSE）。
 *
 * chat.php 里有 ignore_user_abort(true)，浏览器一断它照样跑到底，
 * 增量按秒写进 chat_runs。这个接口就是把那份增量读出来接着往下推：
 * 用户刷新页面、切到别的对话再回来、手机息屏重连，都走这里。
 *
 * 只读接口，不碰余额也不调上游，所以不需要 CSRF：
 * 会话身份由 require_login_api() 把住，数据按 user_id 隔离。
 */
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/thinking.php';
api_error_guard();

$me = require_login_api();

$convId = (int) ($_GET['conv_id'] ?? 0);
$runId  = (int) ($_GET['run_id'] ?? 0);
// 前端已经显示了多少字符，从这里往后推，避免把开头重复发一遍
$from   = max(0, (int) ($_GET['from'] ?? 0));

if ($convId <= 0 && $runId <= 0) {
    json_out(['error' => '参数不完整'], 400);
}

// 找这轮任务。带 run_id 就精确找，否则取该会话最近一条。
// 两个分支都锁死 user_id，别人的任务查不到。
if ($runId > 0) {
    $run = db_one('SELECT * FROM chat_runs WHERE id = ? AND user_id = ? LIMIT 1',
        [$runId, $me['id']]);
} else {
    $run = db_one('SELECT * FROM chat_runs WHERE conv_id = ? AND user_id = ?
                    ORDER BY id DESC LIMIT 1', [$convId, $me['id']]);
}

if (!$run) {
    json_out(['error' => '没有找到生成任务'], 404);
}

// 轻量状态查询：只回一个 JSON，不开流。
// 前端切会话时先问一句「这轮还在跑吗」——已经收尾的回答早就落进 messages 了，
// 历史记录里会渲染出来，这时候再接一次流会让同一条回答显示两遍。
if (($_GET['act'] ?? '') === 'stat') {
    json_out([
        'run_id'  => (int) $run['id'],
        'conv_id' => (int) $run['conv_id'],
        'status'  => (string) $run['status'],
        'msg_id'  => (int) $run['msg_id'],
    ]);
}

/**
 * 判断这轮是不是已经没人在跑了。
 *
 * status 还是 running 但心跳停了，说明 PHP 进程已经不在了——
 * 可能是 PHP-FPM 重启、进程被杀、或者致命错误没走到收尾那步。
 * 这种情况不能一直让前端转圈，得明确告诉它「断了」。
 *
 * 阈值取 90 秒：正常情况下写增量的间隔是 1 秒，
 * 但上游首字可能要等十几秒，思考型模型更久，中间没有任何增量可写。
 * 留够余量，免得把还在等上游的正常任务误判成死了。
 */
function 任务已死(array $run): bool
{
    if ($run['status'] !== 'running') {
        return false;
    }
    return (time() - strtotime((string) $run['beat_at'])) > 90;
}

// ---- 输出 SSE（与 chat.php 保持一致的头，否则 nginx 会缓冲住）----
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_flush();
}
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// 和 chat.php 同样的道理：这个接口要挂着轮询等增量，一样几十秒不返回。
// 不放锁的话，用户自己的其他请求会全部卡在 session_start() 上。
// 下面只读 chat_runs，不再碰 $_SESSION。
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function rsse(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

/** 把结束状态发给前端。字段名与 chat.php 的 done 事件对齐，前端复用同一套处理。 */
function 发收尾(array $run): void
{
    if ($run['status'] === 'error' && (string) $run['err_msg'] !== '') {
        rsse('err', ['msg' => (string) $run['err_msg']]);
    }
    // 这轮是被用户暂停的：得补一个 stopped，否则前端不知道，
    // 气泡上不会标「已暂停生成」，暂停按钮也一直停在「停止中」不复位。
    // chat.php 发的那个 stopped 走的是它自己那条已经断掉的连接，这边收不到。
    if ($run['status'] === 'stopped') {
        rsse('stopped', ['t' => '']);
    }
    rsse('done', [
        'conv_id'   => (int) $run['conv_id'],
        'run_id'    => (int) $run['id'],
        'msg_id'    => (int) $run['msg_id'],
        'tokens_in' => (int) $run['tokens_in'],
        'tokens_out'=> (int) $run['tokens_out'],
        'tokens_cache' => (int) $run['tokens_cache'],
        'tokens_cache_create' => (int) $run['tokens_cache_create'],
        'cost'      => money($run['cost']),
        'estimated' => (int) $run['is_estimated'],
        'balance'   => money($run['balance']),
    ]);
}

// 先把已经生成的部分一次性铺给前端，让用户立刻看到内容，
// 而不是从头一个字一个字重放。
$已发 = 0;
$已有 = (string) ($run['answer'] ?? '');

// 推送已执行的工具结果（断点重续）
$pdo = get_pdo();
$toolResultsRows = db_all('SELECT tool_call_id, content AS result FROM tool_results WHERE conv_id = ? ORDER BY id ASC', [(int)$run['conv_id']]);
if (!empty($toolResultsRows)) {
    $toolCalls = [];
    $toolResults = [];
    foreach ($toolResultsRows as $row) {
        $toolCalls[] = [
            'id' => $row['tool_call_id'],
            'type' => 'function',
            'function' => ['name' => 'unknown', 'arguments' => '{}']
        ];
        $toolResults[] = [
            'tool_call_id' => $row['tool_call_id'],
            'role' => 'tool',
            'content' => $row['result']
        ];
    }
    rsse('tool_result', ['calls' => $toolCalls, 'results' => $toolResults]);
}

if ($已有 !== '') {
    // $from 是前端已显示的字符数。按字符切而不是按字节，
    // 否则中文会被切成半个字，前端拼出来是乱码。
    $全长 = mb_strlen($已有);
    $已发 = min($from, $全长);
    if ($全长 > $已发) {
        rsse('delta', ['t' => mb_substr($已有, $已发)]);
        $已发 = $全长;
    }
}
if ((string) ($run['fold'] ?? '') !== '') {
    rsse('fold', ['t' => (string) $run['fold']]);
}

// 已经结束的任务：铺完就收尾，不用轮询
if ($run['status'] !== 'running') {
    发收尾($run);
    exit;
}
if (任务已死($run)) {
    // 把这行标成 dead，免得下次进来又等一轮 90 秒。
    // 加 status='running' 条件是防覆盖：万一生成进程刚好在这一刻收尾，
    // 它写的 done 不能被我们这句改掉。
    db_exec('UPDATE chat_runs SET status = \'dead\' WHERE id = ? AND status = \'running\'',
        [(int) $run['id']]);
    // 进程被杀时半截内容只存在 chat_runs.answer 里，没来得及落进 messages。
    // 这里补存一次，用户刷新页面后历史记录里能看到已生成的部分，不会白丢。
    $半截 = trim((string) ($run['answer'] ?? ''));
    $死msgId = (int) ($run['msg_id'] ?? 0);
    if ($死msgId === 0) {
        // 有内容存内容，没内容不存假消息——前端 err 事件已告知用户
        if ($半截 !== '') {
            $fold = trim((string) ($run['fold'] ?? ''));
            if ($fold !== '') {
                $半截 = wrap_thinking($fold, $半截);
            }
            try {
                $死msgId = (int) db_insert('INSERT INTO messages (conv_id, user_id, role, content, created_at)
                     VALUES (?,?,?,?,NOW())',
                    [(int) $run['conv_id'], $me['id'], 'assistant', $半截]);
                db_exec('UPDATE chat_runs SET msg_id = ? WHERE id = ?', [$死msgId, (int) $run['id']]);
            } catch (\Throwable $e) {}
        }
    }
    rsse('err', ['msg' => '上一轮生成中断了（服务重启或超时），内容只到这里。可以重新发一次。']);
    rsse('done', ['conv_id' => (int) $run['conv_id'], 'run_id' => (int) $run['id'],
                  'msg_id' => $死msgId,
                  'dead' => 1, 'tokens_in' => 0, 'tokens_out' => 0, 'tokens_cache' => 0,
                  'tokens_cache_create' => 0,
                  'cost' => '0.000000', 'estimated' => 0, 'balance' => '']);
    exit;
}

// ---- 轮询追新 ----
// 生成进程和这个进程之间没有共享内存，只能靠数据库这个中间层传递。
// 每 700 毫秒查一次：比写增量的 1 秒稍快，保证不会积压；
// 再快就纯属空转查询，对体感没有帮助。
$轮询间隔 = 700000;   // 微秒
$上限 = 600;          // 最多盯 7 分钟（600 × 0.7 秒），够 240 秒的命令加上游生成
$心跳计数 = 0;

for ($i = 0; $i < $上限; $i++) {
    // 客户端又跑了（用户再次刷新或关页面）就退出，别让这个进程白占着 PHP-FPM 的槽位。
    // 这里和 chat.php 相反：那边要跑完才算完整，这边只是个读取者，随时可以走。
    if (connection_aborted()) {
        exit;
    }

    usleep($轮询间隔);

    $cur = db_one('SELECT * FROM chat_runs WHERE id = ? AND user_id = ? LIMIT 1',
        [(int) $run['id'], $me['id']]);
    if (!$cur) {
        exit;   // 行被清理了，没什么可推的
    }

    $正文 = (string) ($cur['answer'] ?? '');
    $长 = mb_strlen($正文);
    if ($长 > $已发) {
        rsse('delta', ['t' => mb_substr($正文, $已发)]);
        $已发 = $长;
        $心跳计数 = 0;
    }

    // fold 只会被写一次，出现了就转发，之后不再重复
    if ((string) ($cur['fold'] ?? '') !== '' && (string) ($run['fold'] ?? '') === '') {
        rsse('fold', ['t' => (string) $cur['fold']]);
        $run['fold'] = $cur['fold'];
    }

    if ($cur['status'] !== 'running') {
        发收尾($cur);
        exit;
    }

    if (任务已死($cur)) {
        db_exec('UPDATE chat_runs SET status = \'dead\' WHERE id = ? AND status = \'running\'',
            [(int) $cur['id']]);
        // 同上：进程被杀时半截内容只在 chat_runs 里，补存到 messages
        $半截2 = trim((string) ($cur['answer'] ?? ''));
        $死msgId2 = (int) ($cur['msg_id'] ?? 0);
        if ($死msgId2 === 0) {
            if ($半截2 !== '') {
                $fold2 = trim((string) ($cur['fold'] ?? ''));
                if ($fold2 !== '') {
                    $半截2 = wrap_thinking($fold2, $半截2);
                }
                try {
                    $死msgId2 = (int) db_insert('INSERT INTO messages (conv_id, user_id, role, content, created_at)
                         VALUES (?,?,?,?,NOW())',
                        [(int) $cur['conv_id'], $me['id'], 'assistant', $半截2]);
                    db_exec('UPDATE chat_runs SET msg_id = ? WHERE id = ?', [$死msgId2, (int) $cur['id']]);
                } catch (\Throwable $e) {}
            }
        }
        rsse('err', ['msg' => '生成中断了（服务重启或超时），内容只到这里。']);
        rsse('done', ['conv_id' => (int) $cur['conv_id'], 'run_id' => (int) $cur['id'],
                      'msg_id' => $死msgId2,
                      'dead' => 1, 'tokens_in' => 0, 'tokens_out' => 0, 'tokens_cache' => 0,
                      'tokens_cache_create' => 0,
                      'cost' => '0.000000', 'estimated' => 0, 'balance' => '']);
        exit;
    }

    // 长时间没有新内容时发个注释行做心跳。
    // 不发的话中间的反代可能认为连接空闲而掐掉，前端就会莫名断流。
    if (++$心跳计数 >= 20) {   // 约 14 秒
        $心跳计数 = 0;
        echo ": beat\n\n";
        flush();
    }
}

// 盯到上限还没结束：让前端自己再连一次，而不是无限占着这个进程
rsse('idle', ['run_id' => (int) $run['id'], 'from' => $已发]);
exit;
