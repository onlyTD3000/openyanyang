package cn.bot77.yanyang.data
import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
/**
 * 密钥存取。
 *
 * 桌面端的做法是把 token 留在主进程内存里，界面拿不到 fs 也拿不到 ipcRenderer，
 * 所以明文不落盘。安卓这边没有多进程隔离可用，改成 EncryptedSharedPreferences：
 * 主密钥存在系统 Keystore 里，App 私有目录下的文件即使被 root 拖出来也是密文。
 *
 * 注意不要退化成普通 SharedPreferences，那个是明文 XML，root 手机上直接可读。
 */
class 密钥仓(上下文: Context) {
    private val 存储 = run {
        val 主密钥 = MasterKey.Builder(上下文)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            上下文,
            "岩羊密钥",
            主密钥,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    }
    /** 读出保存的密钥，没存过返回空串 */
    fun 读(): String = 存储.getString(键_密钥, "") ?: ""
    /** 存密钥。传空串等于清除 */
    fun 存(密钥: String) {
        存储.edit().apply {
            if (密钥.isBlank()) remove(键_密钥) else putString(键_密钥, 密钥.trim())
        }.apply()
    }
    /** 退出登录时清掉 */
    fun 清() {
        存储.edit().remove(键_密钥).apply()
    }
    fun 有密钥(): Boolean = 读().isNotBlank()
    private companion object {
        const val 键_密钥 = "token"
    }
}
