import 'package:flutter/material.dart';
import '../constants/app_colors.dart';

/// Theme-scoped design tokens shared across every screen.
///
/// Light values mirror the repo's original constants (light appearance is
/// unchanged); dark values mirror the admin dashboard's `[data-theme='dark']`
/// tokens in `admin-react/src/index.css`.
class AppTokens extends ThemeExtension<AppTokens> {
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

  @override
  AppTokens copyWith({
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