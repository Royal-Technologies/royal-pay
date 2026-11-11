# Add project specific ProGuard rules here.
-keepattributes *Annotation*
-keepclassmembers class * {
    @androidx.room.* *;
}
-keep class com.royaltechbd.royalpay.data.remote.dto.** { *; }
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn retrofit2.**
