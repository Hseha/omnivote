# OmniVote Launch Screen Assets (iOS)

Branded launch images for the OmniVote student app (`user-flutter`, `omnivote`
`1.1.0+2`). Keep them in sync with `docs/07_DESIGN_SYSTEM.md`
(`primaryBlue #2F5EFF–#3B6EF6`, `navyDark #0F172A`) so the native splash
matches the Flutter theme in `lib/core/theme/`.

- Replace `LaunchImage.png` / `@2x` / `@3x` in this directory, keeping the
  filenames in `Contents.json` unchanged (referenced by
  `Base.lproj/LaunchScreen.storyboard`).
- Or open `ios/Runner.xcworkspace` in Xcode → `Runner/Assets.xcassets` and
  drop in the images.
- Android launch drawables live separately under `android/app/` — update both
  platforms together so splash branding matches.
- Do not commit generated build output; source images only.