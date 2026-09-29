# 08 — Build & Release (APK)

This app's primary distribution target is a **direct-install Android APK** for students (not necessarily Play Store).

## Local development

```bash
flutter pub get
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
```

`10.0.2.2` is how the Android emulator reaches `localhost` on the host machine running the Laravel dev server (`php artisan serve`). On a physical device on the same network, use the machine's LAN IP instead.

## Release APK

Always ship **per-ABI APKs, obfuscated, with the Dart symbols split out**:

```bash
cd user-flutter

# What students actually download: one APK per CPU architecture.
flutter build apk --release --split-per-abi \
  --obfuscate --split-debug-info=build/symbols \
  --dart-define=API_BASE_URL=https://api.yourdomain.com/api
```

`--obfuscate` shrinks the AOT code by stripping Dart symbol names and renames
private identifiers. Always keep `--split-debug-info=build/symbols` with it,
and archive `build/symbols/` with the release — without it, a release crash
stack trace is unreadable. (The deprecated `--strip` ELF flag is *not* used:
`--obfuscate` refuses to combine with it, so it cannot ship in a coherent
release pipeline.)

Output (`build/app/outputs/flutter-apk/`):

| Artifact | Ships to | Note |
|---|---|---|
| `app-arm64-v8a-release.apk` | **all current Android phones (send this one)** | **18.9 MB obfuscated** (19.9 MB plain) |
| `app-armeabi-v7a-release.apk` | very old 32-bit-only phones | 17.4 MB |
| `app-x86_64-release.apk` | emulators only — do not send | — |

Why per-ABI: a universal APK carries a copy of the Flutter engine **and** of the
app's AOT code for every architecture, so students on a phone paid ~3× for
binaries they never execute. Splitting is a pure win for a direct-install
distribution channel — the same APK simply does not install on the wrong device.

For a Play-Store (or any bundle-based) rollout build an AAB instead:

```bash
flutter build appbundle --release \
  --obfuscate --split-debug-info=build/symbols \
  --dart-define=API_BASE_URL=https://api.yourdomain.com/api
```

Google Play then slices per device automatically; keep shipping the split APKs
for the direct-share path (school group chats, printed QR codes, …). The AAB
is bulkier on disk than any single APK (~52 MB: it carries every native slice
before the store slices it), but student downloads from Play are per-device
like the split APKs.

### One-off helpers

```bash
# APK/AAB size breakdown by package, asset and native lib (this is the
# measurement step — always run it before claiming a size change worked):
flutter build apk --release --analyze-size --target-platform android-arm64

# Enforce the budget in CI / before a handout (see "Size budget" below):
./tool/release_size.sh build

# Symbol files for re-unobfuscating a release stack trace:
flutter symbolize -i stack.txt -d build/symbols/app.android-arm64.symbols
```

### Size budget

| Artifact | Budget | Enforced by |
|---|---|---|
| `app-release.apk` (`--target-platform android-arm64`, obfuscated) | **24 MB** | `user-flutter/tool/release_size.sh` (CI job `size`) |

19.0 MB at the time of writing (headroom for real growth, e.g. a new plugin).

The budget is intentionally one artifact, one number. If it fails:

1. `flutter build apk --release --analyze-size --target-platform android-arm64`
2. Open the report in DevTools and check what grew (a new asset? a new
   dependency? `libapp.so` vs `libflutter.so` vs `classes.dex`).
3. If the growth is deliberate, raise `APK_SIZE_BUDGET_BYTES` in
   `tool/release_size.sh` **and** the table above in the same commit.

### Symbol files

`--split-debug-info=build/symbols` writes the obfuscation map used to
de-obfuscate Dart stack traces. `build/` is git-ignored on purpose — archive the
symbols with the release (CI artifact, release tag asset, or the deploy host),
otherwise a release crash report is unreadable.

### Why no `ndk.abiFilters`

Deliberately absent. A release-scoped ABI filter shifts size control into the
Gradle DSL, where AGP refuses to coexist with `--split-per-abi` ("Conflicting
configuration … when splits abi filters are set"), and splits are the supported
way to hand students a small APK. Size is controlled with build *flags*:

* per-device distribution: `--split-per-abi` (students download one slice);
* one-file constraint: `--target-platform android-arm64` (~21 MB arm64) or
  `--target-platform android-arm,android-arm64` (~40 MB for both ARM ABIs —
  **only if you must** ship a single file).

## Suggested flavors

| Flavor | `API_BASE_URL` | Purpose |
|---|---|---|
| `dev` | local Laravel (`10.0.2.2:8000/api`) | day-to-day development |
| `staging` | staging Laravel URL | QA before an election window |
| `prod` | production Laravel URL | the APK actually handed to students |

Wire these through `--dart-define` (as above) rather than committing separate config files, so `lib/core/constants/api_constants.dart` stays a single source of truth reading from `String.fromEnvironment('API_BASE_URL')`.

## Performance check

Release numbers only mean something next to a measurement:

```bash
# Cold start, frame build/raster times, rebuild counts, network log:
flutter run --profile --dart-define=API_BASE_URL=https://staging…/api
```

Then attach DevTools and check, per screen (Dashboard → Vote Now → Candidates →
My Ballot → Results):

* **Rebuild counts** (Widget rebuild stats) while `electionStatusProvider` ticks
  every 30 s — a screen that only cares about the phase must not rebuild.
* **Frame chart** — scrolling the Candidates list and the Vote Now sheet should
  stay under 16 ms build + raster on a low-end device.
* **Network** — one `GET /election/status` per 30 s in the foreground, **zero**
  while backgrounded, no `GET /api/results` storm while `voting_closed`.

## Pre-release checklist

- [ ] `electionStatusProvider` correctly disables all vote actions when phase isn't `voting_open`
- [ ] Auth token cleared and Login shown on any `401` from the API
- [ ] Ballot submission is idempotent — re-opening My Ballot after a successful submit never allows a second `POST /api/ballot/me/submit`
- [ ] App icon / name reflect "OmniVote" branding
- [ ] Tested against the actual Laravel staging URL, not just `10.0.2.2`
- [ ] `flutter analyze` and `flutter test` are green
- [ ] `./tool/release_size.sh build` is within budget
- [ ] Smoke-tested the **obfuscated** release APK on a real phone: login,
      forced password change, draft → submit → receipt, receipt verification,
      results gate. Obfuscation and the tightened `proguard-rules.pro` are only
      proven by running the actual artifact.

