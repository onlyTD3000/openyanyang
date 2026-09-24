<?php
/**
 * 回归测试：工具回执的来源标记。
 *
 * 背景 bug：api/chat.php 里回执分两条路径进上下文——历史回执裹了
 * tool_result_wrap，本轮回执直接走 build_chat_msg 裸着进去。模型收到无标记的
 * user 消息只能当成用户在说话，于是把机器回执误读成用户的答复。
 *
 * 这里锁住两条路径的一致性，以及标记在合并逻辑后仍然存活。
 */
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/inc/helpers.php';
require_once APP_ROOT . '/inc/tool_results.php';
$通过 = 0;
$失败 = 0;
function 断言(string $名, bool $条件): void
{
    global $通过, $失败;
    if ($条件) { $通过++; echo "✔ {$名}\n"; }
    else        { $失败++; echo "✖ {$名}\n"; }
}
/** 复刻 chat.php 里本轮消息进上下文的那段判断 */
function 本轮消息(string $toolKind, string $content): array
{
    return $toolKind !== ''
        ? ['role' => 'user', 'content' => tool_result_wrap($content)]
        : ['role' => 'user', 'content' => $content];
}
/** 复刻 chat.php 的同角色合并 */
function 合并(array $msgs): array
{
    $出 = [];
    foreach ($msgs as $一条) {
        $尾 = count($出) - 1;
        if ($尾 >= 0 && $出[$尾]['role'] === $一条['role'] && $一条['role'] !== 'system') {
            $出[$尾]['content'] = merge_msg_content($出[$尾]['content'], $一条['content']);
            continue;
        }
        $出[] = $一条;
    }
    return $出;
}
$回执 = 本轮消息('ssh', "命令执行结果：\n退出码：0");
断言('本轮工具回执带来源标记', strpos($回执['content'], TOOL_RESULT_MARK_OPEN) === 0);
断言('标记有闭合', substr($回执['content'], -strlen(TOOL_RESULT_MARK_CLOSE)) === TOOL_RESULT_MARK_CLOSE);
$人话 = 本轮消息('', '继续');
断言('用户真实消息不加标记', strpos($人话['content'], TOOL_RESULT_MARK_OPEN) === false);
// 五种工具类型都要裹
foreach (['ssh', 'sftp', 'repo', 'ws', 'ppt', 'other'] as $种) {
    断言("{$种} 类回执同样裹标记", strpos(本轮消息($种, 'x')['content'], TOOL_RESULT_MARK_OPEN) === 0);
}
// 历史回执和本轮回执裹出来的形状必须一致，否则模型要认两种格式
断言('历史与本轮的包裹结果一致',
    tool_result_wrap('同样的正文') === 本轮消息('ssh', '同样的正文')['content']);
// 合并后标记不能丢
$并 = 合并([['role' => 'user', 'content' => '前一条'], $回执]);
断言('与前一条 user 合并后标记存活',
    count($并) === 1
    && strpos($并[0]['content'], TOOL_RESULT_MARK_OPEN) !== false
    && strpos($并[0]['content'], TOOL_RESULT_MARK_CLOSE) !== false);
$并2 = 合并([['role' => 'assistant', 'content' => '我来执行'], $回执]);
断言('assistant 之后不合并，标记在开头',
    count($并2) === 2 && strpos($并2[1]['content'], TOOL_RESULT_MARK_OPEN) === 0);
// 落盘的正文必须是原文，标记只加在发给上游的那份
断言('落盘正文不含标记', strpos("命令执行结果：\n退出码：0", TOOL_RESULT_MARK_OPEN) === false);
// 提示词里的标记要和常量一致，三处对不上模型就认不出
$提示 = tool_result_system_prompt();
断言('系统提示词含开标记', strpos($提示, TOOL_RESULT_MARK_OPEN) !== false);
断言('系统提示词含闭标记', strpos($提示, TOOL_RESULT_MARK_CLOSE) !== false);
echo "\n通过 {$通过}，失败 {$失败}\n";
exit($失败 > 0 ? 1 : 0);
