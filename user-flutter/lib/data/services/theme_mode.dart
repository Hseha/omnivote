import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Persisted app theme preference (Light / Dark / System).
///
/// Seeded in `main()` from shared preferences and updated whenever the user
/// picks a mode in the Settings screen, so the choice survives relaunches.
class ThemeModeStorage {
  ThemeModeStorage._();

  static const String key = 'theme_mode';

  /// Stored mode, or system default when nothing was ever saved.
  static Future<ThemeMode> load() async {
    final prefs = await SharedPreferences.getInstance();
    return _fromName(prefs.getString(key));
  }

  static Future<void> save(ThemeMode mode) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(key, mode.name);
  }

  static ThemeMode _fromName(String? name) {
    switch (name) {
      case 'light':
        return ThemeMode.light;
      case 'dark':
        return ThemeMode.dark;
      default:
        return ThemeMode.system;
    }
  }
}

/// Current theme mode; `main()` seeds it from [ThemeModeStorage.load].
final StateProvider<ThemeMode> themeModeProvider =
    StateProvider<ThemeMode>((ref) => ThemeMode.system);