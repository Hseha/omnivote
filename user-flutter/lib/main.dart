import 'package:flutter/foundation.dart' show kReleaseMode;
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

  // Release builds must state their API origin explicitly.
  //
  // `ApiConstants.baseUrl` falls back to the maintainer's Tailscale host when
  // `--dart-define=API_BASE_URL=...` is absent. An APK built that way builds
  // green and then fails for every student the moment that host is unreachable,
  // which is the worst possible time to discover it. In release we therefore
  // refuse to run rather than silently pointing at a developer machine.
  const configuredApiBaseUrl = String.fromEnvironment('API_BASE_URL');
  if (kReleaseMode && configuredApiBaseUrl.isEmpty) {
    runApp(const _MissingApiUrlErrorApp());
    return;
  }

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

/// Blocking screen shown when a release build was compiled without
/// `--dart-define=API_BASE_URL=...`. It never signs in and never reaches the
/// network — it exists so a mis-packaged APK fails loudly on the device that
/// runs it instead of appearing to work.
class _MissingApiUrlErrorApp extends StatelessWidget {
  const _MissingApiUrlErrorApp();

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      home: Scaffold(
        backgroundColor: const Color(0xFF0B1220),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: const [
                Icon(Icons.error_outline, color: Color(0xFFF87171), size: 48),
                SizedBox(height: 20),
                Text(
                  'This build is not configured',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Color(0xFFE5E7EB),
                    fontSize: 20,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                SizedBox(height: 12),
                Text(
                  'The app was built without an API server address, so it '
                  'cannot connect to the election service. Please install the '
                  'correct release build or contact your administrator.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Color(0xFF9CA3AF), fontSize: 14, height: 1.5),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
