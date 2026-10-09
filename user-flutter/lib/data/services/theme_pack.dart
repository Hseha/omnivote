import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../core/theme/theme_pack.dart';

/// Persisted theme-pack choice (a [ThemePack] palette id).
///
/// Seeded in `main()` from shared preferences and updated whenever the user
/// picks a pack in the Settings screen, so the choice survives relaunches.
/// The active pack composes with [ThemeMode]:
///   - `theme_mode` decides light vs dark rendering,
///   - `theme_pack` decides which palette is rendered.
class ThemePackStorage {
  ThemePackStorage._();

  static const String key = 'theme_pack';

  /// Stored pack, or [ThemePack.classic] when nothing was ever saved.
  static Future<ThemePack> load() async {
    final prefs = await SharedPreferences.getInstance();
    return ThemePack.fromId(prefs.getString(key));
  }

  static Future<void> save(ThemePack pack) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(key, pack.id);
  }
}

/// Currently selected theme pack; `main()` seeds it from
/// [ThemePackStorage.load].
final StateProvider<ThemePack> themePackProvider =
    StateProvider<ThemePack>((ref) => ThemePack.classic);