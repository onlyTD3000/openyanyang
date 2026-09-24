# OkHttp / Okio
-dontwarn okhttp3.**
-dontwarn okio.**
# Kotlin 协程
-dontwarn kotlinx.coroutines.**
# Tink（EncryptedSharedPreferences 依赖）只在编译期用到的注解，运行时不需要
-dontwarn com.google.errorprone.annotations.**
-dontwarn com.google.crypto.tink.**
-keep class com.google.crypto.tink.** { *; }
# kotlinx.serialization 的序列化器靠反射查找，不能混淆
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keepclassmembers class cn.bot77.yanyang.** {
    *** Companion;
}
-keepclasseswithmembers class cn.bot77.yanyang.** {
    kotlinx.serialization.KSerializer serializer(...);
}
