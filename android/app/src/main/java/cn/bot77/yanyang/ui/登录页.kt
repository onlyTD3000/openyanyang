package cn.bot77.yanyang.ui
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
/**
 * 登录页。只要一个 API 密钥，跟桌面端一样。
 * 密钥在 /api/me.php 上验，验过就存进加密的 SharedPreferences。
 */
@Composable
fun 登录页(状态: 主状态) {
    var 明文 by remember { mutableStateOf(false) }
    val 加载 = 状态.加载中.value
    val 提示 = 状态.提示.value
    Box(
        Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .imePadding(),          // 键盘弹出时整体上推，不遮住输入框
        contentAlignment = Alignment.Center
    ) {
        Column(
            Modifier
                .widthIn(max = 400.dp)
                .padding(horizontal = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Text(
                "岩羊Ai",
                fontSize = 32.sp,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.primary
            )
            Spacer(Modifier.height(8.dp))
            Text(
                "填入 API 密钥即可开始",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.height(36.dp))
            OutlinedTextField(
                value = 状态.输入的密钥.value,
                onValueChange = { 状态.输入的密钥.value = it },
                label = { Text("API 密钥") },
                placeholder = { Text("sk-...") },
                singleLine = true,
                enabled = !加载,
                visualTransformation =
                    if (明文) VisualTransformation.None
                    else PasswordVisualTransformation(),
                trailingIcon = {
                    IconButton(onClick = { 明文 = !明文 }) {
                        Icon(
                            if (明文) Icons.Default.VisibilityOff else Icons.Default.Visibility,
                            contentDescription = if (明文) "隐藏密钥" else "显示密钥"
                        )
                    }
                },
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Go),
                keyboardActions = KeyboardActions(onGo = { 状态.登录() }),
                isError = 提示.isNotBlank(),
                modifier = Modifier.fillMaxWidth()
            )
            // 错误提示区。用最小高度而不是固定高度：固定 28dp 只装得下一行，
            // 网络类报错常是两三行（「Unable to resolve host ...」那种），
            // 超出的部分会被裁掉，用户只看到半句话。保留最小高度是为了
            // 没有错误时也占住位置，避免提示一出现就把登录按钮往下顶。
            Box(
                Modifier.fillMaxWidth().heightIn(min = 28.dp).padding(vertical = 2.dp),
                contentAlignment = Alignment.CenterStart
            ) {
                if (提示.isNotBlank()) {
                    Text(
                        提示,
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodySmall
                    )
                }
            }
            Button(
                onClick = { 状态.登录() },
                enabled = !加载 && 状态.输入的密钥.value.isNotBlank(),
                modifier = Modifier.fillMaxWidth().height(48.dp)
            ) {
                if (加载) {
                    CircularProgressIndicator(
                        Modifier.size(20.dp),
                        color = MaterialTheme.colorScheme.onPrimary,
                        strokeWidth = 2.dp
                    )
                } else {
                    Text("登录", fontSize = 16.sp)
                }
            }
            Spacer(Modifier.height(20.dp))
            Text(
                "密钥只存在本机，加密保存，不会上传到别处",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center
            )
        }
    }
}
