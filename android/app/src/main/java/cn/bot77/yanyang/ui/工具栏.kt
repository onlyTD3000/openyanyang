package cn.bot77.yanyang.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.KeyboardArrowUp
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.lazy.LazyListState
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/**
 * 输入框上方的工具栏，包含三个按钮：
 * 1. ↑上一条需求 - 跳到上一条用户消息
 * 2. ⚙上下文 - 上下文条数设置（会话级，落库持久保存）
 * 3. ⚙工具 - 弹出工具开关面板
 *
 * 与网页端 chat.php 对齐：「跟随提问语言」功能已下线（后端接口已删），
 * 按钮一并移除；工具开关列表同步为网页端的 21 项。
 */
@Composable
fun 工具栏(
    状态: 主状态,
    列表态: LazyListState,
    协程域: CoroutineScope
) {
    var 显示工具面板 by remember { mutableStateOf(false) }
    var 显示上下文 by remember { mutableStateOf(false) }

    val 有会话 = 状态.当前会话.value != null
    val 条数 = 状态.当前会话.value?.上下文条数 ?: 0

    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 12.dp, vertical = 6.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        // ↑上一条需求按钮
        OutlinedButton(
            onClick = {
                协程域.launch {
                    // 找当前可见区域前面最近的一条用户消息
                    val 可见项 = 列表态.layoutInfo.visibleItemsInfo
                    val 第一个可见 = 可见项.firstOrNull()?.index ?: 0

                    // 往前找用户消息
                    val 上一条索引 = if (第一个可见 > 0) {
                        状态.消息表.subList(0, 第一个可见)
                            .indexOfLast { it.是用户 }
                    } else -1

                    if (上一条索引 >= 0) {
                        列表态.animateScrollToItem(上一条索引)
                    }
                }
            },
            modifier = Modifier.height(32.dp),
            shape = RoundedCornerShape(999.dp),
            colors = ButtonDefaults.outlinedButtonColors(
                containerColor = Color.Transparent,
                contentColor = MaterialTheme.colorScheme.onSurfaceVariant
            ),
            contentPadding = PaddingValues(horizontal = 10.dp, vertical = 0.dp),
            enabled = 状态.消息表.count { it.是用户 } > 0
        ) {
            Icon(
                Icons.Filled.KeyboardArrowUp,
                contentDescription = null,
                modifier = Modifier.size(14.dp)
            )
            Spacer(Modifier.width(4.dp))
            Text("上一条需求", fontSize = 12.sp)
        }

        // ⚙上下文按钮：有自定义值时把条数标在按钮上，一眼看出当前会话的窗口大小
        OutlinedButton(
            onClick = { 显示上下文 = true },
            enabled = 有会话,
            modifier = Modifier.height(32.dp),
            shape = RoundedCornerShape(999.dp),
            colors = ButtonDefaults.outlinedButtonColors(
                containerColor = if (条数 in 2..60)
                    MaterialTheme.colorScheme.primaryContainer
                else
                    Color.Transparent,
                contentColor = if (条数 in 2..60)
                    MaterialTheme.colorScheme.primary
                else
                    MaterialTheme.colorScheme.onSurfaceVariant
            ),
            contentPadding = PaddingValues(horizontal = 10.dp, vertical = 0.dp)
        ) {
            Icon(
                Icons.Filled.Settings,
                contentDescription = null,
                modifier = Modifier.size(14.dp)
            )
            Spacer(Modifier.width(4.dp))
            Text(
                if (条数 in 2..60) "上下文 $条数" else "上下文",
                fontSize = 12.sp
            )
        }

        Spacer(Modifier.weight(1f))

        // ⚙工具按钮
        OutlinedButton(
            onClick = { 显示工具面板 = true },
            modifier = Modifier.height(32.dp),
            shape = RoundedCornerShape(999.dp),
            colors = ButtonDefaults.outlinedButtonColors(
                containerColor = Color.Transparent,
                contentColor = MaterialTheme.colorScheme.onSurfaceVariant
            ),
            contentPadding = PaddingValues(horizontal = 10.dp, vertical = 0.dp)
        ) {
            Icon(
                Icons.Filled.Settings,
                contentDescription = null,
                modifier = Modifier.size(14.dp)
            )
            Spacer(Modifier.width(4.dp))
            Text("工具", fontSize = 12.sp)
        }
    }

    // 工具开关弹窗
    if (显示工具面板) {
        工具面板对话框(
            状态 = 状态,
            关闭 = { 显示工具面板 = false }
        )
    }

    // 上下文条数弹窗
    if (显示上下文) {
        上下文对话框(
            状态 = 状态,
            关闭 = { 显示上下文 = false }
        )
    }
}

/**
 * 上下文条数设置对话框（会话级）。
 *
 * 空输入 = 跟随模型默认；2..60 = 自定义。保存后随会话落库，
 * 切换会话自动恢复各自的设置，行为与网页端一致。
 */
@Composable
private fun 上下文对话框(
    状态: 主状态,
    关闭: () -> Unit
) {
    val 当前值 = 状态.当前会话.value?.上下文条数 ?: 0
    var 输入 by remember {
        mutableStateOf(if (当前值 in 2..60) 当前值.toString() else "")
    }

    AlertDialog(
        onDismissRequest = 关闭,
        title = {
            Text(
                "上下文条数",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold
            )
        },
        text = {
            Column(Modifier.fillMaxWidth()) {
                OutlinedTextField(
                    value = 输入,
                    onValueChange = { 输入 = it.filter { c -> c.isDigit() }.take(2) },
                    label = { Text("条数") },
                    placeholder = { Text("默认") },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )
                Spacer(Modifier.height(10.dp))
                Text(
                    "会话级设置：保存后只对当前会话生效，切换会话自动恢复各自的设置。" +
                        "范围 2~60，留空为跟随模型默认。",
                    fontSize = 12.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    val 值 = 输入.trim().toIntOrNull()
                    if (值 == null) {
                        状态.提示.value = "请输入 2 到 60，或清空后点恢复默认"
                        return@TextButton
                    }
                    状态.设上下文条数(值)
                    关闭()
                }
            ) {
                Text("应用")
            }
        },
        dismissButton = {
            Row {
                TextButton(
                    onClick = {
                        状态.设上下文条数(0)
                        关闭()
                    }
                ) {
                    Text("恢复默认")
                }
                Spacer(Modifier.width(4.dp))
                TextButton(onClick = 关闭) {
                    Text("取消")
                }
            }
        }
    )
}

/**
 * 工具开关面板对话框。
 *
 * 21 个 Function Calling 工具，与网页端 chat.php 的工具弹窗一一对应。
 */
@Composable
private fun 工具面板对话框(
    状态: 主状态,
    关闭: () -> Unit
) {
    // 临时保存工具开关状态
    var 临时工具 by remember {
        mutableStateOf(
            mapOf(
                "ssh_exec" to 状态.我的.value.tool_ssh_exec,
                "sftp_read" to 状态.我的.value.tool_sftp_read,
                "sftp_write" to 状态.我的.value.tool_sftp_write,
                "sftp_list" to 状态.我的.value.tool_sftp_list,
                "sftp_delete" to 状态.我的.value.tool_sftp_delete,
                "sftp_patch" to 状态.我的.value.tool_sftp_patch,
                "file_list" to 状态.我的.value.tool_file_list,
                "file_read" to 状态.我的.value.tool_file_read,
                "file_write" to 状态.我的.value.tool_file_write,
                "file_delete" to 状态.我的.value.tool_file_delete,
                "file_push" to 状态.我的.value.tool_file_push,
                "file_patch" to 状态.我的.value.tool_file_patch,
                "ws_list" to 状态.我的.value.tool_ws_list,
                "ws_read" to 状态.我的.value.tool_ws_read,
                "ws_write" to 状态.我的.value.tool_ws_write,
                "ws_delete" to 状态.我的.value.tool_ws_delete,
                "ws_patch" to 状态.我的.value.tool_ws_patch,
                "ws_zip" to 状态.我的.value.tool_ws_zip,
                "web_open" to 状态.我的.value.tool_web_open,
                "web_search" to 状态.我的.value.tool_web_search,
                "ppt_generate" to 状态.我的.value.tool_ppt_generate
            )
        )
    }

    AlertDialog(
        onDismissRequest = 关闭,
        title = {
            Text(
                "工具开关",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold
            )
        },
        text = {
            Column(
                Modifier
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState())
            ) {
                // SSH / SFTP 组
                工具组(
                    标题 = "SSH / SFTP",
                    工具列表 = listOf(
                        "ssh_exec" to "执行命令",
                        "sftp_read" to "读文件",
                        "sftp_write" to "写文件",
                        "sftp_list" to "列目录",
                        "sftp_delete" to "删文件",
                        "sftp_patch" to "补丁改"
                    ),
                    临时工具 = 临时工具,
                    更新 = { 临时工具 = it }
                )

                Spacer(Modifier.height(16.dp))

                // 代码仓组
                工具组(
                    标题 = "代码仓",
                    工具列表 = listOf(
                        "file_list" to "列文件",
                        "file_read" to "读文件",
                        "file_write" to "写文件",
                        "file_delete" to "删文件",
                        "file_push" to "推送",
                        "file_patch" to "补丁改"
                    ),
                    临时工具 = 临时工具,
                    更新 = { 临时工具 = it }
                )

                Spacer(Modifier.height(16.dp))

                // 工作中心组
                工具组(
                    标题 = "工作中心",
                    工具列表 = listOf(
                        "ws_list" to "列文件",
                        "ws_read" to "读文件",
                        "ws_write" to "写文件",
                        "ws_delete" to "删文件",
                        "ws_patch" to "补丁改",
                        "ws_zip" to "打包"
                    ),
                    临时工具 = 临时工具,
                    更新 = { 临时工具 = it }
                )

                Spacer(Modifier.height(16.dp))

                // 其他组
                工具组(
                    标题 = "其他",
                    工具列表 = listOf(
                        "web_open" to "抓网页",
                        "web_search" to "实时搜索",
                        "ppt_generate" to "生成PPT"
                    ),
                    临时工具 = 临时工具,
                    更新 = { 临时工具 = it }
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    状态.保存工具开关(临时工具)
                    关闭()
                }
            ) {
                Text("保存")
            }
        },
        dismissButton = {
            TextButton(onClick = 关闭) {
                Text("取消")
            }
        }
    )
}

/**
 * 工具组，包含标题和一行三个的按钮网格
 */
@Composable
private fun 工具组(
    标题: String,
    工具列表: List<Pair<String, String>>,
    临时工具: Map<String, Int>,
    更新: (Map<String, Int>) -> Unit
) {
    Text(
        标题,
        style = MaterialTheme.typography.labelSmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        fontWeight = FontWeight.Medium,
        modifier = Modifier.padding(bottom = 8.dp)
    )

    // 使用分组实现一行三个的布局
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        工具列表.chunked(3).forEach { 行 ->
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                行.forEach { (键, 标签) ->
                    工具开关项(
                        标签 = 标签,
                        选中 = 临时工具[键] == 1,
                        点击 = {
                            更新(临时工具.toMutableMap().apply {
                                this[键] = if (this[键] == 1) 0 else 1
                            })
                        },
                        modifier = Modifier.weight(1f)
                    )
                }
                // 不足三个时填充空白
                repeat(3 - 行.size) {
                    Spacer(Modifier.weight(1f))
                }
            }
        }
    }
}

/**
 * 单个工具开关按钮
 */
@Composable
private fun 工具开关项(
    标签: String,
    选中: Boolean,
    点击: () -> Unit,
    modifier: Modifier = Modifier
) {
    Surface(
        onClick = 点击,
        modifier = modifier.height(40.dp),
        shape = RoundedCornerShape(8.dp),
        color = if (选中)
            MaterialTheme.colorScheme.primaryContainer
        else
            MaterialTheme.colorScheme.surfaceVariant,
        border = BorderStroke(
            width = if (选中) 1.5.dp else 0.dp,
            color = if (选中)
                MaterialTheme.colorScheme.primary
            else
                Color.Transparent
        )
    ) {
        Box(
            contentAlignment = Alignment.Center,
            modifier = Modifier.fillMaxSize()
        ) {
            Text(
                标签,
                fontSize = 12.sp,
                fontWeight = if (选中) FontWeight.Medium else FontWeight.Normal,
                color = if (选中)
                    MaterialTheme.colorScheme.primary
                else
                    MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center
            )
        }
    }
}
