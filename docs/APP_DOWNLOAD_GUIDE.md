# OmniVote — Student App Download Guide

Each OmniVote release publishes **three** APK files. Only **one** of them is
right for your phone, and picking the wrong one is the #1 cause of the
"app not compatible"/"can't install" messages.

## Which file should I download?

| File | For which phone | Recommended? |
|---|---|---|
| `app-arm64-v8a-release.apk` | Most phones (2017 and newer, 64-bit) | **Yes — try this first** |
| `app-armeabi-v7a-release.apk` | Older phones, and phones whose Android runs in 32-bit mode (common on budget Vivo, Infinix, OPPO, Tecno units) | Fallback when the first won't install |
| `app-x86_64-release.apk` | **PC emulators only** (e.g. BlueStacks, LDPlayer, Android Studio emulator) | **Not for phones** |

## How to choose in 10 seconds

1. Tap the `arm64` file first.
2. If it installs — done, that's your file.
3. If it says **"not compatible with your phone"** — download the
   `armeabi-v7a` file instead. It installs on every phone.
4. Never download the `x86_64` file on a phone; it will always fail.

## I still get "app not installed" — what now?

If the *correct* file still won't install, the app is fine — the phone's
security settings are blocking installs from "unknown sources":

**Android 8+ (most phones):**
1. Settings → Apps / Applications → **Special app access** → **Install
   unknown apps**
2. Find the app you use to open the APK (File Manager, Chrome, Gmail) →
   toggle **Allow**.

**Vivo / iQOO (FunTouch OS):**
1. Open **i管家 (Phone Manager)** → Security Center → turn **off** "scan apps
   before install" (or tap **Continue/Allow** when it warns).
2. Settings → Apps → Special app access → Install unknown apps.

**Samsung:** Settings → Biometrics and security → **Install unknown apps**.

**Google Play Protect popup ("You can't install this app on your device"):**
- Tap **More details** → **Install anyway** if the button is shown.
- If there is no button, temporarily turn off scanning: Settings → Security →
  **Play Protect** → gear icon → off → install → turn back on.

**Everything failed — install from a computer (ADB):**
1. On the phone: Settings → About → tap Build number 7× (Developer Options),
   then enable **USB debugging**.
2. Connect the phone to a computer with `adb` installed and run:
   ```bash
   adb install app-arm64-v8a-release.apk   # or the v7a file
   ```

## Quick checks before retrying

- Delete the old/partly-downloaded APK and re-download; a corrupted file says
  "check the APK".
- If OmniVote was previously installed with a different signature, uninstall it
  first.
- Make sure the phone has enough free storage.

## I installed the wrong file — can I just install the right one?

If you already have OmniVote installed and it works, you're fine — no need to
change anything. If the install failed, just pick the right file and install.