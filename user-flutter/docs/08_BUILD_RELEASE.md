# 08 — Build & Release (APK)

This app's primary distribution target is a **direct-install Android APK** for students (not necessarily Play Store).

## Local development

```bash
flutter pub get
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
```

`10.0.2.2` is how the Android emulator reaches `localhost` on the host machine running the Laravel dev server (`php artisan serve`). On a physical device on the same network, use the machine's LAN IP instead.

## Release APK

```bash
flutter build apk --release --dart-define=API_BASE_URL=https://api.yourdomain.com/api
```

Output: `build/app/outputs/flutter-apk/app-release.apk`

For smaller per-device downloads later, `flutter build apk --split-per-abi` can be used, but ship the universal APK first for simplicity while distributing directly to students.

## Suggested flavors

| Flavor | `API_BASE_URL` | Purpose |
|---|---|---|
| `dev` | local Laravel (`10.0.2.2:8000/api`) | day-to-day development |
| `staging` | staging Laravel URL | QA before an election window |
| `prod` | production Laravel URL | the APK actually handed to students |

Wire these through `--dart-define` (as above) rather than committing separate config files, so `lib/core/constants/api_constants.dart` stays a single source of truth reading from `String.fromEnvironment('API_BASE_URL')`.

## Pre-release checklist

- [ ] `electionStatusProvider` correctly disables all vote actions when phase isn't `voting_open`
- [ ] Auth token cleared and Login shown on any `401` from the API
- [ ] Ballot submission is idempotent — re-opening My Ballot after a successful submit never allows a second `POST /api/ballot/me/submit`
- [ ] App icon / name reflect "OmniVote" branding
- [ ] Tested against the actual Laravel staging URL, not just `10.0.2.2`
