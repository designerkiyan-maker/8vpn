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
