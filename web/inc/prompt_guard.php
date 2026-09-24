<?php
/**
 * 提示词套取防护（Prompt Guard）
 *
 * 检测用户消息是否包含让 AI 忘记／绕过／获取系统提示词的指令。
 * 规则：24 小时滚动窗口内命中 3 次，自动将账号 status 改为 0（封禁）。
 * 每次命中均写入 prompt_guard_logs，供管理员复核。
 */

/** 24 小时窗口（秒） */
const PROMPT_GUARD_WINDOW = 86400;

/** 第几次触发时封禁 */
const PROMPT_GUARD_BAN_AT = 3;

/**
 * 所有检测规则。
 * 每条：['name' => '规则标识', 'patterns' => [...]]
 * 以 / 开头的字符串视为正则表达式，其余用 mb_stripos 做子串匹配。
 */
function prompt_guard_rules(): array
{
    return [
        // 规则1：命令 AI 忽略/忘记自己的指令或提示词
        [
            'name' => 'ignore_instructions',
            'patterns' => [
                // 中文：忽略/无视/忘记 + 你的/所有/以上 + 指令/规则/提示词/限制
                '/忽略(你|您)?(之前|以前|原来|现在|上面|前面)?(的)?(所有的?|全部的?)?(指令|规则|提示词|提示|限制|设定)/u',
                '/无视你(之前|以前|原来|现在)?(的|所有的)?(指令|规则|提示词|提示|限制|设定)/u',
                '/忘(记|掉)你(之前|以前|原来|现在|上面)?(的|所有的)?(指令|提示词|提示|规则|设定|身份|角色|限制)/u',
                '/忽略(以上|上面|前面|所有)(的|这些|这条|这些条)?(指令|规则|提示|限制|约束|设定)/u',
                '/不(要|用|再)(遵守|遵循|受|听从|考虑).{0,8}(指令|规则|提示词|限制|约束|设定)/u',
                '/清(空|除|除掉).{0,8}(你的|之前|以前|所有)?.{0,5}(记忆|提示词|指令|设定|上下文)/u',
                // 英文常见套取短语
                '/ignore (?:your|all|the|previous|prior|any)(?:\s+(?:your|all|the|previous|prior|any|past))?\s+(instructions?|rules?|guidelines?|prompts?|constraints?|directives?)/i',
                '/forget (your|all|the|previous|prior|any) (instructions?|rules?|guidelines?|prompts?|constraints?|directives?)/i',
                '/disregard (your|all|the|previous|prior|any) (instructions?|rules?|guidelines?|prompts?|constraints?|directives?)/i',
                '/override (your|the) (system.?prompt|instructions?|rules?|guidelines?|constraints?)/i',
            ],
        ],
        // 规则2：要求 AI 复述/输出系统提示词原文
        [
            'name' => 'reveal_prompt',
            'patterns' => [
                '/(输出|打印|复述|重复|背诵|告诉我|说出|写出|展示).{0,15}(你的|系统|完整|原始|全部|初始).{0,8}(提示词|system\s*prompt|指令|初始设定|规则)/u',
                '/(print|output|repeat|recite|reveal|show|tell me|display|write out).{0,20}(your|the|full|complete|original|initial).{0,8}(system\s*prompt|instructions?|initial prompt|directives?)/i',
                '/what (is|are|was) (your|the) system\s*prompt/i',
                '/你的系统提示词是(什么|哪些|怎么写的|怎样的)/',
                '/你的(初始|原始|完整|全部)(提示|指令|设定|规则)是(什么|哪些|怎么写的)/',
            ],
        ],
        // 规则3：越狱专属触发词
        [
            'name' => 'jailbreak_mode',
            'patterns' => [
                '/\bDAN\b/',
                '/developer\s*mode/i',
                '/开发者模式/',
                '/jailbreak/i',
                '/越狱模式/',
                '/无限制模式/',
                '/god\s*mode/i',
                '/上帝模式/',
                '/unrestricted\s*mode/i',
                '/do\s*anything\s*now/i',
                '/奶奶漏洞/',
                '/奶奶模式/',
            ],
        ],
        // 规则4：身份替换（你现在是另一个没有限制的 AI）
        [
            'name' => 'identity_override',
            'patterns' => [
                '/你现在是(一个)?(没有|不受|无|不再有).{0,10}(限制|约束|规则|道德|伦理|过滤).{0,5}(的)?(AI|模型|助手|机器人)/u',
                '/从现在起你是.{0,20}(没有|不受|无).{0,8}(限制|约束|规则)/u',
                '/you are now (an? )?(unrestricted|limitless|free|uncensored|unfiltered).{0,15}(AI|model|assistant|bot)/i',
                '/pretend (you have|you are).{0,20}(no restriction|no rule|no limit|no guideline|no filter)/i',
                '/act as.{0,20}(without|ignoring|no).{0,12}(restriction|limit|rule|guideline|filter|constraint)/i',
            ],
        ],
    ];
}

/**
 * 检测 $content 是否命中任意规则。
 *
 * @return array{rule:string,excerpt:string}|null  命中时返回规则名和原文片段，否则 null
 */
function prompt_guard_detect(string $content): ?array
{
    foreach (prompt_guard_rules() as $rule) {
        foreach ($rule['patterns'] as $pat) {
            if ($pat[0] === '/') {
                $hit = (bool) @preg_match($pat, $content);
            } else {
                $hit = mb_stripos($content, $pat) !== false;
            }
            if ($hit) {
                return [
                    'rule'    => (string) $rule['name'],
                    'excerpt' => mb_substr($content, 0, 400),
                ];
            }
        }
    }
    return null;
}

/**
 * 提示词防护主入口。
 * 未命中直接返回，不影响正常流程。
 * 命中则：记录日志 → 第1/2次返回警告 JSON → 第3次封禁账号并返回封禁 JSON。
 * 两种情况都调用 json_out() 终止请求，消息不会送达 AI。
 *
 * @param array  $me      require_login_api() 返回的用户行（需含 id）
 * @param int    $convId  当前对话 ID（0 表示未知）
 * @param string $content 用户本次输入（已 trim）
 */
function prompt_guard_check(array $me, int $convId, string $content): void
{
    $hit = prompt_guard_detect($content);
    if ($hit === null) {
        return; // 未命中，正常放行
    }

    $uid = (int) $me['id'];

    // 取客户端真实 IP（兼容 CDN / 反向代理）
    $ip = (string) (
        $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? ''
    );
    $ip = trim(explode(',', $ip)[0]);

    // 统计本用户在 24 小时滚动窗口内的违规次数
    $窗口开始 = date('Y-m-d H:i:s', time() - PROMPT_GUARD_WINDOW);
    $row = db_one(
        'SELECT COUNT(*) AS c FROM prompt_guard_logs WHERE user_id = ? AND created_at >= ?',
        [$uid, $窗口开始]
    );
    $pastCount = $row ? (int) $row['c'] : 0;
    $strikeNo  = $pastCount + 1; // 本次是窗口内第几次
    $willBan   = ($strikeNo >= PROMPT_GUARD_BAN_AT);

    // 先写日志，再执行封禁，确保记录留存
    db_exec(
        'INSERT INTO prompt_guard_logs
             (user_id, conv_id, hit_rule, excerpt, strike_no, banned, ip, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
        [$uid, $convId, $hit['rule'], $hit['excerpt'], $strikeNo, (int) $willBan, $ip]
    );

    if ($willBan) {
        // 第三次及以上：永久封禁账号
        db_exec('UPDATE users SET status = 0 WHERE id = ?', [$uid]);
        db_exec(
            'INSERT INTO user_ban_logs
                 (user_id, admin_id, action, reason, source, ip, created_at)
             VALUES (?, 0, ?, ?, ?, ?, NOW())',
            [
                $uid,
                'ban',
                sprintf('提示词套取防护自动封禁：24小时内第%d次触发规则 [%s]，原文片段：%s',
                    $strikeNo, $hit['rule'], mb_substr($hit['excerpt'], 0, 100)),
                'prompt_guard',
                $ip,]
        );
        json_out([
            'error'  => '⚠️ 您的账号因多次试图破坏 AI 安全规则已被封禁，如有疑问请联系客服处理。',
            'banned' => true,
        ], 403);
    }

    // 第一次或第二次：警告，本次消息不送达 AI
    $remaining = PROMPT_GUARD_BAN_AT - $strikeNo;
    json_out([
        'error' => sprintf(
            '⚠️ 您的消息包含试图绕过 AI 安全规则的内容，本次请求已被拒绝。'
            . '（第 %d 次警告，再触发 %d 次将封禁账号）',
            $strikeNo,
            $remaining
        ),
        'guard_strike' => $strikeNo,
    ], 403);
}