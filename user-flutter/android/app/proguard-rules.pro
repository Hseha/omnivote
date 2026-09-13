# ProGuard/R8 rules for the OmniVote release build.
#
# Dart code is compiled AOT into libapp.so and needs no rules, but the Flutter
# engine bindings and the platform plugins (Dio, flutter_secure_storage,
# cached_network_image, path_provider, ...) are plain Java/Kotlin and must not
# be stripped or renamed.

## Flutter wrapper + engine
-keep class io.flutter.app.** { *; }
-keep class io.flutter.plugin.** { *; }
-keep class io.flutter.util.** { *; }
-keep class io.flutter.view.** { *; }
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }

## Google Play Core is optional (Flutter's deferred-component support). R8
## fails on the missing classes unless they are flagged as ok-to-miss.
-dontwarn com.google.android.play.core.splitcompat.**
-dontwarn com.google.android.play.core.splitinstall.**
-dontwarn com.google.android.play.core.tasks.**

## Third-party JSON used by plugins.
-keep class com.google.gson.** { *; }
-keep class androidx.core.** { *; }