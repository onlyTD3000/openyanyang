package cn.bot77.yanyang.net
import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.File
import java.security.MessageDigest
import java.util.concurrent.TimeUnit
/**
 * 下载进度。
 *
 * 已下 和 总长 都是字节数。总长 为 0 表示服务端没给 Content-Length
 * 也没给 size，这时算不出百分比，界面显示已下多少 MB 就行。
 */
data class 下载进度(
    val 已下: Long = 0L,
    val 总长: Long = 0L
) {
    /** 0..1，总长未知时返回 null，界面据此切成不确定进度条 */
    val 比例: Float?
        get() = if (总长 > 0L) (已下.toFloat() / 总长.toFloat()).coerceIn(0f, 1f) else null
    val 百分比: Int
        get() = ((比例 ?: 0f) * 100).toInt()
}
/**
 * 应用内下载新版 APK 并拉起系统安装界面。
 *
 * 为什么不用 DownloadManager：它把文件下到公共下载目录，Android 10 起
 * 拿回可读路径要绕分区存储，而且进度只能靠轮询查询系统数据库。
 * 这里用 OkHttp 直接下到应用自己的缓存目录，进度是流式读出来的，
 * 配 FileProvider 交给安装器，全程不碰外部存储权限。
 */
object 更新下载 {
    private val 客户端 = OkHttpClient.Builder()
        // 不设总超时（callTimeout = 0），因为下载 APK 的速度完全取决于服务器带宽，
        // 当前带宽满载时 2MB 可能要 3 分钟，设置死线反而会提前断掉。
        .callTimeout(0, TimeUnit.SECONDS)
        .connectTimeout(15, TimeUnit.SECONDS)
        // 读超时：如果 5 分钟内没有任何数据流入再判定死连接，通常带宽再慢也
        // 不会连续 5 分钟完全没字节，所以这个值既能兜底也不会误杀。
        .readTimeout(300, TimeUnit.SECONDS)
        .build()
    /** 下载目录。优先外部缓存（安装器好读），拿不到就回退内部缓存 */
    private fun 目录(上下文: Context): File {
        val 根 = 上下文.externalCacheDir ?: 上下文.cacheDir
        return File(根, "update").apply { if (!exists()) mkdirs() }
    }
    /**
     * 下载指定版本的安装包。
     *
     * @param 进度回调 在 IO 线程上被频繁调用，界面那层要自己切回主线程更新状态
     * @return 成功给出本地文件，失败给出错误说明
     */
    suspend fun 下(
        上下文: Context,
        地址: String,
        版本号: String,
        期望校验值: String,
        期望大小: Long,
        进度回调: (下载进度) -> Unit
    ): Result<File> = withContext(Dispatchers.IO) {
        runCatching {
            val 目标 = File(目录(上下文), "yanyang-$版本号.apk")
            // 之前下过同一版且校验通过，直接复用，不重复消耗流量
            if (目标.isFile && 期望校验值.isNotBlank() && 算校验值(目标) == 期望校验值.lowercase()) {
                进度回调(下载进度(目标.length(), 目标.length()))
                return@runCatching 目标
            }
            // 边下边写临时文件，全部完成并校验通过才改名成正式文件，
            // 中途断了不会留下一个看起来完整的坏包
            val 临时 = File(目录(上下文), "yanyang-$版本号.apk.part")
            if (临时.exists()) 临时.delete()
            val 响应 = 客户端.newCall(Request.Builder().url(地址).get().build()).execute()
            响应.use { 回 ->
                if (!回.isSuccessful) {
                    error("服务端返回 ${回.code}")
                }
                val 体 = 回.body ?: error("响应没有内容")
                // Content-Length 优先，没有就用接口给的 size
                val 总长 = 体.contentLength().takeIf { it > 0L } ?: 期望大小
                体.byteStream().use { 入 ->
                    临时.outputStream().use { 出 ->
                        val 缓冲 = ByteArray(64 * 1024)
                        var 已下 = 0L
                        var 上次报告 = 0L
                        while (true) {
                            val n = 入.read(缓冲)
                            if (n < 0) break
                            出.write(缓冲, 0, n)
                            已下 += n
                            // 每积累 128KB 报一次，太频繁会让主线程忙于重组
                            if (已下 - 上次报告 >= 128 * 1024) {
                                进度回调(下载进度(已下, 总长))
                                上次报告 = 已下
                            }
                        }
                        出.flush()
                        进度回调(下载进度(已下, 总长))
                    }
                }
            }
            // 校验：服务端给了校验值就必须对上，不然宁可失败也不装
            if (期望校验值.isNotBlank()) {
                val 实际 = 算校验值(临时)
                if (实际 != 期望校验值.lowercase()) {
                    临时.delete()
                    error("安装包校验不通过，可能下载被中断或篡改，请重试")
                }
            }
            if (目标.exists()) 目标.delete()
            if (!临时.renameTo(目标)) {
                临时.delete()
                error("保存安装包失败")
            }
            目标
        }.onFailure {
            // 失败时清掉半截文件，下次从头下
            File(目录(上下文), "yanyang-$版本号.apk.part").delete()
        }
    }
    /** 算文件的 SHA-256，返回小写十六进制 */
    private fun 算校验值(文件: File): String {
        val 摘要 = MessageDigest.getInstance("SHA-256")
        文件.inputStream().use { 入 ->
            val 缓冲 = ByteArray(64 * 1024)
            while (true) {
                val n = 入.read(缓冲)
                if (n < 0) break
                摘要.update(缓冲, 0, n)
            }
        }
        return 摘要.digest().joinToString("") { "%02x".format(it) }
    }
    /**
     * 拉起系统安装界面。
     *
     * 用户没开「允许安装未知应用」时系统会自己弹设置引导，
     * 这里不额外判断权限：各家 ROM 的跳转页不一致，交给系统更稳。
     */
    fun 装(上下文: Context, 包文件: File): Result<Unit> = runCatching {
        val 地址: Uri = FileProvider.getUriForFile(
            上下文,
            上下文.packageName + ".apkprovider",
            包文件
        )
        val 意图 = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(地址, "application/vnd.android.package-archive")
            // 授临时读权限给安装器，否则它读不到 content:// 里的内容
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        上下文.startActivity(意图)
    }
}
