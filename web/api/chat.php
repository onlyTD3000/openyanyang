<?php
/** 流式对话接口（SSE）。所有数据按 user_id 隔离。 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();   // 让未捕获异常返回可读 JSON，而不是空的 500
require_once __DIR__ . '/../inc/upstream.php';
require_once __DIR__ . '/../inc/ssh_prompt.php';
require_once __DIR__ . '/../inc/thinking.php';
require_once __DIR__ . '/../inc/project.php';
require_once __DIR__ . '/../inc/tool_results.php';
require_once __DIR__ . '/../inc/ws_prompt.php';
require_once __DIR__ . '/../inc/web_prompt.php';
require_once __DIR__ . '/../inc/ppt_prompt.php';
require_once __DIR__ . '/../inc/repo_prompt.php';
require_once __DIR__ . '/../inc/sftp_prompt.php';
require_once __DIR__ . '/../inc/tools_schema.php';  // Function Calling 工具定义
require_once __DIR__ . '/../inc/prompt_guard.php';

$me = require_login_api();
csrf_check();

// 释放会话锁，避免长连接阻塞同一用户的其他请求
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$convId  = (int) ($_POST['conv_id'] ?? 0);
$projId  = (int) ($_POST['project_id'] ?? 0);   // 新建对话时归属哪个项目
$modelId = (int) ($_POST['model_id'] ?? 0);
// lang_follow 参数已废弃：「跟随提问语言」功能已下线，回复统一按 soul.md 只用简体中文。
// 老客户端仍会携带该参数，这里不再读取，收到即忽略。
$content = trim((string) ($_POST['content'] ?? ''));
// "继续"命令：检测上一条AI回复是否被中断，带上末尾内容让AI从断点续写
if ($content === "继续" && $convId > 0) {
    $末条 = db_one('SELECT role, content FROM messages WHERE conv_id = ? AND user_id = ? AND hidden = 0 ORDER BY id DESC LIMIT 1',
        [$convId, $me['id']]);
    if ($末条 && $末条['role'] === 'assistant' && trim((string)$末条['content']) !== '') {
        $部分 = trim((string)$末条['content']);
        // 去掉末尾可能有的"已暂停生成"标记
        $部分 = preg_replace('/<div class="stop-note">.*?<\/div>\s*$/s', '', $部分);
        // 取最后 800 字作为断点参考，避免提示词过长
        $尾 = mb_strlen($部分) > 800 ? mb_substr($部分, -800) : $部分;
        $content = "你上一条回答被中断了，以下是已写出的最后部分：\n\n---\n" . $尾 . "\n---\n\n请从断点处继续完成回答，直接接上内容，不要重复已写过的部分。";
    } else {
        $content = "请继续完成刚才的回答";
    }
} elseif ($content === "继续") {
    $content = "请继续完成刚才的回答";
}
// 图片 id 列表，前端先调 api/upload.php 拿到 id 再一起提交
$imgIds  = array_filter(array_map('intval', (array) ($_POST['images'] ?? [])));
// 工具回执（SFTP/命令/工作中心结果）：照旧入库并进 AI 上下文，但不在聊天界面渲染
// 工具回执：非空表示本轮内容是命令/文件操作的结果，落 tool_results 而不是 messages
$toolKind = trim((string) ($_POST['tool_kind'] ?? ''));
if ($toolKind !== '' && !in_array($toolKind, ['ssh','sftp','repo','ws','ppt','other'], true)) {
    $toolKind = 'other';
}
// 断点重续：已执行的工具ID列表（逗号分隔）
$executedTools = isset($_POST['executed_tools']) ? explode(',', trim($_POST['executed_tools'])) : [];

if ($content === '' && !$imgIds) {
    json_out(['error' => '内容不能为空'], 400);
}
// 只拦普通用户手打/粘贝的内容；工具回执（读文件、命令输出等）动辄几万字符是正常情况，
// 交给后面的 tool_result_truncate 压缩后再进上下文，不能在这里一刀切拦掉。
if ($toolKind === '' && mb_strlen($content) > 20000) {
    json_out(['error' => '单次输入过长（上限 2 万字）'], 400);
}

/* 提示词套取防护：只扫用户手打/粘贴的内容。
   工具回执（读文件、命令输出）里可能带着提示词文件原文或含关键词的日志，
   扫它等于用户读一次自己的配置文件就被封，所以 $toolKind 非空时跳过。 */
if ($toolKind === '') {
    prompt_guard_check($me, $convId, $content);
}

// 校验余额与配额（最低余额门槛可在后台「站点设置」调整）
$minBal = (float) setting_get('min_balance', 0);
if ((float) $me['balance'] <= 0 || (float) $me['balance'] < $minBal) {
    json_out(['error' => '余额不足，请联系管理员充值'], 402);
}
if ((int) $me['token_quota'] > 0 && (int) $me['used_tokens'] >= (int) $me['token_quota']) {
    json_out(['error' => 'Token 配额已用尽，请联系管理员'], 402);
}

// 取模型 + 渠道（子分类缺 base_url/protocol/chat_path 等会从父分类 api_platforms 继承）
$m = db_one(
    'SELECT m.*,
            c.id AS ch_id, c.parent_id, c.name AS ch_name,
            c.base_url, c.api_key, c.protocol, c.chat_path, c.api_version, c.timeout, c.status AS ch_status,
            c.`rotate`, c.rotate_idx
       FROM models m JOIN channels c ON c.id = m.channel_id
      WHERE m.id = ? AND m.status = 1 LIMIT 1',
    [$modelId]
);
if (!$m || (int) $m['ch_status'] !== 1) {
    json_out(['error' => '模型不可用，请重新选择'], 400);
}
// 把渠道相关字段抽出来做合并，再写回 $m（完全透明兼容旧代码里用 $m[base_url/api_key/...] 的部分）
$__ch = [
    'id'          => $m['ch_id'],
    'parent_id'   => $m['parent_id'] ?? 0,
    'name'        => $m['ch_name']   ?? '',
    'base_url'    => $m['base_url']  ?? '',
    'api_key'     => $m['api_key']   ?? '',
    'protocol'    => $m['protocol']  ?? 'openai',
    'chat_path'   => $m['chat_path'] ?? '/chat/completions',
    'api_version' => $m['api_version'] ?? '2023-06-01',
    'timeout'     => (int)($m['timeout'] ?? 60),
    'status'      => (int)($m['ch_status'] ?? 0),
    // SK 轮询需要这两个字段，缺了就退化成「只用第一个 SK」
    'rotate'      => (int)($m['rotate'] ?? 0),
    'rotate_idx'  => (int)($m['rotate_idx'] ?? 0),
];
// 注意：这里只做父子分类字段继承，不选 SK。
// 选 SK 需要知道是哪个对话（会话粘性），而 $convId 要到下面才确定，
// 所以真正的 SK 绑定推迟到「会话归属校验」之后执行。
$__merged = channel_merge_platform($__ch, null, null, true);
foreach (['base_url','protocol','chat_path','api_version','timeout'] as $__k) {
    $m[$__k] = $__merged[$__k];
}
unset($__merged, $__k);
// 非视觉模型不接受图片，避免白花钱调一次必然报错的请求
if ($imgIds && (int) ($m['vision'] ?? 0) !== 1) {
    json_out(['error' => '当前模型不支持图片输入，请换一个支持视觉的模型'], 400);
}

// 会话归属校验（关键隔离点）
if ($convId > 0) {
    $conv = db_one('SELECT * FROM conversations WHERE id = ? AND user_id = ? LIMIT 1', [$convId, $me['id']]);
    if (!$conv) {
        json_out(['error' => '会话不存在'], 404);
    }
    // 更新活跃时间：后台轮询页面 SK 负载分布里的「最近活跃」拿的是这个字段。
    // 不更新的话安卓端发再多消息也刷不动它，负载永远停在很久之前。
    db_exec('UPDATE conversations SET updated_at = NOW() WHERE id = ? AND user_id = ?',
        [$convId, $me['id']]);
} else {
    // 新建对话必须落在某个项目下，避免出现游离对话
    if ($projId <= 0 || !project_of($projId, (int) $me['id'])) {
        json_out(['error' => '请先在左侧选择或新建一个项目'], 400);
    }
    $title  = $content !== '' ? mb_substr($content, 0, 30) : '[图片]';
    // 会话级上下文条数：用户在首条消息前就能在「⚙ 上下文」面板设好，
    // 此时会话还不存在，随建会话一并落库；后面 $limit 计算处仍按
    // $_POST['context_limit'] 取值，两处同口径校验，行为一致。
    $convCtxLimit = isset($_POST['context_limit']) && (int) $_POST['context_limit'] >= 2
        ? min(60, (int) $_POST['context_limit']) : 0;
    $convId = db_insert('INSERT INTO conversations
                         (user_id, project_id, model_id, context_limit, title, created_at, updated_at)
                         VALUES (?,?,?,?,?,NOW(),NOW())',
        [$me['id'], $projId, $modelId, $convCtxLimit, $title]);
}

// ---- SK 会话粘性绑定 ----
// 同一对话固定用同一个 SK：换 SK 等于换 prompt 缓存空间，缓存会整体失效。
// 老对话直接读 conversations.sk_index（搭上面那次 SELECT * 的车，不额外查库）；
// 没绑过的按渠道游标分配一个，再落库记住，之后这条对话就固定了。
$__bindIdx = null;
if (isset($conv) && $conv && $conv['sk_index'] !== null) {
    $__bindIdx = (int) $conv['sk_index'];
}
$__merged = channel_merge_platform($__ch, null, $__bindIdx);
$m['api_key'] = $__merged['api_key'];
if ($__bindIdx === null && (int)($__merged['_sk_total'] ?? 0) > 1) {
    db_exec('UPDATE conversations SET sk_index = ? WHERE id = ? AND user_id = ?',
        [(int) $__merged['_sk_index'], $convId, $me['id']]);
}
// 留住这两个值给后面的「坏 SK 自动解绑」用（$__merged 下一行就被 unset 了）
$__skUsed  = $__merged['_sk_index'] ?? null;
$__skTotal = (int) ($__merged['_sk_total'] ?? 0);
// 留住原始多行 SK 列表，供请求完全失败后换下一个 SK 重试用
// （$__ch['api_key'] 是 channels.api_key 原始未挑选前的值，下一行就要 unset 了）
$__skRawList = channel_sk_list($__ch['api_key'] ?? '');
unset($__ch, $__merged, $__bindIdx);
// 上下文（仅本会话本用户）
// $limit 的语义是「对话块数」而非消息行数：一轮里 AI 连做多步工具会连出几十条
// assistant（实测单轮 25 条），按行数计时 20 条连一个完整轮次都装不下，
// 用户最初的需求被挤出窗口，AI 只看到自己工具循环中间的独白，于是自称看不到上下文。
// 折算规则：连续的 assistant 段整体算 1 块，user 各算 1 块。
/* 对话级上下文条数：前端输入框上方「⚙ 上下文」面板可传 context_limit，
   优先于模型默认值。只认 2..60，缺省/越界回落 models.max_context，
   防止被伪造的超大窗口撑爆 token 账单。
   模型级设置已从后台 models.php 移除，max_context 列只作隐藏默认值。 */
if (isset($_POST['context_limit']) && (int) $_POST['context_limit'] >= 2) {
    $limit = min(60, (int) $_POST['context_limit']);
} else {
    $limit = max(2, min(60, (int) $m['max_context']));
}
// 起点量化步长。不量化的话起点每轮往后挪，Claude 提示词缓存的前缀逐轮失配、
// 一次都命中不了，而缓存写入按 1.25 倍计费，等于开了缓存反而更贵。
// 量化后起点每 $块步长 块才动一次，中间几轮前缀完全一致。
$块步长 = max(1, (int) setting_get('history_block_step', 4));
// 预算按「字符」而不是「条数」卡。回执是绝对主体（实测某会话 20 块里
// 消息 25k 字、回执 110k 字，回执是消息的 4.4 倍），按条数算根本控不住体积。
//
// 定在 120000：这条预算才是实际生效的那道闸（实测 5 个长会话里 4 个都是先
// 撞预算、$limit 根本没机会触发），所以它直接决定 AI 能回看多少轮。
// 余量按 opus-5 的实测峰值反推：80000 字符时 prompt 峰值 99375 token，
// 等比折到 120000 约 149k，加上输出上限 16384，峰值约 165k，
// 距 Claude 的 200k 窗口留 35k 余量。再往上加就有撞窗口的风险了。
$字符预算 = max(10000, (int) setting_get('history_char_budget', 120000));
// 最近这几块的回执保留原文：AI 正靠它接着往下做，截了会直接干不动活。
$完整回执块数 = max(1, (int) setting_get('history_full_blocks', 3));
// 更早的回执压到这个长度。十几轮前的命令输出留个头尾够 AI 知道当时干了什么。
$老回执上限 = max(200, (int) setting_get('history_tool_cap', 2000));
// 老 assistant 消息也要截。实测某会话 assistant 平均一条 10988 字（中位 12075），
// 只截回执的话 4 条消息就吃满预算，覆盖轮数反而比不截时更少。
$老消息上限 = max(500, (int) setting_get('history_text_cap', 3000));

$全部回执 = tool_result_map((int) $me['id'], $convId);
// 骨架只取 id/role 和正文长度，长会话也就几百行。带上长度是为了在划窗口时
// 就能按字符算预算，不用把正文全捞回来再算。
$骨架 = db_all(
    "SELECT id, role, CHAR_LENGTH(content) AS len FROM messages
      WHERE conv_id = ? AND user_id = ? AND hidden = 0 AND role IN ('user','assistant')
      ORDER BY id",
    [$convId, $me['id']]
);
$总行数 = count($骨架);
// 给每行标注它属于第几块，并记下每块的起始行下标
$块号 = [];
$块首行 = [];
$当前块 = -1;
$前一角色 = '';
foreach ($骨架 as $i => $行) {
    if ($行['role'] === 'user' || $前一角色 !== 'assistant') {
        $当前块++;
        $块首行[$当前块] = $i;
    }
    $块号[$i] = $当前块;
    $前一角色 = $行['role'];
}
$总块数 = $当前块 + 1;
// 消息 id 到块号，后面给回执定级要用（判断它属于「最近几块」还是老块）
$id到块号 = [];
foreach ($骨架 as $i => $行) {
    $id到块号[(int) $行['id']] = $块号[$i];
}
/* 从这一块开始（含）的回执保留原文。
 *
 * 这里必须量化，否则就是缓存杀手：原来写成 $总块数 - $完整回执块数，跟着总块数
 * 逐轮滑动，于是「上一轮保原文的那块」这一轮就掉进老块被截断。改动发生在窗口
 * 中间，它之后的所有内容全部失配——实测账单上表现为每隔一两轮缓存读从 18 万掉到
 * 7200（只剩系统提示词那段），缓存写 19 万余，单笔从 0.012 跳到 0.074 甚至 0.128。
 *
 * 对齐到和窗口起点同一个步长，界线每 $块步长 块才移动一次。代价是保原文的块数会在
 * $完整回执块数 到 $完整回执块数+$块步长-1 之间浮动，多带几块原文换缓存稳定命中。
 */
/* 向上取整而不是向下：向下取整会让界线往前退最多 $块步长-1 块，白白多带几块原文
   （实测输入从 5 万涨到 9 万）。向上取整同样每 $块步长 块才动一次、缓存照样命中，
   但不会多带。再夹一道上限：界线不能越过最后一块，否则最近的回执全被截断，
   AI 正靠它接着干活。 */
$保原文起始块 = max(0, min(
    $总块数 - 1,
    (int) (ceil(($总块数 - $完整回执块数) / $块步长) * $块步长)
));

// 从最新一块往前贪心累加字符，受 $limit 块数和 $字符预算 双重约束，
// 得出「最早还能带上的块」。至少保留最后一块，否则本轮提问会没有任何铺垫。
$最早可用块 = max(0, $总块数 - 1);
$累计字符 = 0;
for ($b = $总块数 - 1; $b >= 0; $b--) {
    $本块首 = $块首行[$b];
    $本块尾 = ($b + 1 < $总块数) ? $块首行[$b + 1] - 1 : $总行数 - 1;
    $本块字符 = 0;
    for ($i = $本块首; $i <= $本块尾; $i++) {
        // 老块的 assistant 会被截断，预算按截断后的长度估，否则高估几万字、
        // 白白把可用块数压小（实测因此从覆盖 10 轮掉到 4 轮）。
        $行长 = (int) $骨架[$i]['len'];
        if ($b < $保原文起始块 && $骨架[$i]['role'] === 'assistant') {
            $行长 = min($行长, $老消息上限);
        }
        $本块字符 += $行长;
        // 回执跟着消息一起发，占同一份预算。老块的按截断后的长度估，
        // 否则会高估出好几万字，白白把可用块数压小。
        foreach ($全部回执[(int) $骨架[$i]['id']] ?? [] as $回执) {
            $回执长 = mb_strlen($回执);
            $本块字符 += ($b >= $保原文起始块) ? $回执长 : min($回执长, $老回执上限);
        }
    }
    /* $limit 来自 models.max_context，语义是「消息条数」。这里卡的是块数，
       而一块通常是 user + assistant 两条，直接拿 60 当块数用等于放到 129 条
       （实测日志：起点块 167/209、history 129 条），是设定值的两倍多。
       折半换算回块数，让这个设置项名副其实。 */
    $块数上限 = max(1, (int) ceil($limit / 2));
    $已收块数 = $总块数 - $b;
    if ($b < $总块数 - 1
        && ($已收块数 > $块数上限 || $累计字符 + $本块字符 > $字符预算)) {
        break;
    }
    $累计字符 += $本块字符;
    $最早可用块 = $b;
}

// 量化：起点块往前对齐到 $块步长 的整数倍，但不能早于 $最早可用块，
// 否则又把预算撑爆。向下取整让起点每 $块步长 块才动一次。
$起点块 = (int) (floor(max(0, $总块数 - max(1, (int) ceil($limit / 2))) / $块步长) * $块步长);
$起点块 = max($起点块, $最早可用块);
$起点块 = max(0, min($起点块, max(0, $总块数 - 1)));
$起点行 = $块首行[$起点块] ?? 0;

$history = $总行数 > 0 ? db_all(
    "SELECT id, role, content, images FROM messages
      WHERE conv_id = ? AND user_id = ? AND hidden = 0 AND role IN ('user','assistant')
        AND id >= ?
      ORDER BY id",
    [$convId, $me['id'], (int) $骨架[$起点行]['id']]
) : [];
// 首条必须是 user（Anthropic 硬要求）。块首多数就是 user，这里兜底：
// 会话开头就是 assistant（历史数据）时仍需剥掉。
while ($history && $history[0]['role'] === 'assistant') {
    array_shift($history);
}
// 老块的 assistant 正文按上限截断，和上面估预算的口径保持一致。
// user 消息一律不截：那是客户自己说的话，截了 AI 就理解错需求了。
foreach ($history as $下标 => $一行) {
    if ($一行['role'] !== 'assistant') {
        continue;
    }
    $本块 = $id到块号[(int) $一行['id']] ?? 0;
    if ($本块 < $保原文起始块) {
        $history[$下标]['content'] =
            tool_result_truncate((string) $一行['content'], $老消息上限);
    }
}
// 取本窗口内各条消息后面挂着的工具回执，拼上下文时插回原位。
// $全部回执 在上面算预算时已经读过一次，这里直接复用，不重复读文件。
// 老块的回执在这里真正截断，和上面估预算时的口径保持一致。
$回执表 = [];
foreach (array_column($history, 'id') as $mid) {
    $mid = (int) $mid;
    if (empty($全部回执[$mid])) {
        continue;
    }
    $本块 = $id到块号[$mid] ?? 0;
    $原文 = $本块 >= $保原文起始块;
    $回执表[$mid] = $原文
        ? $全部回执[$mid]
        : array_map(fn($t) => tool_result_truncate((string) $t, $老回执上限), $全部回执[$mid]);
}

/* 同一个文件被反复读取时，历史里会留下多份几乎一样的全文回执。
   实测某会话 helpers.php 被读了十几次，每次 12000 余字，光这一个文件就占十几万字。
   这里只保留最后一次读取的正文，更早的换成一行指引——AI 要的是文件当前内容，
   而当前内容一定在最后那份里，早期几份除了占预算没有别的作用。
   只对老块生效，最近几块（$保原文起始块 之后）一律不动，避免影响正在进行的工作。 */
if ((int) setting_get('history_dedup_read', 1) === 1) {
    /* 按「内容身份」归组：同一身份的老回执只留最后一份。
       实测某会话代码仓文件清单重发 19 次、每次 12270 字，是最大的单一浪费源。 */
    $身份 = static function (string $文): ?string {
        $首 = trim((string) strtok($文, "\n"));
        // 各类文件清单：整份列表几百行，会话中途基本不变。
        // 「操作结果：」是通用前缀，不同操作都用它，只有确实是文件清单
        // （正文出现「路径照抄」「不要再猜文件名」这类清单专有措辞）才归组。
        if (preg_match('/^(代码仓文件清单|工作中心现有文件清单)/u', $首)) {
            return '清单:' . $首;
        }
        if (strncmp($首, '操作结果', 12) === 0
            && preg_match('/^\S+（\d+(\.\d+)? ?[BKMG]i?B?）$/mu', $文)) {
            return '清单:目录列表';   // 每行「路径（体积）」的目录清单
        }
        // 文件内容：同一路径读多次，只有最后一份反映当前状态
        if (preg_match('/^(?:服务器|工作中心)?文件内容（([^）]+)）：/u', $首, $m3)) {
            return '文件:' . $m3[1];
        }
        return null;
    };
    $最后出现 = [];          // 内容身份 => [消息id, 回执下标]
    foreach ($回执表 as $mid => $组) {
        if (($id到块号[$mid] ?? 0) >= $保原文起始块) {
            continue;        // 最近几块不参与去重
        }
        foreach ($组 as $i => $文) {
            $标 = $身份((string) $文);
            if ($标 !== null) {
                $最后出现[$标] = [$mid, $i];
            }
        }
    }
    foreach ($回执表 as $mid => $组) {
        if (($id到块号[$mid] ?? 0) >= $保原文起始块) {
            continue;
        }
        foreach ($组 as $i => $文) {
            $标 = $身份((string) $文);
            if ($标 === null) {
                continue;
            }
            $末 = $最后出现[$标] ?? null;
            if ($末 !== null && ($末[0] !== $mid || $末[1] !== $i)) {
                $首行 = trim((string) strtok((string) $文, "\n"));
                $回执表[$mid][$i] = mb_substr($首行, 0, 80)
                    . '（早期快照，同样的内容后面又取过一次，以后面那份为准）';
            }
        }
    }
}

// 认领本次要发的图片（校验归属 + 未被复用）
$imgRows = $imgIds ? claim_uploads($imgIds, (int) $me['id'], $convId) : [];
if ($imgIds && !$imgRows) {
    json_out(['error' => '图片已失效，请重新上传'], 400);
}

$msgs = [];
// 系统提示词 = 平台准则(inc/soul.md) + 该模型的额外要求 + 项目档案 + 远程执行说明
$sysPrompt = build_system_prompt((string) $m['system_prompt']);

// 如实告知模型身份：用户问「你是什么模型」时，AI 必须说出当前真正用的底层模型名，
// 而不是报平台昵称「岩羊Ai」。模型名来自数据库 models 表，切换模型后这里自动跟着变。
$模型名 = $m['display_name'] ?? $m['model_name'] ?? '';
$模型标识 = $m['model_name'] ?? '';
if ($模型名 !== '') {
    $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n") .
        "## 当前模型\n\n" .
        "本对话当前实际使用的模型：" . $模型名 . "。\n" .
        "用户问「你是什么模型」「用的什么模型」时，照实回答上面的模型名即可，不要回答「岩羊Ai」——那是平台昵称，不是模型名。";
}

// 项目档案：让 AI 知道当前在做哪个项目、往哪台机器哪个目录部署
$当前项目 = project_of((int) ($conv['project_id'] ?? $projId), (int) $me['id']);
$projTip  = project_system_prompt($当前项目);
if ($projTip !== '') {
    $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n") . $projTip;
}

// 用户登记过服务器才追加远程执行说明，避免无谓占用上下文
$sshHosts = db_all('SELECT id, name, host, username FROM ssh_hosts
                    WHERE user_id=? AND status=1 ORDER BY id', [(int) $me['id']]);
// 判断当前项目是否具备 SFTP 直连条件（绑了服务器且填了部署目录）
// 能力开关。SFTP 那个要一起并进 $有SFTP：这个变量同时决定
// ssh_system_prompt 里要不要说「改文件优先用 sftp-*」，
// 开关关了却还这么说，等于指着一条走不通的路让 AI 去撞。
// 能力开关：从 users 表的 22 个细粒度开关（cap_<工具名>）推导组级总开关。
// 历史上这里读的是 cap_ssh / cap_sftp 等 6 个列，但这些列在库里不存在，
// ?? 1 让它们恒为开，属于死代码；现在改成按实际工具列推导，关掉才真关。
$cap_on  = fn(string $t): bool => (int) ($me['cap_' . $t] ?? 1) === 1;
$cap_any = fn(array $ts): bool => array_reduce($ts, fn($c, $t) => $c || $cap_on($t), false);
// 细粒度裁剪：组内部分工具被禁时，明确告诉 AI 哪些不能用，
// 避免它输出操作卡片→执行层拒绝→换写法重试，白烧 token。
$禁用提示 = function (array $组) use ($cap_on): string {
    $禁 = array_values(array_filter($组, fn($t) => !$cap_on($t)));
    if (!$禁) {
        return '';
    }
    return "\n\n注意：以下工具对你已禁用，不要调用：" . implode(', ', $禁)
         . "。用户要求相关操作时，说明管理员未开放该权限。";
};

$ssh可用  = $cap_on('ssh_exec');
$sftp可用 = $cap_any(['sftp_read', 'sftp_write', 'sftp_patch', 'sftp_list', 'sftp_delete']);
$有SFTP = $sftp可用 && $当前项目 && (int)($当前项目['host_id'] ?? 0) > 0 && trim((string)($当前项目['deploy_dir'] ?? '')) !== '';

// 生成工具定义（Function Calling），用于判断是否启用工具模式
$tools = tools_schema((int) $me['id'], (int) $convId, $sshHosts, (int) ($当前项目['host_id'] ?? 0), $当前项目);
$启用工具 = !empty($tools);

if ($ssh可用) {
    // 传入 $启用工具 参数，当启用 Function Calling 时不生成代码块格式说明
    $sshTip = ssh_system_prompt($sshHosts, (int) ($当前项目['host_id'] ?? 0), $有SFTP, $启用工具);
    if ($sshTip !== '') {
        $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n") . $sshTip;
    }
}

// SFTP 直连改文件说明：只在项目绑了服务器且填了部署目录时注入
// 这是 ssh_system_prompt 里「改文件优先用 sftp-*」那句话的完整版说明
if ($sftp可用 && $有SFTP) {
    $绑定主机 = null;
    foreach ($sshHosts as $h) {
        if ((int)$h['id'] === (int)($当前项目['host_id'] ?? 0)) {
            $绑定主机 = $h;
            break;
        }
    }
    $sftpTip = sftp_system_prompt($当前项目, $绑定主机 ?: [])
             . $禁用提示(['sftp_read', 'sftp_write', 'sftp_patch', 'sftp_list', 'sftp_delete']);
    if ($sftpTip !== '') {
        $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n") . $sftpTip;
    }
}

// 代码仓说明：前端 chat.js 一直认 file-read / file-write / file-patch / file-push
// 这四个标签并会当真执行，但这段说明此前从未注入过——AI 是在盲用一套没人告诉过它
// 规则的工具，路径、用法全靠猜，打到空仓上就只剩各种失败。
// 只在有当前项目时注入：file-* 全都要带 project_id，没项目这些标签根本调不通，
// 讲了反而引它去用。
// 和工作中心同样处理：这段是纯静态文本，不带文件清单、不带文件数、
// 不带「有无未回传改动」，所以不需要指纹和缓存，也不会污染上游缓存前缀。
$repo可用 = $cap_any(['file_list', 'file_read', 'file_write', 'file_patch', 'file_delete', 'file_push']);
if ($当前项目 && $repo可用) {
    $repoTip = repo_system_prompt()
             . $禁用提示(['file_list', 'file_read', 'file_write', 'file_patch', 'file_delete', 'file_push']);
    if ($repoTip !== '') {
        $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n") . $repoTip;
    }
}

// 工作中心说明：不注入的话 AI 根本不知道自己能写文件、能打包，
// 客户要「生成并打包发我」时它只会说做不到。
// 这段是纯静态文本：不查文件清单、不带文件数，所以也不需要指纹和缓存。
// 刻意如此——系统提示词是上游缓存的前缀，掺进易变内容会让客户每写一个文件
// 就整段失效一次，而打包场景正好要连写多个文件。清单改由 AI 用 ws-list 按需查。
/* 打包说明（约 700 字节）按需带：低频操作，绝大多数会话用不到。
   判定口径和 PPT 那段一致，只扫本轮和最近几条，避免早期提过一次就永久带上。 */
$要打包 = (int) setting_get('prompt_zip_ondemand', 1) !== 1;
if (!$要打包) {
    $近文2 = $content;
    foreach (array_slice($history, -6) as $近条2) {
        $近文2 .= "\n" . (string) $近条2['content'];
    }
    $要打包 = (bool) preg_match('/(打包|压缩包|zip|一起发我|发我一份|下载)/iu', $近文2);
}
$ws可用 = $cap_any(['ws_list', 'ws_read', 'ws_write', 'ws_patch', 'ws_delete', 'ws_zip']);
if ($ws可用) {
    $wsTip = ws_system_prompt([], false, $要打包)
           . $禁用提示(['ws_list', 'ws_read', 'ws_write', 'ws_patch', 'ws_delete', 'ws_zip']);
    if ($wsTip !== '') {
        $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n") . $wsTip;
    }
}

/* PPT 生成能力：原先无条件注入，实测这段占约 1100 token，而绝大多数会话
   从头到尾不做 PPT，等于每轮白付一次。改成按需——本轮提问或近期历史里
   出现过相关字样才注入。
   判定放宽一点没关系：漏判的代价只是这一轮 AI 不知道能做 PPT，用户再说一次
   就会带上；而每轮硬带的代价是长会话里累积上万 token。
   注意只扫最近几条，避免早期偶然提过一次就让后面每轮都带上。 */
// 能力总开关优先于按需注入：能力关掉就完全不提，别让 AI 知道有这回事。
// 另外 PPT 生成的文件存在工作中心，工作中心关了这项也没法用，跟着一起关。
$ppt可用 = $cap_on('ppt_generate') && $ws可用;
$ppt开关 = $ppt可用 && (int) setting_get('prompt_ppt_ondemand', 1) !== 1;
if ($ppt可用 && !$ppt开关) {
    $近文 = $content;
    foreach (array_slice($history, -6) as $近条) {
        $近文 .= "\n" . (string) $近条['content'];
    }
    $ppt开关 = (bool) preg_match(
        '/(ppt|pptx|幻灯片|演示文稿|汇报材料|演示稿|做个演示|slide)/iu', $近文);
}
if ($ppt开关) {
    $pptTip = ppt_system_prompt();
    if ($pptTip !== '') {
        $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n") . $pptTip;
    }
}
// 网页访问能力：无条件注入，不学 PPT 那样按关键词触发。
//
// 理由是这个能力得让 AI 先知道自己有，才谈得上判断该不该用。按关键词触发只能
// 抓到用户明确给了网址的情况，「查一下这个库最新版本是多少」这类没提网址、
// 但恰恰最该去抓一次的问法会全部漏掉，AI 只能凭记忆答，答的还是过期信息。
// 这段一千出头字符，和静态文本一样不影响上游缓存前缀，代价可以接受。
$web可用 = $cap_on('web_open') || $cap_on('web_search');
if ($web可用) {
    $webTip = web_system_prompt()
            . $禁用提示(['web_open', 'web_search']);
    if ($webTip !== '') {
        $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n") . $webTip;
    }
}
// 系统回执标记的说明：解释上下文里那些被标记包住的 user 轮不是用户发言，
// 收到它不等于用户回答了问题或同意了改动。无条件注入——回执随时可能出现，
// 而且这段是纯静态文本，不影响上游缓存前缀。
$toolTip = tool_result_system_prompt();
if ($toolTip !== '') {
    $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n") . $toolTip;
}
// 钉住会话最初的用户需求。
//
// 为什么必须单独钉：窗口是从最新往前贪心收的，最近 3 块的回执要保原文
// （AI 正靠它干活，截了直接停摆），实测单块就一两万字，4 块吃掉 75k 字符。
// 于是无论 $字符预算 和 $limit 调到多少，长会话里最开头那条「我要做什么」
// 一定会被挤出窗口。AI 只看到自己工具循环中间的独白，就开始重复问需求、
// 或者按自己的理解跑偏——这才是用户说的「像失忆」最直接的那一面。
//
// 放进 system 而不是插一条 user 消息，有两个原因：
//   1. system 不参与 user/assistant 交替校验，插进 messages 里要处理合并
//   2. 每轮内容完全一致，不破坏 Claude 的提示词缓存前缀
// 只在窗口起点已经越过会话开头时才注入，短会话原文还在窗口里，重复反而浪费。
if ($起点行 > 0) {
    // 多捞一些候选再筛。只取前 2 条的话，「继续」这类短指令会把真需求挤掉
    // （实测某会话前两条 user 是「需求原文」+「继续」，钉进去一半是废话）。
    $最初 = db_all(
        "SELECT content FROM messages
          WHERE conv_id = ? AND user_id = ? AND hidden = 0 AND role = 'user'
            AND id < ?
          ORDER BY id LIMIT 12",
        [$convId, $me['id'], (int) $骨架[$起点行]['id']]
    );
    // 纯催促/应答类短指令，钉住它们没有任何信息量
    $废话 = ['继续', '接着', '嗯', '好', '好的', 'ok', 'OK', '可以', '行',
             '你继续', '继续吧', '往下', '下一步', '谢谢'];
    // 凭据关键词。命中就整条跳过。
    // 钉进 system 意味着这条密码/密钥会出现在该会话之后每一轮请求里，
    // 落进上游日志就拿不回来了。需求描述本身不需要凭据。
    $凭据词 = ['密码', '口令', 'passwd', 'password', '密钥', 'secret',
               'api_key', 'apikey', 'token', '私钥', 'BEGIN RSA', 'BEGIN OPENSSH'];
    // AI 自己的推演文本偶尔会以 user 角色落库（历史数据问题）。
    // 这类文本有明显自述口吻，钉住它会让模型把自己的独白当成用户需求。
    $独白词 = ['我们被要求', '这是命令执行结果', '没有新用户消息',
               '这是一次自动执行', '需要根据结果继续推进', '等待下一条用户消息'];
    $需求 = [];
    // 只在前 8 条里找。再往后就不算「最初的需求」，且越往后越容易捞到独白
    // 或中途的细节指令（实测会话 170 的第 12 条 user 就是 AI 独白）。
    $已看 = 0;
    foreach ($最初 as $一条) {
        if (++$已看 > 8) {
            break;
        }
        $文 = trim((string) $一条['content']);
        // 跳过工具回执：它是机器发的，不是需求描述
        // PHP 7.4 没有 str_contains()，用 strpos 保持兼容
        if ($文 === '' || strpos($文, '<<<系统回执') !== false) {
            continue;
        }
        // 短于 8 字的基本都是催促，真需求再简略也会长一些
        if (mb_strlen($文) < 8 || in_array($文, $废话, true)) {
            continue;
        }
        // 和已收的重复就跳过（用户常把同一句话发两遍）
        if (in_array($文, $需求, true)) {
            continue;
        }
        // 凭据或独白，命中即整条丢弃
        $低 = mb_strtolower($文);
        $跳 = false;
        foreach ($凭据词 as $词) {
            if (strpos($低, mb_strtolower($词)) !== false) {
                $跳 = true;
                break;
            }
        }
        if (!$跳) {
            foreach ($独白词 as $词) {
                if (strpos($文, $词) !== false) {
                    $跳 = true;
                    break;
                }
            }
        }
        if ($跳) {
            continue;
        }
        $需求[] = tool_result_truncate($文, 1500);
        if (count($需求) >= 2) {
            break;
        }
    }
    if ($需求) {
        $sysPrompt = ($sysPrompt === '' ? '' : $sysPrompt . "\n\n---\n\n")
            . "## 本次会话最初的需求\n\n"
            . "下面是用户在这个会话开头提出的原始需求。会话已经很长，这几条\n"
            . "已经不在上下文窗口里了，但它定义了整件事要做成什么样，你后续\n"
            . "所有工作都应当服务于它。不要因为看不到就重新追问需求。\n\n"
            . implode("\n\n", array_map(fn($t) => "> " . str_replace("\n", "\n> ", $t), $需求));
    }
}
if ($sysPrompt !== '') {
    $msgs[] = ['role' => 'system', 'content' => $sysPrompt];
    // 系统提示词体积明细：只在开了 prompt_debug 时写，平时不产生任何开销。
    // 排查「输入 token 为什么这么高」时打开它，看是哪个模块在膨胀。
    if ((int) setting_get('prompt_debug', 0) === 1) {
        $行 = [date('Y-m-d H:i:s'), '总计 ' . strlen($sysPrompt) . ' 字节 / 约 '
            . estimate_tokens($sysPrompt) . ' token',
            '窗口选中：起点块 ' . $起点块 . '/' . $总块数 . '，history ' . count($history) . ' 条，'
            . '回执 ' . array_sum(array_map('count', $回执表)) . ' 条'];
        foreach (preg_split("/\n(?=## )/", $sysPrompt) as $段) {
            $标 = trim((string) strtok($段, "\n"));
            $行[] = sprintf('  %7d 字节 %6d tk  %s',
                strlen($段), estimate_tokens($段), mb_substr($标, 0, 40));
        }
        @file_put_contents(DATA_DIR . '/prompt_size.log',
            implode("\n", $行) . "\n\n", FILE_APPEND | LOCK_EX);
    }
}
/* 历史图片每轮都要重新 base64 塞进请求体，一张 1MB 的旧图聊一百轮就重传一百次，
   很容易把上游的请求体上限顶爆（报 images_bytes 过大）。这里只让最近若干条带图
   消息真的回发图片，更早的换成一行文字说明——模型知道那里曾有图，但不占字节。 */
$保留图条数 = max(0, (int) setting_get('history_img_keep', 2));
$带图下标 = [];
foreach ($history as $序 => $条) {
    $j = trim((string) ($条['images'] ?? ''));
    if ($j !== '' && $j !== '[]' && $j !== 'null') {
        $带图下标[] = $序;
    }
}
$保留下标 = $保留图条数 > 0 ? array_slice($带图下标, -$保留图条数) : [];
foreach ($history as $序 => $hm) {
    /* assistant 的思考段不回发给 API：只对前端折叠展示有用，
       对模型是一次性推演。留着的话每轮都要重发一遍并按全价计费。 */
    $正文 = (string) $hm['content'];
    if ($hm['role'] === 'assistant') {
        $正文 = strip_thinking_for_api($正文);
        if (trim($正文) === '') {
            continue;              // 整条都是思考（流式中断的残条），直接跳过
        }
    }
    /* 历史图片只在视觉模型下回发。
       会话早期用视觉模型传过图，之后切到不支持视觉的模型（如 DeepSeek V4 Pro）
       继续聊时，本轮 $imgIds 是空的，第 91 行的前置校验放过了，但历史图片仍会
       被组装进请求，上游直接回 400「当前模型不支持该能力：vision」，整个会话
       就聊不下去了。这里按模型能力过滤，非视觉模型只发文字，老会话仍可继续。 */
    // 超出保留轮数的历史图片不回发，换成一行说明，避免每轮重传把请求体顶爆
    $本条图JSON = trim((string) ($hm['images'] ?? ''));
    $本条有图 = $本条图JSON !== '' && $本条图JSON !== '[]' && $本条图JSON !== 'null';
    $可发图 = (int) ($m['vision'] ?? 0) === 1 && in_array($序, $保留下标, true);
    $历史图 = $可发图
        ? history_upload_rows($本条图JSON, (int) $me['id'])
        : [];
    if ($本条有图 && !$可发图) {
        $张数 = is_array($t = json_decode($本条图JSON, true)) ? count($t) : 1;
        $正文 = ($正文 === '' ? '' : $正文 . "\n\n")
            . '（此处原有 ' . $张数 . ' 张图片，为控制请求体积未回发）';
    }
    $msgs[] = build_chat_msg($hm['role'], $正文, $历史图);
    // 挂在这条消息后面的工具回执，按 user 轮插回去：AI 当初就是看着它接着往下做的。
    //
    // 角色只能是 user——上游要求 user/assistant 严格交替，而这套系统没有真正的
    // tool_use/tool_result 块（工具调用是正文里的 ssh-exec 等代码块，没有 tool_use_id，
    // 补造不出来），所以协议层面无法把回执标成第三种角色。
    //
    // 后果是模型分不清「这条 user 消息是人打的」还是「机器用 user 身份发的回执」：
    // 它上一条问了「选方案 1 还是 2」，紧接着来一条 user 轮，按协议就该当成用户的答复，
    // 于是自己挑一个接着干。用户那边界面上还看不见这条（前端 静默=true 不渲染气泡）。
    //
    // 权宜之计：给回执裹一层机器可识别的来源标记，让模型靠文本自己判断。
    // 标记只在发给上游的这份上下文里加，不进 tool_results 落盘的正文，
    // 所以对历史回执也追溯生效，且随时可以摘掉。
    foreach ($回执表[(int) $hm['id']] ?? [] as $回执) {
        $msgs[] = ['role' => 'user', 'content' => tool_result_wrap($回执)];
    }
}
// 本轮消息。若它是工具回执（$toolKind 非空），同样要裹上来源标记。
// 不裹的后果：模型收到一条无标记的 user 消息，按协议只能当成用户在说话，
// 于是把机器回执误读成用户的答复。历史回执在上面已经裹过，
// 这里补的是当前这一条。回执不带图片，所以不走 build_chat_msg。
$msgs[] = $toolKind !== ''
    ? ['role' => 'user', 'content' => tool_result_wrap($content)]
    : build_chat_msg('user', $content, $imgRows);
// 上游要求 user/assistant 严格交替，连续同角色会被打回 400。
// 回执插回后可能和相邻消息同角色，这里并成一条；system 不参与合并。
$合并 = [];
foreach ($msgs as $一条) {
    $尾 = count($合并) - 1;
    if ($尾 >= 0 && $合并[$尾]['role'] === $一条['role'] && $一条['role'] !== 'system') {
        $合并[$尾]['content'] = merge_msg_content($合并[$尾]['content'], $一条['content']);
        continue;
    }
    $合并[] = $一条;
}
$msgs = $合并;
/* 请求体真实体积。加这段的起因：预算按 12 万字符卡，实测最后 60 条历史只有 6.2 万字，
   可账单上 tokens_in 却是 53 万——差了十倍，说明有东西绕过了预算这道闸。
   这里直接量最终发出去的 $msgs，按角色分开记，好判断是哪一类在膨胀。 */
if ((int) setting_get('prompt_debug', 0) === 1) {
    $按角 = [];
    foreach ($msgs as $一 => $条) {
        $体 = is_string($条['content'])
            ? $条['content']
            : json_encode($条['content'], JSON_UNESCAPED_UNICODE);
        $按角[$条['role']]['条'] = ($按角[$条['role']]['条'] ?? 0) + 1;
        $按角[$条['role']]['节'] = ($按角[$条['role']]['节'] ?? 0) + strlen((string) $体);
    }
    $总节 = strlen(json_encode($msgs, JSON_UNESCAPED_UNICODE));
    $文 = date('Y-m-d H:i:s') . "  请求体 " . $总节 . " 字节 / 约 "
        . estimate_tokens(str_repeat('x', 0) . json_encode($msgs, JSON_UNESCAPED_UNICODE)) . " token\n";
    foreach ($按角 as $角 => $v) {
        $文 .= sprintf("    %-10s %4d 条  %9d 字节\n", $角, $v['条'], $v['节']);
    }
    @file_put_contents(DATA_DIR . '/req_size.log', $文 . "\n", FILE_APPEND | LOCK_EX);
}

if ($toolKind !== '') {
    // 工具回执不进 messages：它不是用户说的话，后台聊天记录里也不该出现它。
    // 挂到本会话最后一条消息（也就是刚吐出工具卡片的那条 assistant）后面。
    $挂点 = (int) db_val("SELECT COALESCE(MAX(id),0) FROM messages
                           WHERE conv_id = ? AND user_id = ?", [$convId, $me['id']]);
    tool_result_add((int) $me['id'], $convId, $挂点, $toolKind, $content);
} else {
    // 落库用户消息（images 存 uploads.id 的 JSON 数组）
    $imgJson = $imgRows ? json_encode(array_map('intval', array_column($imgRows, 'id'))) : '';
    db_insert("INSERT INTO messages (conv_id, user_id, role, content, images, created_at)
               VALUES (?,?,?,?,?,NOW())",
        [$convId, $me['id'], 'user', $content, $imgJson]);
}

// ---- 初始化断线重连任务 ----
db_exec('DELETE FROM chat_runs WHERE user_id = ? AND beat_at < DATE_SUB(NOW(), INTERVAL 1 DAY)',
    [$me['id']]);
$runId = db_insert('INSERT INTO chat_runs (user_id, conv_id, status, created_at, beat_at)
                    VALUES (?,?,?,NOW(),NOW())',
    [$me['id'], $convId, 'running']);

// ---- 输出 SSE ----
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@set_time_limit(0);
ignore_user_abort(true);
// 应急持久化：如果脚本在流完成前异常退出（客户端断开、进程被干等），
// 仍把已生成的内容写进 messages 表，避免重启 APP 后丢失数据。
// $msgId 正常结束后非零，这里只兜底未保存的情况。
//
// 旧代码用 $GLOBALS['me'] 取 user_id，但 $me 是 db_one() 返回的数组，
// (int) 数组恒为 1（非空数组），导致异常退出时内容被写到 user_id=1 名下，
// 真正的用户刷新后看不到。改为在闭包外提前取出标量 id。
$__uid = (int) $me['id'];
$__runId = (int) $runId;
register_shutdown_function(function () use ($convId, &$answer, &$msgId, $__uid, $__runId) {
    if ($convId > 0 && empty($msgId) && $__uid > 0) {
        try {
            // 有内容就存内容，没内容不存假消息——让前端 localStorage 缓存兜底显示
            if (!empty($answer)) {
                db_exec('INSERT INTO messages (conv_id, user_id, role, content, created_at)
                         VALUES (?,?,?,?,NOW())',
                    [$convId, $__uid, 'assistant', $answer]);
            }
            // 把 chat_runs 标记为 done，避免重连接口把它判成 dead 后
            // 又发一条 err 消息——内容已经落库了，不应该再报中断
            if ($__runId > 0) {
                db_exec('UPDATE chat_runs SET status = \'done\', answer = ?, beat_at = NOW()
                         WHERE id = ? AND status = \'running\'',
                    [$answer ?? '', $__runId]);
            }
        } catch (\Throwable $e) {}
    }
});
while (ob_get_level() > 0) {
    ob_end_flush();
}
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

function sse(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

sse('meta', ['conv_id' => $convId, 'run_id' => $runId]);

$answer = '';
$usage  = null;
$t0     = microtime(true);
$foldDone = false;   // 是否已判定过思考前言（每轮回答只判一次）
$foldText = '';       // 上游按字段明确给出的思考内容（Claude thinking / OpenAI reasoning_content）
$foldFromApi = false; // true 表示 $foldText 来自上游精确字段，结尾不再用启发式规则重新猜
$lastBeat = 0;     // 上次写 chat_runs 增量时间戳
$ttftMs   = null;  // 首 token 到达时间（接口响应速度的核心指标）

// 暂停检测：每 1 秒查一次 stop_req 标记
$stop = false;
$lastCheck = 0;
$msgId = 0;   // 提前声明：暂停瞬间要立即落库，不能等最后统一处理
$查暂停 = function () use (&$stop, &$lastCheck, &$answer, &$msgId, $runId, $convId, $me): bool {
    if ($stop) return true;
    $now = microtime(true);
    if ($now - $lastCheck < 1.0) return false;
    $lastCheck = $now;
    // SSE 心跳：上游思考期间可能几十秒不吐字，中间没有任何数据流过，
    // 浏览器、CDN、反代会认为连接空闲而掐断，前端就报"⚠ 网络中断"。
    // 每 1 秒发一行 SSE 注释（以冒号开头），浏览器不显示但能保持连接活跃。
    echo ": heartbeat\n\n";
    flush();
    if ((int) db_val('SELECT stop_req FROM chat_runs WHERE id = ?', [$runId]) !== 1) return false;
    $stop = true;
    // 暂停瞬间立即把已生成的内容落库：用户点了暂停，后面随时可能关页面、
    // 断网络、或 FPM 把进程杀掉，不能等到流收尾才保存。$msgId 非零后，
    // 后面的正式落库和 register_shutdown_function 兜底都会跳过，不会重复插入。
    if ($msgId === 0 && $answer !== '') {
        try {
            $msgId = db_insert('INSERT INTO messages (conv_id, user_id, role, content, created_at)
                                VALUES (?,?,?,?,NOW())',
                [$convId, $me['id'], 'assistant', $answer]);
            db_exec('UPDATE conversations SET updated_at = NOW(),
                     msg_count = (SELECT COUNT(*) FROM messages WHERE conv_id = ?)
                     WHERE id = ? AND user_id = ?', [$convId, $convId, $me['id']]);
        } catch (\Throwable $e) {
            error_log('chat.php 暂停落库失败: ' . $e->getMessage());
        }
    }
    sse('stopped', ['t' => '']);
    return true;
};

// ---- 上游请求：一个 SK 完全没吐出内容就失败时，自动换下一个 SK 重来 ----
// 只在「一个字都没吐出来」且故障码明确是 SK 自身问题（认证失效/欠费/被限流/
// 上游过载）时才换 SK 重试；已经吐了部分正文后失败、或普通的 400 请求错误
// （换哪个 SK 都一样会 400），都不在这里重试，直接把结果原样返回给用户。
$__服务端故障 = sk_http_codes('sk_http_busy_codes',
    [500, 501, 502, 503, 504, 505, 506, 507, 508, 509, 510, 511, 520, 522, 524]);
$__认证类故障 = sk_http_codes('sk_http_auth_codes', [401, 402, 403]);
$__skCanSwitch = array_merge($__认证类故障, $__服务端故障, [429]);
$__triedSk = [];
if ($__skUsed !== null) {
    $__triedSk[$__skUsed] = true;
}

// ==================== 生成工具定义（Function Calling）====================
// 工具列表已在前面生成（第437行），这里直接使用

while (true) {
    $res = upstream_stream(
        $m,
        (function () use ($m, $msgs, $tools): array {
            $body = [
                'model'    => $m['model_name'],
                'messages' => $msgs,
            ];
            // 加入工具定义
            if (!empty($tools)) {
                $body['tools'] = $tools;
                $body['tool_choice'] = 'auto';
                error_log('[DEBUG] 工具数量: ' . count($tools) . ', 第一个工具: ' . ($tools[0]['function']['name'] ?? 'N/A'));
            } else {
                error_log('[DEBUG] 工具列表为空，未启用 Function Calling');
            }
            // 后台配了正数才向上游指定输出上限；填 0 表示交给上游自己的默认值，
            // 这样支持长输出的模型（如 384K 输出）不会被我们这边写死的数字砍短。
            // 注意不能写成 max(256, 值)：那样填 0 会变成 256，比不填更糟。
            // Claude 渠道即使这里不带，upstream_to_claude() 也会兜一个默认值，不会请求失败。
            $上限 = (int) ($m['max_tokens'] ?? 0);
            if ($上限 > 0) {
                $body['max_tokens'] = max(256, $上限);
            }
            return $body;
        })(),
        /**
         * 流式回调：文字即时下发，不做缓冲，保证首字尽快出现。
         *
         * 前言的判定需要「首段 + 空行 + 正文」这个结构，流到中途才具备条件。
         * 所以这里不等待，而是一旦攒到第一个空行就单独发一个 fold 事件，
         * 告诉前端「开头这段是自述」，由前端把已显示的内容重渲染成折叠块。
         * 这样既不牺牲首字延迟，也能正确折叠。
         */
        function (string $delta) use (&$answer, &$foldDone, &$foldFromApi, $runId, &$lastBeat, &$ttftMs, $t0) {
            if ($ttftMs === null) {
                $ttftMs = (int) round((microtime(true) - $t0) * 1000);
            }
            $answer .= $delta;
            sse('delta', ['t' => $delta]);

            // 每 1 秒更新 chat_runs 增量，供断线重连读取
            $now = microtime(true);
            if ($now - $lastBeat >= 1.0) {
                $lastBeat = $now;
                db_exec('UPDATE chat_runs SET answer = ?, beat_at = NOW() WHERE id = ?',
                    [$answer, $runId]);
            }

            // 上游已按字段明确给过思考内容，这轮不需要再用启发式规则去猜，
            // 猜了反而会把正文开头误判成自述、二次折叠
            if ($foldDone || $foldFromApi) {
                return;
            }
            // 还没出现段落分隔（空行或代码块围栏），条件不足，继续等后面的分片
            if (!preg_match('/\R[ \t]*\R|\R
<pre><code>/u', $answer)) {
                return;
            }
            $foldDone = true;
            $r = split_thinking($answer);
            if ($r['think'] !== '') {
                sse('fold', ['t' => $r['think']]);
                // 写入 chat_runs.fold 供重连时读取
                db_exec('UPDATE chat_runs SET fold = ? WHERE id = ?', [$r['think'], $runId]);
            }
        },
        function (array $u) use (&$usage) {
            $usage = array_merge((array) $usage, $u);
        },
        // 等数据的间隙也查暂停，覆盖「上游还在思考、一个字没吐」的时间
        $查暂停,
        /**
         * 思考回调：上游用独立字段给出思考内容时才会走这里
         * （Claude 的 thinking / OpenAI 兼容的 reasoning_content）。
         *
         * 这条通道来的文本来源确定，不需要启发式判断，所以置起 $foldFromApi
         * 让 $onDelta 侧不再拿 split_thinking 去猜。
         *
         * 思考文本同时拼进 $answer 的开头：前端 markFold(acc, prefix) 要求
         * prefix 是 acc 的字面前缀才会折叠，思考若只走 fold 事件、不进 acc，
         * 前端匹配不上就整段丢掉，用户既看不到思考、落库也会少这一段。
         */
        function (string $think) use (&$answer, &$foldText, &$foldFromApi, $runId, &$lastBeat, &$ttftMs, $t0) {
            if ($think === '') {
                return;
            }
            if ($ttftMs === null) {
                $ttftMs = (int) round((microtime(true) - $t0) * 1000);
            }
            $foldFromApi = true;
            $foldText   .= $think;
            // 思考总是先于正文到达，这时 $answer 里还只有思考，直接跟上即可
            $answer     .= $think;
            // 思考也即时下发，让用户看到模型正在推演，不用干等首字。
            // 必须先 fold 再 delta：反过来的话 delta 到达时前端的 foldPrefix
            // 还是上一片的旧值，新片段会被当成正文渲染在折叠块外面，等下一个
            // fold 才收进去，每个分片都跳一次，看起来就是思考在闪。
            sse('fold', ['t' => $foldText]);
            sse('delta', ['t' => $think]);

            $now = microtime(true);
            if ($now - $lastBeat >= 1.0) {
                $lastBeat = $now;
                db_exec('UPDATE chat_runs SET answer = ?, fold = ?, beat_at = NOW() WHERE id = ?',
                    [$answer, $foldText, $runId]);
            }
        }
    );

    // ---- 工具调用处理 ----
    $toolCallsReturned = $res['tool_calls'] ?? [];
    error_log('[TOOL_DEBUG] AI返回的tool_calls数量: ' . count($toolCallsReturned) . ', res[ok]=' . ($res['ok'] ? 'true' : 'false'));
    if (!empty($toolCallsReturned)) {
        error_log('[TOOL_DEBUG] 工具调用详情: ' . json_encode($toolCallsReturned, JSON_UNESCAPED_UNICODE));
    }
    if (!empty($toolCallsReturned) && $res['ok']) {
        // 支持多工具调用：允许AI一次返回多个 tool_calls，全部执行和发送
        // 前端（网页端和安卓端）已支持多卡片显示
        require_once __DIR__ . '/../inc/tool_execute.php';

        // 执行每个工具调用
        $toolResults = [];
        // 挂点：工具结果统一挂到当前会话最后一条消息后面（断点重续用）
        $挂点 = (int) db_val("SELECT COALESCE(MAX(id),0) FROM messages WHERE conv_id = ? AND user_id = ?", [$convId, $me['id']]);
        foreach ($toolCallsReturned as $tc) {
            $toolId = $tc['id'] ?? '';
            $toolName = $tc['function']['name'] ?? '';
            $toolArgs = $tc['function']['arguments'] ?? '{}';

            // 断点重续：跳过已执行的工具
            if (in_array($toolId, $executedTools, true)) {
                // 该工具已执行过，从数据库读取结果
                $savedResult = db_first(
                    'SELECT content AS result FROM tool_results WHERE conv_id = ? AND tool_call_id = ? ORDER BY id DESC LIMIT 1',
                    [$convId, $toolId]
                );
                $result = $savedResult ? $savedResult['result'] : "（已执行，但未找到结果记录）";

                $toolResults[] = [
                    'tool_call_id' => $toolId,
                    'role' => 'tool',
                    'content' => $result
                ];

                // 不在这里单独发送，统一在下面的第1087行一次性发送
                continue; // 跳过执行，直接使用缓存结果
            }

            // 解析参数
            $args = json_decode($toolArgs, true);
            if (!is_array($args)) {
                $args = [];
            }

            // 注入会话信息供 SFTP/Repo 工具使用
            $args['_conv_id'] = $convId;

            // 执行工具
            try {
                $result = '';
                switch ($toolName) {
                    case 'ssh_exec':
                        $result = tool_execute_ssh((int) $me['id'], $args);
                        break;
                    case 'sftp_read':
                    case 'sftp_patch':
                    case 'sftp_write':
                    case 'sftp_list':
                    case 'sftp_delete':
                        $result = tool_execute_sftp($toolName, (int) $me['id'], $args);
                        break;
                    case 'file_read':
                    case 'file_patch':
                    case 'file_delete':
                    case 'file_write':
                    case 'file_list':
                    case 'file_push':
                        $result = tool_execute_repo($toolName, (int) $me['id'], (int) $convId, $args);
                        break;
                    case 'ws_read':
                    case 'ws_patch':
                    case 'ws_delete':
                    case 'ws_zip':
                    case 'ws_write':
                    case 'ws_list':
                        $result = tool_execute_ws($toolName, (int) $me['id'], $args);
                        break;
                    case 'web_open':
                        $result = tool_execute_web((int) $me['id'], $args);
                        break;
                    case 'web_search':
                        $result = tool_execute_web_search((int) $me['id'], $args);
                        break;
                    case 'ppt_generate':
                        $result = tool_execute_ppt((int) $me['id'], $args);
                        break;
                    default:
                        $result = "未知工具：{$toolName}";
                }
            } catch (Throwable $e) {
                $result = "工具执行错误：" . $e->getMessage();
            }

            $toolResults[] = [
                'tool_call_id' => $toolId,
                'role' => 'tool',
                'content' => $result
            ];

            // 存储工具结果到数据库，用于断点重续
            try {
                $stmt = db()->prepare('INSERT INTO tool_results (user_id, conv_id, after_msg_id, tool_call_id, kind, content, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$me['id'], $convId, $挂点, $toolId, 'other', $result]);
            } catch (Exception $e) {
                error_log("存储工具结果失败: " . $e->getMessage());
            }
        }

        // 一次性发送所有工具调用结果到前端
        error_log("发送tool_result事件: calls=" . json_encode($toolCallsReturned) . ", results=" . json_encode($toolResults));
        sse('tool_result', ['calls' => $toolCallsReturned, 'results' => $toolResults]);

        // 把工具调用和结果追加到消息历史
        $msgs[] = [
            'role' => 'assistant',
            'content' => $answer !== '' ? $answer : null,
            'tool_calls' => $toolCallsReturned
        ];

        foreach ($toolResults as $tr) {
            $msgs[] = $tr;
        }
        
        // 清空当前回答，准备接收下一轮。
        // $foldDone 也要复位：新一轮可能又以思考前言开头，若不复位，
        // 首个空行处的判定会被跳过、fold 事件不发，思考内容会当正文直发，
        // 表现就是「工具调用后思考卡片突然打开、正文里露出思考」
        $answer = '';
        $foldText = '';
        $foldFromApi = false;
        $foldDone = false;
        
        // 继续循环，让模型根据工具结果生成最终回答
        continue;
    }

    // ---- 坏 SK 熔断与健康度回写（每试一个 SK 都要记一次账）----
    $__badHttp = (int) ($res['http'] ?? 0);
    $__chIdNow = (int) ($m['ch_id'] ?? 0);
    if ($__skUsed !== null && $__chIdNow > 0) {
        if (!$res['ok'] && in_array($__badHttp, $__skCanSwitch, true)) {
            channel_sk_fail($__chIdNow, (int) $__skUsed, $__badHttp, (string) $res['error']);
            error_log(sprintf('[sk] 渠道 %d 的 SK #%d 失败(HTTP %d)，已记账',
                $__chIdNow, (int) $__skUsed + 1, $__badHttp));
            // 认证类和服务端故障都解绑本对话：前者 key 废了/欠费短期回不来，
            // 后者当前 SK 对应的上游节点在过载，继续锁在它身上只会一直失败。
            // 429 不解绑——它只是被打满，选 key 时会临时借别的用，冷却完还能回原 key 复用缓存。
            if (in_array($__badHttp, array_merge($__认证类故障, $__服务端故障), true) && $__skTotal > 1) {
                db_exec('UPDATE conversations SET sk_index = NULL WHERE id = ? AND user_id = ? AND sk_index = ?',
                    [$convId, $me['id'], (int) $__skUsed]);
            }
        } elseif ($res['ok']) {
            // 成功一次就把失败计数清零，避免零星抖动累积到熔断
            channel_sk_ok($__chIdNow, (int) $__skUsed);
        }
    }

    // 是否要换下一个 SK 重来：本次一个字都没吐出来、故障码属于「换 SK 有意义」
    // 的类型、还有别的 SK 没试过、而且用户没有主动暂停。
    // 只看正文：$answer 开头可能是上游先吐的思考，思考不算有效输出，
    // 拿它挡住切换会让「只想了几句就失败」的请求白白丢掉重试机会。
    $__正文已出 = substr($answer, strlen($foldText)) !== '';
    $__canSwitch = !$res['ok'] && !$__正文已出 && !$stop && $__skTotal > 1
        && count($__triedSk) < $__skTotal
        && in_array($__badHttp, $__skCanSwitch, true);
    if (!$__canSwitch) {
        break;
    }
    // 换 SK 是整轮重发，上一个 SK 吐的思考不能留：否则新 SK 的思考会接在
    // 后面拼成两份，落库和折叠边界也跟着错位
    if ($foldText !== '') {
        $answer      = '';
        $foldText    = '';
        $foldFromApi = false;
        db_exec('UPDATE chat_runs SET answer = \'\', fold = \'\' WHERE id = ?', [$runId]);
    }
    $__nextIdx = null;
    for ($k = 0; $k < $__skTotal; $k++) {
        if (!isset($__triedSk[$k])) {
            $__nextIdx = $k;
            break;
        }
    }
    if ($__nextIdx === null) {
        break;
    }
    $__triedSk[$__nextIdx] = true;
    $m['api_key'] = $__skRawList[$__nextIdx] ?? $m['api_key'];
    $__skUsed = $__nextIdx;
    // 游标跟着这次真实用到的 SK 走，下一个新会话从它之后一位开始分配，
    // 而不是停在旧值上看不出刚才切过线路。
    if ($__chIdNow > 0 && $__skTotal > 0) {
        db_exec('UPDATE channels SET rotate_idx = ? WHERE id = ?',
            [($__nextIdx + 1) % $__skTotal, $__chIdNow]);
    }
    sse('retry_sk', ['t' => '当前线路暂时不可用，正在自动切换到其它线路重试…']);
}

// 如果被暂停，补写最后一次增量
if ($stop) {
    db_exec('UPDATE chat_runs SET answer = ?, beat_at = NOW(), status = \'stopped\' WHERE id = ?',
        [$answer, $runId]);
    // 用户手动停止后，直接把已生成的内容落库，避免白费
    // 思考来自上游精确字段时按已知边界切，不能再跑启发式：$answer 开头是思考，
    // 规则很可能把它和正文的分界猜错，白丢内容
    if ($foldFromApi) {
        $answerFinal = wrap_thinking($foldText, substr($answer, strlen($foldText)));
    } else {
        $rr = split_thinking($answer);
        $answerFinal = wrap_thinking($rr['think'], $rr['body']);
    }
    $answerRaw = $answer;
    $answer = $answerFinal;
}

// 短回答里可能一个空行都没有，流结束时再判一次。
// $foldFromApi 为真表示思考已由上游字段明确给出、fold 事件也发过了，跳过启发式
if (!$foldDone && !$foldFromApi && !$stop) {
    $r = split_thinking($answer);
    if ($r['think'] !== '') {
        sse('fold', ['t' => $r['think']]);
        db_exec('UPDATE chat_runs SET fold = ? WHERE id = ?', [$r['think'], $runId]);
    }
}
// 计费仍按上游实际产出的原文估算（剥离只影响展示，不该少扣费）
$answerRaw = $answerRaw ?? $answer;
// 落库用剥离后的正文，保证刷新后看到的和当时显示的一致
if (!$stop) {
    if ($foldFromApi) {
        // 思考边界是上游字段给的，按长度直接切，不让启发式再猜一遍
        $answer = wrap_thinking($foldText, substr($answer, strlen($foldText)));
    } else {
        $rr    = split_thinking($answer);
        $answer = wrap_thinking($rr['think'], $rr['body']);
    }
}

// ---- 计费结算 ----
$promptText = '';
foreach ($msgs as $mm) {
    if (is_array($mm['content'])) {
        // 多模态消息：只累计文字部分，图片按固定 token 数粗估
        foreach ($mm['content'] as $part) {
            if (($part['type'] ?? '') === 'text') {
                $promptText .= (string) ($part['text'] ?? '') . "\n";
            } elseif (($part['type'] ?? '') === 'image_url') {
                // 一张图按 800 token 估，仅在上游未返回 usage 时用作兜底
                $promptText .= str_repeat('x', 3200) . "\n";
            }
        }
    } else {
        $promptText .= $mm['content'] . "\n";
    }
}
$estimated = 0;
// 命中缓存的输入量。只有上游明确报了才算，估算模式下一律按 0
$tcache = 0;
$tcache_create = 0;
// 坏 SK 熔断记账已经挪进上面的重试循环里逐次处理了，这里不用再算一次。
$failed = (!$res['ok'] && $answer === '');
if ($failed) {
    // 上游失败且没有任何输出：不计 token、不扣费，只留错误日志
    $tin = 0;
    $tout = 0;
} elseif (is_array($usage) && (int) ($usage['prompt_tokens'] ?? 0) > 0) {
    $tin  = (int) $usage['prompt_tokens'];
    $tout = (int) ($usage['completion_tokens'] ?? 0);
    $tcache = (int) ($usage['cached_tokens'] ?? $usage['prompt_cache_hit_tokens'] ?? 0);
    $tcache_create = (int) ($usage['cache_create_tokens'] ?? $usage['prompt_cache_miss_tokens'] ?? 0);
} else {
    $estimated = 1;
    $tin  = estimate_tokens($promptText);
    $tout = estimate_tokens($answerRaw);
}
$cost = calc_cost($tin, $tout, $m['price_in'], $m['price_out'], $tcache, $m['price_cache'] ?? 0, $tcache_create, $m['price_cache_create'] ?? 0);
$latency = (int) round((microtime(true) - $t0) * 1000);
$logStatus = $res['ok'] ? 'ok' : 'error';
$logError  = $res['ok'] ? '' : mb_substr((string) $res['error'], 0, 480);
// 重试过就记下来，便于在后台看出上游抖动的频率
if (!$res['ok'] && (int) ($res['attempts'] ?? 1) > 1) {
    $logError = '[重试 ' . (int) $res['attempts'] . ' 次] ' . mb_substr($logError, 0, 460);
}

try {
    db()->beginTransaction();
    // 暂停时 $查暂停 已提前把半截内容落库并设好 $msgId，这里不能重置为 0，
    // 否则 register_shutdown_function 的兜底会再插一条重复消息。
    if (!isset($msgId)) { $msgId = 0; }
    if ($msgId === 0 && $answer !== '') {
        $msgId = db_insert('INSERT INTO messages (conv_id, user_id, role, content,
                            tokens_in, tokens_out, tokens_cache, tokens_cache_create, cost, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,NOW())',
            [$convId, $me['id'], 'assistant', $answer, $tin, $tout, $tcache, $tcache_create, $cost]);
    }
    // 上游失败且无输出时：不入库错误信息——错误通过 SSE err 事件直接发给前端，
    // 不再写进 messages 表。chat_runs.err_msg 仍记录错误供后台排查。
    db_exec('UPDATE conversations SET updated_at = NOW(), model_id = ?,
                    msg_count = (SELECT COUNT(*) FROM messages WHERE conv_id = ?)
              WHERE id = ? AND user_id = ?', [$modelId, $convId, $convId, $me['id']]);

    if ($tin + $tout > 0) {
        db_exec('UPDATE users SET balance = balance - ?, used_tokens = used_tokens + ?, total_cost = total_cost + ?
                  WHERE id = ?', [$cost, $tin + $tout, $cost, $me['id']]);
        // 消费记负数：账单页按 amount 的正负决定显示 +/- 与红绿色。
        // 零单价渠道不写流水，避免账单里刷一堆 0.0000 的空记录。
        if ($cost > 0) {
            $余额后 = (float) db_val('SELECT balance FROM users WHERE id = ?', [$me['id']]);
            db_exec('INSERT INTO balance_logs (user_id, amount, balance_after, type, note, created_at)
                     VALUES (?,?,?,?,?,NOW())',
                [$me['id'], -$cost, $余额后, 'consume',
                 $m['model_name'] . '，输入 ' . $tin . ' / 输出 ' . $tout . ' token']);
        }
    }
    // 成功或失败都留一条日志，便于后台排查上游问题
    if ($tin + $tout > 0 || $logStatus === 'error') {
        $clientType = strtolower(trim((string) ($_SERVER['HTTP_X_CLIENT_TYPE'] ?? '')));
        if (!in_array($clientType, ['android', 'desktop', 'web'], true)) {
            $clientType = '';  // 不认识的值统一记空，便于后台筛选「未标注」
        }
        db_exec('INSERT INTO usage_logs (user_id, conv_id, channel_id, model_name, tokens_in, tokens_out,
                        tokens_cache, tokens_cache_create, cost, is_estimated, status, error_msg, latency_ms, ttft_ms, ip, client_type, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
            [$me['id'], $convId, $m['ch_id'], $m['model_name'], $tin, $tout, $tcache, $tcache_create, $cost, $estimated,
             $logStatus, $logError, $latency, $ttftMs, client_ip(), $clientType]);
    }
    db()->commit();

    // 更新 chat_runs 为最终状态，供重连接口读取
    $newBal = db_val('SELECT balance FROM users WHERE id = ?', [$me['id']]);
    $runStatus = $stop ? 'stopped' : ($failed ? 'error' : 'done');
    db_exec('UPDATE chat_runs SET status = ?, answer = ?, tokens_in = ?, tokens_out = ?,
                    tokens_cache = ?, tokens_cache_create = ?, cost = ?, is_estimated = ?,
                    err_msg = ?, msg_id = ?, balance = ?, beat_at = NOW()
              WHERE id = ?',
        [$runStatus, $answer, $tin, $tout, $tcache, $tcache_create, $cost, $estimated,
         $logError, $msgId, $newBal, $runId]);
} catch (Throwable $e) {
    error_log('chat.php save error: ' . $e->getMessage());
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    // 即使落库失败也要标记任务结束，否则重连会一直轮询
    db_exec('UPDATE chat_runs SET status = \'error\', err_msg = ?, beat_at = NOW() WHERE id = ?',
        [mb_substr($e->getMessage(), 0, 480), $runId]);
}

$newBal = $newBal ?? db_val('SELECT balance FROM users WHERE id = ?', [$me['id']]);

if (!$res['ok'] && $answer === '') {
    sse('err', ['msg' => $res['error'] ?: '生成失败，请重试']);
}
sse('done', [
    'conv_id'   => $convId,
    'run_id'    => $runId,
    'msg_id'    => (int) ($msgId ?? 0),
    'tokens_in' => $tin,
    'tokens_out'=> $tout,
    'tokens_cache' => $tcache,
    'tokens_cache_create' => $tcache_create,
    'cost'      => $cost,
    'estimated' => $estimated,
    'balance'   => money($newBal),
]);
exit;
