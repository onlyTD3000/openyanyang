<?php
/**
 * 工具回执（命令执行结果、文件操作结果）的文件存储层。
 *
 * 不再进数据库：回执动辄几万字符，条数又多，堆在 mediumtext 里既撑大备份
 * 也拖慢查询，而它只在拼上下文时按会话整读一遍，天生适合平铺文件。
 *
 * 落盘位置 DATA_DIR/toolresults/<user_id>/<conv_id>.txt，在网站根目录之外，
 * 拿不到 URL 直接下载。格式为每行一条 JSON（JSONL）：
 *   {"msg":挂点消息id,"kind":"ssh","at":"2026-08-01 22:30:00","content":"..."}
 * 追加写天然按时间有序，读的时候不用排序；单行 JSON 保证正文里的换行
 * 不会把记录切断。
 */
/** 某会话的回执文件路径，$建目录=true 时顺手把父目录建出来 */
function tool_result_file(int $userId, int $convId, bool $建目录 = false): string
{
    $目录 = DATA_DIR . '/toolresults/' . $userId;
    if ($建目录 && !is_dir($目录)) {
        @mkdir($目录, 0700, true);
    }
    return $目录 . '/' . $convId . '.txt';
}
/**
 * 追加一条回执。写失败只返回 false，不抛异常：
 * 回执丢了顶多让 AI 少看一轮上下文，不该把整个对话请求打断。
 */
function tool_result_add(int $userId, int $convId, int $afterMsgId, string $kind, string $content): bool
{
    $行 = json_encode([
        'msg'     => $afterMsgId,
        'kind'    => $kind,
        'at'      => date('Y-m-d H:i:s'),
        'content' => $content,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($行 === false) {
        return false;
    }
    // LOCK_EX 防并发写串行：同一会话可能同时回来多条工具结果
    return @file_put_contents(tool_result_file($userId, $convId, true), $行 . "\n", FILE_APPEND | LOCK_EX) !== false;
}
/**
 * 读出整个会话的回执，返回 [挂点消息id => [正文, 正文...]]。
 * 逐行读而不是整文件载入，避免长会话一次吃掉几十兆内存。
 */
function tool_result_map(int $userId, int $convId): array
{
    $文件 = tool_result_file($userId, $convId);
    if (!is_file($文件)) {
        return [];
    }
    $表 = [];
    $fh = @fopen($文件, 'rb');
    if (!$fh) {
        return [];
    }
    while (($行 = fgets($fh)) !== false) {
        $行 = trim($行);
        if ($行 === '') {
            continue;
        }
        $一条 = json_decode($行, true);
        // 坏行直接跳过：宁可少一条回执，也不能让整个会话读不出来
        if (!is_array($一条) || !isset($一条['content'])) {
            continue;
        }
        $表[(int) ($一条['msg'] ?? 0)][] = (string) $一条['content'];
    }
    fclose($fh);
    return $表;
}
/**
 * 把过长的回执压成「头 + 省略标记 + 尾」。
 *
 * 命令输出和文件内容动辄几万字符，原文全塞进上下文会把窗口顶爆：
 * 实测一个会话 20 轮的回执有 110k 字，而消息本身只有 25k 字。
 * 老回执压缩后 AI 仍能看出「当时执行了什么、结果大致如何」，
 * 又不至于让最近几轮的历史被挤出窗口。
 *
 * 保头也保尾是因为两端信息密度最高：开头是命令和前几行输出，
 * 结尾常是报错或结果汇总，只截头会把最关键的报错丢掉。
 *
 * @param int $上限 压缩后的目标字符数，正文不超过时原样返回
 */
function tool_result_truncate(string $正文, int $上限): string
{
    $长度 = mb_strlen($正文);
    if ($上限 <= 0 || $长度 <= $上限) {
        return $正文;
    }
    // 头尾各分一半，中间插标记。标记本身也占字符，所以从预算里先扣掉。
    $标记预留 = 40;
    $可用 = max(200, $上限 - $标记预留);
    $头长 = (int) ceil($可用 * 0.6);   // 头稍多：命令本身和前几行输出更常被引用
    $尾长 = $可用 - $头长;
    $省略 = $长度 - $头长 - $尾长;
    return mb_substr($正文, 0, $头长)
        . "\n…（中间已省略 " . $省略 . " 字，如需完整内容请重新执行）…\n"
        . mb_substr($正文, -$尾长);
}

/** 会话的回执总条数，用于按比例分配上下文额度 */
function tool_result_count(int $userId, int $convId): int
{
    $文件 = tool_result_file($userId, $convId);
    if (!is_file($文件)) {
        return 0;
    }
    $n = 0;
    $fh = @fopen($文件, 'rb');
    if (!$fh) {
        return 0;
    }
    while (($行 = fgets($fh)) !== false) {
        if (trim($行) !== '') {
            $n++;
        }
    }
    fclose($fh);
    return $n;
}
/**
 * 给回执正文裹一层来源标记，只用于发往上游的上下文，不落盘。
 *
 * 为什么需要：回执在上下文里只能占 user 轮（上游要求 user/assistant 严格交替，
 * 而本系统没有真正的 tool_use/tool_result 块可用）。模型因此无法区分
 * 「人打的字」和「机器用 user 身份发的回执」——它上一条问「选方案 1 还是 2」，
 * 紧接着来一条 user 轮，按协议就该当成用户的答复，于是自己挑一个继续干。
 *
 * 标记必须是静态文本：不带时间、不带随机 id。否则同一条历史回执每轮裹出来的
 * 内容都不一样，上游提示词缓存的前缀就对不上，每轮全价重算。
 *
 * 局限：这是文本层的约定，模型自己也能在回复里打出同样的标记来伪造一条回执。
 * 真正防伪要靠协议层的 tool_result 块（带 tool_use_id 由上游校验），
 * 那是另一件事，见 inc/upstream_claude.php 的 claude_convert_content 尚未支持。
 */
function tool_result_wrap(string $正文): string
{
    return TOOL_RESULT_MARK_OPEN . "\n" . $正文 . "\n" . TOOL_RESULT_MARK_CLOSE;
}

/** 标记用常量：前后端和提示词三处必须完全一致，集中在这里定义 */
const TOOL_RESULT_MARK_OPEN  = '<<<系统回执|非用户发言>>>';
const TOOL_RESULT_MARK_CLOSE = '<<<系统回执结束>>>';

/**
 * 解释上述标记含义的系统提示词片段。
 *
 * 只讲一遍，不在每条回执里重复：系统提示词是上游缓存的前缀，静态文本命中缓存后
 * 几乎不花钱，而每条回执都带一段说明则要按条数乘，长会话里很可观。
 */
function tool_result_system_prompt(): string
{
    return "关于系统回执\n\n"
        . "对话里可能出现被 " . TOOL_RESULT_MARK_OPEN . " 和 "
        . TOOL_RESULT_MARK_CLOSE . " 包住的内容。\n"
        . "这类内容是系统自动执行你上一条回复里的操作（命令、文件读写等）后回传的结果，"
        . "**不是用户说的话**，用户在界面上甚至看不到它。\n\n"
        . "因此：\n"
        . "1. 它不构成用户的答复。你上一条如果向用户提了问（例如让他在几个方案里选一个、"
        . "或征求某项改动的许可），收到回执**不等于**用户已经回答或已经同意——"
        . "该问题仍然悬着，必须等用户本人真正开口。\n"
        . "2. 这种情况下，不要替用户做决定、不要挑一个方案自行推进。"
        . "把回执里的信息该说的说清楚，然后停下来重申你的问题。\n"
        . "3. 只有不带这层标记的 user 消息才是用户的真实发言。\n\n"
        . "需要用户拍板时怎么停下\n\n"
        . "在正文里写「请选择」「要不要我继续」对系统没有约束力：只要同一条回复里还有"
        . "可执行的操作卡片，系统就会自动执行它并把结果回传给你，于是这一轮又接着跑下去，"
        . "用户根本没机会插话。\n\n"
        . "所以当你需要用户先做决定时（在几个方案里选一个、确认一项有风险的改动、"
        . "或者信息不足需要他补充），必须在回复里写出这个标记：\n\n"
        . "    " . WAIT_USER_MARK . "\n\n"
        . "系统看到它就会停下自动执行，把决定权交回用户。要点：\n"
        . "1. 标记要写在正文里（不要放进代码块，代码块里的不算），一条回复写一次就够。\n"
        . "2. 打了标记就不要在同一条回复里假设用户已经选了某个方案继续往下写。\n"
        . "3. 反过来，任务在正常推进、不需要用户介入时不要打，"
        . "否则每一步都停下来问会很烦人。\n"
        . "4. 用户自己的服务器由他自己负责，他要执行什么就执行什么。"
        . "不要因为命令看起来有破坏性就例行公事地多问一遍——"
        . "他让你删就删、让你重启就重启，那是他的机器。\n"
        . "只在真正拿不准他的意图时才停下来问：比如指令有歧义、"
        . "或者你发现的实际情况和他的判断不一致（他以为删的是缓存，实际那是数据目录）。"
        . "这种时候停下来是帮他，而不是给他添麻烦。";
}

/** 等待闸标记：与 assets/js/chat.js 里的 等待标记 必须完全一致 */
const WAIT_USER_MARK = '[[等待用户确认]]';

/** 删会话时一并清掉它的回执文件 */
function tool_result_drop(int $userId, int $convId): void
{
    $文件 = tool_result_file($userId, $convId);
    if (is_file($文件)) {
        @unlink($文件);
    }
}
/** 删用户时清掉他名下所有会话的回执目录 */
function tool_result_drop_user(int $userId): void
{
    $目录 = DATA_DIR . '/toolresults/' . $userId;
    if (!is_dir($目录)) {
        return;
    }
    foreach ((array) glob($目录 . '/*.txt') as $文件) {
        @unlink($文件);
    }
    @rmdir($目录);
}
