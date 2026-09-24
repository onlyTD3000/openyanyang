<?php
/**
 * 岩羊AI 开放 API 网关（v1）
 *
 * 兼容 OpenAI 与 Anthropic 两套协议，供第三方客户端（Trae / Codex / Cursor / NewAPI 等）对接。
 * 认证：Authorization: Bearer <个人资料页生成的 API Key>
 *
 * 端点：
 *   POST /v1/chat/completions   OpenAI 兼容对话补全
 *   POST /v1/messages           Anthropic 兼容对话补全
 *   GET  /v1/models             OpenAI 兼容模型列表
 *   GET  /v1/me                 校验 Key 并返回用户信息
 *
 * 特性：
 *   - 纯转发：不改写客户端提交的 messages / tools / system，不做工具执行循环
 *   - 直连上游：按后台配置的渠道与协议把请求转发给上游模型服务
 *   - 流式与非流式都支持
 *   - 按上游 usage 计费，扣用户余额并记录 usage_logs
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/upstream.php';
require_once __DIR__ . '/../inc/concurrency.php';

// 防止 PHP 默认的输出缓冲干扰 SSE
// output_buffering 是 PHP_INI_PERDIR，ini_set 改不了，只能显式关掉所有缓冲层
while (ob_get_level()) ob_end_clean();
@set_time_limit(0);
ignore_user_abort(false);

// 缓存请求体：PHP 8.5 中 php://input 只能读一次，后续统一用这个变量
$GLOBALS['__v1_raw_input'] = file_get_contents('php://input');



/** 统一错误出口：输出 OpenAI 兼容的 error 结构 */
function v1_err(int $code, string $msg, string $type = 'invalid_request_error'): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'message' => $msg,
            'type'    => $type,
            'code'    => $code,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** SSE data-only 帧（OpenAI 流式格式） */
function v1_sse_data(array $data): void
{
    // 先检查连接状态，避免向已断开的连接写入
    if (connection_aborted()) {
        throw new \RuntimeException('客户端连接已断开');
    }
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_length() !== false) {
        ob_flush();
    }
    flush();
    // 再次检查，确保数据发送后连接仍然有效
    if (connection_status() !== CONNECTION_NORMAL) {
        throw new \RuntimeException('客户端连接异常');
    }
}

/** SSE event + data 帧（Anthropic / Responses API 流式格式） */
function v1_sse_anthropic(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_length() !== false) {
        ob_flush();
    }
    flush();
}

/** SSE event + data 帧（别名，语义更通用） */
function v1_sse_event(string $event, array $data): void
{
    v1_sse_anthropic($event, $data);
}

/** 余额/配额校验（基于 SK 卡密余额） */
function v1_check_balance(array $me): void
{
    $card = $me['_sk_card'] ?? null;
    if (!$card) {
        v1_err(403, '卡密信息缺失。', 'authentication_error');
    }
    if ((float) $card['balance'] <= 0) {
        v1_err(402, '卡密余额不足，请充值或更换卡密。', 'insufficient_quota');
    }
    // 检查过期
    if (!empty($card['expires_at']) && strtotime((string) $card['expires_at']) < time()) {
        v1_err(402, '卡密已过期。', 'insufficient_quota');
    }
}

/**
 * 按模型名找模型 + 渠道。
 * 支持 model_name 与 display_name 两种匹配，只取启用状态。
 * $bindIdx 用于 SK 粘性：传「会话指纹」——同一会话固定用同一个上游 SK（prompt 缓存连续命中）。
 * $skipSk=true 时只继承父分类字段、不选 SK，由调用方在拿到渠道 ID 后再单独选 SK。
 */
function v1_find_model(string $model, ?int $bindIdx = null, bool $skipSk = false): array
{
    $m = db_one(
        'SELECT m.*, c.id AS ch_id, c.parent_id, c.name AS ch_name,
                c.base_url, c.api_key, c.protocol, c.chat_path, c.api_version, c.timeout, c.status AS ch_status,
                c.`rotate`, c.rotate_idx, c.tool_choice_downgrade
           FROM models m JOIN channels c ON c.id = m.channel_id
          WHERE (m.model_name = ? OR m.display_name = ?) AND m.status = 1 AND c.status = 1
          ORDER BY m.id LIMIT 1',
        [$model, $model]
    );
    if (!$m) {
        v1_err(400, '模型不存在或已停用：' . $model, 'model_not_found');
    }
    $ch = [
        'id'                    => (int) $m['ch_id'],
        'parent_id'             => (int) ($m['parent_id'] ?? 0),
        'name'                  => $m['ch_name'] ?? '',
        'base_url'              => $m['base_url'] ?? '',
        'api_key'               => $m['api_key'] ?? '',
        'protocol'              => $m['protocol'] ?? 'openai',
        'chat_path'             => $m['chat_path'] ?? '/chat/completions',
        'api_version'           => $m['api_version'] ?? '2023-06-01',
        'timeout'               => (int) ($m['timeout'] ?? 60),
        'status'                => (int) ($m['ch_status'] ?? 0),
        'rotate'                => (int) ($m['rotate'] ?? 0),
        'rotate_idx'            => (int) ($m['rotate_idx'] ?? 0),
        'tool_choice_downgrade' => (int) ($m['tool_choice_downgrade'] ?? 0),
    ];
    // 继承父分类字段；$skipSk=true 时暂不选 SK
    $merged = channel_merge_platform($ch, null, $bindIdx, $skipSk);
    return [$m, $merged];
}

/**
 * 把 content（字符串 / 文本块数组 / 多模态块数组）拍平成纯文本。
 * 兼容 OpenAI 的 string content 与 Anthropic 的 [{type:"text",text:...}] 块。
 */
function v1_text_from_content($content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }
    $out = [];
    foreach ($content as $block) {
        if (is_string($block)) {
            $out[] = $block;
            continue;
        }
        if (!is_array($block)) {
            continue;
        }
        $t = (string) ($block['type'] ?? '');
        if (in_array($t, ['text', 'input_text', 'output_text'], true) && isset($block['text'])) {
            $out[] = (string) $block['text'];
        }
    }
    return implode("\n", $out);
}

/**
 * 从请求体提取「会话指纹源」字符串（system + 首条 user 消息）。
 * 兼容 OpenAI（messages）、Anthropic（system 顶层 + messages 块）、Responses（instructions + input）三种入参。
 * 返回 null 表示提取不到稳定前缀，调用方应退化为轮询选 SK。
 */
function v1_session_fingerprint_source(array $req): ?string
{
    $system = '';
    $firstUser = '';

    // Anthropic 顶层 system；Responses 的 instructions 也当 system 用
    if (array_key_exists('system', $req)) {
        $system = v1_text_from_content($req['system']);
    }
    if ($system === '' && isset($req['instructions']) && is_string($req['instructions'])) {
        $system = $req['instructions'];
    }

    $msgs = $req['messages'] ?? $req['input'] ?? null;
    if (is_array($msgs)) {
        foreach ($msgs as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = strtolower((string) ($m['role'] ?? ''));
            if ($role === 'system' && $system === '') {
                $system = v1_text_from_content($m['content'] ?? '');
            } elseif ($role === 'user' && $firstUser === '') {
                $firstUser = v1_text_from_content($m['content'] ?? '');
            }
            if ($system !== '' && $firstUser !== '') {
                break;
            }
        }
    }

    $src = trim($system . "\n" . $firstUser);
    return $src === '' ? null : $src;
}

/**
 * 会话指纹 → 稳定的非负整数（作为 channel_pick_sk 的 bindIdx）。
 * 同一会话（system + 首条 user 不变）指纹恒定 → 固定同一 SK；不同会话指纹不同 → 分散。
 * 返回 null 表示无稳定前缀，退化轮询。
 */
function v1_session_fingerprint(array $req): ?int
{
    $src = v1_session_fingerprint_source($req);
    if ($src === null) {
        return null;
    }
    // md5 前 8 字节 → 32 位无符号整数，避免 crc32 有符号负值
    return (int) hexdec(substr(md5($src), 0, 8));
}

/**
 * 解析模型 + 选 SK（带会话持久绑定 + 负载均衡）。
 *
 * 新会话不再用「指纹散列固定一个 SK」，而是走 channel_pick_sk 的负载均衡：
 * 选「当前承载对话/会话数最少」且没熔断的 SK，把请求摊开，避免盯着一个 SK 打到 429。
 * 同一会话（指纹相同）选中后记入 session_sk_bind，后续请求复用同一个 SK，
 * 保证 prompt 缓存能连续命中；绑定的 SK 熔断换线后也把借来的 SK 写回，不再每次撞坏 SK。
 */
function v1_resolve_model(string $model, array $req): array
{
    $fp = v1_session_fingerprint($req);
    // 先只解析模型 + 渠道（继承父分类字段，不选 SK），拿到渠道 ID 后才知道往哪查绑定。
    [$m, $ch] = v1_find_model($model, null, true);
    $chId = (int) ($ch['id'] ?? 0);

    // 同会话：优先复用已绑定的 SK（保缓存）；没绑定的新会话 bindIdx 留空，走负载均衡。
    $bindIdx = null;
    if ($fp !== null) {
        $saved = channel_session_sk_get($fp, $chId);
        if ($saved !== null) {
            $bindIdx = $saved;
        }
    }

    // 选 SK：有绑定走绑定分支，新会话走负载均衡分支。
    $ch = channel_pick_sk($ch, $bindIdx);

    // 新会话：负载均衡选中后记入绑定，后续同会话复用，不再每次重新散列。
    if ($fp !== null && $bindIdx === null && isset($ch['_sk_index'])) {
        channel_session_sk_set($fp, (int) $ch['_sk_index'], $chId);
    }
    // 绑定 SK 熔断临时借了健康 SK：也记住借来的，避免下次又撞回坏 SK。
    if ($fp !== null && !empty($ch['_sk_borrow']) && isset($ch['_sk_index'])) {
        channel_session_sk_set($fp, (int) $ch['_sk_index'], $chId);
    }
    return [$m, $ch, $fp];
}

/**
 * v1 网关专用：按上游结果回写 SK 健康度（记录本次会话指纹命中的那个 SK）。
 * 上游返回「换 SK 有意义」的故障（401/402/403/429/5xx）才记失败，普通 400 参数错误不记。
 * 注意：upstream_stream 内部在重试时会临时换 SK，这里记的是会话指纹命中的 SK。
 */
function v1_sk_health(array $ch, array $res): void
{
    if (empty($ch['id']) || !isset($ch['_sk_index'])) {
        return;
    }
    $badHttp = (int) ($res['http'] ?? 0);
    if (!$res['ok'] && $badHttp >= 400 && in_array($badHttp, upstream_sk_switchable_codes(), true)) {
        channel_sk_fail((int) $ch['id'], (int) $ch['_sk_index'], $badHttp, (string) ($res['error'] ?? ''));
    } elseif ($res['ok']) {
        channel_sk_ok((int) $ch['id'], (int) $ch['_sk_index']);
    }
}

/**
 * 校验卡密是否允许调用该模型。
 * 卡密的 allowed_models 存的是 display_name 逗号分隔列表，为空表示不限制。
 */
function v1_check_model_limit(array $me, array $m): void
{
    $card = $me['_sk_card'] ?? null;
    if (!$card || empty($card['allowed_models'])) {
        return;
    }
    $allowed = array_filter(array_map('trim', explode(',', (string) $card['allowed_models'])));
    $name = (string) ($m['display_name'] ?? '');
    if (!in_array($name, $allowed, true)) {
        v1_err(403, '当前卡密不允许调用模型：' . $name, 'model_not_allowed');
    }
}

/** 归一化 usage → OpenAI usage 结构 */
function v1_usage_openai(?array $u): array
{
    $tin  = (int) ($u['prompt_tokens'] ?? 0);
    $tout = (int) ($u['completion_tokens'] ?? 0);
    return [
        'prompt_tokens'     => $tin,
        'completion_tokens' => $tout,
        'total_tokens'      => $tin + $tout,
        'prompt_tokens_details' => [
            'cached_tokens' => (int) ($u['cached_tokens'] ?? 0),
        ],
    ];
}

/** 归一化 usage → Anthropic usage 结构 */
function v1_usage_anthropic(?array $u): array
{
    return [
        'input_tokens'               => (int) ($u['prompt_tokens'] ?? 0),
        'output_tokens'              => (int) ($u['completion_tokens'] ?? 0),
        'cache_creation_input_tokens' => (int) ($u['cache_create_tokens'] ?? 0),
        'cache_read_input_tokens'    => (int) ($u['cached_tokens'] ?? 0),
    ];
}

/** 计费：扣余额、写账单、写 usage_logs */
function v1_billing(array $me, array $m, array $ch, array $usage, string $clientType, array $res, ?int $ttft, float $t0): void
{
    $tin  = (int) ($usage['prompt_tokens'] ?? 0);
    $tout = (int) ($usage['completion_tokens'] ?? 0);
    $tcache        = (int) ($usage['cached_tokens'] ?? 0);
    $tcache_create = (int) ($usage['cache_create_tokens'] ?? 0);
    if ($tin + $tout <= 0) {
        return;
    }
    $cost = calc_cost($tin, $tout, $m['price_in'], $m['price_out'], $tcache,
        $m['price_cache'] ?? 0, $tcache_create, $m['price_cache_create'] ?? 0);
    $latency = (int) round((microtime(true) - $t0) * 1000);

    $cardId = (int) ($me['_sk_card']['id'] ?? 0);
    db_exec('UPDATE sk_cards SET balance = balance - ?, total_cost = total_cost + ? WHERE id = ?',
        [$cost, $cost, $cardId]);

    if ($cost > 0) {
        $余额后 = (float) db_val('SELECT balance FROM sk_cards WHERE id = ?', [$cardId]);
        db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, created_at)
                 VALUES (?,?,?,?,?,NOW())',
            [$me['id'], -$cost, $余额后, 'consume', $m['model_name'] . '，输入 ' . $tin . ' / 输出 ' . $tout . ' token']);
    }

    $logStatus = $res['ok'] ? 'ok' : 'error';
    $logError  = $res['ok'] ? '' : mb_substr((string) ($res['error'] ?? ''), 0, 480);
    db_exec('INSERT INTO usage_logs (user_id, conv_id, channel_id, model_name, tokens_in, tokens_out,
                    tokens_cache, tokens_cache_create, cost, is_estimated, status, error_msg, latency_ms, ttft_ms, ip, client_type, sk_card_id, created_at)
             VALUES (?,0,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,NOW())',
        [$me['id'], (int) $ch['id'], $m['model_name'], $tin, $tout, $tcache, $tcache_create, $cost,
         $logStatus, $logError, $latency, $ttft ?? 0, client_ip(), $clientType, $cardId]);
}

/**
 * Anthropic 入参 → OpenAI 中间格式。
 * 网关内部统一用 OpenAI 结构，再由 upstream_stream 按渠道协议转发。
 */
function v1_anthropic_to_openai(array $req): array
{
    $out = [
        'model'    => (string) ($req['model'] ?? ''),
        'messages' => [],
    ];

    // system（字符串或块数组）→ 首条 system 消息
    $system = $req['system'] ?? '';
    if (is_array($system)) {
        $texts = [];
        foreach ($system as $block) {
            if (($block['type'] ?? '') === 'text' && trim((string) ($block['text'] ?? '')) !== '') {
                $texts[] = (string) $block['text'];
            }
        }
        $system = implode("\n", $texts);
    }
    if (is_string($system) && trim($system) !== '') {
        $out['messages'][] = ['role' => 'system', 'content' => $system];
    }

    foreach (($req['messages'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role    = (string) ($m['role'] ?? 'user');
        $content = $m['content'] ?? '';

        if (is_string($content)) {
            $out['messages'][] = ['role' => $role, 'content' => $content];
            continue;
        }
        if (!is_array($content)) {
            continue;
        }

        // 块数组：text / image / tool_use / tool_result
        $texts      = [];
        $images     = [];
        $toolCalls  = [];
        $toolResult = null;
        foreach ($content as $block) {
            $bt = $block['type'] ?? '';
            if ($bt === 'text') {
                $t = (string) ($block['text'] ?? '');
                if ($t !== '') {
                    $texts[] = $t;
                }
            } elseif ($bt === 'image') {
                $src = $block['source'] ?? [];
                if (($src['type'] ?? '') === 'base64' && !empty($src['data'])) {
                    $images[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:' . ($src['media_type'] ?? 'image/png') . ';base64,' . $src['data'],
                        ],
                    ];
                }
            } elseif ($bt === 'tool_use') {
                $toolCalls[] = [
                    'id'       => (string) ($block['id'] ?? ''),
                    'type'     => 'function',
                    'function' => [
                        'name'      => (string) ($block['name'] ?? ''),
                        'arguments' => json_encode($block['input'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
                    ],
                ];
            } elseif ($bt === 'tool_result') {
                $toolResult = [
                    'tool_call_id' => (string) ($block['tool_use_id'] ?? ''),
                    'content'      => is_array($block['content'] ?? null)
                        ? json_encode($block['content'], JSON_UNESCAPED_UNICODE)
                        : (string) ($block['content'] ?? ''),
                ];
            }
        }

        if ($role === 'user' && $toolResult !== null) {
            $out['messages'][] = [
                'role'         => 'tool',
                'tool_call_id' => $toolResult['tool_call_id'],
                'content'      => $toolResult['content'],
            ];
        } elseif ($role === 'assistant') {
            $msg = ['role' => 'assistant', 'content' => implode("\n", $texts)];
            if ($toolCalls) {
                $msg['tool_calls'] = $toolCalls;
            }
            $out['messages'][] = $msg;
        } else {
            $parts = [];
            foreach ($texts as $t) {
                $parts[] = ['type' => 'text', 'text' => $t];
            }
            foreach ($images as $im) {
                $parts[] = $im;
            }
            $out['messages'][] = ['role' => $role, 'content' => $parts ?: ''];
        }
    }

    // 透传参数
    foreach (['max_tokens', 'temperature', 'top_p', 'top_k', 'stop_sequences', 'stream', 'metadata'] as $k) {
        if (isset($req[$k])) {
            $out[$k] = $req[$k];
        }
    }
    if (isset($req['stop_sequences'])) {
        $out['stop'] = $req['stop_sequences'];
    }

    // tools：Anthropic 格式 {name, description, input_schema} → OpenAI 格式
    if (!empty($req['tools'])) {
        $out['tools'] = [];
        foreach ($req['tools'] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $out['tools'][] = [
                'type' => 'function',
                'function' => [
                    'name'        => (string) ($t['name'] ?? ''),
                    'description' => (string) ($t['description'] ?? ''),
                    'parameters'  => $t['input_schema'] ?? new stdClass(),
                ],
            ];
        }
    }

    // Anthropic thinking → OpenAI reasoning_effort（供 OpenAI 协议上游使用）。
    // thinking 字段本身也保留在 $out 里：走 Claude 协议上游时由
    // upstream_to_claude 原样透传；OpenAI 上游由 upstream_stream 剔除，
    // 只认转换后的 reasoning_effort（原样转发 thinking 会被上游拒收 400）。
    if (isset($req['thinking'])) {
        $out['thinking'] = $req['thinking'];
        $effort = v1_thinking_to_effort($req['thinking']);
        if ($effort !== null) {
            $out['reasoning_effort'] = $effort;
        }
    }
    return $out;
}

/**
 * Anthropic thinking 参数 → OpenAI reasoning_effort。
 *
 * Anthropic 客户端传的是 {type:"enabled", budget_tokens:N}，
 * OpenAI 协议的标准参数是 reasoning_effort: low|medium|high。
 * 返回 null 表示不需要开启思考（disabled 或参数无效），调用方应剔除。
 */
function v1_thinking_to_effort($thinking): ?string
{
    if (!is_array($thinking)) {
        return null;
    }
    if (($thinking['type'] ?? '') !== 'enabled') {
        return null;
    }
    // 预算越大思考越深。没给预算时按中等强度兜底（enabled 就是要思考）。
    $budget = (int) ($thinking['budget_tokens'] ?? 0);
    if ($budget >= 10000) {
        return 'high';
    }
    if ($budget >= 3000) {
        return 'medium';
    }
    if ($budget > 0) {
        return 'low';
    }
    return 'medium';
}

/** 读请求体 JSON */
function v1_read_body(): array
{
    $raw = $GLOBALS['__v1_raw_input'] ?? file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        error_log('[v1] 读取请求体失败：内容为空或 false，Content-Length: ' . ($_SERVER['CONTENT_LENGTH'] ?? 'unknown'));
        v1_err(400, '请求体为空。', 'invalid_request_error');
    }
    error_log('[v1] 读取到请求体，长度: ' . strlen($raw) . ' 字节');
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        error_log('[v1] JSON 解析失败：' . json_last_error_msg() . '，原始内容前 200 字节: ' . substr($raw, 0, 200));
        v1_err(400, '请求体不是合法 JSON。', 'invalid_request_error');
    }
    return $req;
}

/**
 * 判断 /v1/messages 请求该走 Anthropic 还是 OpenAI 输出。
 *
 * 不能只看 anthropic-version 请求头：部分 OpenAI 兼容客户端请求 /v1/messages 时
 * 也会带上该头，导致客户端收到 Anthropic 流（message_start）却按 OpenAI schema
 * 校验而报错。这里优先按请求体结构判断：
 *   - 有 stop_sequences / metadata / system / tool_use / tool_result / image 块 → Anthropic
 *   - content 全是字符串且无 Anthropic 必填的 max_tokens → OpenAI
 *   - 其余情况（content 为块数组）再用请求头兜底
 */
function v1_is_anthropic_request(): bool
{
    $raw = $GLOBALS['__v1_raw_input'] ?? file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        return false;
    }
    // Anthropic 特有顶层字段
    if (array_key_exists('stop_sequences', $req) || array_key_exists('metadata', $req)) {
        return true;
    }
    if (array_key_exists('system', $req) && !empty($req['system'])) {
        return true;
    }
    $allContentString  = true;
    $hasAnthropicBlock = false;
    $hasOpenAIImage    = false;
    foreach (($req['messages'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $c = $m['content'] ?? null;
        if (is_string($c)) {
            continue;
        }
        if (is_array($c)) {
            $allContentString = false;
            foreach ($c as $b) {
                $t = (string) ($b['type'] ?? '');
                if ($t === 'tool_use' || $t === 'tool_result' || $t === 'image') {
                    $hasAnthropicBlock = true;
                }
                if ($t === 'image_url') {
                    $hasOpenAIImage = true;
                }
            }
        } else {
            $allContentString = false;
        }
    }
    if ($hasAnthropicBlock) {
        return true;
    }
    if ($hasOpenAIImage) {
        return false;
    }
    $hasMaxTokens       = array_key_exists('max_tokens', $req) && (int) $req['max_tokens'] > 0;
    $hasAnthropicHeader = stripos((string) ($_SERVER['HTTP_ANTHROPIC_VERSION'] ?? ''), '2023') !== false;
    if ($allContentString) {
        // 纯文本 content：只有同时带头 + 带 max_tokens 才认作 Anthropic
        return $hasAnthropicHeader && $hasMaxTokens;
    }
    // content 为块数组（非 Anthropic 特有块）：靠请求头兜底
    return $hasAnthropicHeader;
}

/**
 * 判断请求是否为 OpenAI Responses API 格式。
 * Responses API 用 input 代替 messages，且可能带有 instructions 字段。
 */
function v1_is_responses_request(): bool
{
    $raw = $GLOBALS['__v1_raw_input'] ?? '';
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        return false;
    }
    // 有 input 字段且没有 messages 字段 → Responses API
    if (array_key_exists('input', $req) && !array_key_exists('messages', $req)) {
        return true;
    }
    return false;
}

// ==================== 端点实现 ====================

/** POST /v1/chat/completions（OpenAI 兼容） */
function v1_chat_completions(array $me): void
{
    $requestId = bin2hex(random_bytes(4));
    error_log("[v1:{$requestId}] 开始处理 chat/completions 请求，用户 ID: " . ($me['id'] ?? 'unknown'));
    
    $req   = v1_read_body();
    $model = (string) ($req['model'] ?? '');
    if ($model === '') {
        error_log("[v1:{$requestId}] 缺少 model 参数");
        v1_err(400, '缺少必填参数：model。', 'invalid_request_error');
    }
    error_log("[v1:{$requestId}] 开始解析模型: {$model}");
    [$m, $ch] = v1_resolve_model($model, $req);
    error_log("[v1:{$requestId}] 模型解析完成，渠道 ID: " . ($ch['id'] ?? 'unknown'));
    v1_check_model_limit($me, $m);
    v1_check_balance($me);

    // 并发控制：检查每分钟限流
    $concurrency_rpm   = (int) setting_get('concurrency_rpm', 0);
    $concurrency_mode  = setting_get('concurrency_mode', 'sk');
    if ($concurrency_rpm > 0) {
        $rateResult = concurrency_rate_limit(
            $concurrency_mode,
            $concurrency_rpm,
            (int) ($ch['id'] ?? 0),
            (int) ($ch['_sk_index'] ?? 0),
            (int) ($me['id'] ?? 0)
        );
        if (!$rateResult['ok']) {
            v1_err(429, "每分钟请求数已达上限（{$rateResult['current']}/{$concurrency_rpm}），请稍后重试（建议等待 {$rateResult['wait']} 秒）", 'rate_limit_exceeded');
        }
    }

    // 并发控制：尝试获取槽位
    $concurrency_limit = (int) setting_get('concurrency_limit', 0);
    $concurrency_mode  = setting_get('concurrency_mode', 'sk');
    $concurrency_token = null;
    if ($concurrency_limit > 0) {
        $result = concurrency_acquire(
            $concurrency_mode,
            $concurrency_limit,
            (int) ($ch['id'] ?? 0),
            (int) ($ch['_sk_index'] ?? 0),
            (int) ($me['id'] ?? 0)
        );
        if (!$result['ok']) {
            v1_err(429, "并发请求已达上限，请稍后重试（建议等待 {$result['wait']} 秒）", 'rate_limit_exceeded');
        }
        $concurrency_token = $result['token'];
    }

    $stream = !empty($req['stream']);
    error_log("[v1:{$requestId}] 模型: {$model}, 流式: " . ($stream ? 'true' : 'false') . ", 渠道 ID: " . ($ch['id'] ?? 'unknown'));
    
    // 纯转发：去掉 stream 字段交给 upstream_stream 统一管理，其余原样保留
    $payload = $req;
    unset($payload['stream']);
    // 兼容 Anthropic 风格的 thinking 参数：转成 OpenAI 标准的 reasoning_effort。
    // OpenAI 上游不认 thinking 字段，原样转发会被拒收（HTTP 400：未知请求字段）。
    if (isset($payload['thinking'])) {
        $effort = v1_thinking_to_effort($payload['thinking']);
        unset($payload['thinking']);
        if ($effort !== null) {
            $payload['reasoning_effort'] = $effort;
        }
    }
    // 丢弃 ZCode 等客户端特有的非 OpenAI 标准字段，避免上游严格校验模型返回 400。
    // 只保留 OpenAI 标准参数。
    $__openaiStandard = ['model','messages','max_tokens','max_completion_tokens','max_output_tokens','temperature','top_p','stream','stop','tools','tool_choice','reasoning_effort','stream_options','presence_penalty','frequency_penalty','logit_bias','user','response_format','seed','n','logprobs','top_logprobs','modalities','audio'];
    foreach (array_keys($payload) as $__k) {
        if (!in_array($__k, $__openaiStandard, true)) {
            unset($payload[$__k]);
        }
    }
    // 清理消息里的非标准字段（cache_control 等 Anthropic 特有块）
    foreach ($payload['messages'] as &$__msg) {
        if (is_array($__msg)) {
            foreach (array_keys($__msg) as $__mk) {
                if (!in_array($__mk, ['role','content','tool_calls','tool_call_id','name','reasoning_content'], true)) {
                    unset($__msg[$__mk]);
                }
            }
            // 补缺失/为 null 的 content：部分客户端发带 tool_calls 的 assistant 消息时 content 省略或为 null
            // （符合 OpenAI 规范），但上游 Rust 严格校验 content 必填，原样转发会报
            // 400：messages[N]: missing field `content`。这里统一补空字符串，保证字段存在。
            if (!array_key_exists('content', $__msg) || $__msg['content'] === null) {
                $__msg['content'] = '';
            }
            if (is_array($__msg['content'] ?? null)) {
                foreach ($__msg['content'] as $__bi => &$__block) {
                    if (!is_array($__block)) continue;
                    $__bt = $__block['type'] ?? '';
                    if (!in_array($__bt, ['text','image_url'], true)) {
                        unset($__msg['content'][$__bi]);
                        continue;
                    }
                    foreach (array_keys($__block) as $__bk) {
                        if (!in_array($__bk, ['type','text','image_url'], true)) {
                            unset($__block[$__bk]);
                        }
                    }
                }
                unset($__block);
                $__msg['content'] = array_values($__msg['content']);
            }
            if (is_array($__msg['tool_calls'] ?? null)) {
                foreach ($__msg['tool_calls'] as &$__tc) {
                    foreach (array_keys($__tc) as $__tck) {
                        if (!in_array($__tck, ['id','type','function'], true)) {
                            unset($__tc[$__tck]);
                        }
                    }
                }
                unset($__tc);
            }
        }
    }
    unset($__msg);
    // 检查模型 vision 能力：请求含图片但模型不支持 vision 时，直接返回明确错误。
    // 否则图片会被原样转发给上游，上游报 400「不支持 vision」并重试 10 次（约 1.5 分钟），
    // 客户端长时间收不到任何正文，表现为「不回复」。
    $__hasImage = false;
    foreach (($payload['messages'] ?? []) as $__m) {
        if (!is_array($__m)) continue;
        $__c = $__m['content'] ?? null;
        if (is_array($__c)) {
            foreach ($__c as $__b) {
                if (is_array($__b) && ($__b['type'] ?? '') === 'image_url') {
                    $__hasImage = true;
                    break 2;
                }
            }
        }
    }
    if ($__hasImage && (int) ($m['vision'] ?? 0) !== 1) {
        error_log("[v1:{$requestId}] 请求含图片但模型 {$model} 不支持 vision，直接拒绝");
        v1_err(400, "模型 {$model} 不支持图片输入（vision），请改用支持图片的模型。", 'model_not_support_vision');
    }
    // 修复 tools 的 JSON Schema：ZCode 会把「无参数工具」的 properties 序列化成空数组 []，
    // 违反 JSON Schema 规范（properties 必须是对象）。严格校验的模型（如 deepseek-v4-flash-0731）
    // 会因此报 MODEL_TOOL_NOT_SUPPORTED。递归把 properties:[] → {}、additionalProperties:[] → false。
    if (isset($payload['tools']) && is_array($payload['tools'])) {
        $__fixSchema = function (&$node) use (&$__fixSchema) {
            if (!is_array($node)) return;
            foreach ($node as &$__v) {
                if (is_array($__v)) {
                    $__fixSchema($__v);
                }
            }
            unset($__v);
            if (array_key_exists('properties', $node) && is_array($node['properties']) && $node['properties'] === []) {
                $node['properties'] = (object) [];
            }
            if (array_key_exists('additionalProperties', $node) && is_array($node['additionalProperties']) && $node['additionalProperties'] === []) {
                $node['additionalProperties'] = false;
            }
        };
        foreach ($payload['tools'] as &$__tool) {
            if (isset($__tool['function']['parameters']) && is_array($__tool['function']['parameters'])) {
                $__fixSchema($__tool['function']['parameters']);
            }
        }
        unset($__tool);
    }
    // Anthropic 格式的 tool_choice（对象形式 {"type":"auto"/"any"/"tool"}）转成 OpenAI 格式
    if (isset($payload['tool_choice']) && is_array($payload['tool_choice'])) {
        $tcType = (string) ($payload['tool_choice']['type'] ?? '');
        if ($tcType === 'auto') {
            $payload['tool_choice'] = 'auto';
        } elseif ($tcType === 'any') {
            $payload['tool_choice'] = 'required';
        } elseif ($tcType === 'tool' && !empty($payload['tool_choice']['name'])) {
            $payload['tool_choice'] = ['type' => 'function', 'function' => ['name' => (string) $payload['tool_choice']['name']]];
        }
    }
    // 应用通道配置：强制工具调用降级（required→auto），避免不支持的上游返回 400
    $payload = upstream_downgrade_tool_choice($ch, $payload);

    $answer = '';
    $think  = '';
    $usage  = null;
    $t0     = microtime(true);
    $ttft   = null;
    $chatId = 'chatcmpl-' . bin2hex(random_bytes(8));
    $created = time();

    // 思考通道：上游按字段明确给出的思考内容（reasoning_content / thinking）
    // 单独走这里，用 OpenAI 标准的 reasoning_content 输出，绝不混进正文 delta。
    // 这样客户端能正确折叠显示思考，回传历史时思考也不会被当成正文，上下文不乱。
    $onThink = function (string $delta) use (&$think, &$ttft, $t0, $stream, $chatId, $created, $model) {
        if ($ttft === null) {
            $ttft = (int) round((microtime(true) - $t0) * 1000);
        }
        $think .= $delta;
        if ($stream) {
            // 检查客户端连接，已断开则停止输出
            if (connection_aborted()) {
                return false;  // 通知 upstream_stream 停止
            }
            try {
                v1_sse_data([
                    'id'      => $chatId,
                    'object'  => 'chat.completion.chunk',
                    'created' => $created,
                    'model'   => $model,
                    'choices' => [[
                        'index'         => 0,
                        'delta'         => ['reasoning_content' => $delta],
                        'finish_reason' => null,
                    ]],
                ]);
            } catch (Throwable $e) {
                error_log('[v1] onThink 输出失败: ' . $e->getMessage());
                return false;
            }
        }
    };

    $onDelta = function (string $delta) use (&$answer, &$ttft, $t0, $stream, $chatId, $created, $model) {
        if ($ttft === null) {
            $ttft = (int) round((microtime(true) - $t0) * 1000);
        }
        $answer .= $delta;
        if ($stream) {
            // 检查客户端连接，已断开则停止输出
            if (connection_aborted()) {
                return false;  // 通知 upstream_stream 停止
            }
            try {
                v1_sse_data([
                    'id'      => $chatId,
                    'object'  => 'chat.completion.chunk',
                    'created' => $created,
                    'model'   => $model,
                    'choices' => [[
                        'index'         => 0,
                        'delta'         => ['content' => $delta],
                        'finish_reason' => null,
                    ]],
                ]);
            } catch (Throwable $e) {
                error_log('[v1] onDelta 输出失败: ' . $e->getMessage());
                return false;
            }
        }
    };
    $onUsage = function (array $u) use (&$usage) {
        $usage = array_merge((array) $usage, $u);
    };
    // 工具调用回调：上游返回 tool_calls 后以 OpenAI 流式 delta 格式转发给客户端
    $onToolCall = function (array $calls) use ($stream, $chatId, $created, $model) {
        if (!$stream) return;
        foreach ($calls as $tc) {
            v1_sse_data([
                'id'      => $chatId,
                'object'  => 'chat.completion.chunk',
                'created' => $created,
                'model'   => $model,
                'choices' => [[
                    'index'         => 0,
                    'delta'         => ['tool_calls' => [$tc]],
                    'finish_reason' => null,
                ]],
            ]);
        }
    };

    if ($stream) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        
        error_log("[v1:{$requestId}] 开始流式响应");
        
        // 角色块
        v1_sse_data([
            'id'      => $chatId,
            'object'  => 'chat.completion.chunk',
            'created' => $created,
            'model'   => $model,
            'choices' => [[
                'index'         => 0,
                'delta'         => ['role' => 'assistant', 'content' => ''],
                'finish_reason' => null,
            ]],
        ]);
    }

    try {
        error_log("[v1:{$requestId}] 开始调用上游");
        $res = upstream_stream($ch, $payload, $onDelta, $onUsage, null, $onThink, $onToolCall);
        error_log("[v1:{$requestId}] 上游调用完成，结果: " . ($res['ok'] ? 'success' : 'failed') . ", 尝试次数: " . ($res['attempts'] ?? 0));
        try { v1_sk_health($ch, $res); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        error_log("[v1:{$requestId}] 上游调用异常: " . $e->getMessage());
        concurrency_release($concurrency_token);
        throw $e;
    } finally {
        // 释放并发槽位
        concurrency_release($concurrency_token);
    }

    if ($stream) {
        // 流式结束：必须发送 finish_reason 和 [DONE]，否则客户端报 Provider finish_reason error
        // 检查连接状态，防止客户端已断开时继续写入导致 PHP 错误
        if (connection_aborted()) {
            error_log("[v1:{$requestId}] 客户端已断开连接，跳过 finish_reason 发送");
        } else {
            try {
                error_log("[v1:{$requestId}] 发送结束块");
                // 上游失败且没吐任何正文：补一个错误 delta，让客户端能显示错误信息，
                // 而不是只拿到 finish_reason=error 却无 content，被误报 empty_model_response。
                if (!$res['ok'] && $answer === '' && $think === '') {
                    v1_sse_data([
                        'id'      => $chatId,
                        'object'  => 'chat.completion.chunk',
                        'created' => $created,
                        'model'   => $model,
                        'choices' => [[
                            'index'         => 0,
                            'delta'         => ['content' => '（上游请求失败：' . (string) ($res['error'] ?? 'unknown') . '）'],
                            'finish_reason' => null,
                        ]],
                    ]);
                }
                // 结束块：上游失败、或 HTTP 200 但没有任何正文（空响应）都算 error，
                // 否则客户端会拿到 finish_reason=stop 却无内容，报 provider returned an empty response。
                v1_sse_data([
                    'id'      => $chatId,
                    'object'  => 'chat.completion.chunk',
                    'created' => $created,
                    'model'   => $model,
                    'choices' => [[
                        'index'         => 0,
                        'delta'         => new \stdClass(),
                        'finish_reason' => ($res['ok'] && ($answer !== '' || $think !== '')) ? 'stop' : 'error',
                    ]],
                ]);
                // usage 块（上游给了才发）
                if ($usage) {
                    v1_sse_data([
                        'id'      => $chatId,
                        'object'  => 'chat.completion.chunk',
                        'created' => $created,
                        'model'   => $model,
                        'choices' => [],
                        'usage'   => v1_usage_openai($usage),
                    ]);
                }
                echo "data: [DONE]\n\n";
                flush();
                error_log("[v1:{$requestId}] 流式响应完成");
            } catch (Throwable $e) {
                error_log("[v1:{$requestId}] 发送 finish_reason 失败: " . $e->getMessage());
            }
        }
    } else {
        // 非流式：上游失败、或返回 HTTP 200 但没有任何输出（正文和思考都空）→ 都返回 502。
        if ($answer === '' && $think === '') {
            error_log("[v1:{$requestId}] 非流式响应：空输出（ok=" . ($res['ok'] ? 'true' : 'false') . "）");
            v1_err(500, $res['ok']
                ? '上游返回空响应，请稍后重试'
                : ('上游请求失败：' . (string) ($res['error'] ?? 'unknown')), 'upstream_error');
        }
        // deepseek-v4 是推理模型，先吐 reasoning_content 再吐 content。max_tokens 太小（如 16）时
        // token 预算在思考阶段就耗尽，正文为空但思考有内容。客户端（Trae 等）通常只读 content，
        // 此时 content 为空会误报 provider returned an empty response，所以把思考兜底进 content。
        $finalContent = ($answer !== '') ? $answer : $think;
        error_log("[v1:{$requestId}] 非流式响应：准备返回结果，内容长度: " . strlen($finalContent));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'id'      => $chatId,
            'object'  => 'chat.completion',
            'created' => $created,
            'model'   => $model,
            'choices' => [[
                'index'         => 0,
                'message'       => ['role' => 'assistant', 'content' => $finalContent, 'reasoning_content' => $think],
                'finish_reason' => $res['ok'] ? 'stop' : 'error',
            ]],
            'usage'   => v1_usage_openai($usage),
        ], JSON_UNESCAPED_UNICODE);
    }

    if ($usage) {
        try { v1_billing($me, $m, $ch, $usage, 'api_openai', $res, $ttft, $t0); } catch (Throwable $e) {}
    }
}

/** POST /v1/messages（Anthropic 兼容） */
function v1_messages(array $me): void
{
    $req   = v1_read_body();
    $model = (string) ($req['model'] ?? '');
    if ($model === '') {
        v1_err(400, '缺少必填参数：model。', 'invalid_request_error');
    }
    [$m, $ch] = v1_resolve_model($model, $req);
    v1_check_model_limit($me, $m);
    v1_check_balance($me);

    // 并发控制：检查每分钟限流
    $concurrency_rpm   = (int) setting_get('concurrency_rpm', 0);
    $concurrency_mode  = setting_get('concurrency_mode', 'sk');
    if ($concurrency_rpm > 0) {
        $rateResult = concurrency_rate_limit(
            $concurrency_mode,
            $concurrency_rpm,
            (int) ($ch['id'] ?? 0),
            (int) ($ch['_sk_index'] ?? 0),
            (int) ($me['id'] ?? 0)
        );
        if (!$rateResult['ok']) {
            v1_err(429, "每分钟请求数已达上限（{$rateResult['current']}/{$concurrency_rpm}），请稍后重试（建议等待 {$rateResult['wait']} 秒）", 'rate_limit_exceeded');
        }
    }

    // 并发控制：尝试获取槽位
    $concurrency_limit = (int) setting_get('concurrency_limit', 0);
    $concurrency_mode  = setting_get('concurrency_mode', 'sk');
    $concurrency_token = null;
    if ($concurrency_limit > 0) {
        $result = concurrency_acquire(
            $concurrency_mode,
            $concurrency_limit,
            (int) ($ch['id'] ?? 0),
            (int) ($ch['_sk_index'] ?? 0),
            (int) ($me['id'] ?? 0)
        );
        if (!$result['ok']) {
            v1_err(429, "并发请求已达上限，请稍后重试（建议等待 {$result['wait']} 秒）", 'rate_limit_exceeded');
        }
        $concurrency_token = $result['token'];
    }

    $stream = !empty($req['stream']);
    $payload = v1_anthropic_to_openai($req);
    unset($payload['stream']);
    // 应用通道配置：强制工具调用降级（required→auto），避免不支持的上游返回 400
    $payload = upstream_downgrade_tool_choice($ch, $payload);

    $answer = '';
    $think  = '';
    $usage  = null;
    $t0     = microtime(true);
    $ttft   = null;
    $msgId  = 'msg_' . bin2hex(random_bytes(8));

    // 流式输出时按需惰性发送 content_block_start：
    // 思考内容先到就发 thinking 块（index 0），正文到达时先关掉思考块再开 text 块（index 1）。
    $thinkingStarted = false;
    $textStarted     = false;
    $toolBlockIdx    = 0;

    $onThink = function (string $delta) use (&$think, &$ttft, $t0, $stream, &$thinkingStarted) {
        if ($ttft === null) {
            $ttft = (int) round((microtime(true) - $t0) * 1000);
        }
        $think .= $delta;
        if ($stream) {
            if (!$thinkingStarted) {
                v1_sse_anthropic('content_block_start', [
                    'type'          => 'content_block_start',
                    'index'         => 0,
                    'content_block' => ['type' => 'thinking', 'thinking' => ''],
                ]);
                $thinkingStarted = true;
            }
            v1_sse_anthropic('content_block_delta', [
                'type'  => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'thinking_delta', 'thinking' => $delta],
            ]);
        }
    };

    $onDelta = function (string $delta) use (&$answer, &$ttft, $t0, $stream, &$textStarted, &$thinkingStarted) {
        if ($ttft === null) {
            $ttft = (int) round((microtime(true) - $t0) * 1000);
        }
        $answer .= $delta;
        if ($stream) {
            if (!$textStarted) {
                // 正文开始：先把已开始的思考块收尾，再开 text 块
                if ($thinkingStarted) {
                    v1_sse_anthropic('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
                }
                v1_sse_anthropic('content_block_start', [
                    'type'          => 'content_block_start',
                    'index'         => $thinkingStarted ? 1 : 0,
                    'content_block' => ['type' => 'text', 'text' => ''],
                ]);
                $textStarted = true;
            }
            v1_sse_anthropic('content_block_delta', [
                'type'  => 'content_block_delta',
                'index' => $thinkingStarted ? 1 : 0,
                'delta' => ['type' => 'text_delta', 'text' => $delta],
            ]);
        }
    };

    $onUsage = function (array $u) use (&$usage) {
        $usage = array_merge((array) $usage, $u);
    };
    // Anthropic 格式的工具调用回调
    $onToolCall = function (array $calls) use ($stream, $msgId, $model, &$toolBlockIdx) {
        if (!$stream) return;
        foreach ($calls as $tc) {
            v1_sse_anthropic('content_block_start', [
                'type'          => 'content_block_start',
                'index'         => $toolBlockIdx++,
                'content_block' => [
                    'type'  => 'tool_use',
                    'id'    => $tc['id'] ?? '',
                    'name'  => $tc['function']['name'] ?? '',
                    'input' => json_decode($tc['function']['arguments'] ?? '{}', true) ?? [],
                ],
            ]);
            v1_sse_anthropic('content_block_stop', [
                'type'  => 'content_block_stop',
                'index' => $toolBlockIdx - 1,
            ]);
        }
    };
    if ($stream) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        v1_sse_anthropic('message_start', [
            'type'    => 'message_start',
            'message' => [
                'id'            => $msgId,
                'type'          => 'message',
                'role'          => 'assistant',
                'model'         => $model,
                'content'       => [],
                'stop_reason'   => null,
                'stop_sequence' => null,
                'usage'         => ['input_tokens' => 0, 'output_tokens' => 0],
            ],
        ]);
    }

    try {
        $res = upstream_stream($ch, $payload, $onDelta, $onUsage, null, $onThink, $onToolCall);
        try { v1_sk_health($ch, $res); } catch (Throwable $e) {}
    } finally {
        // 释放并发槽位
        concurrency_release($concurrency_token);
    }

    if ($stream) {
        if ($textStarted) {
            v1_sse_anthropic('content_block_stop', ['type' => 'content_block_stop', 'index' => $thinkingStarted ? 1 : 0]);
        } elseif ($thinkingStarted) {
            v1_sse_anthropic('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
        } else {
            // 上游一个字都没吐：补一个空的 text 块，保证 message_delta 前至少有一个完整块
            v1_sse_anthropic('content_block_start', [
                'type'          => 'content_block_start',
                'index'         => 0,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]);
            v1_sse_anthropic('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
        }
        v1_sse_anthropic('message_delta', [
            'type'  => 'message_delta',
            'delta' => [
                'stop_reason'   => $res['ok'] ? 'end_turn' : 'error',
                'stop_sequence' => null,
            ],
            'usage' => v1_usage_anthropic($usage),
        ]);
        v1_sse_anthropic('message_stop', ['type' => 'message_stop']);
    } else {
        if (!$res['ok'] && $answer === '' && $think === '') {
            v1_err(500, '上游请求失败：' . (string) ($res['error'] ?? 'unknown'), 'upstream_error');
        }
        // 非流式：思考在前、正文在后，都放进 content 数组
        $content = [];
        if ($think !== '') {
            $content[] = ['type' => 'thinking', 'thinking' => $think];
        }
        if ($answer !== '') {
            $content[] = ['type' => 'text', 'text' => $answer];
        }
        if (!$content) {
            $content[] = ['type' => 'text', 'text' => ''];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'id'            => $msgId,
            'type'          => 'message',
            'role'          => 'assistant',
            'model'         => $model,
            'content'       => $content,
            'stop_reason'   => $res['ok'] ? 'end_turn' : 'error',
            'stop_sequence' => null,
            'usage'         => v1_usage_anthropic($usage),
        ], JSON_UNESCAPED_UNICODE);
    }

    if ($usage) {
        try { v1_billing($me, $m, $ch, $usage, 'api_anthropic', $res, $ttft, $t0); } catch (Throwable $e) {}
    }
}

/** POST /v1/responses（OpenAI Responses API 兼容） */
function v1_responses(array $me): void
{
    $req   = v1_read_body();
    $model = (string) ($req['model'] ?? '');
    if ($model === '') {
        v1_err(400, '缺少必填参数：model。', 'invalid_request_error');
    }
    [$m, $ch] = v1_resolve_model($model, $req);
    v1_check_model_limit($me, $m);
    v1_check_balance($me);
    // 并发控制：检查每分钟限流
    $concurrency_rpm   = (int) setting_get('concurrency_rpm', 0);
    $concurrency_mode  = setting_get('concurrency_mode', 'sk');
    if ($concurrency_rpm > 0) {
        $rateResult = concurrency_rate_limit(
            $concurrency_mode,
            $concurrency_rpm,
            (int) ($ch['id'] ?? 0),
            (int) ($ch['_sk_index'] ?? 0),
            (int) ($me['id'] ?? 0)
        );
        if (!$rateResult['ok']) {
            v1_err(429, "每分钟请求数已达上限（{$rateResult['current']}/{$concurrency_rpm}），请稍后重试（建议等待 {$rateResult['wait']} 秒）", 'rate_limit_exceeded');
        }
    }


    // 并发控制：尝试获取槽位
    $concurrency_limit = (int) setting_get('concurrency_limit', 0);
    $concurrency_mode  = setting_get('concurrency_mode', 'sk');
    $concurrency_token = null;
    if ($concurrency_limit > 0) {
        $result = concurrency_acquire(
            $concurrency_mode,
            $concurrency_limit,
            (int) ($ch['id'] ?? 0),
            (int) ($ch['_sk_index'] ?? 0),
            (int) ($me['id'] ?? 0)
        );
        if (!$result['ok']) {
            v1_err(429, "并发请求已达上限，请稍后重试（建议等待 {$result['wait']} 秒）", 'rate_limit_exceeded');
        }
        $concurrency_token = $result['token'];
    }

    $stream = !empty($req['stream']);

    // Responses API → Chat Completions 内部格式
    $payload = $req;
    if (isset($req['input']) && !isset($req['messages'])) {
        $payload['messages'] = $req['input'];
        unset($payload['input']);
    }
    // instructions → system message
    if (isset($req['instructions']) && is_string($req['instructions']) && trim($req['instructions']) !== '') {
        $sys = ['role' => 'system', 'content' => $req['instructions']];
        array_unshift($payload['messages'], $sys);
        unset($payload['instructions']);
    }
    unset($payload['stream']);
    // 补缺失/为 null 的 content：Responses API 的 input 直接转 messages，带 tool_calls 的
    // assistant 消息 content 可能省略（符合规范），但上游 Rust 严格校验 content 必填会报 400。
    if (isset($payload['messages']) && is_array($payload['messages'])) {
        foreach ($payload['messages'] as &$__m) {
            if (is_array($__m) && (!array_key_exists('content', $__m) || $__m['content'] === null)) {
                $__m['content'] = '';
            }
        }
        unset($__m);
    }
    // 应用通道配置：强制工具调用降级（required→auto），避免不支持的上游返回 400
    $payload = upstream_downgrade_tool_choice($ch, $payload);

    $answer  = '';
    $usage   = null;
    $t0      = microtime(true);
    $ttft    = null;
    $respId  = 'resp_' . bin2hex(random_bytes(8));
    $itemId  = 'msg_' . bin2hex(random_bytes(8));
    $created = time();
    $seq     = 0;

    $onDelta = function (string $delta) use (&$answer, &$ttft, $t0, $stream, &$seq, $itemId) {
        if ($ttft === null) {
            $ttft = (int) round((microtime(true) - $t0) * 1000);
        }
        $answer .= $delta;
        if ($stream) {
            v1_sse_event('response.output_text.delta', [
                'type'            => 'response.output_text.delta',
                'sequence_number' => $seq++,
                'item_id'         => $itemId,
                'output_index'    => 0,
                'content_index'   => 0,
                'delta'           => $delta,
            ]);
        }
    };
    $onUsage = function (array $u) use (&$usage) {
        $usage = array_merge((array) $usage, $u);
    };
    // Responses 格式的工具调用回调
    $onToolCall = function (array $calls) use ($stream, &$seq, $respId) {
        if (!$stream) return;
        foreach ($calls as $tc) {
            $fnCallId = 'fc_' . bin2hex(random_bytes(8));
            v1_sse_event('response.output_item.added', [
                'type'            => 'response.output_item.added',
                'sequence_number' => $seq++,
                'output_index'    => 1,
                'item' => [
                    'id'      => $fnCallId,
                    'type'    => 'function_call',
                    'status'  => 'in_progress',
                    'call_id' => $tc['id'] ?? '',
                    'name'    => $tc['function']['name'] ?? '',
                    'arguments' => $tc['function']['arguments'] ?? '',
                ],
            ]);
            v1_sse_event('response.output_item.done', [
                'type'            => 'response.output_item.done',
                'sequence_number' => $seq++,
                'output_index'    => 1,
                'item' => [
                    'id'      => $fnCallId,
                    'type'    => 'function_call',
                    'status'  => 'completed',
                    'call_id' => $tc['id'] ?? '',
                    'name'    => $tc['function']['name'] ?? '',
                    'arguments' => $tc['function']['arguments'] ?? '',
                ],
            ]);
        }
    };
    if ($stream) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        // response.created
        v1_sse_event('response.created', [
            'type'            => 'response.created',
            'sequence_number' => $seq++,
            'response' => [
                'id'         => $respId,
                'object'     => 'response',
                'created_at' => $created,
                'status'     => 'in_progress',
                'model'      => $model,
                'output'     => [],
                'usage'      => null,
            ],
        ]);

        // response.output_item.added
        v1_sse_event('response.output_item.added', [
            'type'         => 'response.output_item.added',
            'sequence_number' => $seq++,
            'output_index' => 0,
            'item' => [
                'id'     => $itemId,
                'type'   => 'message',
                'role'   => 'assistant',
                'status' => 'in_progress',
            ],
        ]);

        // response.content_part.added
        v1_sse_event('response.content_part.added', [
            'type'            => 'response.content_part.added',
            'sequence_number' => $seq++,
            'item_id'         => $itemId,
            'output_index'    => 0,
            'content_index'   => 0,
            'part' => [
                'type' => 'output_text',
                'text' => '',
            ],
        ]);
    }

    try {
        $res = upstream_stream($ch, $payload, $onDelta, $onUsage, null, null, $onToolCall);
        try { v1_sk_health($ch, $res); } catch (Throwable $e) {}
    } finally {
        // 释放并发槽位
        concurrency_release($concurrency_token);
    }

    if ($stream) {
        // response.output_text.done
        v1_sse_event('response.output_text.done', [
            'type'            => 'response.output_text.done',
            'sequence_number' => $seq++,
            'item_id'         => $itemId,
            'output_index'    => 0,
            'content_index'   => 0,
            'text'            => $answer,
        ]);

        // response.content_part.done
        v1_sse_event('response.content_part.done', [
            'type'            => 'response.content_part.done',
            'sequence_number' => $seq++,
            'item_id'         => $itemId,
            'output_index'    => 0,
            'content_index'   => 0,
            'part' => [
                'type' => 'output_text',
                'text' => $answer,
            ],
        ]);

        // response.output_item.done
        v1_sse_event('response.output_item.done', [
            'type'            => 'response.output_item.done',
            'sequence_number' => $seq++,
            'output_index'    => 0,
            'item' => [
                'id'      => $itemId,
                'type'    => 'message',
                'role'    => 'assistant',
                'status'  => 'completed',
                'content' => [
                    ['type' => 'output_text', 'text' => $answer],
                ],
            ],
        ]);

        // response.completed
        $usageOut = null;
        if ($usage) {
            $tin  = (int) ($usage['prompt_tokens'] ?? 0);
            $tout = (int) ($usage['completion_tokens'] ?? 0);
            $usageOut = [
                'input_tokens'  => $tin,
                'output_tokens' => $tout,
                'total_tokens'  => $tin + $tout,
            ];
        }
        v1_sse_event('response.completed', [
            'type'            => 'response.completed',
            'sequence_number' => $seq++,
            'response' => [
                'id'         => $respId,
                'object'     => 'response',
                'created_at' => $created,
                'status'     => $res['ok'] ? 'completed' : 'failed',
                'model'      => $model,
                'output' => [
                    [
                        'id'      => $itemId,
                        'type'    => 'message',
                        'role'    => 'assistant',
                        'status'  => 'completed',
                        'content' => [
                            ['type' => 'output_text', 'text' => $answer],
                        ],
                    ],
                ],
                'usage' => $usageOut,
            ],
        ]);
    } else {
        if (!$res['ok'] && $answer === '') {
            v1_err(500, '上游请求失败：' . (string) ($res['error'] ?? 'unknown'), 'upstream_error');
        }
        $usageOut = null;
        if ($usage) {
            $tin  = (int) ($usage['prompt_tokens'] ?? 0);
            $tout = (int) ($usage['completion_tokens'] ?? 0);
            $usageOut = [
                'input_tokens'  => $tin,
                'output_tokens' => $tout,
                'total_tokens'  => $tin + $tout,
            ];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'id'         => $respId,
            'object'     => 'response',
            'created_at' => $created,
            'status'     => $res['ok'] ? 'completed' : 'failed',
            'model'      => $model,
            'output' => [
                [
                    'id'      => $itemId,
                    'type'    => 'message',
                    'role'    => 'assistant',
                    'status'  => 'completed',
                    'content' => [
                        ['type' => 'output_text', 'text' => $answer],
                    ],
                ],
            ],
            'usage' => $usageOut,
        ], JSON_UNESCAPED_UNICODE);
    }

    if ($usage) {
        try { v1_billing($me, $m, $ch, $usage, 'api_responses', $res, $ttft, $t0); } catch (Throwable $e) {}
    }
}

// ==================== Gemini CLI 兼容端点（/v1beta/*） ====================

/** Gemini 请求 → OpenAI messages 格式 */
function v1_gemini_to_openai(array $req): array
{
    $out = [
        'model'    => (string) ($req['model'] ?? ''),
        'messages' => [],
    ];

    // systemInstruction → system 消息
    $sys = $req['systemInstruction'] ?? null;
    if (is_array($sys) && !empty($sys['parts'])) {
        $texts = [];
        foreach ($sys['parts'] as $p) {
            if (is_array($p) && isset($p['text']) && trim((string) $p['text']) !== '') {
                $texts[] = (string) $p['text'];
            }
        }
        if ($texts) {
            $out['messages'][] = ['role' => 'system', 'content' => implode("\n", $texts)];
        }
    }

    foreach (($req['contents'] ?? []) as $c) {
        if (!is_array($c)) {
            continue;
        }
        $role = (string) ($c['role'] ?? 'user');
        // Gemini 的 role 是 user/model → OpenAI 的 user/assistant
        if ($role === 'model') {
            $role = 'assistant';
        } elseif ($role !== 'user' && $role !== 'assistant' && $role !== 'system') {
            $role = 'user';
        }
        $texts = [];
        foreach (($c['parts'] ?? []) as $p) {
            if (is_array($p) && isset($p['text']) && (string) $p['text'] !== '') {
                $texts[] = (string) $p['text'];
            }
        }
        if ($texts === []) {
            continue;
        }
        $out['messages'][] = ['role' => $role, 'content' => implode("\n", $texts)];
    }

    // generationConfig → OpenAI 参数
    $gc = $req['generationConfig'] ?? null;
    if (is_array($gc)) {
        if (isset($gc['temperature'])) {
            $out['temperature'] = $gc['temperature'];
        }
        if (isset($gc['maxOutputTokens'])) {
            $out['max_tokens'] = (int) $gc['maxOutputTokens'];
        }
        if (isset($gc['topP'])) {
            $out['top_p'] = $gc['topP'];
        }
        if (isset($gc['topK'])) {
            $out['top_k'] = $gc['topK'];
        }
        if (!empty($gc['stopSequences'])) {
            $out['stop'] = $gc['stopSequences'];
        }
    }
    return $out;
}

/** Gemini generateContent 端点（非流式 + 流式） */
function v1_gemini_generate(array $me, string $model, bool $stream): void
{
    $req = v1_read_body();
    if ($model === '') {
        v1_err(400, '缺少必填参数：model。', 'invalid_request_error');
    }
    [$m, $ch] = v1_resolve_model($model, $req);
    v1_check_model_limit($me, $m);
    v1_check_balance($me);

    $payload = v1_gemini_to_openai($req);
    $payload['model'] = $model; // 保持客户端传入的模型名（与 chat/completions 行为一致）
    $payload['stream'] = $stream;
    // 丢弃非 OpenAI 标准字段
    $__openaiStandard = ['model', 'messages', 'max_tokens', 'temperature', 'top_p', 'stream', 'stop'];
    foreach (array_keys($payload) as $__k) {
        if (!in_array($__k, $__openaiStandard, true)) {
            unset($payload[$__k]);
        }
    }
    // 补缺失 content
    foreach ($payload['messages'] as &$__msg) {
        if (is_array($__msg) && (!array_key_exists('content', $__msg) || $__msg['content'] === null)) {
            $__msg['content'] = '';
        }
    }
    unset($__msg);
    // 应用通道配置：强制工具调用降级
    $payload = upstream_downgrade_tool_choice($ch, $payload);

    $answer = '';
    $usage  = null;
    $t0     = microtime(true);
    $ttft   = null;

    $onDelta = function (string $delta) use (&$answer, &$ttft, $t0, $stream) {
        if ($ttft === null) {
            $ttft = (int) round((microtime(true) - $t0) * 1000);
        }
        $answer .= $delta;
        if ($stream) {
            // Gemini 流式帧：每帧一个 candidates 数组
            v1_sse_data([
                'candidates' => [[
                    'content'     => ['role' => 'model', 'parts' => [['text' => $delta]]],
                    'finishReason' => null,
                    'index'       => 0,
                ]],
            ]);
        }
    };
    $onUsage = function (array $u) use (&$usage) {
        $usage = array_merge((array) $usage, $u);
    };
    $onThink = function (string $delta) use ($stream) {
        // Gemini 无 thinking 字段，忽略
    };
    $onToolCall = function (array $calls) use ($stream) {
        // Gemini 无 tool_calls，忽略
    };

    if ($stream) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
    }

    $res = upstream_stream($ch, $payload, $onDelta, $onUsage, null, $onThink, $onToolCall);
    try { v1_sk_health($ch, $res); } catch (Throwable $e) {}

    if ($stream) {
        // 结束帧：finishReason STOP
        v1_sse_data([
            'candidates' => [[
                'content'     => ['role' => 'model', 'parts' => [['text' => '']]],
                'finishReason' => $res['ok'] ? 'STOP' : 'ERROR',
                'index'       => 0,
            ]],
        ]);
        echo "data: [DONE]\n\n";
        flush();
    } else {
        if (!$res['ok'] && $answer === '') {
            v1_err(500, '上游请求失败：' . (string) ($res['error'] ?? 'unknown'), 'upstream_error');
        }
        $tin  = (int) ($usage['prompt_tokens'] ?? 0);
        $tout = (int) ($usage['completion_tokens'] ?? 0);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'candidates' => [[
                'content'     => ['role' => 'model', 'parts' => [['text' => $answer]]],
                'finishReason' => $res['ok'] ? 'STOP' : 'ERROR',
                'index'       => 0,
            ]],
            'usageMetadata' => [
                'promptTokenCount'     => $tin,
                'candidatesTokenCount' => $tout,
                'totalTokenCount'      => $tin + $tout,
            ],
            'modelVersion' => $model,
        ], JSON_UNESCAPED_UNICODE);
    }

    if ($usage) {
        try { v1_billing($me, $m, $ch, $usage, 'api_gemini', $res, $ttft, $t0); } catch (Throwable $e) {}
    }
}

/** GET /v1beta/models（Gemini 格式模型列表） */
function v1_gemini_models(array $me): void
{
    $rows = db_all('SELECT display_name FROM models WHERE status = 1 ORDER BY sort DESC, id');
    $models = [];
    foreach ($rows as $r) {
        $models[] = [
            'name'    => $r['display_name'],
            'displayName' => $r['display_name'],
            'supportedGenerationMethods' => ['generateContent', 'streamGenerateContent'],
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['models' => $models], JSON_UNESCAPED_UNICODE);
}

/** Gemini 端点路由分发：/v1beta/* */
function v1_gemini_route(array $me, string $path): void
{
    // GET /v1beta/models
    if ($path === '/v1beta/models') {
        v1_gemini_models($me);
        return;
    }
    // POST /v1beta/models/{model}:generateContent
    if (preg_match('#^/v1beta/models/([^:]+):generateContent$#', $path, $mm)) {
        v1_gemini_generate($me, $mm[1], false);
        return;
    }
    // POST /v1beta/models/{model}:streamGenerateContent
    if (preg_match('#^/v1beta/models/([^:]+):streamGenerateContent$#', $path, $mm)) {
        v1_gemini_generate($me, $mm[1], true);
        return;
    }
    // 未知 Gemini 端点
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'message' => 'Gemini 端点不存在：' . $path,
            'type'    => 'not_found',
            'code'    => 404,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** GET /v1/models（OpenAI 兼容模型列表，带 anthropic-version 头时输出 Anthropic 格式） */
function v1_models(array $me): void
{
    $rows = db_all('SELECT model_name, display_name, vision FROM models WHERE status = 1 ORDER BY sort DESC, id');
    $isAnthropic = stripos((string) ($_SERVER['HTTP_ANTHROPIC_VERSION'] ?? ''), '2023') !== false;
    header('Content-Type: application/json; charset=utf-8');
    if ($isAnthropic) {
        // Anthropic SDK 期望 data[].type = "model"，含 display_name / created_at
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'type'         => 'model',
                'id'           => $r['display_name'],
                'display_name' => $r['display_name'],
                'created_at'   => '2024-01-01T00:00:00Z',
            ];
        }
        echo json_encode([
            'data'     => $data,
            'has_more' => false,
            'first_id' => $data[0]['id'] ?? null,
            'last_id'  => $data[count($data) - 1]['id'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'id'         => $r['display_name'],
            'object'     => 'model',
            'created'    => 0,
            'owned_by'   => 'yanyang',
            'display_name' => $r['display_name'],
            'vision'     => (int) $r['vision'] === 1,
        ];
    }
    echo json_encode(['object' => 'list', 'data' => $data], JSON_UNESCAPED_UNICODE);
}

/** GET /v1/me（校验 Key 并返回用户信息） */
function v1_me(array $me): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'       => 1,
        'id'       => (int) $me['id'],
        'username' => $me['username'],
        'email'    => $me['email'],
        'role'     => $me['role'],
        'balance'  => money($me['_sk_card']['balance'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
}

/** 余额查询：兼容 RikkaHub 等客户端 GET /v1/credits 探测余额 */
function v1_credits(array $me): void
{
    $card    = $me['_sk_card'] ?? [];
    $balance = (float) ($card['balance'] ?? 0);
    $used    = (float) ($card['total_cost'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'object'          => 'credit_summary',
        'balance'         => $balance,
        'total_granted'   => $balance + $used,
        'total_used'      => $used,
        'total_available' => $balance,
        'currency'        => 'CNY',
        'grants'          => [
            'object' => 'list',
            'data'   => [
                [
                    'object'        => 'credit_grant',
                    'grant_amount'  => $balance + $used,
                    'used_amount'   => $used,
                    'effective_at'  => 0,
                    'expires_at'    => isset($card['expires_at']) && $card['expires_at'] !== '' && $card['expires_at'] !== null
                        ? strtotime((string) $card['expires_at'])
                        : null,
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

// ==================== 入口 ====================

// 统一 CORS 头（所有响应都带）
// 用 * 通配符允许所有自定义头（x-stainless-os、anthropic-dangerous-direct-browser-access 等）
// Authorization 按规范不能被 * 覆盖，需显式列出
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, *');
header('Access-Control-Max-Age: 86400');

// OPTIONS 预检请求直接返回 204，跳过认证
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 路由：nginx rewrite 已把 /v1/xxx 转成 /v1/index.php?path=xxx
$path = (string) ($_GET['path'] ?? '');
$path = '/' . trim($path, '/');

// ============ 路径容错归一 ============
// 客户端 base_url 配错会导致路径重复或拼写错误，例如：
//   base_url = https://host/v1                  → SDK 又拼 /v1/chat/completions → /v1/v1/chat/completions
//   base_url = https://host/v1/chat/completions → /v1/chat/completions/chat/completions
//   base_url = https://host/v1/chat/respones    → 端点名拼错
// 进 switch 前把 path 归一到标准端点，避免客户端因配错 base_url 而 404。

// 1. 去掉多余的 /v1 前缀（可能连续多个）：/v1/v1/xxx → /xxx
while (str_starts_with($path, '/v1/') || $path === '/v1') {
    $path = substr($path, 3);
    if ($path === '') {
        $path = '/';
        break;
    }
}

// 2. 拼写修正 + 端点归一：/respones、/respone、/chat/respones → /responses
$path = preg_replace('#^(?:/chat)?/respones?$#', '/responses', $path);

// 3. 去掉重复的端点后缀：/chat/completions/chat/completions → /chat/completions
$path = preg_replace('#^/chat/completions/chat/completions$#', '/chat/completions', $path);

// 4. 混搭端点：/responses/chat/completions → /responses
$path = preg_replace('#^/responses/chat/completions$#', '/responses', $path);

if ($path === '/') {
    // 根路径：给个简单的服务信息
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'name'    => '岩羊AI Open API',
        'version' => 'v1',
        'endpoints' => ['/v1/chat/completions', '/v1/messages', '/v1/responses', '/v1/models', '/v1/me'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 模型列表对匿名开放：很多客户端（CC Switch / RikkaHub / 各类 APP）在添加接口、
// 测试连接时会不带 key 请求 /v1/models，强制认证会让它们「获取不到模型」。
// 模型列表只含模型名，不含余额等敏感信息，放开无风险。
if ($path === '/models') {
    v1_models([]);
    exit;
}

// 认证（除模型列表外的接口均要求 SK 卡密）
$me = sk_card_validate();
if (!$me) {
    $err = $GLOBALS['sk_validate_error'] ?? '无效的 API Key。请在个人中心「SK 卡密管理」生成卡密后，用 Authorization: Bearer sk-xxx 访问。';
    v1_err(401, $err, 'authentication_error');
}

// Gemini CLI 兼容：/v1beta/*（nginx rewrite 已转成 path=v1beta/xxx）走 Gemini 端点
if (str_starts_with($path, '/v1beta/')) {
    v1_gemini_route($me, $path);
    exit;
}

switch ($path) {
    case '/chat/completions':
        v1_chat_completions($me);
        break;
    case '/messages':
        // 智能判断：优先按请求体结构识别协议
        // 1. Anthropic 格式（system/stop_sequences/content 块数组等）
        // 2. Responses API 格式（有 input 无 messages）
        // 3. OpenAI Chat Completions 格式
        if (v1_is_anthropic_request()) {
            v1_messages($me);
        } elseif (v1_is_responses_request()) {
            v1_responses($me);
        } else {
            v1_chat_completions($me);
        }
        break;
    case '/responses':
        v1_responses($me);
        break;
    case '/models':
        v1_models($me);
        break;
    case '/me':
        v1_me($me);
        break;
    case '/credits':
        v1_credits($me);
        break;
    default:
        // 注意：这里故意不抛 404 状态码。nginx 全局 error_page 404 /404.php 会把
        // 网关返回的 404 JSON 替换成 HTML 页面，API 客户端（RikkaHub / NewAPI 等）
        // 解析非 JSON 会直接报错。改为 200 + error 体，客户端能正常读到错误信息。
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => [
                'message' => '接口不存在：' . $path,
                'type'    => 'not_found',
                'code'    => 404,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
}
