package cn.bot77.yanyang.media
import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFocusRequest
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioTrack
import android.os.Build
import android.util.Log
import kotlin.concurrent.thread
import kotlin.math.PI
import kotlin.math.asin
import kotlin.math.exp
import kotlin.math.sin
/**
 * 任务完成提示音。三连上升音 2400 / 2900 / 3400 Hz，间隔 0.11 秒，
 * 三角波，起音 8ms，之后指数衰减到 0.12 秒收尾。参数跟网页端一致。
 *
 * 为什么走媒体通道（USAGE_MEDIA）而不是 USAGE_ASSISTANCE_SONIFICATION：
 * SONIFICATION 会被系统映射到 STREAM_SYSTEM，手机一旦切到静音或振动模式，
 * 这个通道被整条掐掉，一点声音都没有。而用户调的「音量」日常指的就是媒体
 * 音量，网页端也是走媒体输出，改成 MEDIA 才和用户预期一致。
 *
 * 用 MODE_STREAM 而不是 MODE_STATIC：STATIC 要求一次写满整块缓冲，
 * 缓冲区大小算得不合适就静默失败，排查起来没有任何线索。STREAM 边写边播，
 * 放在后台线程里写，写完再释放，行为可预测。
 */
object 提示音 {
    private const val 标签 = "提示音"
    private const val 采样率 = 44100
    /** 三声的频率和起始秒数 */
    private val 音符 = listOf(2400.0 to 0.0, 2900.0 to 0.11, 3400.0 to 0.22)
    /** 单声时长：0.12 秒 */
    private const val 单声时长 = 0.12
    /**
     * 响一声。整个过程在后台线程完成，调用方不会被阻塞。
     *
     * @param 上下文 用来拿 AudioManager 申请媒体焦点
     * @param 音量百分比 5~100，超出范围会被夹回区间内
     */
    fun 响(上下文: Context, 音量百分比: Int) {
        val 百分比 = 音量百分比.coerceIn(5, 100)
        // 0.9 上限：再高衰减起点会贴到削波边缘，出现失真
        val 峰值 = 百分比 / 100.0 * 0.9
        thread(name = "提示音", isDaemon = true) {
            var 音管: AudioManager? = null
            var 焦点请求: AudioFocusRequest? = null
            var 轨: AudioTrack? = null
            try {
                音管 = 上下文.applicationContext
                    .getSystemService(Context.AUDIO_SERVICE) as? AudioManager
                val 属性 = AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_MEDIA)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
                // 申请短暂焦点，让系统把用户正在放的音乐压低（ducking），响完还回去
                if (音管 != null) {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        焦点请求 = AudioFocusRequest.Builder(
                            AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_MAY_DUCK
                        ).setAudioAttributes(属性).build()
                        音管.requestAudioFocus(焦点请求!!)
                    } else {
                        @Suppress("DEPRECATION")
                        音管.requestAudioFocus(
                            null,
                            AudioManager.STREAM_MUSIC,
                            AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_MAY_DUCK
                        )
                    }
                }
                val 采样 = 合成(峰值)
                val 格式 = AudioFormat.Builder()
                    .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
                    .setSampleRate(采样率)
                    .setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                    .build()
                // 缓冲区取系统建议的最小值和整段音频的较大者，避免欠载导致断音
                val 最小缓冲 = AudioTrack.getMinBufferSize(
                    采样率,
                    AudioFormat.CHANNEL_OUT_MONO,
                    AudioFormat.ENCODING_PCM_16BIT
                )
                val 缓冲 = if (最小缓冲 > 0) maxOf(最小缓冲, 采样.size * 2) else 采样.size * 2
                轨 = AudioTrack.Builder()
                    .setAudioAttributes(属性)
                    .setAudioFormat(格式)
                    .setBufferSizeInBytes(缓冲)
                    .setTransferMode(AudioTrack.MODE_STREAM)
                    .build()
                // 初始化失败时 write 会静默返回负数，什么都听不到又没有报错。
                // 这里显式检查，把原因留在 logcat 里，方便真机排查。
                if (轨.state != AudioTrack.STATE_INITIALIZED) {
                    Log.w(标签, "AudioTrack 初始化失败，state=" + 轨.state)
                    return@thread
                }
                轨.play()
                // write 是阻塞的，在这个后台线程里写完整段就等于播完
                var 已写 = 0
                while (已写 < 采样.size) {
                    val n = 轨.write(采样, 已写, 采样.size - 已写)
                    if (n <= 0) {
                        Log.w(标签, "write 返回 " + n + "，中断播放")
                        break
                    }
                    已写 += n
                }
                // 等缓冲里剩下的采样播完再停，否则尾音会被切掉
                try { 轨.stop() } catch (e: IllegalStateException) {}
                val 毫秒 = ((音符.last().second + 单声时长) * 1000).toLong()
                Thread.sleep(毫秒 + 60)
            } catch (e: Exception) {
                // 出不了声不该影响主流程：设备不支持、焦点被独占都可能异常
                Log.w(标签, "播放失败: " + e)
            } finally {
                try { 轨?.release() } catch (e: Exception) {}
                if (音管 != null) {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        焦点请求?.let {
                            try { 音管.abandonAudioFocusRequest(it) } catch (e: Exception) {}
                        }
                    } else {
                        @Suppress("DEPRECATION")
                        try { 音管.abandonAudioFocus(null) } catch (e: Exception) {}
                    }
                }
            }
        }
    }
    /**
     * 合成三连音的 PCM 采样。
     *
     * 包络跟网页端的 exponentialRampToValueAtTime 对齐：
     * 前 8ms 从近零冲到峰值，之后指数衰减回近零。直接切断会有「啪」的爆音。
     */
    private fun 合成(峰值: Double): ShortArray {
        val 总秒 = 音符.last().second + 单声时长
        val 总长 = (总秒 * 采样率).toInt()
        val 采样 = ShortArray(总长)
        for ((频率, 起) in 音符) {
            val 起点 = (起 * 采样率).toInt()
            val 长度 = (单声时长 * 采样率).toInt()
            for (i in 0 until 长度) {
                val 下标 = 起点 + i
                if (下标 >= 总长) break
                val t = i.toDouble() / 采样率
                // 三角波：由正弦经反正弦变换得到，不用查表也不引入额外依赖
                val 波 = 2.0 / PI * asin(sin(2.0 * PI * 频率 * t))
                // 8ms 起音 + 指数衰减
                val 包络 = if (t < 0.008) t / 0.008 else exp(-(t - 0.008) * 34.0)
                val 值 = 波 * 包络 * 峰值
                // 三声叠加处可能超界，夹一下防溢出
                val 合 = 采样[下标] + (值 * Short.MAX_VALUE).toInt()
                采样[下标] = 合.coerceIn(Short.MIN_VALUE.toInt(), Short.MAX_VALUE.toInt()).toShort()
            }
        }
        return 采样
    }
}
