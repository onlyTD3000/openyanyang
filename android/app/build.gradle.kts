import java.io.FileInputStream
import java.util.Properties
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("org.jetbrains.kotlin.plugin.serialization")
}
// 签名配置从 keystore.properties 读，密码不写死在构建脚本里。
// 属性读取放在 android 块外面：嵌套 lambda 里 Properties 的方法解析不到。
val 签名配置文件 = rootProject.file("keystore.properties")
val 签名属性 = Properties()
if (签名配置文件.exists()) {
    FileInputStream(签名配置文件).use { 流 -> 签名属性.load(流) }
}
val 有签名 = 签名配置文件.exists()
android {
    namespace = "cn.bot77.yanyang"
    compileSdk = 35
    defaultConfig {
        applicationId = "cn.bot77.yanyang"
        minSdk = 26          // Android 8.0，EncryptedSharedPreferences 要求 23+，26 能用上更多新 API
        targetSdk = 35
        versionCode = 68
        versionName = "1.6.0"
    }
    signingConfigs {
        if (有签名) {
            create("发布") {
                storeFile = rootProject.file(签名属性.getProperty("storeFile"))
                storePassword = 签名属性.getProperty("storePassword")
                keyAlias = 签名属性.getProperty("keyAlias")
                keyPassword = 签名属性.getProperty("keyPassword")
            }
        }
    }
    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            // 有 keystore 才启用签名，没有就出未签名包，避免构建直接失败
            if (有签名) {
                signingConfig = signingConfigs.getByName("发布")
            }
        }
        debug {
            applicationIdSuffix = ".debug"
            isMinifyEnabled = false
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        // 版本更新项要读 BuildConfig.VERSION_NAME 拿本机版本号。
        // AGP 8 起 buildConfig 默认关闭，不显式打开这个类不会生成。
        buildConfig = true
    }
    packaging {
        resources.excludes += setOf(
            "/META-INF/{AL2.0,LGPL2.1}",
            "/META-INF/DEPENDENCIES"
        )
    }
}
dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.09.02")
    implementation(composeBom)
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.5")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.5")
    implementation("androidx.activity:activity-compose:1.9.2")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-graphics")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    // 加密存储密钥，替代桌面端主进程内存保管的做法
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    // HTTP 与 SSE 流式对话
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    // JSON 解析，用 kotlinx 官方库
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.3")
    debugImplementation("androidx.compose.ui:ui-tooling")
    implementation("androidx.compose.ui:ui-tooling-preview")
}
