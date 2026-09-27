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
  final storedBaseUrl = await ApiConfigStorage.loadBaseUrl();
  final storedThemeMode = await ThemeModeStorage.load();
  runApp(ProviderScope(
    overrides: [
      apiBaseUrlProvider.overrideWith((ref) => storedBaseUrl),
      themeModeProvider.overrideWith((ref) => storedThemeMode),
    ],
    child: const OmniVoteApp(),
  ));
}