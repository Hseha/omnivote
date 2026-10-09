import 'package:url_launcher/url_launcher.dart';

/// Opens a web URL in the system browser, silently ignoring failures.
///
/// Update links point at GitHub release pages students must open in a browser
/// to download the APK; there is no in-app installer. Failures (no browser,
/// blocked network) must never crash or flash an error — the update screen
/// still shows the link's text.
Future<void> launchExternalUrl(String url) async {
  final uri = Uri.tryParse(url);
  if (uri == null) return;
  try {
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  } catch (_) {
    // Silently unavailable (e.g. a device without a handler).
  }
}