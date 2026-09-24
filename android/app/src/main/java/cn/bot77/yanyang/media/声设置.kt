package cn.bot77.yanyang.media
import android.content.Context
/**
 * 提示音设置。开关 + 音量百分比，跟网页端 chat.js 的 声开着 / 声音量 一一对应。
 *
 * 按用户隔离：键名带用户 id，同一台手机换账号登录各自的设置互不影响，
 * 这跟网页端 localStorage 里带 UID 的做法一致。
 *
 * 用普通 SharedPreferences 就够，不走 EncryptedSharedPreferences：
 * 这里面只有开关和音量，不是敏感数据，加密白付一次开销（密钥仓那边存的是
 * API 密钥，才需要加密）。
 */
object 声设置 {
    private const val 库名 = "提示音"
    /** 默认开，跟网页端一致 */
    private const val 默认开 = true
    /** 默认 45%，跟网页端一致 */
    const val 默认音量 = 45
    const val 最小音量 = 5
    const val 最大音量 = 100
    private fun 库(上下文: Context) =
        上下文.getSharedPreferences(库名, Context.MODE_PRIVATE)
    private fun 键(名: String, 用户id: String) = 名 + "_" + 用户id.ifBlank { "0" }
    fun 开着(上下文: Context, 用户id: String): Boolean =
        库(上下文).getBoolean(键("on", 用户id), 默认开)
    fun 设开关(上下文: Context, 用户id: String, 开: Boolean) {
        库(上下文).edit().putBoolean(键("on", 用户id), 开).apply()
    }
    /** 取音量百分比，落在 5~100，越界或没存过都回默认值 */
    fun 音量(上下文: Context, 用户id: String): Int {
        val v = 库(上下文).getInt(键("vol", 用户id), 默认音量)
        return if (v < 最小音量 || v > 最大音量) 默认音量 else v
    }
    fun 设音量(上下文: Context, 用户id: String, 百分比: Int) {
        库(上下文).edit()
            .putInt(键("vol", 用户id), 百分比.coerceIn(最小音量, 最大音量))
            .apply()
    }
}
