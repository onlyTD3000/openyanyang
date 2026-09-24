package cn.bot77.yanyang.net
import org.json.JSONObject
import java.util.concurrent.TimeUnit
import okhttp3.OkHttpClient
import okhttp3.Request
/**
 * 版本检查结果。
 *
 * 有更新时 有新版 为 true，界面据此把菜单项标红。
 * 查询失败不当成「有更新」——网络不通时弹红点会让人以为漏了版本，
 * 实际什么也点不出来，反而误导。
 */
data class 版本信息(
    val 有新版: Boolean = false,
    val 最新版: String = "",
    val 当前版: String = "",
    val 强制: Boolean = false,
    val 下载地址: String = "",
    val 更新日志: String = "",
    /** 服务端给出的 APK 校验值，下载完比对，防止装到半截包 */
    val 校验值: String = "",
    /** APK 字节数，用于算下载进度；为 0 表示服务端没给 */
    val 大小: Long = 0L,
    val 出错: String = ""
)
/**
 * 问服务端安卓端有没有新版。
 *
 * 走 /api/version.php?side=android，不需要登录：
 * 密钥失效时用户恰恰最需要能查更新，这个接口只吐版本号和下载地址。
 */
object 版本检查 {
    private val 客户端 = OkHttpClient.Builder()
        .callTimeout(30, TimeUnit.SECONDS)
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()
    /**
     * @param 当前版本 本机 versionName，由调用方从 BuildConfig 传进来
     */
    fun 查(当前版本: String): 版本信息 {
        return try {
            val 地址 = "$服务端/api/version.php?side=android&cur=" + 当前版本
            val 响应 = 客户端.newCall(Request.Builder().url(地址).get().build()).execute()
            响应.use { 回 ->
                val 文 = 回.body?.string().orEmpty()
                if (!回.isSuccessful) {
                    return 版本信息(出错 = "服务端返回 " + 回.code)
                }
                val j = JSONObject(文)
                版本信息(
                    有新版 = j.optInt("update", 0) == 1,
                    最新版 = j.optString("latest", ""),
                    当前版 = 当前版本,
                    强制 = j.optInt("force", 0) == 1,
                    下载地址 = j.optString("url", ""),
                    更新日志 = j.optString("notes", ""),
                    校验值 = j.optString("sha256", ""),
                    大小 = j.optLong("size", 0L)
                )
            }
        } catch (e: Exception) {
            // 网络异常、JSON 解析失败都走这里。不抛出去，界面那层不用 try-catch
            版本信息(出错 = e.message ?: "检查失败")
        }
    }
}
