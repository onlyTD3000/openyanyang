package cn.bot77.yanyang
import android.app.Application
import cn.bot77.yanyang.data.密钥仓
/**
 * Application。
 *
 * 密钥仓的初始化要碰系统 Keystore，有几十毫秒开销，
 * 放这里建一次全局复用，别每个界面各建一个。
 */
class 岩羊应用 : Application() {
    /** 全进程共用的密钥仓，懒加载 */
    val 密钥存储: 密钥仓 by lazy { 密钥仓(this) }
    override fun onCreate() {
        super.onCreate()
        实例 = this
    }
    companion object {
        lateinit var 实例: 岩羊应用
            private set
    }
}
