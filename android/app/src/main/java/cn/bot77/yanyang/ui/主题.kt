package cn.bot77.yanyang.ui
import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import androidx.core.view.WindowCompat
// 色值照抄桌面端 app.css 里的 CSS 变量，保持两端观感一致
private val 主色 = Color(0xFF2563EB)      // --主
private val 危险色 = Color(0xFFDC2626)    // --危险
private val 字色 = Color(0xFF1F2329)      // --字
private val 底色 = Color(0xFFF7F8FA)      // --底
private val 淡字色 = Color(0xFF8A9099)    // --淡字
private val 线色 = Color(0xFFE4E6EB)      // --线
private val 面色 = Color(0xFFFFFFFF)      // --面
private val 主色浅 = Color(0xFFEFF4FF)    // --c-primary-soft
// 深色是手机上补的，桌面端没有深色主题。夜里看屏幕刺眼，值得多做这一套
private val 深底 = Color(0xFF15171A)
private val 深面 = Color(0xFF1E2126)
private val 深线 = Color(0xFF2C3036)
private val 深字 = Color(0xFFE3E5E8)
private val 深淡字 = Color(0xFF8B9198)
private val 浅色板 = lightColorScheme(
    primary = 主色,
    onPrimary = Color.White,
    primaryContainer = 主色浅,
    onPrimaryContainer = 主色,
    background = 底色,
    onBackground = 字色,
    surface = 面色,
    onSurface = 字色,
    surfaceVariant = 底色,
    onSurfaceVariant = 淡字色,
    outline = 线色,
    outlineVariant = 线色,
    error = 危险色,
    onError = Color.White
)
private val 深色板 = darkColorScheme(
    primary = Color(0xFF5B8DEF),
    onPrimary = Color.White,
    primaryContainer = Color(0xFF1E3A6E),
    onPrimaryContainer = Color(0xFFCEDDFF),
    background = 深底,
    onBackground = 深字,
    surface = 深面,
    onSurface = 深字,
    surfaceVariant = 深线,
    onSurfaceVariant = 深淡字,
    outline = 深线,
    outlineVariant = 深线,
    error = Color(0xFFEF5350),
    onError = Color.White
)
/** 字号偏小一档，手机上信息密度高些更好用 */
private val 字体 = Typography(
    bodyLarge = TextStyle(fontSize = 15.sp, lineHeight = 23.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 21.sp),
    bodySmall = TextStyle(fontSize = 12.sp, lineHeight = 18.sp, color = 淡字色),
    titleMedium = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.Medium),
    titleSmall = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Medium),
    labelLarge = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Medium)
)
/** 代码和终端输出用等宽，缩进和对齐才不会乱 */
val 等宽体 = TextStyle(
    fontFamily = FontFamily.Monospace,
    fontSize = 12.sp,
    lineHeight = 18.sp
)
@Composable
fun 岩羊主题(深色: Boolean = isSystemInDarkTheme(), 内容: @Composable () -> Unit) {
    val 色板 = if (深色) 深色板 else 浅色板
    val 视图 = LocalView.current
    if (!视图.isInEditMode) {
        SideEffect {
            val 窗 = (视图.context as Activity).window
            窗.statusBarColor = 色板.surface.toArgb()
            // 浅色背景要用深色图标，不然状态栏文字看不见
            WindowCompat.getInsetsController(窗, 视图)
                .isAppearanceLightStatusBars = !深色
        }
    }
    MaterialTheme(colorScheme = 色板, typography = 字体, content = 内容)
}
