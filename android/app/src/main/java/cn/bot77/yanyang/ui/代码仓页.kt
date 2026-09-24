package cn.bot77.yanyang.ui
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import cn.bot77.yanyang.data.仓文件
/**
 * 代码仓浏览。
 *
 * 后端 tree 接口返回的是平铺路径，这里按目录归组再展示。
 * 打开文件时盖一层全屏预览，不做单独路由，返回键直接关掉。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun 代码仓页(状态: 主状态) {
    val 概况 = 状态.仓概况.value
    val 打开的 = 状态.打开的文件.value
    val 编辑模式 = 状态.仓编辑模式.value
    val 选中数 = 状态.仓选中文件.size
    val 上下文 = LocalContext.current
    
    // 上传文件选择器
    var 显上传选项 by remember { mutableStateOf(false) }
    
    // 原样收下模式
    val 原样上传选择器 = rememberLauncherForActivityResult(
        ActivityResultContracts.GetContent()
    ) { uri: Uri? ->
        uri?.let {
            try {
                val 流 = 上下文.contentResolver.openInputStream(it)
                val 字节 = 流?.readBytes()
                流?.close()
                if (字节 != null) {
                    val 文件名 = 上下文.contentResolver.query(it, null, null, null, null)?.use { cursor ->
                        val 名索引 = cursor.getColumnIndex(android.provider.OpenableColumns.DISPLAY_NAME)
                        if (cursor.moveToFirst() && 名索引 >= 0) cursor.getString(名索引) else "未命名文件"
                    } ?: "未命名文件"
                    状态.上传仓文件(字节, 文件名, "", "keep", true)
                }
            } catch (e: Exception) {
                状态.提示.value = "读取文件失败：${e.message}"
            }
        }
    }
    
    // 自动解压模式
    val 解压上传选择器 = rememberLauncherForActivityResult(
        ActivityResultContracts.GetContent()
    ) { uri: Uri? ->
        uri?.let {
            try {
                val 流 = 上下文.contentResolver.openInputStream(it)
                val 字节 = 流?.readBytes()
                流?.close()
                if (字节 != null) {
                    val 文件名 = 上下文.contentResolver.query(it, null, null, null, null)?.use { cursor ->
                        val 名索引 = cursor.getColumnIndex(android.provider.OpenableColumns.DISPLAY_NAME)
                        if (cursor.moveToFirst() && 名索引 >= 0) cursor.getString(名索引) else "未命名文件"
                    } ?: "未命名文件"
                    if (!文件名.endsWith(".zip", ignoreCase = true)) {
                        状态.提示.value = "自动解压只支持 .zip 格式"
                    } else {
                        状态.上传仓文件(字节, 文件名, "", "extract", true)
                    }
                }
            } catch (e: Exception) {
                状态.提示.value = "读取文件失败：${e.message}"
            }
        }
    }
    
    // 返回键分三级：先关文件预览，再退出编辑模式，最后退出代码仓页
    BackHandler {
        when {
            打开的 != null -> 状态.关文件()
            编辑模式 -> 状态.切仓编辑模式()
            else -> 状态.按返回()
        }
    }
    // 上传选项对话框
    if (显上传选项) {
        AlertDialog(
            onDismissRequest = { 显上传选项 = false },
            title = { Text("上传文件") },
            text = {
                Column {
                    Text("选择文件后的处理方式：")
                    Spacer(Modifier.height(12.dp))
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clickable {
                                显上传选项 = false
                                原样上传选择器.launch("*/*")
                            }
                            .padding(vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Icon(Icons.Filled.FileUpload, null, Modifier.size(24.dp))
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text("原样收下", style = MaterialTheme.typography.bodyLarge)
                            Text(
                                "压缩包整个入仓，不解压",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                    }
                    HorizontalDivider()
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clickable {
                                显上传选项 = false
                                解压上传选择器.launch("application/zip")
                            }
                            .padding(vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Icon(Icons.Filled.Archive, null, Modifier.size(24.dp))
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text("自动解压", style = MaterialTheme.typography.bodyLarge)
                            Text(
                                "仅 .zip 格式，解压后逐个入仓",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                    }
                }
            },
            confirmButton = {},
            dismissButton = {
                TextButton(onClick = { 显上传选项 = false }) { Text("取消") }
            }
        )
    }
    
    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(
                            概况?.项目名?.ifBlank { "代码仓" } ?: "代码仓",
                            style = MaterialTheme.typography.titleMedium,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis
                        )
                        if (概况 != null && 概况.远程目录.isNotBlank()) {
                            Text(
                                概况.远程目录,
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis
                            )
                        }
                    }
                },
                navigationIcon = {
                    IconButton(onClick = { 状态.回对话() }) {
                        Icon(Icons.Filled.ArrowBack, "返回对话")
                    }
                },
                actions = {
                    if (编辑模式) {
                        // 编辑模式：全选 + 批量删除
                        IconButton(onClick = { 状态.全选仓文件() }) {
                            Icon(Icons.Filled.CheckCircle, "全选")
                        }
                        if (选中数 > 0) {
                            IconButton(onClick = { 状态.批量删仓文件() }) {
                                Badge(
                                    containerColor = MaterialTheme.colorScheme.error,
                                    modifier = Modifier.offset(x = 8.dp, y = (-8).dp)
                                ) {
                                    Text(选中数.toString())
                                }
                                Icon(
                                    Icons.Filled.Delete, 
                                    "删除 $选中数 个",
                                    tint = MaterialTheme.colorScheme.error
                                )
                            }
                        }
                    } else {
                        // 普通模式：筛选 + 上传 + 刷新
                        IconButton(onClick = { 显上传选项 = true }) {
                            Icon(Icons.Filled.CloudUpload, "上传文件")
                        }
                        IconButton(onClick = { 状态.切只看改动() }) {
                            Icon(
                                Icons.Filled.FilterList,
                                if (状态.只看改动.value) "显示全部文件" else "只看改动过的",
                                tint = if (状态.只看改动.value) MaterialTheme.colorScheme.primary
                                else MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                        IconButton(onClick = { 状态.载入仓() }) {
                            Icon(Icons.Filled.Refresh, "刷新")
                        }
                    }
                    // 编辑模式开关
                    IconButton(onClick = { 状态.切仓编辑模式() }) {
                        Icon(
                            Icons.Filled.Edit,
                            if (编辑模式) "退出编辑" else "编辑",
                            tint = if (编辑模式) MaterialTheme.colorScheme.primary
                            else MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }
            )
        }
    ) { 内边距 ->
        Box(Modifier.padding(内边距).fillMaxSize()) {
            文件列表(状态)
            if (状态.加载中.value && 状态.仓文件表.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
            }
        }
    }
    // 文件预览盖在最上层
    if (打开的 != null) {
        文件预览(状态, 打开的)
    }
}
@Composable
private fun 文件列表(状态: 主状态) {
    val 文件们 = 状态.仓文件表
    if (文件们.isEmpty() && !状态.加载中.value) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Text(
                if (状态.只看改动.value) "没有改动过的文件" else "代码仓是空的",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodyMedium
            )
        }
        return
    }
    // 按目录归组，根目录的文件排在最前
    val 分组 = remember(文件们.toList()) {
        文件们.groupBy { it.目录 }.toSortedMap(compareBy { it })
    }
    LazyColumn(Modifier.fillMaxSize()) {
        分组.forEach { (目录, 组内文件) ->
            item(key = "目录:$目录") {
                Surface(color = MaterialTheme.colorScheme.surfaceVariant) {
                    Text(
                        if (目录.isBlank()) "/" else "/$目录",
                        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp),
                        style = MaterialTheme.typography.labelMedium,
                        fontFamily = FontFamily.Monospace,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }
            items(组内文件, key = { it.路径 }) { 文件 ->
                文件行(状态, 文件) { 状态.开文件(文件.路径) }
            }
        }
        item { Spacer(Modifier.height(24.dp)) }
    }
}
@Composable
private fun 文件行(状态: 主状态, 文件: 仓文件, 点击: () -> Unit) {
    val 编辑模式 = 状态.仓编辑模式.value
    val 被选中 = 状态.仓选中文件.contains(文件.路径)
    
    Row(
        Modifier
            .fillMaxWidth()
            .clickable(
                enabled = if (编辑模式) true else 文件.是文本,
                onClick = {
                    if (编辑模式) {
                        状态.切文件选中(文件.路径)
                    } else {
                        点击()
                    }
                }
            )
            .padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        if (编辑模式) {
            Checkbox(
                checked = 被选中,
                onCheckedChange = { 状态.切文件选中(文件.路径) },
                modifier = Modifier.size(24.dp)
            )
            Spacer(Modifier.width(12.dp))
        }
        Icon(
            Icons.Filled.Description,
            null,
            Modifier.size(18.dp),
            tint = if (文件.是文本) MaterialTheme.colorScheme.onSurfaceVariant
            else MaterialTheme.colorScheme.outline
        )
        Spacer(Modifier.width(12.dp))
        Text(
            文件.文件名,
            Modifier.weight(1f),
            style = MaterialTheme.typography.bodyMedium,
            fontFamily = FontFamily.Monospace,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            color = if (文件.是文本) MaterialTheme.colorScheme.onSurface
            else MaterialTheme.colorScheme.outline
        )
        if (文件.有改动) {
            改动标(文件.状态)
            Spacer(Modifier.width(8.dp))
        }
        if (文件.大小文本.isNotBlank()) {
            Text(
                文件.大小文本,
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
        if (!编辑模式) {
            Spacer(Modifier.width(8.dp))
            IconButton(
                onClick = { 状态.删仓文件(文件.路径) },
                modifier = Modifier.size(32.dp)
            ) {
                Icon(
                    Icons.Filled.Delete,
                    "删除",
                    Modifier.size(18.dp),
                    tint = MaterialTheme.colorScheme.error.copy(alpha = 0.7f)
                )
            }
        }
    }
    HorizontalDivider(color = MaterialTheme.colorScheme.surfaceVariant)
}
@Composable
private fun 改动标(状态文本: String) {
    val (字, 底色) = when (状态文本) {
        "new" -> "新" to MaterialTheme.colorScheme.tertiary
        else -> "改" to MaterialTheme.colorScheme.primary
    }
    Surface(color = 底色, shape = RoundedCornerShape(4.dp)) {
        Text(
            字,
            Modifier.padding(horizontal = 5.dp, vertical = 1.dp),
            style = MaterialTheme.typography.labelSmall,
            fontWeight = FontWeight.Medium,
            color = MaterialTheme.colorScheme.surface
        )
    }
}
/**
 * 文件内容预览。
 * 代码不折行，横向可滚，折行会把缩进结构搞乱。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun 文件预览(状态: 主状态, 路径: String) {
    val 编辑模式 = 状态.文件编辑模式.value
    
    Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
        Scaffold(
            topBar = {
                TopAppBar(
                    title = {
                        Text(
                            路径,
                            style = MaterialTheme.typography.titleSmall,
                            fontFamily = FontFamily.Monospace,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis
                        )
                    },
                    navigationIcon = {
                        IconButton(onClick = { 
                            if (编辑模式) 状态.切文件编辑模式() else 状态.关文件()
                        }) {
                            Icon(
                                if (编辑模式) Icons.Filled.ArrowBack else Icons.Filled.Close,
                                if (编辑模式) "退出编辑" else "关闭"
                            )
                        }
                    },
                    actions = {
                        if (编辑模式) {
                            TextButton(onClick = { 状态.保存文件编辑() }) {
                                Text("保存")
                            }
                        } else {
                            IconButton(onClick = { 状态.切文件编辑模式() }) {
                                Icon(Icons.Filled.Edit, "编辑")
                            }
                        }
                    }
                )
            }
        ) { 内边距 ->
            Box(Modifier.padding(内边距).fillMaxSize()) {
                when {
                    状态.加载中.value -> Box(
                        Modifier.fillMaxSize(),
                        contentAlignment = Alignment.Center
                    ) { CircularProgressIndicator() }
                    状态.文件内容.value.isBlank() && !编辑模式 -> Box(
                        Modifier.fillMaxSize(),
                        contentAlignment = Alignment.Center
                    ) {
                        Text(
                            "文件是空的",
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            style = MaterialTheme.typography.bodyMedium
                        )
                    }
                    编辑模式 -> {
                        OutlinedTextField(
                            value = 状态.编辑中内容.value,
                            onValueChange = { 状态.编辑中内容.value = it },
                            modifier = Modifier
                                .fillMaxSize()
                                .padding(8.dp),
                            textStyle = MaterialTheme.typography.bodySmall.copy(
                                fontFamily = FontFamily.Monospace,
                                fontSize = 12.sp
                            ),
                            placeholder = { Text("输入代码...") }
                        )
                    }
                    else -> 带行号的代码(状态.文件内容.value)
                }
            }
        }
    }
}
@Composable
private fun 带行号的代码(内容: String) {
    val 行们 = remember(内容) { 内容.lines() }
    val 行号宽 = remember(行们.size) { "${行们.size}".length }
    Row(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .horizontalScroll(rememberScrollState())
            .padding(vertical = 8.dp)
    ) {
        // 行号列，单独一列固定宽度，跟正文一起横向滚
        Column(Modifier.padding(horizontal = 8.dp)) {
            行们.forEachIndexed { i, _ ->
                Text(
                    "${i + 1}".padStart(行号宽),
                    style = MaterialTheme.typography.bodySmall,
                    fontFamily = FontFamily.Monospace,
                    fontSize = 12.sp,
                    color = MaterialTheme.colorScheme.outline
                )
            }
        }
        Column(Modifier.padding(end = 16.dp)) {
            行们.forEach { 行 ->
                Text(
                    行.ifEmpty { " " },
                    style = MaterialTheme.typography.bodySmall,
                    fontFamily = FontFamily.Monospace,
                    fontSize = 12.sp,
                    softWrap = false
                )
            }
        }
    }
}
