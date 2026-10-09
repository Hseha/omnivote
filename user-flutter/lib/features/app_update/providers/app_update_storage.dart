import 'package:shared_preferences/shared_preferences.dart';

/// Remembers which release version already prompted the one-time "update
/// available" dialog, so a detected update nudges the student once per version
/// instead of on every launch.
class AppUpdateStorage {
  AppUpdateStorage._();

  static const String seenVersionKey = 'app_update_seen_version';

  static Future<String?> loadSeenVersion() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(seenVersionKey);
  }

  static Future<void> saveSeenVersion(String version) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(seenVersionKey, version);
  }
}