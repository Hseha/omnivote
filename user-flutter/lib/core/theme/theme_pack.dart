import 'package:flutter/material.dart';

import '../constants/app_colors.dart';
import 'app_tokens.dart';

/// Selectable color theme packs for the whole app.
///
/// A pack is a named palette with its own light + dark [AppTokens] and accent
/// colors. It composes with the existing ThemeMode light/system/dark switch:
/// the mode decides whether the light or dark variant renders, the pack decides
/// which palette is used in the first place. Persist the pack id through
/// `ThemePackStorage` (see `data/services/theme_pack.dart`).
///
/// School branding wins over the pack accent whenever the backend has actually
/// configured a primary color (see `Branding.primaryConfigured`); otherwise the
/// pack's own accent drives buttons, toggles and highlights.
enum ThemePack {
  classic(
    id: 'classic',
    label: 'Classic',
    description: 'OmniVote’s signature blue on slate.',
    primary: AppColors.primaryBlue,
    secondary: AppColors.primaryBlueDark,
    light: AppTokens.light,
    dark: AppTokens.dark,
  ),
  ocean(
    id: 'ocean',
    label: 'Ocean',
    description: 'Calm teal tones, light and dark.',
    primary: Color(0xFF14B8A6),
    secondary: Color(0xFF0D9488),
    light: AppTokens.oceanLight,
    dark: AppTokens.oceanDark,
  ),
  sunset(
    id: 'sunset',
    label: 'Sunset',
    description: 'Warm amber and bronze, light and dark.',
    primary: Color(0xFFF97316),
    secondary: Color(0xFFEA580C),
    light: AppTokens.sunsetLight,
    dark: AppTokens.sunsetDark,
  );

  const ThemePack({
    required this.id,
    required this.label,
    required this.description,
    required this.primary,
    required this.secondary,
    required this.light,
    required this.dark,
  });

  /// Stable persisted identifier (never rely on enum index or name).
  final String id;
  final String label;
  final String description;
  final Color primary;
  final Color secondary;
  final AppTokens light;
  final AppTokens dark;

  /// Resolves a stored [id]; unknown/empty falls back to [ThemePack.classic].
  static ThemePack fromId(String? id) {
    return values.firstWhere(
      (pack) => pack.id == id,
      orElse: () => ThemePack.classic,
    );
  }
}