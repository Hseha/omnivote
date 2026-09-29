import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// --- Release signing -------------------------------------------------------
// Read credentials from android/keystore.properties (never committed — see
// keystore.properties.example). The release build is signed with the real
// keystore ONLY when that file exists; otherwise it is intentionally left
// unsigned so debug-signed artifacts can never ship as a release (audit
// 2026-09-13 §4 #1 / fix order #2).
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("keystore.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(keystorePropertiesFile.inputStream())
}

android {
    namespace = "com.hseha.omnivote"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // Reverse-domain application id for the org (com.<org>.<app>).
        applicationId = "com.hseha.omnivote"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (keystorePropertiesFile.exists()) {
            create("release") {
                storeFile = file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            // Provided by android/keystore.properties when present; unsigned
            // otherwise (no more debug-keystore fallback).
            if (keystorePropertiesFile.exists()) {
                signingConfig = signingConfigs.getByName("release")
            }
            // Release hardening (audit §4 #3): minify + resource shrinking.
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
            // NOTE: no `ndk.abiFilters` on purpose. A release-scoped ABI filter
            // conflicts with `flutter build apk --split-per-abi` (AGP refuses
            // "Conflicting configuration ... when splits abi filters are set"),
            // and splits are the supported way to hand students a small APK.
        }
    }

    packaging {
        resources {
            // Metadata that is never read at runtime (dependency version
            // markers, Kotlin module descriptors and debug probes).
            excludes += setOf(
                "META-INF/*.kotlin_module",
                "META-INF/*.version",
                "META-INF/DEPENDENCIES",
                "kotlin/**",
                "DebugProbesKt.bin",
            )
        }
        jniLibs {
            // Keep native libraries uncompressed inside the APK and load them
            // straight from it (no extract-on-install copy). This is the AGP
            // default for minSdk >= 23 and is stated here explicitly so a future
            // change cannot silently reintroduce the legacy packaging.
            useLegacyPackaging = false
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
