<?php
/**
 * 思考前言的识别与拆分。
 *
 * 背景：部分中转通道把模型的思考过程和正文塞进同一个 text 字段，
 * 字段名一样，解析层分不开，只能按内容判断。
 *
 * 原先的做法是直接剥掉，但只认英文前言，中文的漏过去了；
 * 而且直接剥有误伤风险（正常回答也可能以「首先」开头）。
 * 现在改为拆分 + 折叠：前言不丢，交给前端折起来，用户想看能展开。
 */

/** 折叠块的包裹标记。用方括号是因为 HTML 转义不会改动它，前端转义后仍可匹配。 */
const THINK_OPEN  = '[[思考开始]]';
const THINK_CLOSE = '[[思考结束]]';

/**
 * 把回答拆成「思考前言」与「正文」两段。
 *
 * @param string $txt 模型返回的完整回答
 * @return array{think:string, body:string} 认不出前言时 think 为空串、body 为原文
 */
function split_thinking(string $txt): array
{
    $none = ['think' => '', 'body' => $txt];
    $s = ltrim($txt);
    if ($s === '') {
        return $none;
    }
    // 前置判断：文本里已经原样带着 THINK_OPEN/THINK_CLOSE 字面标记。
    // 出现在这里说明模型自己模仿吐出了这个格式，或者中转通道把上游的
    // reasoning_content 和这对标记一起拼进了正文字段——不是后面启发式
    // 判断要处理的「裸文本自述前言」，得先按标记本身切，不然标记原样
    // 混进正文一起展示给用户，折叠形同虚设。
    $r = split_thinking_marked($s);
    if ($r !== null) {
        return $r;
    }
    // 注意：这里不再检查全文是否含代码块。
    // 前言后面紧跟代码块是最常见的形态（先自述再给命令），
    // 一刀切放行会导致该折叠的没折叠。是否含代码改由各判定函数只检查首段。
    $r = split_thinking_en($s);
    if ($r !== null) {
        return $r;
    }
    $r = split_thinking_zh($s);
    if ($r !== null) {
        return $r;
    }
    return $none;
}

/**
 * 识别文本里字面出现的折叠标记，直接按标记切分。
 *
 * 正常流程里标记是 wrap_thinking() 存库时才加的，模型输出本身不该带它。
 * 一旦模型输出（或中转通道拼接后的结果）里原样出现这对标记，说明思考
 * 内容已经跟正文糊在一起了，不能再指望后面的启发式判断——那些规则只认
 * 「裸文本自述」的语言特征，对着已经带标记的内容反而会误判或漏判。
 *
 * 只取第一对标记：模型一次回答不会自己重复包裹多轮。
 *
 * @return array{think:string, body:string}|null 没有标记返回 null
 */
function split_thinking_marked(string $s): ?array
{
    $开 = strpos($s, THINK_OPEN);
    if ($开 === false) {
        return null;
    }
    $闭 = strpos($s, THINK_CLOSE, $开);
    if ($闭 === false) {
        // 只有开标记没有闭标记，跟 strip_thinking_for_api 的处理逻辑一致：
        // 流式中断留下的残缺内容，标记后面到末尾全算思考，正文为空。
        $think = trim(substr($s, $开 + strlen(THINK_OPEN)));
        return ['think' => $think, 'body' => ''];
    }
    $think = trim(substr($s, $开 + strlen(THINK_OPEN), $闭 - $开 - strlen(THINK_OPEN)));
    $body  = trim(substr($s, 0, $开) . substr($s, $闭 + strlen(THINK_CLOSE)));
    return ['think' => $think, 'body' => $body];
}

/**
 * 英文前言 + 中文正文：找第一个中日韩字符，前面全是英文元叙述即为前言。
 *
 * @return array{think:string, body:string}|null 不匹配返回 null
 */
function split_thinking_en(string $s): ?array
{
    if (!preg_match('/^[A-Za-z]/', $s)) {
        return null;
    }
    $meta = '/^(I\s|I\'m\s|I\'ll\s|The user\s|Let me\s|Looking at\s|Now\s|First,?\s|Okay,?\s|Alright,?\s)/i';
    if (!preg_match($meta, $s)) {
        return null;
    }
    // 找「不在引号内」的第一个中日韩字符：思考里常直接引用用户原话
    // （The user asks "你好" ...），引号里的中文字符不能当分界点，
    // 否则切割点会切进思考内容中途，后面真正的思考正文全被当成回答正文。
    if (!preg_match_all('/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}]/u', $s, $mAll, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $pos = null;
    foreach ($mAll[0] as $hit) {
        $候选 = $hit[1];
        $before = substr($s, 0, $候选);
        // 英文直引号成对出现，之前出现奇数次说明当前正处于引号内
        $直引号内 = substr_count($before, '"') % 2 === 1;
        // 中文弯引号左右分开计数，左比右多说明还没闭合，当前在引号内
        $弯引号内 = substr_count($before, '“') > substr_count($before, '”');
        if ($直引号内 || $弯引号内) {
            continue;
        }
        $pos = $候选;
        break;
    }
    if ($pos === null) {
        return null;
    }
    $head = substr($s, 0, $pos);
    // 前言段自身不能含代码（含了说明这段是正文而非自述）
    if (strpos($head, '`') !== false || $pos > 1200) {
        return null;
    }
    $body = ltrim(substr($s, $pos));
    if ($body === '') {
        return null;
    }
    return ['think' => rtrim($head), 'body' => $body];
}

/**
 * 中文前言：判定比英文严格，必须整段成立才认。
 *
 * 四道条件同时满足才算前言，避免误伤以「首先」开头的正常回答：
 *   1. 首段以第一人称元叙述句式开头（用户要求 / 我需要 / 我会用 …）；
 *   2. 首段后面有空行分隔，且分隔后确实还有内容（正文存在）；
 *   3. 首段长度在 15～500 字之间（太短是正文开头，太长可能是正文本身）；
 *   4. 首段里含「元叙述动词」，即在描述自己要做什么，而非在讲事实。
 *
 * @return array{think:string, body:string}|null 不匹配返回 null
 */
function split_thinking_zh(string $s): ?array
{
    // 段落边界：空行，或紧随其后的代码块围栏（模型常写完自述就直接开代码块，
    // 中间不留空行，这时围栏本身就是边界）。
    $parts = preg_split('/\R[ \t]*\R|\R(?=```)/u', $s, 2);
    if (!is_array($parts) || count($parts) < 2) {
        return null;
    }
    $head = trim($parts[0]);
    $body = ltrim($parts[1]);
    if ($head === '' || $body === '') {
        return null;
    }
    // 首段出现代码块围栏说明它已是正文；但自述里顺口提一个函数名
    // （「最基础的就是 `proxy_pass` 加几个转发头」）很常见，行内代码不作为排除依据。
    if (strpos($head, '```') !== false) {
        return null;
    }
    $len = mb_strlen($head, 'UTF-8');
    if ($len < 15 || $len > 500) {
        return null;
    }
    // 判定思路：不穷举开头说法（中文表达无穷，补不完），
    // 而是看这段话是否在「谈论本次该怎么回答」，需同时具备两类信号。
    //
    // 判据说明：不再匹配「用中文回答」这类完整短语——模型换个字（「用中文简短回答」）
    // 就漏掉，穷举短语治不了本。改为拆成独立原子词，按「是否谈论本次作答」计分。
    //
    // 甲类拆成强弱两档。
    // 强甲类：只有在自述时才会这么说，单独出现即可判定。
    $strong = [
        '我需要', '我应该', '需要我', '我先说', '我来说', '我直接',
        '用户在问', '用户想', '用户要', '用户希望', '用户需要', '用户问',
        '用户提到', '用户没有提供', '这个问题需要',
        '回答问题', '进入正文', '我的回答',
    ];
    // 「用中文/简体中文」单独不足以判定（「回答需要用中文存储」是业务句），
    // 必须后面跟着表达类动词，才说明是在交代自己怎么作答。
    // 判定用副本，避免污染 $head（它要原样作为前言返回）
    $probe = $head;
    if (preg_match('/(用|讲|说)(简体)?中文.{0,8}'
        . '(回答|说|讲|输出|作答|表述|阐述|给出|提供|解释|说明|介绍|列出|描述)/u', $head)) {
        $probe .= '＃中文作答';
        $strong[] = '＃中文作答';
    }
    // 弱甲类：可能出现在正常句子里，需要乙类策略词配合才算。
    // 「用户」不直接列入弱档：业务句里它多作定语（用户提交的、用户量、用户表），
    // 只有位于开头且紧跟提问类动作时才算指代提问者。
    $weak = ['对方', '提问者', '我会', '我要', '我来', '我得', '让我', '这个回答'];
    // 动词用完整词，不用单字：「提」会误中「用户提交/用户提供」这类业务动作。
    // 「用户没有提供」是自述（在说提问者没给信息），单独在强档里列了。
    if (preg_match('/^.{0,4}(用户|对方)(在|正|也|又)?(问的|问了|问|想知道|想了解|想要|'
        . '希望|需要的是|关心|询问|提到|提的是|说的是)/u', $head)) {
        $probe .= '＃指代提问者';
        $weak[] = '＃指代提问者';
    }
    // 乙类：动词性策略词，交代自己打算怎么答。名词不收（易两义）。
    $plan = [
        '简短', '简洁', '简明', '明了', '直接', '清晰', '先说', '先看', '先给',
        '提供', '给出', '说明', '解释', '介绍', '列出', '对比', '分析', '建议',
        '思路', '顺序', '步骤', '要点', '结构', '框架', '再补充',
    ];

    /** 判断首段是否含列表中任一词 */
    $含 = function (array $words) use (&$probe): bool {
        foreach ($words as $w) {
            if (mb_strpos($probe, $w) !== false) {
                return true;
            }
        }
        return false;
    };

    // 强甲类词单独成立；弱甲类词必须搭配乙类策略词。
    if (!$含($strong) && !($含($weak) && $含($plan))) {
        return null;
    }

    // 「我需要你…」「我想请你…」是在向对方提要求，属正文而非自述。
    // 自述说的是「我」要做什么，不是「你」要做什么。
    if (preg_match('/(我|咱)(需要|想请|得请|希望)(你|您|你们|用户)/u', $head)) {
        return null;
    }

    // 反向排除：首段自身就是完整的事实陈述（含数字结论、代码、列表符号）时不算前言
    if (preg_match('/^[\-\*\d]+[\.\)、]\s/u', $head)) {
        return null;
    }

    return ['think' => $head, 'body' => $body];
}

/**
 * 把拆分结果拼成带折叠标记的存储文本。
 * 前端按标记渲染成可展开的折叠块。
 */
function wrap_thinking(string $think, string $body): string
{
    $think = trim($think);
    if ($think === '') {
        return $body;
    }
    return THINK_OPEN . "\n" . $think . "\n" . THINK_CLOSE . "\n\n" . $body;
}
/**
 * 剥掉存储文本里的思考段，只留正文。用于把历史回发给 API 之前。
 *
 * 为什么要剥：思考是模型一次性的内部推演，对后续轮次没有参考价值，
 * 但它经常比正文还长（实测单条 2 万字节）。存库时要留着给前端折叠展示，
 * 回发给 API 时必须去掉 —— 否则每一轮都把历史里所有思考段重新发一遍，
 * 全部按新输入全价计费，是账单被撑大的主因。
 *
 * 按标记切，不重跑 split_thinking：存进去时已经判定过一次，
 * 再判一次不但白费 CPU，还可能因判定口径变化把正文误当思考切掉。
 */
function strip_thinking_for_api(string $txt): string
{
    if (strpos($txt, THINK_OPEN) === false) {
        return $txt;                                // 绝大多数消息没有思考段
    }
    $开 = strpos($txt, THINK_OPEN);
    $闭 = strpos($txt, THINK_CLOSE, $开);
    if ($闭 === false) {
        // 只有开标记没有闭标记：说明是流式中断留下的残缺内容，
        // 整段都是思考，正文压根没生成出来。返回空串让调用方跳过这条。
        return trim(substr($txt, 0, $开));
    }
    $正文 = substr($txt, 0, $开) . substr($txt, $闭 + strlen(THINK_CLOSE));
    return trim($正文);
}
