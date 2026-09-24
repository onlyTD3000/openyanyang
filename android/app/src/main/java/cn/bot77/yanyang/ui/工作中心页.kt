package cn.bot77.yanyang.ui
import android.content.ContentValues
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Download
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Image
import androidx.compose.material.icons.filled.Inventory2
import androidx.compose.material.icons.filled.NoteAdd
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Save
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import cn.bot77.yanyang.data.工作文件
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
/**
 * 工作中心：账号专属的文件库，和网页端 workspace.php 同一套接口。
 *
 * 交互对齐网页端：列表支持类型筛选、名字搜索、分页；
 * 文本文件点开就能改、能存；Office 文档服务端转成 PNG 逐页看；
 * 图片直接显示；任何文件都能下载到手机的「下载」目录。
 *
 * 返回键分两级：先关文件详情回列表，再退出本页回上一页。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun 工作中心页(状态: 主状态) {
    val 打开的 = 状态.工作打开的.value
    var 显搜索 by remember { mutableStateOf(false) }
    var 要删的 by remember { mutableStateOf<工作文件?>(null) }
    var 显新建 by remember { mutableStateOf(false) }
    var 显改名 by remember { mutableStateOf(false) }
    BackHandler {
        if (打开的 != null) 状态.关工作文件() else 状态.按返回()
    }
    // 搜索防抖：停手 400 毫秒再发请求，边打字边请求会把列表刷得乱跳
    val 搜索词 = 状态.工作搜索.value
    LaunchedEffect(搜索词) {
        if (显搜索) {
            delay(400)
            状态.搜工作中心()
        }
    }
    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(
                            if (打开的 != null) 打开的.名字 else "工作中心",
                            style = MaterialTheme.typography.titleMedium,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis
                        )
                        val 副 = if (打开的 != null) 打开的.大小文本 else 状态.工作用量.value
                        if (副.isNotBlank()) {
                            Text(
                                副,
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                maxLines = 1
                            )
                        }
                    }
                },
                navigationIcon = {
                    IconButton(onClick = {
                        if (打开的 != null) 状态.关工作文件() else 状态.回对话()
                    }) {
                        Icon(Icons.Filled.ArrowBack, "返回")
                    }
                },
                actions = {
                    if (打开的 == null) {
                        IconButton(onClick = { 显搜索 = !显搜索 }) {
                            Icon(
                                Icons.Filled.Search, "搜索文件",
                                tint = if (显搜索) MaterialTheme.colorScheme.primary
                                else MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                        IconButton(onClick = { 显新建 = true }) {
                            Icon(Icons.Filled.NoteAdd, "新建文本文件")
                        }
                        IconButton(onClick = { 状态.载入工作中心() }) {
                            Icon(Icons.Filled.Refresh, "刷新")
                        }
                    } else {
                        文件操作按钮(状态, 打开的, 改名 = { 显改名 = true }, 删除 = { 要删的 = 打开的 })
                    }
                }
            )
        }
    ) { 内边距 ->
        Box(Modifier.padding(内边距).fillMaxSize()) {
            if (打开的 == null) {
                Column(Modifier.fillMaxSize()) {
                    if (显搜索) {
                        OutlinedTextField(
                            value = 搜索词,
                            onValueChange = { 状态.改工作搜索(it) },
                            placeholder = { Text("按文件名搜索") },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(horizontal = 12.dp, vertical = 6.dp)
                        )
                    }
                    类型筛选(状态)
                    文件表(状态)
                }
                if (状态.加载中.value && 状态.工作文件表.isEmpty()) {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }
                }
            } else {
                文件详情(状态, 打开的)
            }
        }
    }
    if (显新建) {
        文本输入弹窗(
            标题 = "新建文本文件",
            说明 = "带扩展名，可以带目录，如 备忘.md、scripts/run.sh",
            初值 = "新建文件.md",
            确定 = { 名 -> 状态.新建工作文件(名); 显新建 = false },
            取消 = { 显新建 = false }
        )
    }
    if (显改名 && 打开的 != null) {
        文本输入弹窗(
            标题 = "重命名",
            说明 = "可以带目录，如 docs/说明.md",
            初值 = 打开的.名字,
            确定 = { 名 -> 状态.改工作文件名(打开的, 名); 显改名 = false },
            取消 = { 显改名 = false }
        )
    }
    要删的?.let { 文件 ->
        AlertDialog(
            onDismissRequest = { 要删的 = null },
            title = { Text("删除文件") },
            text = { Text("删除「${文件.名字}」？删掉就找不回来了。") },
            confirmButton = {
                TextButton(onClick = { 状态.删工作文件(文件); 要删的 = null }) {
                    Text("删除", color = MaterialTheme.colorScheme.error)
                }
            },
            dismissButton = { TextButton(onClick = { 要删的 = null }) { Text("取消") } }
        )
    }
}
/** 类型筛选条，对齐网页端的下拉：全部 / 文本 / 图片 / 其它 */
@Composable
private fun 类型筛选(状态: 主状态) {
    val 项 = listOf("all" to "全部", "text" to "文本", "image" to "图片", "bin" to "其它")
    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 12.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        项.forEach { (值, 名) ->
            FilterChip(
                selected = 状态.工作类型.value == 值,
                onClick = { 状态.切工作类型(值) },
                label = { Text(名, style = MaterialTheme.typography.labelMedium) }
            )
        }
    }
}
@Composable
private fun 文件表(状态: 主状态) {
    val 文件们 = 状态.工作文件表
    if (文件们.isEmpty() && !状态.加载中.value) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Text(
                if (状态.工作搜索.value.isNotBlank()) "没有匹配的文件"
                else "还没有文件。让 AI 帮你写一个，或者点右上角新建",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier.padding(24.dp)
            )
        }
        return
    }
    LazyColumn(Modifier.fillMaxSize()) {
        items(文件们, key = { it.id }) { 文件 ->
            文件行(文件) { 状态.开工作文件(文件) }
            HorizontalDivider(color = MaterialTheme.colorScheme.surfaceVariant)
        }
        // 分页：只在多页时显示，手机上用「上一页 / 下一页」比页码格子好点
        if (状态.工作总页数.value > 1) {
            item {
                Row(
                    Modifier.fillMaxWidth().padding(16.dp),
                    horizontalArrangement = Arrangement.Center,
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    TextButton(
                        onClick = { 状态.载入工作中心(状态.工作页码.value - 1) },
                        enabled = 状态.工作页码.value > 1
                    ) { Text("上一页") }
                    Text(
                        "${状态.工作页码.value} / ${状态.工作总页数.value}",
                        style = MaterialTheme.typography.labelLarge,
                        modifier = Modifier.padding(horizontal = 12.dp)
                    )
                    TextButton(
                        onClick = { 状态.载入工作中心(状态.工作页码.value + 1) },
                        enabled = 状态.工作页码.value < 状态.工作总页数.value
                    ) { Text("下一页") }
                }
            }
        }
        item { Spacer(Modifier.height(24.dp)) }
    }
}
@Composable
private fun 文件行(文件: 工作文件, 点击: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .clickable(onClick = 点击)
            .padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(
            when {
                文件.是图片 -> Icons.Filled.Image
                文件.是文本 -> Icons.Filled.Description
                else -> Icons.Filled.Inventory2
            },
            null,
            Modifier.size(20.dp),
            tint = MaterialTheme.colorScheme.onSurfaceVariant
        )
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(
                文件.名字,
                style = MaterialTheme.typography.bodyMedium,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    文件.大小文本,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                if (文件.是AI产出) {
                    Spacer(Modifier.width(8.dp))
                    Surface(
                        color = MaterialTheme.colorScheme.primaryContainer,
                        shape = RoundedCornerShape(4.dp)
                    ) {
                        Text(
                            "AI",
                            Modifier.padding(horizontal = 5.dp, vertical = 1.dp),
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onPrimaryContainer
                        )
                    }
                }
                if (文件.版本 > 1) {
                    Spacer(Modifier.width(8.dp))
                    Text(
                        "改过 ${文件.版本} 次",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }
        }
    }
}
/** 详情页右上角那排操作：保存（仅文本）、下载、改名、删除 */
@Composable
private fun 文件操作按钮(
    状态: 主状态,
    文件: 工作文件,
    改名: () -> Unit,
    删除: () -> Unit
) {
    val 上下文 = LocalContext.current
    val 域 = rememberCoroutineScope()
    var 下载中 by remember { mutableStateOf(false) }
    if (文件.是文本) {
        IconButton(
            onClick = { 状态.保存工作文件() },
            enabled = !状态.工作保存中.value && 状态.工作有改动
        ) {
            if (状态.工作保存中.value) {
                CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
            } else {
                Icon(
                    Icons.Filled.Save, "保存",
                    tint = if (状态.工作有改动) MaterialTheme.colorScheme.primary
                    else MaterialTheme.colorScheme.outline
                )
            }
        }
    }
    IconButton(
        onClick = {
            if (下载中) return@IconButton
            下载中 = true
            域.launch {
                val 字节 = 状态.下载工作文件(文件.id)
                下载中 = false
                if (字节 == null) {
                    Toast.makeText(上下文, "下载失败", Toast.LENGTH_SHORT).show()
                } else {
                    val 结果 = 存到下载目录(上下文, 文件.名字, 字节)
                    Toast.makeText(上下文, 结果, Toast.LENGTH_LONG).show()
                }
            }
        },
        enabled = !下载中
    ) {
        if (下载中) {
            CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
        } else {
            Icon(Icons.Filled.Download, "下载到手机")
        }
    }
    IconButton(onClick = 改名) { Icon(Icons.Filled.Edit, "重命名") }
    IconButton(onClick = 删除) {
        Icon(Icons.Filled.Delete, "删除", tint = MaterialTheme.colorScheme.error)
    }
}
@Composable
private fun 文件详情(状态: 主状态, 文件: 工作文件) {
    when {
        文件.是文本 -> 文本编辑(状态)
        文件.是图片 -> 图片查看(状态, 文件)
        文件.可预览 -> 文档预览(状态, 文件)
        else -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Text(
                "这是二进制文件，不能在线查看，点右上角下载取用。",
                Modifier.padding(24.dp),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodyMedium
            )
        }
    }
}
@Composable
private fun 文本编辑(状态: 主状态) {
    if (状态.工作读取中.value) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }
        return
    }
    Column(Modifier.fillMaxSize()) {
        if (状态.工作已截断.value) {
            Surface(color = MaterialTheme.colorScheme.errorContainer) {
                Text(
                    "文件过长，这里只显示了前一部分，为避免丢内容已禁止保存。",
                    Modifier.fillMaxWidth().padding(12.dp),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onErrorContainer
                )
            }
        }
        OutlinedTextField(
            value = 状态.工作正文.value,
            onValueChange = { 状态.改工作正文(it) },
            modifier = Modifier.fillMaxSize().padding(8.dp),
            textStyle = MaterialTheme.typography.bodySmall.copy(
                fontFamily = FontFamily.Monospace,
                fontSize = 13.sp
            ),
            readOnly = 状态.工作已截断.value
        )
    }
}
@Composable
private fun 图片查看(状态: 主状态, 文件: 工作文件) {
    var 字节 by remember(文件.id) { mutableStateOf<ByteArray?>(null) }
    var 失败 by remember(文件.id) { mutableStateOf(false) }
    LaunchedEffect(文件.id) {
        val b = 状态.下载工作文件(文件.id)
        if (b == null) 失败 = true else 字节 = b
    }
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        val b = 字节
        when {
            失败 -> Text("图片加载失败", color = MaterialTheme.colorScheme.onSurfaceVariant)
            b == null -> CircularProgressIndicator()
            else -> {
                val 图 = remember(b) {
                    android.graphics.BitmapFactory.decodeByteArray(b, 0, b.size)
                }
                if (图 == null) {
                    Text("这张图解不开，可能格式不支持", color = MaterialTheme.colorScheme.onSurfaceVariant)
                } else {
                    Image(
                        图.asImageBitmap(),
                        文件.名字,
                        Modifier.fillMaxWidth().padding(8.dp),
                        contentScale = ContentScale.Fit
                    )
                }
            }
        }
    }
}
/**
 * Office 文档预览：服务端逐页转 PNG，这里一页一张往下滚。
 *
 * 和网页端一个思路，不走 PDF 内嵌——安卓没有系统级 PDF 渲染控件，
 * 图片最省事也最可控。
 */
@Composable
private fun 文档预览(状态: 主状态, 文件: 工作文件) {
    when {
        状态.工作预览中.value -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                CircularProgressIndicator()
                Spacer(Modifier.height(12.dp))
                Text(
                    "正在生成预览，首次转换要几秒…",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }
        状态.工作预览错.value.isNotBlank() -> Box(
            Modifier.fillMaxSize(),
            contentAlignment = Alignment.Center
        ) {
            Text(
                "预览生成失败：${状态.工作预览错.value}\n可以点右上角下载原文件。",
                Modifier.padding(24.dp),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodyMedium
            )
        }
        状态.工作预览页数.value <= 0 -> Box(
            Modifier.fillMaxSize(),
            contentAlignment = Alignment.Center
        ) {
            Text("没有可预览的页", color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        else -> LazyColumn(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.surfaceVariant)) {
            item {
                Text(
                    "共 ${状态.工作预览页数.value} 页",
                    Modifier.fillMaxWidth().padding(12.dp),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            items(状态.工作预览页数.value) { 序 ->
                预览页(状态, 文件.id, 序 + 1)
            }
            item { Spacer(Modifier.height(24.dp)) }
        }
    }
}
/** 单页预览图。滚到才拉，页数多时不会一次拉几十张 */
@Composable
private fun 预览页(状态: 主状态, 文件id: String, 页: Int) {
    var 字节 by remember(文件id, 页) { mutableStateOf<ByteArray?>(null) }
    var 失败 by remember(文件id, 页) { mutableStateOf(false) }
    LaunchedEffect(文件id, 页) {
        val b = 状态.取预览图(文件id, 页)
        if (b == null) 失败 = true else 字节 = b
    }
    Box(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 8.dp, vertical = 6.dp)
            .heightIn(min = 160.dp),
        contentAlignment = Alignment.Center
    ) {
        val b = 字节
        when {
            失败 -> Text(
                "第 $页 页加载失败",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            b == null -> CircularProgressIndicator(Modifier.size(28.dp))
            else -> {
                val 图 = remember(b) {
                    android.graphics.BitmapFactory.decodeByteArray(b, 0, b.size)
                }
                if (图 == null) {
                    Text("第 $页 页解不开", style = MaterialTheme.typography.labelMedium)
                } else {
                    Surface(shadowElevation = 2.dp) {
                        Image(
                            图.asImageBitmap(),
                            "第 $页 页",
                            Modifier.fillMaxWidth(),
                            contentScale = ContentScale.FillWidth
                        )
                    }
                }
            }
        }
    }
}
/** 新建 / 改名共用的一个输入弹窗 */
@Composable
private fun 文本输入弹窗(
    标题: String,
    说明: String,
    初值: String,
    确定: (String) -> Unit,
    取消: () -> Unit
) {
    var 文本 by remember { mutableStateOf(初值) }
    AlertDialog(
        onDismissRequest = 取消,
        title = { Text(标题) },
        text = {
            Column {
                OutlinedTextField(
                    value = 文本,
                    onValueChange = { 文本 = it },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )
                Spacer(Modifier.height(8.dp))
                Text(
                    说明,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { 确定(文本) },
                enabled = 文本.isNotBlank()
            ) { Text("确定") }
        },
        dismissButton = { TextButton(onClick = 取消) { Text("取消") } }
    )
}
/**
 * 把字节写进系统「下载」目录。
 *
 * Android 10 及以上走 MediaStore，不需要存储权限；
 * 以下的老系统直接写公共下载目录（清单里已声明 WRITE_EXTERNAL_STORAGE）。
 * 名字里的目录分隔符要拍平，不然 MediaStore 会把它当非法名字拒掉。
 */
private fun 存到下载目录(上下文: android.content.Context, 名字: String, 字节: ByteArray): String {
    val 文件名 = 名字.replace('/', '_').replace('\\', '_')
    return try {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val 值 = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, 文件名)
                put(MediaStore.Downloads.MIME_TYPE, "application/octet-stream")
                put(MediaStore.Downloads.IS_PENDING, 1)
            }
            val 解析器 = 上下文.contentResolver
            val 地址 = 解析器.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, 值)
                ?: return "无法创建下载文件"
            解析器.openOutputStream(地址)?.use { it.write(字节) } ?: return "无法写入下载文件"
            值.clear()
            值.put(MediaStore.Downloads.IS_PENDING, 0)
            解析器.update(地址, 值, null, null)
            "已保存到「下载」：$文件名"
        } else {
            @Suppress("DEPRECATION")
            val 目录 = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS)
            if (!目录.exists()) 目录.mkdirs()
            java.io.File(目录, 文件名).writeBytes(字节)
            "已保存到「下载」：$文件名"
        }
    } catch (e: Exception) {
        "保存失败：${e.message ?: "未知原因"}"
    }
}
