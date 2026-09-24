package cn.bot77.yanyang.ui
import androidx.compose.animation.AnimatedVisibility
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.widget.Toast
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.ui.platform.LocalContext
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import cn.bot77.yanyang.data.消息
import androidx.compose.ui.text.style.TextOverflow
/**
 * 一条消息。
 * 用户消息靠右、带主色底；助手消息占满宽度、不套气泡，长文读起来更舒服。
 */
@Composable
fun 消息条(消: 消息) {
    when {
        消.是回执 -> 回执条(消)
        消.是用户 -> 用户条(消)
        else -> 助手条(消)
    }
}
/**
 * 工具回执条：命令输出这类东西不是用户说的话，
 * 所以不画成用户气泡，而是一条默认收起的窄卡片，点开才看全文。
 */
@Composable
private fun 回执条(消: 消息) {
    var 展开 by remember { mutableStateOf(false) }
    // 首行拿来做摘要，通常是「命令执行结果：」这类
    val 摘要 = 消.正文.lineSequence().firstOrNull { it.isNotBlank() } ?: "执行结果"
    Column(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp)) {
        Surface(
            color = MaterialTheme.colorScheme.surfaceVariant,
            shape = RoundedCornerShape(8.dp),
            modifier = Modifier.fillMaxWidth().clickable { 展开 = !展开 }
        ) {
            Column(Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        if (展开) "▾" else "▸",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                    Spacer(Modifier.width(6.dp))
                    Text(
                        摘要.take(40),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                }
                if (展开) {
                    Spacer(Modifier.height(6.dp))
                    Text(
                        消.正文,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        fontFamily = FontFamily.Monospace
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun 用户条(消: 消息) {
    val 上下文 = LocalContext.current
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp),
        horizontalArrangement = Arrangement.End
    ) {
        Surface(
            color = MaterialTheme.colorScheme.primary,
            shape = RoundedCornerShape(14.dp, 14.dp, 4.dp, 14.dp),
            modifier = Modifier
                .widthIn(max = 300.dp)
                .combinedClickable(
                    onClick = { },
                    onLongClick = {
                        val 剪贴板 = 上下文.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                        剪贴板.setPrimaryClip(ClipData.newPlainText("user_msg", 消.正文))
                        Toast.makeText(上下文, "已复制", Toast.LENGTH_SHORT).show()
                    }
                )
        ) {
            Text(
                消.正文,
                Modifier.padding(horizontal = 14.dp, vertical = 10.dp),
                color = MaterialTheme.colorScheme.onPrimary,
                style = MaterialTheme.typography.bodyLarge
            )
        }
    }
}
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun 助手条(消: 消息) {
    // 整轮回复裹在一张卡片里：多轮自动执行时连着好几条回复，
    // 没有边界的话会糊成一片，分不清哪句是哪一轮说的。
    val 上下文 = LocalContext.current
    Surface(
        color = MaterialTheme.colorScheme.surfaceContainerHighest,
        shape = RoundedCornerShape(12.dp),
        tonalElevation = 4.dp,
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 10.dp, vertical = 5.dp)
            .combinedClickable(
                onClick = { },
                onLongClick = {
                    val 剪贴板 = 上下文.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                    剪贴板.setPrimaryClip(ClipData.newPlainText("assistant_msg", 消.正文))
                    Toast.makeText(上下文, "已复制", Toast.LENGTH_SHORT).show()
                }
            )
    ) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 10.dp)) {
        // 思考过程默认折叠。想看推理就展开，不看就不占地方
        if (消.思考.isNotBlank()) {
            var 展开 by remember { mutableStateOf(false) }
            Row(
                Modifier
                    .clip(RoundedCornerShape(6.dp))
                    .clickable { 展开 = !展开 }
                    .padding(vertical = 4.dp, horizontal = 6.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Icon(
                    if (展开) Icons.Default.ExpandLess else Icons.Default.ExpandMore,
                    contentDescription = if (展开) "收起思考过程" else "展开思考过程",
                    modifier = Modifier.size(18.dp),
                    tint = MaterialTheme.colorScheme.onSurfaceVariant
                )
                Spacer(Modifier.width(4.dp))
                Text(
                    "思考过程",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            AnimatedVisibility(展开) {
                Surface(
                    color = MaterialTheme.colorScheme.surfaceVariant,
                    shape = RoundedCornerShape(8.dp),
                    modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp)
                ) {
                    Text(
                        消.思考,
                        Modifier.padding(10.dp),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }
        }
        // 混合渲染：按正文中代码块顺序交替渲染文字和工具卡片
        if (消.正文.isNotBlank() || 消.有工具) {
            混合渲染(消)
        } else if (消.在输出) {
            // 还没吐字时给个转圈，别让界面看着像卡住
            Row(Modifier.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                Spacer(Modifier.width(8.dp))
                Text(
                    "正在思考…",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }
        if (消.用量.isNotBlank()) {
            Text(
                消.用量,
                Modifier.padding(top = 6.dp),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }
    }
}

/**
 * 混合渲染：按正文中代码块的出现顺序，交替渲染文字段落和工具卡片。
 * 
 * AI的回复格式：正文中包含 ```工具名 的代码块，代码块位置就是工具应该出现的位置。
 * 例如：文字1 → ```ssh-exec{...}``` → 文字2 → ```ssh-exec{...}``` → 文字3
 * 应渲染为：文字1 → 卡片1 → 文字2 → 卡片2 → 文字3
 */
@Composable
private fun 混合渲染(消: 消息) {
    val 正文 = 消.正文
    val 工具调用 = 消.工具调用
    val 工具结果 = 消.工具结果
    
    // 找出所有代码块的位置（```开头到```结束）
    val 代码块正则 = Regex("```([a-z-]+)(.*?)```", RegexOption.DOT_MATCHES_ALL)
    val 匹配列表 = 代码块正则.findAll(正文).toList()
    
    if (匹配列表.isEmpty()) {
        // 没有代码块，纯文字或者只有工具调用
        if (正文.isNotBlank()) {
            正文渲染(正文)
        }
        // 如果有工具但正文中没有代码块（可能是流式还没完成），在末尾显示
        if (消.有工具 && 工具调用.isNotEmpty()) {
            工具调用.forEach { call ->
                val 类型 = 工具类型.从名称识别(call.名称)
                if (类型 != null) {
                    val 结果 = 工具结果.find { it.id == call.id }
                    工具卡片(
                        类型 = 类型,
                        代码 = call.参数,
                        输出 = 结果?.内容 ?: "",
                        状态 = if (结果 != null) 卡片状态.成功 else 卡片状态.执行中
                    )
                }
            }
        }
        return
    }
    
    // 先渲染所有工具卡片（放最上面），再渲染正文文字（放下面）
    
    // 第一部分：工具卡片
    var 工具索引 = 0
    匹配列表.forEach { 匹配 ->
        if (工具索引 < 工具调用.size) {
            val call = 工具调用[工具索引]
            val 类型 = 工具类型.从名称识别(call.名称)
            if (类型 != null) {
                val 结果 = 工具结果.find { it.id == call.id }
                工具卡片(
                    类型 = 类型,
                    代码 = call.参数,
                    输出 = 结果?.内容 ?: "",
                    状态 = if (结果 != null) 卡片状态.成功 else 卡片状态.执行中
                )
            }
            工具索引++
        }
    }

    // 第二部分：正文文字（去掉代码块后合并输出）
    val 文字部分 = buildString {
        var 上次位置 = 0
        匹配列表.forEach { 匹配 ->
            if (匹配.range.first > 上次位置) {
                append(正文.substring(上次位置, 匹配.range.first))
            }
            上次位置 = 匹配.range.last + 1
        }
        if (上次位置 < 正文.length) {
            append(正文.substring(上次位置))
        }
    }
    val 清理后 = 文字部分
        .replace(Regex("<thinking>.*?</thinking>", RegexOption.DOT_MATCHES_ALL), "")
        .replace(Regex("\n{3,}"), "\n\n")
        .trim()
    if (清理后.isNotBlank()) {
        普通段(清理后)
    }
}

/**
 * 渲染正文，只处理普通文本。
 * 工具卡片通过 tool_use 字段渲染，不再从正文中解析代码块。
 * 过滤掉思考标记和代码块，避免重复显示。
 */
@Composable
private fun 正文渲染(正文: String) {
    val 清理后 = remember(正文) {
        var 结果 = 正文
        // 1. 移除 <thinking>...</thinking> 标记（避免与折叠卡片重复）
        结果 = 结果.replace(Regex("<thinking>.*?</thinking>", RegexOption.DOT_MATCHES_ALL), "")
        // 2. 移除所有代码块（工具卡片已经单独渲染）
        结果 = 结果.replace(Regex("```[a-z-]+.*?```", RegexOption.DOT_MATCHES_ALL), "")
        // 3. 清理多余空行
        结果.replace(Regex("\n{3,}"), "\n\n").trim()
    }
    if (清理后.isNotBlank()) {
        普通段(清理后)
    }
}
@Composable
private fun 普通段(文: String) {
    Text(
        text = 渲染行内(文),
        style = MaterialTheme.typography.bodyLarge,
        modifier = Modifier.padding(vertical = 2.dp)
    )
}
/** 处理加粗和行内代码两种行内标记 */
@Composable
private fun 渲染行内(文: String) = remember(文) {
    buildAnnotatedString {
        var i = 0
        while (i < 文.length) {
            when {
                文.startsWith("**", i) -> {
                    val 尾 = 文.indexOf("**", i + 2)
                    if (尾 < 0) {
                        append(文.substring(i)); i = 文.length
                    } else {
                        withStyle(SpanStyle(fontWeight = FontWeight.Bold)) {
                            append(文.substring(i + 2, 尾))
                        }
                        i = 尾 + 2
                    }
                }
                文[i] == '`' -> {
                    val 尾 = 文.indexOf('`', i + 1)
                    if (尾 < 0) {
                        append(文.substring(i)); i = 文.length
                    } else {
                        withStyle(SpanStyle(fontFamily = FontFamily.Monospace)) {
                            append(文.substring(i + 1, 尾))
                        }
                        i = 尾 + 1
                    }
                }
                else -> {
                    append(文[i]); i++
                }
            }
        }
    }
}
