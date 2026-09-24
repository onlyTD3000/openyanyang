package cn.bot77.yanyang.net
import okhttp3.FormBody
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.util.concurrent.TimeUnit
/** 服务端地址，跟桌面端 main.js 里的常量保持一致 */
const val 服务端 = "https://codex.77bot.cn"
/**
 * 普通接口调用。
 *
 * 对齐桌面端 ipcMain.handle('接口') 的行为：
 * GET 把参数拼到查询串，POST 用 form-urlencoded；统一带 Bearer 头；
 * 后端偶发返回 HTML 错误页，解析失败不抛异常，包成 {error:...} 返回，
 * 界面那层就不用到处 try-catch 了。
 */
object 接口 {
    // 服务端靠 User-Agent 里的 Android/Mobile 关键字判断是不是手机设备，
    // 从而在下单时自动选支付宝的 wap 支付形态（而不是电脑网页扫码那套）。
    // OkHttp 默认 UA 不带这些关键字，这里统一加一个请求头拦截器补上。
    private val 客户端 = OkHttpClient.Builder()
        .callTimeout(30, TimeUnit.SECONDS)
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .addInterceptor { 链 ->
            val 新请求 = 链.request().newBuilder()
                .header("User-Agent", "Mozilla/5.0 (Linux; Android 13; Mobile) YanyangAI-Android/1.0")
                .header("X-Client-Type", "android")
                .build()
            链.proceed(新请求)
        }
        .build()
    /**
     * @param 路径 形如 /api/me.php
     * @param 参数 表单或查询参数
     * @param 方法 GET 或 POST，默认 POST
     * @param 密钥 Bearer token
     * @return 解析后的 JSON；失败时是 {"error":"原因"}
     */
    fun 调(
        路径: String,
        参数: Map<String, String> = emptyMap(),
        方法: String = "POST",
        密钥: String
    ): JSONObject {
        val 是GET = 方法.uppercase() == "GET"
        return try {
            val 地址 = (服务端 + 路径).toHttpUrl().newBuilder().apply {
                if (是GET) 参数.forEach { (键, 值) -> addQueryParameter(键, 值) }
            }.build()
            val 构造 = Request.Builder()
                .url(地址)
                .header("Authorization", "Bearer $密钥")
            if (是GET) {
                构造.get()
            } else {
                val 表单 = FormBody.Builder().apply {
                    参数.forEach { (键, 值) -> add(键, 值) }
                }.build()
                构造.post(表单)
            }
            客户端.newCall(构造.build()).execute().use { 响应 ->
                val 正文 = 响应.body?.string().orEmpty()
                try {
                    JSONObject(正文)
                } catch (e: Exception) {
                    错误对象("服务端返回异常（HTTP ${响应.code}）")
                }
            }
        } catch (e: java.io.InterruptedIOException) {
            错误对象("请求超时")
        } catch (e: Exception) {
            错误对象("网络错误：${e.message ?: "未知"}")
        }
    }
    /**
     * 上传文件（multipart）。图片走 /api/upload.php，字段名固定是 file。
     *
     * 后端用 getimagesize 判定真实类型，不信客户端报的 MIME，
     * 所以这里 MIME 传得粗一点也没关系，但文件名要给个合理的扩展名。
     */
    fun 传文件(
        路径: String,
        字节: ByteArray,
        文件名: String,
        类型: String = "image/jpeg",
        密钥: String,
        额外参数: Map<String, String> = emptyMap()
    ): JSONObject {
        return try {
            val 体 = MultipartBody.Builder()
                .setType(MultipartBody.FORM)
                .apply {
                    额外参数.forEach { (键, 值) -> addFormDataPart(键, 值) }
                }
                .addFormDataPart(
                    "file", 文件名,
                    字节.toRequestBody(类型.toMediaTypeOrNull())
                )
                .build()
            val 请求 = Request.Builder()
                .url(服务端 + 路径)
                .header("Authorization", "Bearer $密钥")
                .post(体)
                .build()
            客户端.newCall(请求).execute().use { 响应 ->
                val 正文 = 响应.body?.string().orEmpty()
                try {
                    JSONObject(正文)
                } catch (e: Exception) {
                    错误对象("服务端返回异常（HTTP ${响应.code}）")
                }
            }
        } catch (e: java.io.InterruptedIOException) {
            错误对象("上传超时，图片可能太大")
        } catch (e: Exception) {
            错误对象("上传失败：${e.message ?: "未知"}")
        }
    }
    /**
     * 取原始字节，用于图片一类不是 JSON 的响应（比如支付二维码）。
     * 失败或非 2xx 都返回 null，界面那层自己决定怎么提示。
     */
    fun 取字节(路径: String, 参数: Map<String, String> = emptyMap(), 密钥: String): ByteArray? {
        return try {
            val 地址 = (服务端 + 路径).toHttpUrl().newBuilder().apply {
                参数.forEach { (键, 值) -> addQueryParameter(键, 值) }
            }.build()
            val 请求 = Request.Builder()
                .url(地址)
                .header("Authorization", "Bearer $密钥")
                .get()
                .build()
            客户端.newCall(请求).execute().use { 响应 ->
                if (响应.isSuccessful) 响应.body?.bytes() else null
            }
        } catch (e: Exception) {
            null
        }
    }
    private fun 错误对象(提示: String) = JSONObject().put("error", 提示)
}
