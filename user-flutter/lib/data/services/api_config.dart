import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../core/constants/api_constants.dart';

/// Persisted, runtime-switchable API base URL.
///
/// Lets a single installed build talk to the local dev backend
/// (e.g. http://100.100.224.74:8000/api) during testing and to the deployed
/// backend later, without rebuilding or reinstalling. `null` means "use the
/// compile-time default" ([ApiConstants.baseUrl]).
class ApiConfigStorage {
  ApiConfigStorage._();

  static const String key = 'api_base_url';

  static Future<String?> loadBaseUrl() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(key);
  }

  static Future<void> saveBaseUrl(String value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(key, value);
  }

  static Future<void> clearBaseUrl() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(key);
  }
}

/// Current base URL override. `null` = compile-time default. Seeded from the
/// persisted value in `main()` and written whenever the user saves a new one.
final StateProvider<String?> apiBaseUrlProvider = StateProvider<String?>((ref) {
  return null;
});

String normalizeApiBaseUrl(String url) {
  var value = url.trim();
  while (value.endsWith('/')) {
    value = value.substring(0, value.length - 1);
  }
  return value.isEmpty ? ApiConstants.baseUrl : value;
}