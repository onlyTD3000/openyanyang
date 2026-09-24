<?php
/**
 * OpenAI 格式的请求体转 Anthropic 原生格式。
 * 差异点：
 *  - system 消息要从 messages 里摘出来，放到顶层 system 字段
 *  - 图片部分 image_url + data URL 要拆成 source{type:base64,media_type,data}
 *  - Anthropic 要求 max_tokens 必填
 */

/** 把 data URL 拆成 [media_type, base64 数据]，失败返回 null */
function claude_split_data_url(string $url): ?array
{
    if (strncmp($url, 'data:', 5) !== 0) {
        return null;
    }
    $comma = strpos($url, ',');
    if ($comma === false) {
        return null;
    }
    $meta = substr($url, 5, $comma - 5);       // 形如 image/png;base64
    $data = substr($url, $comma + 1);
    if (stripos($meta, 'base64') === false) {
        return null;
    }
    $mime = strtolower(trim(explode(';', $meta)[0]));
    $allow = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allow, true) || $data === '') {
        return null;
    }
    return [$mime, $data];
}

/** 单条消息的 content 转 Anthropic 的 content 数组 */
function claude_convert_content($content)
{
    if (!is_array($content)) {
        return (string) $content;
    }
    $out = [];
    foreach ($content as $part) {
        $type = $part['type'] ?? '';
        if ($type === 'text') {
            $t = (string) ($part['text'] ?? '');
            if ($t !== '') {
                $out[] = ['type' => 'text', 'text' => $t];
            }
        } elseif ($type === 'image_url') {
            $url = (string) ($part['image_url']['url'] ?? '');
            $sp  = claude_split_data_url($url);
            if ($sp !== null) {
                $out[] = [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $sp[0],
                        'data'       => $sp[1],
                    ],
                ];
            }
        }
    }
    return $out;
}

/** 整个 payload 转 Anthropic 格式 */
function upstream_to_claude(array $p): array
{
    $sys  = [];
    $msgs = [];
    foreach (($p['messages'] ?? []) as $m) {
        $role = $m['role'] ?? 'user';
        if ($role === 'system') {
            $c = $m['content'] ?? '';
            if (is_string($c) && trim($c) !== '') {
                $sys[] = $c;
            }
            continue;
        }
        
        // 处理 tool 消息
        if ($role === 'tool') {
            $msgs[] = [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $m['tool_call_id'] ?? '',
                        'content' => $m['content'] ?? ''
                    ]
                ]
            ];
            continue;
        }
        
        $conv = claude_convert_content($m['content'] ?? '');
        
        // 处理 assistant 消息中的 tool_calls
        if ($role === 'assistant' && !empty($m['tool_calls'])) {
            $content = [];
            
            // 先添加文本内容（如果有）
            if ($conv !== '' && $conv !== []) {
                if (is_string($conv)) {
                    $content[] = ['type' => 'text', 'text' => $conv];
                } else {
                    $content = array_merge($content, $conv);
                }
            }
            
            // 添加工具调用
            foreach ($m['tool_calls'] as $tc) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $tc['id'] ?? '',
                    'name' => $tc['function']['name'] ?? '',
                    'input' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: []
                ];
            }
            
            $msgs[] = ['role' => 'assistant', 'content' => $content];
            continue;
        }
        
        // 内容为空的消息 Anthropic 会报错，跳过
        if ($conv === '' || $conv === []) {
            continue;
        }
        $msgs[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $conv];
    }

    // ====== 断点：SYSTEM + 最后 3 条 assistant，共 4 个（Anthropic 上限）======
    // 不能按绝对下标（原 msgs[1]/[5]/[9]）挂：$msgs 由 api/chat.php 裁剪后的窗口
    // 重建，下标是窗口内相对位置。窗口起点一挪，同一条历史消息的下标就变，断点
    // 跟着漂移，前缀对不上。且绝对下标最远只到第 10 条，更长对话后面全价重算。
    // 改挂最后 3 条 assistant 末尾：
    //   - Anthropic 从最靠后的断点往前找最长匹配前缀
    //   - 最新那条含整段历史，本轮写入、下一轮读取
    //   - 中间那条正是上一轮写入的位置，本轮直接命中
    //   - 最老那条兜底，防某轮历史不严格交替时前两个错位
    // 残留代价：窗口起点挪动那轮前缀必变，缓存失效一次。滑动窗口的固有限制，
    // 只能靠加大 api/chat.php 的 $历史窗口块 降低频率。
    if (!empty($p['cache_prompt'])) {
        // 先把所有消息统一转成块数组：挂断点与不挂断点保持同一结构。
        // 否则同一条历史消息本轮挂断点（转成数组）、下一轮不挂（仍是字符串），
        // 结构在字符串和数组之间翻转，前缀就对不上，缓存读不中。
        // 空字符串不转：Anthropic 拒收 text 为空的块。
        foreach ($msgs as $i => $m) {
            if (is_string($m['content']) && $m['content'] !== '') {
                $msgs[$i]['content'] = [['type' => 'text', 'text' => $m['content']]];
            }
        }
        $助手位 = [];
        foreach ($msgs as $i => $m) {
            if (($m['role'] ?? '') === 'assistant') { $助手位[] = $i; }
        }
        foreach (array_slice($助手位, -3) as $idx) {
            $c = $msgs[$idx]['content'];
            if (is_string($c)) { $c = [['type' => 'text', 'text' => $c]]; }
            if (!is_array($c) || !$c) { continue; }
            $last = count($c) - 1;
            if (!is_array($c[$last])) { continue; }
            $c[$last]['cache_control'] = ['type' => 'ephemeral'];
            $msgs[$idx]['content'] = $c;
        }
    }

    $out = [
        'model'      => $p['model'] ?? '',
        'messages'   => $msgs,
        'max_tokens' => max(1, (int) ($p['max_tokens'] ?? 4096)),
        'stream'     => true,
    ];
    if ($sys) {
        $sysText = implode("\n", $sys);
        // 显式开启提示词缓存：Anthropic 不会自动缓存，必须自己在要缓存的块上挂
        // cache_control。系统提示词（平台准则 + 项目档案 + 远程执行说明）每轮都一样，
        // 是最值得缓存的部分。命中后这段按 cache_read 计价，比原价便宜一个量级。
        // 门槛：内容太短上游不给缓存，反而白搭一次 cache_creation 的写入费，所以短的就不挂。
        if (!empty($p['cache_prompt']) && mb_strlen($sysText) >= 3000) {
            $out['system'] = [[
                'type'          => 'text',
                'text'          => $sysText,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        } else {
            $out['system'] = $sysText;
        }
    }
    if (isset($p['temperature'])) {
        $out['temperature'] = $p['temperature'];
    }
    // Anthropic 原生参数：thinking 原样透传（{type:"enabled", budget_tokens:N}）
    if (isset($p['thinking'])) {
        $out['thinking'] = $p['thinking'];
    }
    
    // 转换 tools 定义
    if (!empty($p['tools'])) {
        $out['tools'] = [];
        foreach ($p['tools'] as $tool) {
            if ($tool['type'] === 'function') {
                $fn = $tool['function'];
                $out['tools'][] = [
                    'name' => $fn['name'] ?? '',
                    'description' => $fn['description'] ?? '',
                    'input_schema' => $fn['parameters'] ?? ['type' => 'object', 'properties' => []]
                ];
            }
        }
    }
    
    return $out;
}

/**
 * Anthropic 的 usage 字段名转成 OpenAI 那套，方便上层统一计费。
 * 注意：input_tokens 在 message_start 事件里给，output_tokens 在 message_delta 里给，
 * 所以这里只输出非零字段，由上层做累加合并，避免后一次把前一次覆盖成 0。
 *
 * cache_read_input_tokens 单独用 cached_tokens 带出去（字段名跟 OpenAI 对齐），
 * 上层按模型配的缓存价单独结算这部分。prompt_tokens 仍是总量，
 * 别在这里先减掉，否则上游只给缓存读、没给 input_tokens 时总量会算少。
 * cache_creation 单独用 cache_create_tokens 带出去，上层按模型配的
 * 缓存创建价(5m)结算。没配缓存创建价的模型，这部分照输入价收。
 * prompt_tokens 仍是总量（含 cache_creation 和 cache_read），
 * 上层计费时按类型拆开算。
 */
function upstream_norm_usage(array $u): array
{
    $out = [];
    $read = (int) ($u['cache_read_input_tokens'] ?? 0);
    $create = (int) ($u['cache_creation_input_tokens'] ?? 0);
    $in  = (int) ($u['input_tokens'] ?? 0)
         + $create
         + $read;
    $out2 = (int) ($u['output_tokens'] ?? 0);
    if ($in > 0) {
        $out['prompt_tokens'] = $in;
    }
    if ($out2 > 0) {
        $out['completion_tokens'] = $out2;
    }
    if ($read > 0) {
        $out['cached_tokens'] = $read;
    }
    if ($create > 0) {
        $out['cache_create_tokens'] = $create;
    }
    return $out;
}
