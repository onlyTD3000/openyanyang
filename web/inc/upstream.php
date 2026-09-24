<?php
/**
 * 上游中转平台调用。
 * 同时支持两种协议，按渠道的 chat_path 自动判定：
 *  - OpenAI 兼容：/chat/completions，消息里图片用 image_url，回包 choices[].delta
 *  - Anthropic 原生：/messages，图片用 source.base64，回包 content_block_delta
 * 仅在服务端使用，API Key 绝不下发到前端。
 */

/**
 * 该渠道是否走 Anthropic 原生协议。
 * 以后台显式选择的 protocol 字段为准；只有该字段缺失（旧数据）时才回退到按路径猜测。
 */
function upstream_is_claude(array $ch): bool
{
    $p = strtolower(trim((string) ($ch['protocol'] ?? '')));
    if ($p === 'claude' || $p === 'anthropic') {
        return true;
    }
    if ($p === 'openai') {
        return false;
    }
    // 没有 protocol 值时的兜底：路径里带 messages 视为 Anthropic
    $path = strtolower(trim((string) ($ch['chat_path'] ?? '')));
    return strpos($path, 'messages') !== false;
}

require_once __DIR__ . '/upstream_claude.php';

function upstream_url(array $ch): string
{
    $base = rtrim(trim($ch['base_url']), '/');
    $path = trim($ch['chat_path'] ?: '/chat/completions');
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return $base . $path;
}

/**
 * 流式请求上游，逐块回调。
 * @param callable $onDelta function(string $text)
 * @param callable $onUsage function(array $usage)
 * @return array [ok=>bool, error=>string]
 */
/**
 * 这个 400 是不是上游内容安全审核拒绝的？
 *
 * 阿里云百炼(DashScope)对整个输入做合规扫描，命中就返 400
 * DataInspectionFailed。这类拒绝是确定性的：同样的输入送十次拒十次，
 * 所以必须从可重试集合里摘出去，否则用户白等三轮退避（约 20 秒）。
 *
 * 顺手把其它家的同类错误也认上：文心的 prompt tokens too long 之外那套
 * 内容审核码、智谱的 1301、OpenAI/Azure 的 content_filter。都是同一性质。
 */
function upstream_is_moderation_block(string $detail): bool
{
    $低 = mb_strtolower($detail);
    $特征 = [
        'datainspectionfailed',          // 阿里云百炼
        'inappropriate content',         // 阿里云百炼英文描述
        'data_inspection_failed',
        'content_filter',                // OpenAI / Azure
        'content_policy_violation',
        'responsibleaipolicyviolation',  // Azure
        '内容审核',
        '内容安全',
        '包含不适当的内容',
        '输入内容存在安全风险',           // 智谱
        '可能包含不安全或敏感内容',        // 文心
    ];
    foreach ($特征 as $词) {
        if (strpos($低, $词) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 该状态码/网络错误是否值得重试（上游抖动、限流、网关错误、请求格式错误）
 * @param string $detail 上游错误正文，用于把「重试也没用」的 400 剔除掉
 */
function upstream_should_retry(int $httpCode, int $curlErrno, string $detail = ''): bool
{
    // 审核拒绝：确定性失败，重试只是让用户多等
    if ($httpCode === 400 && $detail !== '' && upstream_is_moderation_block($detail)) {
        return false;
    }
    if (in_array($httpCode, [400, 408, 429, 500, 502, 503, 504, 520, 522, 524], true)) {
        return true;
    }
    // 连接失败、连接超时、被对端断开：都属于可重试的瞬时故障
    return in_array($curlErrno, [
        CURLE_COULDNT_CONNECT,
        CURLE_OPERATION_TIMEDOUT,
        CURLE_GOT_NOTHING,
        CURLE_RECV_ERROR,
        CURLE_SEND_ERROR,
        CURLE_SSL_CONNECT_ERROR,
    ], true);
}

/**
 * 这些状态码表示「换一个 SK 有希望恢复」，应该触发 SK 切换重试。
 *  - 401/402/403：这个 key 废了/欠费/没权限，换 key 就好
 *  - 429：这个 key 被打满限流，换 key 分流
 *  - 5xx：上游网关/服务抖动，换 key 可能绕开坏节点
 * 普通 400 参数错误不在此列（确定性失败，换 SK 也没用）。
 */
function upstream_sk_switchable_codes(): array
{
    return [401, 402, 403, 429, 500, 501, 502, 503, 504, 505, 506, 507, 508, 509, 510, 511, 520, 522, 524];
}

/**
 * tool_choice 降级：渠道开了 tool_choice_downgrade 开关时，
 * 把客户端强制的 tool_choice（required / any / 指定函数）降为 auto。
 * 某些上游（tokenrhythm.studio / deepseek-v4-flash）不支持 required，原样转发会 400。
 */
function upstream_downgrade_tool_choice(array $ch, array $payload): array
{
    if ((int)($ch['tool_choice_downgrade'] ?? 0) !== 1) {
        return $payload;
    }
    if (!isset($payload['tool_choice'])) {
        return $payload;
    }
    $tc = $payload['tool_choice'];
    $降级 = false;
    if (is_string($tc)) {
        $降级 = in_array(strtolower($tc), ['required', 'any'], true);
    } elseif (is_array($tc)) {
        $type = strtolower((string)($tc['type'] ?? ''));
        $降级 = in_array($type, ['required', 'any', 'function'], true);
    }
    if ($降级) {
        $payload['tool_choice'] = 'auto';
    }
    return $payload;
}

/**
 * 把上游返回的错误正文提炼成一句人话。
 * 中转平台的错误格式五花八门，逐层兜底解析，最后退回截断的原文。
 */
function upstream_pick_error(string $body, int $httpCode): string
{
    $body = trim($body);
    if ($body === '') {
        return '上游返回 HTTP ' . $httpCode . '（无响应内容）';
    }
    // SSE 流里夹带的错误事件：逐行找 data: {...}
    $j = json_decode($body, true);
    if (!is_array($j)) {
        foreach (explode("
", $body) as $line) {
            $line = trim(rtrim($line, "
"));
            if (strncmp($line, 'data:', 5) === 0) {
                $try = json_decode(trim(substr($line, 5)), true);
                if (is_array($try) && isset($try['error'])) { $j = $try; break; }
            }
        }
    }
    if (is_array($j)) {
        $e = $j['error'] ?? $j;
        if (is_array($e)) {
            $msg = $e['message'] ?? ($e['msg'] ?? ($e['detail'] ?? ''));
            if (is_array($msg)) { $msg = json_encode($msg, JSON_UNESCAPED_UNICODE); }
            $msg = trim((string) $msg);
            if ($msg !== '') {
                $type = trim((string) ($e['type'] ?? ''));
                return 'HTTP ' . $httpCode . '：' . mb_substr($msg, 0, 300)
                     . ($type !== '' ? ' [' . $type . ']' : '');
            }
        } elseif (is_string($e) && trim($e) !== '') {
            return 'HTTP ' . $httpCode . '：' . mb_substr(trim($e), 0, 300);
        }
    }
    // 不是 JSON（可能是网关的 HTML 错误页），去标签后截断
    $plain = trim(preg_replace('/s+/u', ' ', strip_tags($body)));
    return 'HTTP ' . $httpCode . '：' . mb_substr($plain === '' ? $body : $plain, 0, 300);
}

/** 状态码转成给用户看的友好提示 */
function upstream_friendly_error(int $httpCode, string $detail): string
{
    $前缀 = '';
    if ($httpCode === 429) {
        $前缀 = '上游限流，已自动重试仍失败，请稍等片刻再发。';
    } elseif (in_array($httpCode, [502, 503, 504, 520, 522, 524], true)) {
        $前缀 = '上游服务暂时不可用（已自动重试）。';
    } elseif ($httpCode === 401 || $httpCode === 403) {
        $前缀 = '上游拒绝访问，通常是 API Key 失效或额度用尽，请检查后台渠道配置。';
    } elseif ($httpCode === 400 && upstream_is_moderation_block($detail)) {
        // 审核拒绝要单独说清楚：这不是系统故障，重试也没用，而且触发点
        // 往往不是用户刚打的那句话，而是上下文里的历史消息或命令回执
        // （每轮都会连同历史一起送审，所以这条对话会一直被拒）。
        return '当前模型的内容审核拒绝了这次请求。触发内容可能在本轮提问里，'
             . '也可能在这个对话的历史记录或命令执行结果里（每轮都会连同上下文一起送审）。'
             . '建议换一个其它厂商的模型继续，或新建对话重新开始。';
    } elseif ($httpCode === 400) {
        $前缀 = '上游认为请求不合法（已自动重试）。';
    } elseif ($httpCode === 404) {
        $前缀 = '上游接口地址不存在，请检查渠道的 base_url 与 chat_path。';
    }
    return $前缀 === '' ? $detail : $前缀 . ' 详情：' . $detail;
}

/**
 * 流式请求上游，逐块回调。
 * 遇到上游瞬时故障（400/503/502/429/连接错误）会自动退避重试。
 * 【断点续接】即使已经吐出部分正文，只要本轮还没结束，遇到故障会在上游支持的前提下重试。
 * 对 OpenAI 兼容：不做续接（它没有 offset 参数），但只要 400/502/503 属于可重试，就重发全量请求，
 *                回调端收到重复内容时天然幂等（前端是纯追加，后端 $answer 也是拼接；
 *                为了避免「重发整轮后和已输出部分拼接成两份」，这里保存已吐出的原文长度，
 *                在重试后的回调里跳过前面已发出的字节，只把新增的 delta 交给上层）。
 * 对 Claude 原生：同理在回调里跳过已吐出的长度，确保用户看到的内容不重复、不中断。
 *
 * @param callable      $onDelta function(string $text): void|false
 * @param callable      $onUsage function(array $usage): void
 * @param callable|null $onIdle  function(): bool
 *        没有数据到达时也会被周期性调用，返回 true 表示要求中断。
 * @param callable|null $onThink function(string $text): void|false
 *        上游按字段明确区分了「思考」（Claude 的 thinking / OpenAI 兼容的
 *        reasoning_content）时才会调用，思考文本单独走这条通道，不混进 $onDelta。
 *        不传时保持老行为：思考内容并入 $onDelta 的文本流，由上层自己猜。
 * @return array [ok=>bool, error=>string, attempts=>int, http=>int]
 */
function upstream_stream(array $ch, array $payload, callable $onDelta, callable $onUsage,
                         ?callable $onIdle = null, ?callable $onThink = null, ?callable $onToolCall = null): array
{
    $isClaude = upstream_is_claude($ch);
    $payload['stream'] = true;
    // 提示词缓存开关：后台「站点设置」里的 prompt_cache，默认开。
    // OpenAI 侧是自动前缀缓存，不需要请求里带任何东西；Claude 侧要显式挂 cache_control。
    $缓存开 = (int) setting_get('prompt_cache', 1) === 1;
    $payload['cache_prompt'] = $缓存开 ? 1 : 0;

    // 【后台配置】重试次数
    $用户重试 = (int) setting_get('upstream_retry_times', 3);
    $最大重试 = max(0, $用户重试);   // 允许设 0，就是不重试
    // 检测到故障要能换到正常 SK：开了轮询且 SK 数 > 1 时，重试次数至少覆盖所有 SK，
    // 避免「3 次重试只试了前 4 个 SK、后面的好 SK 轮不到」就直接报给客户端。
    $skTotal = (int)($ch['_sk_total'] ?? 0);
    if ($skTotal > 1) {
        $最大重试 = max($最大重试, $skTotal - 1);
    }
    $最大重试 = min($最大重试, 20);   // 上限保护，避免配置异常导致无限重试
    // 换 SK 重试不指数退避：固定 200ms 后立即试下一个 SK。
    // 否则并发高时 sleep 累积会让 worker 被长时间占用 → 新请求 network error。

    // 总耗时上限：429/402 秒回时 20 次重试只需几秒，完全不影响；
    // 但个别 SK 连接挂起时（LOW_SPEED_TIME 最少 30 秒才判死），
    // 20 次 × 30 秒 = 600 秒会把 FPM worker 卡死、客户端超时。
    $重试总时限 = 90;   // 秒
    $重试起始时间 = microtime(true);

    $已吐字节    = 0;                 // 已经交给 $onDelta 的文本总长度，用于跳过重试后的重复内容
    $已吐字节think = 0;               // 已经交给 $onThink 的思考文本总长度，单独跟踪，不与正文共用
    $累计usage   = [];                // 跨尝试合并 usage（重试后通常不再给 usage，用第一次的值）
    $toolCalls   = [];                // 收集到的工具调用
    $lastErr     = '';
    $lastCode    = 0;
    $attempt     = 0;
    $aborted     = false;             // 用户点暂停，主动断流（不是错误）

    for ($i = 0; $i <= $最大重试; $i++) {
        $attempt = $i + 1;
        // 重试前自动轮换下一个可用SK（channel_pick_sk自己处理游标推进、跳过熔断SK）
        if ($i > 0) {
            // 让内存里的游标也推进：否则 $ch['rotate_idx'] 一直是初值，
            // channel_pick_sk 会从同一起点再选一次，撞上同一个坏 SK，起不到「立马换线」效果
            $ch['rotate_idx'] = (int)($ch['_sk_index'] ?? 0) + 1;
            $ch = channel_pick_sk($ch);
        }
        // 每次都基于当前SK重新构造请求（换了api_key必须重构headers）
        $构造Payload = $payload;
        $构造Payload['cache_prompt'] = $缓存开 ? 1 : 0;
        if ($isClaude) {
            // Anthropic：system 必须提到顶层，且不认 stream_options
            $reqBody = upstream_to_claude($构造Payload);
            $headers = [
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'x-api-key: ' . trim($ch['api_key']),
                'anthropic-version: 2023-06-01',
            ];
        } else {
            unset($构造Payload['cache_prompt']);   // 非 Claude 的上游不认这个字段，别发过去
            unset($构造Payload['thinking']);       // Anthropic 的 thinking 字段 OpenAI 上游不认，reasoning_effort 才是标准参数
            $构造Payload['stream_options'] = ['include_usage' => true];
            $reqBody = $构造Payload;
            $headers = [
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'Authorization: Bearer ' . trim($ch['api_key']),
            ];
        }
        $json = json_encode($reqBody, JSON_UNESCAPED_UNICODE);
        $url  = upstream_url($ch);

        $buffer  = '';        // 跨 chunk 的行缓冲
        $rawHead = '';        // 非 SSE 响应的正文（用于抓错误详情）
        $errMsg  = '';
        $sawSse  = false;
        $本轮累计文本 = '';   // 本轮尝试累计拿到的正文（用于跳过已吐）
        $本轮累计think = '';  // 本轮尝试累计拿到的思考文本（用于跳过已吐），与正文分开累计
        $本轮usage   = [];   // 本轮拿到的 usage，失败就丢弃，成功就并入 $累计usage

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            // 流式请求不能设总耗时上限。思考型模型在吐 reasoning 阶段可能几分钟都不出正文，
            // 原先的 CURLOPT_TIMEOUT（被硬顶在 60s）会在数据正常流入时把连接掐断，
            // 表现为「已吐 0 字 + Operation timed out after 60s with N bytes received」，
            // 随后重试又从头再来，总耗时突破 FPM 的 request_terminate_timeout 就变成 network error。
            // 改为「静默判定」：只要上游还在吐字节就一直等，连续 $静默上限 秒收不到任何数据才算断线。
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => max(15, min((int) $ch['timeout'], 30)),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (
                &$buffer, &$errMsg, &$rawHead, &$sawSse, &$aborted,
                &$本轮累计文本, &$已吐字节, &$本轮累计think, &$已吐字节think, &$本轮usage, &$toolCalls,
                $onDelta, $onThink, $onUsage, $onToolCall
            ) {
                if ($aborted) {
                    return 0;
                }
                if (strlen($rawHead) < 8192) {
                    $rawHead .= substr($chunk, 0, 8192 - strlen($rawHead));
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "
")) !== false) {
                    $line   = rtrim(substr($buffer, 0, $pos), "
");
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || strncmp($line, ':', 1) === 0) {
                        continue;
                    }
                    if (strncmp($line, 'event:', 6) === 0) {
                        $sawSse = true;
                        continue;
                    }
                    if (strncmp($line, 'data:', 5) !== 0) {
                        // 非 SSE 行（通常是错误 JSON）
                        $j = json_decode($line, true);
                        if (is_array($j) && isset($j['error'])) {
                            $errMsg = is_array($j['error'])
                                ? ($j['error']['message'] ?? '上游返回错误')
                                : (string) $j['error'];
                        }
                        continue;
                    }
                    $sawSse = true;
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }
                    $j = json_decode($data, true);
                    if (!is_array($j)) {
                        continue;
                    }
                    if (isset($j['error'])) {
                        $errMsg = is_array($j['error'])
                            ? ($j['error']['message'] ?? '上游返回错误')
                            : (string) $j['error'];
                        continue;
                    }
                    if (!empty($j['usage']) && is_array($j['usage'])) {
                        $us = $j['usage'];
                        $cached = (int) ($us['prompt_tokens_details']['cached_tokens'] ?? 0);
                        if ($cached > 0) {
                            $us['cached_tokens'] = $cached;
                        }
                        $本轮usage = array_merge($本轮usage, $us);
                        $onUsage($us);
                    }

                    $type = $j['type'] ?? '';
                    if ($type !== '') {
                        // ---- Anthropic 原生事件 ----
                        if ($type === 'content_block_start') {
                            // Claude 的工具调用在 content_block_start 中完整给出
                            $block = $j['content_block'] ?? [];
                            if (($block['type'] ?? '') === 'tool_use') {
                                $toolCalls[] = [
                                    'id' => $block['id'] ?? '',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => $block['name'] ?? '',
                                        'arguments' => json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE)
                                    ]
                                ];
                            }
                        } elseif ($type === 'content_block_delta') {
                            $d = $j['delta'] ?? [];
                            $thinkText = $d['thinking'] ?? '';
                            $bodyText  = $d['text'] ?? '';
                            // 思考文本：有独立回调就单走 onThink，没有就并入正文流（老行为兜底）
                            if ($thinkText !== '' && $thinkText !== null) {
                                if ($onThink !== null) {
                                    $本轮累计think .= $thinkText;
                                    if (strlen($本轮累计think) > $已吐字节think) {
                                        $新增think = substr($本轮累计think, $已吐字节think);
                                        $已吐字节think = strlen($本轮累计think);
                                        if ($onThink((string) $新增think) === false) {
                                            $aborted = true;
                                            return 0;
                                        }
                                    }
                                } else {
                                    $bodyText = $thinkText . $bodyText;
                                }
                            }
                            if ($bodyText !== '' && $bodyText !== null) {
                                $本轮累计文本 .= $bodyText;
                                // 跳过已吐字节：只把本轮新增的尾部交给回调
                                if (strlen($本轮累计文本) > $已吐字节) {
                                    $新增 = substr($本轮累计文本, $已吐字节);
                                    $已吐字节 = strlen($本轮累计文本);
                                    if ($onDelta((string) $新增) === false) {
                                        $aborted = true;
                                        return 0;
                                    }
                                }
                            }
                        } elseif ($type === 'message_start') {
                            $u = upstream_norm_usage($j['message']['usage'] ?? []);
                            if ($u) {
                                $本轮usage = array_merge($本轮usage, $u);
                                $onUsage($u);
                            }
                        } elseif ($type === 'message_delta') {
                            $u = upstream_norm_usage($j['usage'] ?? []);
                            if ($u) {
                                $本轮usage = array_merge($本轮usage, $u);
                                $onUsage($u);
                            }
                        } elseif ($type === 'error') {
                            $e = $j['error'] ?? [];
                            $errMsg = is_array($e) ? ($e['message'] ?? '上游返回错误') : (string) $e;
                        }
                        continue;
                    }

                    // ---- OpenAI 兼容事件 ----
                    $d = $j['choices'][0]['delta'] ?? [];
                    
                    // 工具调用：OpenAI 流式返回时，tool_calls 是增量式的
                    if (isset($d['tool_calls']) && is_array($d['tool_calls'])) {
                        foreach ($d['tool_calls'] as $tc) {
                            $idx = (int) ($tc['index'] ?? 0);
                            if (!isset($toolCalls[$idx])) {
                                $toolCalls[$idx] = [
                                    'id' => $tc['id'] ?? '',
                                    'type' => $tc['type'] ?? 'function',
                                    'function' => [
                                        'name' => '',
                                        'arguments' => ''
                                    ]
                                ];
                            }
                            if (isset($tc['function']['name'])) {
                                $toolCalls[$idx]['function']['name'] .= $tc['function']['name'];
                            }
                            if (isset($tc['function']['arguments'])) {
                                $toolCalls[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                            }
                        }
                    }
                    
                    $thinkText = $d['reasoning_content'] ?? '';
                    $bodyText  = $d['content'] ?? '';
                    // 思考文本：有独立回调就单走 onThink，没有就并入正文流（老行为兜底）
                    if ($thinkText !== '' && $thinkText !== null) {
                        if ($onThink !== null) {
                            $本轮累计think .= $thinkText;
                            if (strlen($本轮累计think) > $已吐字节think) {
                                $新增think = substr($本轮累计think, $已吐字节think);
                                $已吐字节think = strlen($本轮累计think);
                                if ($onThink((string) $新增think) === false) {
                                    $aborted = true;
                                    return 0;
                                }
                            }
                        } else {
                            $bodyText = $thinkText . $bodyText;
                        }
                    }
                    if ($bodyText !== '' && $bodyText !== null) {
                        $本轮累计文本 .= $bodyText;
                        if (strlen($本轮累计文本) > $已吐字节) {
                            $新增 = substr($本轮累计文本, $已吐字节);
                            $已吐字节 = strlen($本轮累计文本);
                            if ($onDelta((string) $新增) === false) {
                                $aborted = true;
                                return 0;
                            }
                        }
                    }
                }
                return strlen($chunk);
            },
        ]);

        // 进度回调：curl 在等数据的间隙也会调它（约每秒一次），
        // 所以上游还在思考、一个字都没吐的时候也能发现「用户点了暂停」。
        if ($onIdle !== null) {
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            curl_setopt($curl, CURLOPT_PROGRESSFUNCTION,
                function ($c, $dlTotal, $dlNow, $upTotal, $upNow) use (&$aborted, $onIdle) {
                    if ($aborted) {
                        return 1;
                    }
                    if ($onIdle() === true) {
                        $aborted = true;
                        return 1;
                    }
                    return 0;
                });
        }

        $ok       = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($curl);
        $curlErr   = curl_error($curl);

        $lastCode = $httpCode;

        // 用户主动暂停：不算错误不算重试
        if ($aborted) {
            return ['ok' => true, 'error' => '', 'attempts' => $attempt,
                    'http' => $httpCode, 'aborted' => true];
        }

        // ---- 判定这一次是否成功 ----
        // 每轮先清空：上一轮的 detail 不能漏到这一轮的重试判定里
        $detail = '';
        if ($httpCode >= 400) {
            $detail = upstream_pick_error($rawHead, $httpCode);
            $lastErr = upstream_friendly_error($httpCode, $detail);
        } elseif ($errMsg !== '') {
            $lastErr = $errMsg;
        } elseif ($ok === false) {
            $lastErr = '上游连接中断：' . ($curlErr !== '' ? $curlErr : '未知错误');
        } else {
            $lastErr = '';
            // 本轮成功：把本轮 usage 并入累计（上层会收到重复 merge 也没关系，幂等）
            if ($本轮usage) {
                $累计usage = array_merge($累计usage, $本轮usage);
            }
        }

        if ($lastErr === '') {
            // 成功：标记当前SK恢复健康
            if (!empty($ch['id']) && isset($ch['_sk_index'])) {
                try { channel_sk_ok((int)$ch['id'], (int)$ch['_sk_index']); } catch (Throwable $e) {}
            }
            // 工具调用收集完成，触发回调
            if (!empty($toolCalls) && $onToolCall !== null) {
                $onToolCall(array_values($toolCalls));
            }
            return [
                'ok' => true, 
                'error' => '', 
                'attempts' => $attempt, 
                'http' => $httpCode,
                'tool_calls' => array_values($toolCalls)
            ];
        }

        // 失败且属于 SK 可切换故障：立即熔断当前 SK，下次 channel_pick_sk 会跳过它，
        // 让重试能真正落到「下一个健康的 SK」上，而不是下一次又撞上同一个坏 key。
        if ($httpCode >= 400 && in_array($httpCode, upstream_sk_switchable_codes(), true)
            && !empty($ch['id']) && isset($ch['_sk_index'])) {
            try { channel_sk_fail((int)$ch['id'], (int)$ch['_sk_index'], $httpCode, $detail); } catch (Throwable $e) {}
        }

        // 429/401/402/403 这类「渠道级」故障：整个渠道的 SK 全被熔断时，
        // 换 SK 也是白等（如 tokenrhythm 限流是账号/IP 级，182 个 SK 全是同一个结果）。
        // 直接放弃重试，避免 20 次空转拖垮 FPM worker；5xx 瞬时故障不受影响，仍走正常重试。
        if ($i > 0 && in_array($httpCode, [429, 401, 402, 403], true)) {
            $冷却数 = count(channel_sk_cooled((int)$ch['id']));
            $总SK数 = (int)($ch['_sk_total'] ?? 0);
            if ($总SK数 > 1 && $冷却数 >= $总SK数) {
                break;
            }
        }

        // ---- 是否重试 ----
        $应重试 = upstream_should_retry($httpCode, $curlErrno, $detail)
               || in_array($httpCode, upstream_sk_switchable_codes(), true);

        // 已吐正文后不再重试：换 SK 后上游会从头生成不同的内容，
        // $已吐字节 跳过前 N 字节的续接逻辑会导致内容丢失/错乱。
        // 只有还没吐正文（0 字）时才换 SK 重试。
        if ($已吐字节 > 0 || $已吐字节think > 0) {
            break;
        }

        // 总耗时上限：429/402 秒回时不受影响，只防个别 SK 连接挂起拖垮 FPM
        if ((microtime(true) - $重试起始时间) > $重试总时限) {
            break;
        }

        if ($i >= $最大重试 || !$应重试) {
            break;
        }
        // 换 SK 秒切：固定 200ms，不指数退避
        usleep(200000);
        error_log(sprintf('[upstream] 第 %d 次失败(HTTP %d, 已吐 %d 字)，换下一个 SK 重试：%s',
            $attempt, $httpCode, $已吐字节, mb_substr($lastErr, 0, 200)));
    }

    // 已吐了正文但最终失败：算部分成功，不返回错误，
    // 让客户端保留已收到的内容，而不是整段丢弃。
    $部分成功 = ($已吐字节 > 0 || $已吐字节think > 0);

    return [
        'ok'       => $部分成功,
        'error'    => $部分成功 ? '' : $lastErr,
        'attempts' => $attempt,
        'http'     => $lastCode,
        'tool_calls' => array_values($toolCalls),
    ];
}

/** 非流式调用，用于后台「测试连通性」 */
function upstream_test(array $ch, string $model, int $timeout = 45): array
{
    $curl = curl_init(upstream_url($ch));
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => $model,
            'messages'   => [['role' => 'user', 'content' => 'hi']],
            'max_tokens' => 16,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => upstream_is_claude($ch)
            ? [
                'Content-Type: application/json',
                'x-api-key: ' . trim($ch['api_key']),
                'anthropic-version: 2023-06-01',
            ]
            : [
                'Content-Type: application/json',
                'Authorization: Bearer ' . trim($ch['api_key']),
            ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $cerr = curl_error($curl);

    if ($body === false) {
        // 连接层失败（超时/DNS/SSL等）没有真实 HTTP 码，curl 也不会返回，保持 0
        // 但要把 curl 的错误信息带出来，别让人误以为是上游返回了 0
        return ['ok' => false, 'http' => 0, 'msg' => '连接失败：' . $cerr];
    }
    $j = json_decode($body, true);
    if ($code >= 400 || isset($j['error'])) {
        $m = $j['error']['message'] ?? ('HTTP ' . $code);
        return ['ok' => false, 'http' => $code, 'msg' => '上游报错：' . mb_substr((string) $m, 0, 200)];
    }
    // Anthropic 回的是 content[0].text，OpenAI 回的是 choices[0].message.content
    $reply = $j['choices'][0]['message']['content'] ?? ($j['content'][0]['text'] ?? '');
    return ['ok' => true, 'http' => $code, 'msg' => '连通正常，回复：' . mb_substr((string) $reply, 0, 60)];
}
