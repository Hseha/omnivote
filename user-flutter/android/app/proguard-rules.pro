# ProGuard/R8 rules for the OmniVote release build.
#
# This file is *additive*: Flutter's Gradle plugin already registers
# `flutter_proguard_rules.pro` (see `FlutterPlugin.kt`), which silences the
# `io.flutter.plugin.**` / `android.**` library warnings and keeps every
# `io.flutter.embedding.engine.plugins.FlutterPlugin` implementation — the class
# that plugin registration actually looks up. A stock Flutter app ships an empty
# file like this one for exactly that reason.
#
# The blanket keeps that used to live here
#   -keep class io.flutter.** { *; }
#   -keep class com.google.gson.** { *; }
#   -keep class androidx.core.** { *; }
# told R8 it may not shrink or rename the Flutter embedding, all of
# androidx.core, and gson — none of which is reached reflectively. Dropping them
# is what lets R8 shrink `classes.dex`.

## Google Play Core is optional (Flutter's deferred-component support). R8
## fails on the missing classes unless they are flagged as ok-to-miss.
-dontwarn com.google.android.play.core.splitcompat.**
-dontwarn com.google.android.play.core.splitinstall.**
-dontwarn com.google.android.play.core.tasks.**
