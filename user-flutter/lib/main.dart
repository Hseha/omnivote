import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'data/services/api_config.dart';
import 'data/services/theme_mode.dart';

/// Standard Flutter entrypoint that delegates to the OmniVote app bootstrap.
/// The app's `main()` lives in [app.dart]; this file enables `flutter build`
/// /`flutter run` which expects a `lib/main.dart`.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Both values have to be known before the first frame: the base URL decides
  // where the splash's `GET /me` goes (getting that wrong would sign a student
  // out), and the theme decides how the very first frame looks. They are
  // independent reads though, so they run **together** instead of one after the
  // other — on a cold start that is one platform-channel round-trip of latency
  // saved rather than two.
  final (storedBaseUrl, storedThemeMode) = await (
    ApiConfigStorage.loadBaseUrl(),
    ThemeModeStorage.load(),
  ).wait;

  runApp(
    ProviderScope(
      overrides: [
        apiBaseUrlProvider.overrideWith((ref) => storedBaseUrl),
        themeModeProvider.overrideWith((ref) => storedThemeMode),
      ],
      child: const OmniVoteApp(),
    ),
  );
}
