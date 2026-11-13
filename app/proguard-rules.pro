# hev-socks5-tunnel registers its natives against this exact class (JNI RegisterNatives).
-keep class hev.htproxy.TProxyService { *; }
-keepclasseswithmembernames class * { native <methods>; }

# tun2socks registers its natives by name (Java_ir_mrkiyan_eligius_Tun2Socks_*).
-keep class ir.mrkiyan.eligius.Tun2Socks { *; }

# sing-box (libbox, built with gomobile): Go <-> Java bindings are looked up by name.
-keep class go.** { *; }
-keep class io.nekohasekai.** { *; }
-dontwarn go.**
-dontwarn io.nekohasekai.**

# --- Eligius subscription stack (docs 06 §8) ---
-keep class com.squareup.moshi.** { *; }
-keep class com.squareup.retrofit2.** { *; }
-keep interface retrofit2.** { *; }
-keep class com.squareup.okhttp3.** { *; }
-keep class okhttp3.** { *; }
-keep class okio.** { *; }
-keep class androidx.security.crypto.** { *; }
-keep class org.bouncycastle.** { *; }
-keep class ir.mrkiyan.eligius.api.model.** { *; }
-keep class ir.mrkiyan.eligius.api.** { *; }
