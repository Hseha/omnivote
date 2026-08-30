import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'app.dart';

/// Standard Flutter entrypoint that delegates to the OmniVote app bootstrap.
/// The app's `main()` lives in [app.dart]; this file enables `flutter build`
/// /`flutter run` which expects a `lib/main.dart`.
void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const ProviderScope(child: OmniVoteApp()));
}
