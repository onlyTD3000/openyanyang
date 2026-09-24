package cn.bot77.yanyang.ui
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import android.content.Intent
import android.net.Uri
import android.graphics.BitmapFactory
/**
 * 充值页。面额、折扣全部由服务端算好，这里只管展示和下单。
 *
 * 下单成功后：
 * - 类型是 qr，主状态会自动去拉二维码图，这里只管显示、扫完等轮询到账；
 * - 类型是 wap/page，给一个「打开支付页」按钮跳系统浏览器完成支付宝流程。
 * 轮询由主状态维护，进页面时如果已有未完成订单会继续显示。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun 充值页(状态: 主状态) {
    // 返回键等同顶栏返回：走 关充值页() 而不是 按返回()，
    // 因为它会顺带停掉订单轮询、清掉二维码，直接弹栈会把轮询漏在后台
    BackHandler { 状态.关充值页() }
    val 上下文 = LocalContext.current
    val 订单 = 状态.当前订单.value
    val 二维码 = 状态.二维码字节.value
    DisposableEffect(Unit) {
        onDispose { }
    }
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("充值") },
                navigationIcon = {
                    IconButton(onClick = { 状态.关充值页() }) {
                        Icon(Icons.Filled.ArrowBack, "返回")
                    }
                }
            )
        }
    ) { 内边距 ->
        Column(
            Modifier
                .padding(内边距)
                .padding(16.dp)
                .fillMaxSize(),
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            if (状态.提示.value.isNotBlank()) {
                Text(状态.提示.value, color = MaterialTheme.colorScheme.error)
            }
            Text(
                "当前余额 ${状态.我的.value.余额}",
                style = MaterialTheme.typography.titleMedium
            )
            if (订单 == null) {
                // ---- 选面额下单 ----
                Text("选择充值金额", style = MaterialTheme.typography.titleSmall)
                if (状态.充值面额表.isEmpty() && !状态.自定义金额开.value) {
                    CircularProgressIndicator(Modifier.align(Alignment.CenterHorizontally))
                } else {
                    /* 两列网格。一行一个面额太占竖向空间，手机上要滑好几屏才看完，
                       两列刚好：卡片还够宽放下「到账 / 实付」两行字，又能一屏看全。
                       自定义输入框作为整行的 item 跟在网格后面，跟着一起滚，
                       不单独拎到网格外面——否则面额多的时候它会被挤出屏幕。 */
                    LazyVerticalGrid(
                        columns = GridCells.Fixed(2),
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        items(状态.充值面额表) { 面额 ->
                            OutlinedCard(
                                onClick = { if (!状态.充值中.value) 状态.充值下单(面额.到账) },
                                modifier = Modifier.fillMaxWidth()
                            ) {
                                Column(
                                    Modifier.padding(14.dp).fillMaxWidth(),
                                    horizontalAlignment = Alignment.CenterHorizontally
                                ) {
                                    Text(
                                        "到账 ¥${面额.到账}",
                                        style = MaterialTheme.typography.bodyLarge,
                                        fontWeight = FontWeight.Bold
                                    )
                                    Text(
                                        "实付 ¥${面额.实付}",
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                }
                            }
                        }
                        // 自定义金额独占一整行
                        if (状态.自定义金额开.value) {
                            item(span = { GridItemSpan(maxLineSpan) }) {
                                自定义金额框(状态)
                            }
                        }
                        // 下单请求发出后给个转圈，占整行，避免每张卡片里都转一个
                        if (状态.充值中.value) {
                            item(span = { GridItemSpan(maxLineSpan) }) {
                                Box(Modifier.fillMaxWidth().padding(8.dp), contentAlignment = Alignment.Center) {
                                    CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
                                }
                            }
                        }
                    }
                }
            } else {
                // ---- 订单已创建，等待支付 ----
                Card(Modifier.fillMaxWidth()) {
                    Column(
                        Modifier.padding(20.dp).fillMaxWidth(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        Text("订单号 ${订单.订单号}", style = MaterialTheme.typography.bodySmall)
                        Text(
                            "到账 ¥${订单.到账}　实付 ¥${订单.实付}",
                            style = MaterialTheme.typography.bodyLarge
                        )
                        if (订单.类型 == "qr") {
                            if (二维码 != null) {
                                val 位图 = BitmapFactory.decodeByteArray(二维码, 0, 二维码.size)
                                Image(
                                    位图.asImageBitmap(),
                                    contentDescription = "支付宝收款码",
                                    modifier = Modifier.size(220.dp)
                                )
                                Text(
                                    "打开支付宝扫码支付，付完自动到账",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    textAlign = TextAlign.Center
                                )
                            } else {
                                CircularProgressIndicator()
                                Text("正在生成收款码…", style = MaterialTheme.typography.bodySmall)
                            }
                        } else if (订单.地址.isNotBlank()) {
                            Text(
                                "点击下方按钮完成支付，付完回到本页会自动到账",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                textAlign = TextAlign.Center
                            )
                            Button(
                                onClick = {
                                    val i = Intent(Intent.ACTION_VIEW, Uri.parse(订单.地址))
                                    上下文.startActivity(i)
                                },
                                modifier = Modifier.fillMaxWidth()
                            ) {
                                Text("打开支付页面")
                            }
                        }
                        Spacer(Modifier.height(4.dp))
                        OutlinedButton(
                            onClick = { 状态.查充值单(订单.订单号, 手动 = true) {} },
                            enabled = !状态.查单中.value,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            if (状态.查单中.value) {
                                CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                                Spacer(Modifier.width(8.dp))
                            }
                            Text(if (状态.查单中.value) "查询中…" else "我已支付，立即查询")
                        }
                        TextButton(onClick = { 状态.关充值页() }) {
                            Text("取消本次充值")
                        }
                    }
                }
            }
        }
    }
}

/**
 * 自定义金额输入框。对齐网页端 recharge.php 的那一个。
 *
 * 三条约束：
 * 1. 输入的是「到账」金额，实付由服务端按折扣阶梯反算，这里不自己算，
 *    免得客户端算法和服务端不一致（面额卡片上的实付也是服务端给的）。
 * 2. 区间用服务端下发的 min / max，后台改了配置不用发版。
 * 3. 只放行数字和一个小数点：金额键盘在部分输入法上仍能敲出 - 和多个点，
 *    脏字符送到服务端会被打回，不如在这里就挡掉。
 */
@Composable
private fun 自定义金额框(状态: 主状态) {
    var 金额 by remember { mutableStateOf("") }
    val 下限 = 状态.自定义金额下限.value
    val 上限 = 状态.自定义金额上限.value
    // 去掉无意义的小数尾零：1.00 显示成 1，99999.00 显示成 99999
    fun 显(v: Double) = if (v == v.toLong().toDouble()) v.toLong().toString() else v.toString()
    val 数值 = 金额.toDoubleOrNull()
    val 合法 = 数值 != null && 数值 >= 下限 && 数值 <= 上限
    Column(Modifier.fillMaxWidth().padding(top = 4.dp)) {
        Text(
            "或自定义金额（${显(下限)} ~ ${显(上限)} 元）",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
        Spacer(Modifier.height(6.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(
                value = 金额,
                onValueChange = { 新值 ->
                    // 只留数字和一个小数点，最多两位小数
                    val 过滤 = 新值.filter { it.isDigit() || it == '.' }
                    val 首点 = 过滤.indexOf('.')
                    金额 = if (首点 < 0) 过滤
                    else {
                        val 整 = 过滤.substring(0, 首点)
                        val 小 = 过滤.substring(首点 + 1).filter { it.isDigit() }.take(2)
                        "$整.$小"
                    }
                },
                modifier = Modifier.weight(1f),
                singleLine = true,
                placeholder = { Text("输入到账金额") },
                prefix = { Text("¥") },
                isError = 金额.isNotBlank() && !合法,
                keyboardOptions = KeyboardOptions(
                    keyboardType = KeyboardType.Decimal,
                    imeAction = ImeAction.Done
                )
            )
            Spacer(Modifier.width(8.dp))
            Button(
                onClick = { if (合法 && !状态.充值中.value) 状态.充值下单(金额) },
                enabled = 合法 && !状态.充值中.value
            ) { Text("充值") }
        }
        // 只在填了东西又不合法时才提示，空着不报错
        if (金额.isNotBlank() && !合法) {
            Text(
                "金额需在 ${显(下限)} ~ ${显(上限)} 元之间",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.error
            )
        }
    }
}
