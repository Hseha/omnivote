import 'package:flutter/material.dart';
import '../constants/app_colors.dart';

/// Theme-scoped design tokens shared across every screen.
///
/// Light values mirror the repo's original constants (light appearance is
/// unchanged); dark values mirror the admin dashboard's `[data-theme='dark']`
/// tokens in `admin-react/src/index.css`.
class AppTokens extends ThemeExtension<AppTokens> {
  final Brightness brightness;
  final Color background;
  final Color surface;
  final Color surfaceAlt;
  final Color border;
  final Color borderStrong;
  final Color textPrimary;
  final Color textSecondary;
  final Color tagBg;
  final Color tagFg;

  const AppTokens({
    required this.brightness,
    required this.background,
    required this.surface,
    required this.surfaceAlt,
    required this.border,
    required this.borderStrong,
    required this.textPrimary,
    required this.textSecondary,
    required this.tagBg,
    required this.tagFg,
  });

  static const AppTokens light = AppTokens(
    brightness: Brightness.light,
    background: AppColors.backgroundGray,
    surface: AppColors.surfaceWhite,
    surfaceAlt: AppColors.surfaceWhite,
    border: AppColors.borderGray,
    borderStrong: Color(0xFFCBD5E1),
    textPrimary: AppColors.textPrimary,
    textSecondary: AppColors.textSecondary,
    tagBg: AppColors.tagBlueBg,
    tagFg: AppColors.tagBlueText,
  );

  /// Dark palette adapted from admin-react `:root[data-theme='dark']`.
  static const AppTokens dark = AppTokens(
    brightness: Brightness.dark,
    background: Color(0xFF0B0F19),
    surface: Color(0xFF131B2E),
    surfaceAlt: Color(0xFF0B0F19),
    border: Color(0xFF334155),
    borderStrong: Color(0xFF475569),
    textPrimary: Color(0xFFF8FAFC),
    textSecondary: Color(0xFF94A3B8),
    tagBg: Color(0xFF172554),
    tagFg: Color(0xFF60A5FA),
  );

  /// "Ocean" theme pack — calm teal tones on near-white (light).
  static const AppTokens oceanLight = AppTokens(
    brightness: Brightness.light,
    background: Color(0xFFF0FDFA),
    surface: Color(0xFFFFFFFF),
    surfaceAlt: Color(0xFFF0FDFA),
    border: Color(0xFFCCFBF1),
    borderStrong: Color(0xFF99F6E4),
    textPrimary: Color(0xFF134E4A),
    textSecondary: Color(0xFF5B7A74),
    tagBg: Color(0xFFCCFBF1),
    tagFg: Color(0xFF0D9488),
  );

  /// "Ocean" theme pack — deep sea-green dusk (dark).
  static const AppTokens oceanDark = AppTokens(
    brightness: Brightness.dark,
    background: Color(0xFF071412),
    surface: Color(0xFF0D241F),
    surfaceAlt: Color(0xFF071412),
    border: Color(0xFF1E3B33),
    borderStrong: Color(0xFF2E5145),
    textPrimary: Color(0xFFECFDF5),
    textSecondary: Color(0xFF86B5A8),
    tagBg: Color(0xFF0E3D33),
    tagFg: Color(0xFF2DD4BF),
  );

  /// "Sunset" theme pack — warm amber/orange (light).
  static const AppTokens sunsetLight = AppTokens(
    brightness: Brightness.light,
    background: Color(0xFFFFF7ED),
    surface: Color(0xFFFFFFFF),
    surfaceAlt: Color(0xFFFFF7ED),
    border: Color(0xFFFFE8D6),
    borderStrong: Color(0xFFFED7AA),
    textPrimary: Color(0xFF7C2D12),
    textSecondary: Color(0xFF8B6E58),
    tagBg: Color(0xFFFFE4CC),
    tagFg: Color(0xFFC2410C),
  );

  /// "Sunset" theme pack — warm bronze dusk (dark).
  static const AppTokens sunsetDark = AppTokens(
    brightness: Brightness.dark,
    background: Color(0xFF160E08),
    surface: Color(0xFF1F150D),
    surfaceAlt: Color(0xFF160E08),
    border: Color(0xFF382A1D),
    borderStrong: Color(0xFF4E3B28),
    textPrimary: Color(0xFFFFF7ED),
    textSecondary: Color(0xFFC4A891),
    tagBg: Color(0xFF3A2814),
    tagFg: Color(0xFFFB923C),
  );

  @override
  AppTokens copyWith({
    Brightness? brightness,
    Color? background,
    Color? surface,
    Color? surfaceAlt,
    Color? border,
    Color? borderStrong,
    Color? textPrimary,
    Color? textSecondary,
    Color? tagBg,
    Color? tagFg,
  }) {
    return AppTokens(
      brightness: brightness ?? this.brightness,
      background: background ?? this.background,
      surface: surface ?? this.surface,
      surfaceAlt: surfaceAlt ?? this.surfaceAlt,
      border: border ?? this.border,
      borderStrong: borderStrong ?? this.borderStrong,
      textPrimary: textPrimary ?? this.textPrimary,
      textSecondary: textSecondary ?? this.textSecondary,
      tagBg: tagBg ?? this.tagBg,
      tagFg: tagFg ?? this.tagFg,
    );
  }

  @override
  AppTokens lerp(AppTokens? other, double t) {
    if (other == null) return this;
    return AppTokens(
      brightness: t < 0.5 ? brightness : other.brightness,
      background: Color.lerp(background, other.background, t)!,
      surface: Color.lerp(surface, other.surface, t)!,
      surfaceAlt: Color.lerp(surfaceAlt, other.surfaceAlt, t)!,
      border: Color.lerp(border, other.border, t)!,
      borderStrong: Color.lerp(borderStrong, other.borderStrong, t)!,
      textPrimary: Color.lerp(textPrimary, other.textPrimary, t)!,
      textSecondary: Color.lerp(textSecondary, other.textSecondary, t)!,
      tagBg: Color.lerp(tagBg, other.tagBg, t)!,
      tagFg: Color.lerp(tagFg, other.tagFg, t)!,
    );
  }
}

/// Convenience accessors so screens read `context.appBackground` instead of
/// threading `Theme.of(context).extension<AppTokens>()` everywhere.
extension AppTokensContextX on BuildContext {
  ThemeData get appTheme => Theme.of(this);

  AppTokens get appTokens => appTheme.extension<AppTokens>() ?? AppTokens.light;

  Color get appBackground => appTokens.background;
  Color get appSurface => appTokens.surface;
  Color get appSurfaceAlt => appTokens.surfaceAlt;
  Color get appBorder => appTokens.border;
  Color get appBorderStrong => appTokens.borderStrong;
  Color get appTextPrimary => appTokens.textPrimary;
  Color get appTextSecondary => appTokens.textSecondary;
  Color get appTagBg => appTokens.tagBg;
  Color get appTagFg => appTokens.tagFg;
}