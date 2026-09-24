package cn.bot77.yanyang.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * 工具卡片 - 对话中的15种工具操作
 * 
 * 设计与网页端对齐：
 * - SSH命令用独立样式（蓝色主题）
 * - 其他14种用统一的卡片样式 + 左边框色区分
 */

/**
 * 工具类型枚举
 * 包含标题、左边框色、图标
 */
enum class 工具类型(
    val 标题: String,
    val 边框色: Color,
    val 图标: String = "▸"
) {
    // SSH命令（1种）- 独立样式，蓝色
    SSH命令("在服务器上执行命令", Color(0xFF3B82F6), "▸"),
    
    // SFTP操作（5种）- 橙色 #f08c2e
    SFTP列目录("查看服务器目录", Color(0xFFF08C2E), "📁"),
    SFTP读文件("读取服务器文件", Color(0xFFF08C2E), "📄"),
    SFTP写文件("改写服务器文件", Color(0xFFF08C2E), "✏️"),
    SFTP补丁("给服务器文件打补丁", Color(0xFFF08C2E), "🔧"),
    SFTP删除("删除服务器文件", Color(0xFFF08C2E), "🗑️"),
    
    // 代码仓操作（7种）- 蓝色（主色）
    代码仓拉取("从服务器拉取代码", Color(0xFF6366F1), "⬇️"),
    代码仓列表("查看代码仓文件清单", Color(0xFF6366F1), "📋"),
    代码仓读取("读取代码文件", Color(0xFF6366F1), "📖"),
    代码仓写入("改写代码文件", Color(0xFF6366F1), "✍️"),
    代码仓补丁("给代码文件打补丁", Color(0xFF6366F1), "⚙️"),
    代码仓删除("删除代码文件", Color(0xFF6366F1), "❌"),
    代码仓回传("回传代码到服务器", Color(0xFF6366F1), "⬆️"),
    
    // 工作中心（3种）- 紫色 #7c5cff
    工作中心列表("查看工作中心文件", Color(0xFF7C5CFF), "📚"),
    工作中心读取("读取工作中心文件", Color(0xFF7C5CFF), "📘"),
    工作中心写入("写入工作中心文件", Color(0xFF7C5CFF), "💾"),
    工作中心补丁("给工作中心文件打补丁", Color(0xFF7C5CFF), "🔨"),
    工作中心打包("打包工作中心文件", Color(0xFF7C5CFF), "📦"),
    
    // 网页抓取（1种）- 青色 #17a2b8
    网页抓取("抓取网页内容", Color(0xFF17A2B8), "🌐"),
    
    // PPT生成（1种）- 用主色，特殊布局
    生成PPT("生成PPT演示文稿", Color(0xFF6366F1), "📊");
    
    companion object {
        /**
         * 从工具名称识别工具类型（用于tool_use字段）
         */
        fun 从名称识别(名称: String): 工具类型? = when(名称.lowercase().trim()) {
            "ssh_exec" -> SSH命令
            "sftp_list" -> SFTP列目录
            "sftp_read" -> SFTP读文件
            "sftp_write" -> SFTP写文件
            "sftp_patch" -> SFTP补丁
            "sftp_delete" -> SFTP删除
            "file_list" -> 代码仓列表
            "file_read" -> 代码仓读取
            "file_write" -> 代码仓写入
            "file_patch" -> 代码仓补丁
            "file_delete" -> 代码仓删除
            "file_push" -> 代码仓回传
            "file_pull" -> 代码仓拉取
            "ws_list" -> 工作中心列表
            "ws_read" -> 工作中心读取
            "ws_write" -> 工作中心写入
            "ws_patch" -> 工作中心补丁
            "ws_zip" -> 工作中心打包
            "web_open" -> 网页抓取
            "ppt_generate" -> 生成PPT
            else -> null
        }
    }
}

/**
 * 卡片状态
 */
enum class 卡片状态(val 文本: String, val 颜色: Color) {
    待执行("待执行", Color(0xFF6B7280)),
    执行中("执行中", Color(0xFF6B7280)),
    成功("完成", Color(0xFF059669)),
    失败("失败", Color(0xFFDC2626)),
    警告("警告", Color(0xFFD97706))
}

/**
 * 工具卡片主组件
 * 
 * @param 类型 工具类型
 * @param 代码 代码块原始内容
 * @param 状态 当前执行状态
 * @param 输出 执行结果输出（可选）
 */
@Composable
fun 工具卡片(
    类型: 工具类型,
    代码: String,
    状态: 卡片状态 = 卡片状态.待执行,
    输出: String = "",
    modifier: Modifier = Modifier
) {
    var 展开 by remember { mutableStateOf(false) }
    
    // SSH命令用独立样式
    if (类型 == 工具类型.SSH命令) {
        SSH卡片(代码, 状态, 输出, 展开, { 展开 = !展开 }, modifier)
        return
    }
    
    // 其他14种用统一的repo-card样式
    统一卡片(类型, 代码, 状态, 输出, 展开, { 展开 = !展开 }, modifier)
}

/**
 * SSH命令独立卡片样式
 */
@Composable
private fun SSH卡片(
    代码: String,
    状态: 卡片状态,
    输出: String,
    展开: Boolean,
    切换展开: () -> Unit,
    modifier: Modifier
) {
    val (命令, 服务器) = 解析SSH(代码)
    
    Surface(
        color = Color(0xFFFCFCFD),
        shape = RoundedCornerShape(10.dp),
        tonalElevation = 0.dp,
        shadowElevation = 0.dp,
        border = androidx.compose.foundation.BorderStroke(1.dp, Color(0xFFE5E7EB)),
        modifier = modifier
            .fillMaxWidth()
            .padding(vertical = 5.dp)
    ) {
        Column {
            // 头部 - 可点击展开/收起
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable(onClick = 切换展开)
                    .padding(horizontal = 14.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                    modifier = Modifier.weight(1f)
                ) {
                    // 展开/收起图标
                    Icon(
                        if (展开) Icons.Default.ExpandLess else Icons.Default.ExpandMore,
                        contentDescription = null,
                        tint = Color(0xFF9CA3AF),
                        modifier = Modifier.size(16.dp)
                    )
                    
                    // 标题
                    Text(
                        "在服务器上执行命令",
                        fontSize = 13.sp,
                        fontWeight = FontWeight.Medium,
                        color = Color(0xFF059669)
                    )
                    
                    if (服务器.isNotBlank()) {
                        Text(
                            "· $服务器",
                            fontSize = 12.sp,
                            color = Color(0xFF6B7280)
                        )
                    }
                }
                
                // 状态标签
                Surface(
                    color = when(状态) {
                        卡片状态.成功 -> Color(0xFFD1FAE5)
                        卡片状态.失败 -> Color(0xFFFEE2E2)
                        卡片状态.警告 -> Color(0xFFFEF3C7)
                        else -> Color(0xFFF3F4F6)
                    },
                    shape = RoundedCornerShape(10.dp)
                ) {
                    Text(
                        状态.文本,
                        fontSize = 11.sp,
                        fontWeight = FontWeight.Medium,
                        color = 状态.颜色,
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 2.dp)
                    )
                }
            }
            
            // 主体 - 展开时显示
            if (展开) {
                Divider(color = Color(0xFFF0F1F3), thickness = 1.dp)
                
                // 命令内容
                Text(
                    命令,
                    fontSize = 13.sp,
                    fontFamily = FontFamily.Monospace,
                    color = Color(0xFF374151),
                    modifier = Modifier.padding(12.dp)
                )
                
                // 输出结果
                if (输出.isNotBlank()) {
                    Divider(color = Color(0xFFE5E7EB), thickness = 1.dp)
                    Text(
                        输出,
                        fontSize = 12.sp,
                        fontFamily = FontFamily.Monospace,
                        color = Color(0xFF6B7280),
                        lineHeight = 18.sp,
                        modifier = Modifier
                            .padding(12.dp)
                            .heightIn(max = 340.dp)
                    )
                }
            }
        }
    }
}

/**
 * 统一卡片样式（SFTP/代码仓/工作中心/网页/PPT）
 * 左边框色区分类型
 */
@Composable
private fun 统一卡片(
    类型: 工具类型,
    代码: String,
    状态: 卡片状态,
    输出: String,
    展开: Boolean,
    切换展开: () -> Unit,
    modifier: Modifier
) {
    val (摘要, 详情) = 提取卡片内容(类型, 代码)
    
    Surface(
        color = Color(0xFFFCFCFD),
        shape = RoundedCornerShape(8.dp),
        tonalElevation = 0.dp,
        shadowElevation = 0.dp,
        modifier = modifier
            .fillMaxWidth()
            .padding(vertical = 5.dp)
    ) {
        Row(modifier = Modifier.fillMaxWidth()) {
            // 左边框色条
            Surface(
                color = 类型.边框色,
                modifier = Modifier
                    .width(3.dp)
                    .fillMaxHeight()
            ) {}
            
            Column(modifier = Modifier
                .weight(1f)
                .padding(end = 1.dp)) {
                
                // 头部
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable(onClick = 切换展开)
                        .padding(horizontal = 14.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    // 展开图标
                    Icon(
                        if (展开) Icons.Default.ExpandLess else Icons.Default.ExpandMore,
                        contentDescription = null,
                        tint = Color(0xFF9CA3AF),
                        modifier = Modifier.size(16.dp)
                    )
                    
                    Spacer(Modifier.width(8.dp))
                    
                    // 标题
                    Text(
                        类型.标题,
                        fontSize = 13.sp,
                        fontWeight = FontWeight.Medium,
                        color = Color(0xFF374151),
                        modifier = Modifier.weight(1f)
                    )
                    
                    // 状态
                    Surface(
                        color = when(状态) {
                            卡片状态.成功 -> Color(0xFFD1FAE5)
                            卡片状态.失败 -> Color(0xFFFEE2E2)
                            卡片状态.警告 -> Color(0xFFFEF3C7)
                            else -> Color(0xFFF3F4F6)
                        },
                        shape = RoundedCornerShape(10.dp)
                    ) {
                        Text(
                            状态.文本,
                            fontSize = 11.sp,
                            fontWeight = FontWeight.Medium,
                            color = 状态.颜色,
                            modifier = Modifier.padding(horizontal = 8.dp, vertical = 2.dp)
                        )
                    }
                }
                
                // 主体内容
                if (展开) {
                    Divider(color = Color(0xFFF0F1F3), thickness = 1.dp)
                    
                    Column(modifier = Modifier.padding(horizontal = 11.dp, vertical = 8.dp)) {
                        // 路径/文件名（等宽字体）
                        if (摘要.isNotBlank()) {
                            Text(
                                摘要,
                                fontSize = 12.5.sp,
                                fontFamily = FontFamily.Monospace,
                                color = Color(0xFF374151),
                                modifier = Modifier.padding(bottom = 4.dp)
                            )
                        }
                        
                        // 详细说明
                        if (详情.isNotBlank()) {
                            Text(
                                详情,
                                fontSize = 12.sp,
                                color = Color(0xFF6B7280),
                                lineHeight = 18.sp
                            )
                        }
                    }
                    
                    // 输出结果
                    if (输出.isNotBlank()) {
                        Divider(color = Color(0xFFE5E7EB), thickness = 1.dp)
                        Text(
                            输出,
                            fontSize = 12.sp,
                            fontFamily = FontFamily.Monospace,
                            color = Color(0xFF6B7280),
                            lineHeight = 18.sp,
                            modifier = Modifier
                                .padding(11.dp)
                                .heightIn(max = 260.dp)
                        )
                    }
                }
            }
        }
    }
}

/**
 * 解析SSH代码块
 * 返回 (命令, 服务器编号)
 */
private fun 解析SSH(代码: String): Pair<String, String> {
    val 行 = 代码.lines()
    var 命令 = ""
    var 服务器 = ""
    
    for (行文本 in 行) {
        when {
            行文本.trim().startsWith("host:", ignoreCase = true) -> {
                服务器 = 行文本.substringAfter(":", "").trim()
                if (服务器.isNotBlank()) {
                    服务器 = "服务器 $服务器"
                }
            }
            行文本.trim().startsWith("cmd:", ignoreCase = true) -> {
                命令 = 行文本.substringAfter(":", "").trim()
            }
        }
    }
    
    return Pair(命令, 服务器)
}

/**
 * 提取卡片内容
 * 返回 (摘要, 详情)
 * 
 * 摘要：路径/文件名/网址等关键信息，用等宽字体
 * 详情：说明文字、补丁预览等，用普通字体
 */
private fun 提取卡片内容(类型: 工具类型, 代码: String): Pair<String, String> {
    val 行 = 代码.lines()
    var 摘要 = ""
    var 详情 = ""
    
    // 根据类型提取不同字段
    when {
        // SFTP/代码仓/工作中心 - 提取 path/dir/name 和 note
        类型.name.startsWith("SFTP") || 
        类型.name.startsWith("代码仓") ||
        类型.name.startsWith("工作中心") -> {
            for (行文本 in 行) {
                val 纯 = 行文本.trim()
                when {
                    纯.startsWith("path:", ignoreCase = true) -> 
                        摘要 = 纯.substringAfter(":", "").trim()
                    纯.startsWith("dir:", ignoreCase = true) -> 
                        摘要 = 纯.substringAfter(":", "").trim()
                    纯.startsWith("name:", ignoreCase = true) -> 
                        摘要 = 纯.substringAfter(":", "").trim()
                    纯.startsWith("note:", ignoreCase = true) -> 
                        详情 = 纯.substringAfter(":", "").trim()
                }
            }
            
            // 补丁类型特殊处理：提取原文和替换预览
            if (类型.name.contains("补丁")) {
                val 补丁正则 = Regex("<{5,}.*?\\n([\\s\\S]*?)\\n={5,}.*?\\n([\\s\\S]*?)\\n>{5,}")
                val 匹配 = 补丁正则.find(代码)
                if (匹配 != null) {
                    val 原文 = 匹配.groupValues[1].lines().take(3).joinToString("\n")
                    val 替换 = 匹配.groupValues[2].lines().take(3).joinToString("\n")
                    详情 = "原文:\n$原文\n\n替换为:\n$替换"
                }
            }
        }
        
        // 网页抓取 - 提取 URL
        类型 == 工具类型.网页抓取 -> {
            for (行文本 in 行) {
                val 纯 = 行文本.trim()
                if (纯.startsWith("url:", ignoreCase = true)) {
                    摘要 = 纯.substringAfter(":", "").trim()
                    break
                }
            }
        }
        
        // PPT生成 - 提取标题和页数
        类型 == 工具类型.生成PPT -> {
            try {
                // 简单提取，不做完整JSON解析
                val 标题匹配 = Regex(""""title"\s*:\s*"([^"]+)"""").find(代码)
                if (标题匹配 != null) {
                    摘要 = 标题匹配.groupValues[1]
                }
                
                // 粗略统计页数
                val 页数 = Regex("""\{\s*"title"""").findAll(代码).count()
                if (页数 > 0) {
                    详情 = "$页数 页演示内容"
                }
            } catch (e: Exception) {
                // 解析失败不影响显示
            }
        }
    }
    
    return Pair(摘要, 详情)
}