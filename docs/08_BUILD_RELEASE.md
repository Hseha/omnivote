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
and archive `build/symbols/` privately with the release — without it, a release crash
stack trace is unreadable. Never attach plaintext symbols to a public GitHub
artifact or release. (The deprecated `--strip` ELF flag is *not* used:
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

### Signing (before anyone installs it)

`android/app/build.gradle.kts` signs a release **only** when
`android/keystore.properties` exists (template:
`android/keystore.properties.example`; the real file is git-ignored). Without
it the build still succeeds but produces an **unsigned** APK, which Android
refuses to install:
`INSTALL_PARSE_FAILED_NO_CERTIFICATES: Failed to collect certificates`. That is
deliberate (audit 2026-09-13 §4 #1: a debug-signed artifact must never be
handed out as a release), but it does mean the command above is not a handout
until the keystore is in place. Verify what you are about to distribute:

```bash
~/Android/Sdk/build-tools/<version>/apksigner verify --print-certs \
  build/app/outputs/flutter-apk/app-release.apk
```

### GitHub Actions test build

The manually triggered `Student app test build` workflow builds and signs
the three split APKs without running the server deployment workflow. It runs
only from `main`, targets `PRODUCTION_API_BASE_URL` from the GitHub
`production` environment, runs analyze/tests, and uploads APKs as a temporary
Actions artifact for device testing.

Configure these values in **Settings → Environments → production**:

| Name | Type | Value |
|---|---|---|
| `PRODUCTION_API_BASE_URL` | variable | Confirmed public API URL, ending in `/api` |
| `ANDROID_RELEASE_KEYSTORE_BASE64` | secret | Base64-encoded release keystore file |
| `ANDROID_RELEASE_STORE_PASSWORD` | secret | Keystore password |
| `ANDROID_RELEASE_KEY_ALIAS` | secret | Signing key alias |
| `ANDROID_RELEASE_KEY_PASSWORD` | secret | Signing key password |
| `RELEASE_SYMBOLS_ARCHIVE_PASSPHRASE` | secret | Strong, unique passphrase for encrypting the symbol archive |

The workflow encrypts `build/symbols/` before uploading it as a separate
artifact. Keep the archive passphrase in a password manager; never publish the
decryption passphrase or plaintext symbol files. Download and retain the
encrypted archive privately before its 90-day Actions-artifact retention
expires. The test APK artifact is temporary and is not a GitHub Release.
Download `app-arm64-v8a-release.apk` from the workflow run for most current
Android phones, then test it on mobile data (with Wi-Fi/Tailscale disconnected)
against the production API. Only publish a student-facing release after that
smoke test passes.

To publish an installable device-test candidate before smoke testing, run
**Publish student app release** on `main`, provide a successful test-build run
ID, leave the smoke-test confirmation false, and choose the `test` channel. This
creates a clearly marked GitHub prerelease with a unique `-test.<run>` tag.
After the device smoke test passes, run it again with the tested run ID, confirm
the smoke test, and choose `stable` to publish the semantic version tag.

The publisher checks the source run, downloads its APK artifact, verifies all
three signatures, derives the version from that run's `pubspec.yaml`, builds
app-only Added/Fixed/Changed notes from commits since `archive/user-flutter`,
refuses an existing tag, and creates the GitHub Release with APKs only. It does
not upload symbols or signing materials.

### In-app update notice

Students learn about a new build from the app itself: on launch the app reads
the newest GitHub Release (`api.github.com/.../releases`) and, when its
`X.Y.Z` is newer than the installed version, shows a one-time dialog and a
dashboard banner with the release notes and a download link (stable `vX.Y.Z`
is preferred over a `vX.Y.Z-test.N` prerelease of the same version). The check
is cached for 30 minutes and fails silent offline. Publishing a Release is the
only trigger — no extra workflow step is needed.

### Public API Funnel isolation

The public Funnel target must use the API-only listener in
`deploy/nginx-funnel-api.conf`, not the main Nginx site (which also serves
`/admin/`). On the server, install the reviewed config and reload Nginx:

```bash
sudo install -m 644 deploy/nginx-funnel-api.conf \
  /etc/nginx/sites-available/omnivote-funnel-api
sudo nginx -t && sudo systemctl reload nginx
curl -sS -o /dev/null -w 'Student API HTTP %{http_code}\n' \
  -H 'X-Forwarded-Proto: https' \
  http://127.0.0.1:8080/api/election/status
curl -sS -o /dev/null -w 'Admin API HTTP %{http_code}\n' \
  http://127.0.0.1:8080/api/admin/login
curl -sS -o /dev/null -w 'Admin panel HTTP %{http_code}\n' \
  http://127.0.0.1:8080/admin/
```

Expected: student API returns 200, and both admin paths return 404. The
`tailscale funnel status` target should remain `http://127.0.0.1:8080`.

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
de-obfuscate Dart stack traces. `build/` is git-ignored on purpose. Keep the
symbols encrypted and archive them privately outside the repository; never
attach plaintext symbols to a public artifact or release, or a release crash
report will be unreadable and the obfuscation will be weakened.

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

Baselines measured on an obfuscated release build (x86_64 API 36 emulator, idle
on the login screen): **~68 MB PSS**; artifacts **18.1 MB** (`--target-platform
android-arm64`) and **19.0 MB** (`--split-per-abi` arm64). Reproduce them on a
real handset before treating a change as a win — emulators flatter nobody.

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
