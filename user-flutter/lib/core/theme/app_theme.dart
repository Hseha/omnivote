import 'package:flutter/material.dart';
import '../constants/app_colors.dart';
import 'app_shape.dart';
import 'app_tokens.dart';

/// Central application theme. [lightTheme] is the original OmniVote look;
/// [darkTheme] mirrors the admin dashboard's `[data-theme='dark']` palette.
class AppTheme {
  /// Font/weight shapes shared by both themes (no colors — those come from
  /// [AppTokens]).
  static const TextStyle _pageTitle = TextStyle(
    fontSize: 24,
    fontWeight: FontWeight.bold,
  );
  static const TextStyle _cardTitle = TextStyle(
    fontSize: 16,
    fontWeight: FontWeight.bold,
  );
  static const TextStyle _body = TextStyle(fontSize: 14);
  static const TextStyle _secondary = TextStyle(fontSize: 14);

  static TextStyle _pageTitleFor(AppTokens t) =>
      _pageTitle.copyWith(color: t.textPrimary);

  static TextStyle _secondaryFor(AppTokens t) =>
      _secondary.copyWith(color: t.textSecondary);

  static CardThemeData _cardThemeFor(AppTokens t) => CardThemeData(
        color: t.surface,
        elevation: AppElevation.card,
        shape: RoundedRectangleBorder(
          borderRadius: AppRadius.mdAll,
          side: BorderSide(color: t.border),
        ),
      );

  static AppBarTheme _appBarThemeFor(AppTokens t) => AppBarTheme(
        backgroundColor: t.surface,
        surfaceTintColor: t.surface,
        elevation: 0,
        centerTitle: false,
        iconTheme: IconThemeData(color: t.textPrimary),
        titleTextStyle: _pageTitleFor(t),
      );

  static ElevatedButtonThemeData _elevatedButtonTheme([Color? background]) =>
      ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: background ?? AppColors.primaryBlue,
          foregroundColor: Colors.white,
          minimumSize: const Size(double.infinity, 48),
          shape: RoundedRectangleBorder(
            borderRadius: AppRadius.smAll,
          ),
          textStyle: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w600,
          ),
        ),
      );

  static InputDecorationTheme _inputDecorationThemeFor(AppTokens t,
          [Color? focus]) =>
      InputDecorationTheme(
        filled: true,
        fillColor: t.surface,
        border: OutlineInputBorder(
          borderRadius: AppRadius.smAll,
          borderSide: BorderSide(color: t.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: AppRadius.smAll,
          borderSide: BorderSide(color: t.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: AppRadius.smAll,
          borderSide: BorderSide(color: focus ?? AppColors.primaryBlue, width: 2),
        ),
        labelStyle: _secondaryFor(t),
      );

  static ThemeData _build(AppTokens tokens) {
    return ThemeData(
      useMaterial3: true,
      brightness: tokens == AppTokens.dark
          ? Brightness.dark
          : Brightness.light,
      scaffoldBackgroundColor: tokens.background,
      colorScheme: tokens == AppTokens.dark
          ? const ColorScheme.dark(
              primary: AppColors.primaryBlue,
              secondary: AppColors.primaryBlueDark,
              surface: Color(0xFF131B2E),
              error: AppColors.errorRed,
            )
          : ColorScheme.fromSeed(
              seedColor: AppColors.primaryBlue,
              primary: AppColors.primaryBlue,
              secondary: AppColors.primaryBlueDark,
              surface: AppColors.surfaceWhite,
              error: AppColors.errorRed,
            ),
      extensions: [tokens],
      appBarTheme: _appBarThemeFor(tokens),
      cardTheme: _cardThemeFor(tokens),
      elevatedButtonTheme: _elevatedButtonTheme(),
      inputDecorationTheme: _inputDecorationThemeFor(tokens),
      dividerColor: tokens.border,
      textTheme: TextTheme(
        // Display: hero numerals (countdowns, result percentages).
        displaySmall: _pageTitleFor(tokens).copyWith(fontSize: 32),
        // Headings: 28-pt screen headers, 24-pt section titles, 22-pt names.
        titleLarge: _pageTitleFor(tokens).copyWith(fontSize: 28),
        headlineSmall: _pageTitleFor(tokens),
        headlineMedium: _pageTitleFor(tokens).copyWith(fontSize: 22),
        // Titles: 16-pt card/dialog titles, 14-pt emphasized labels.
        titleMedium: _cardTitleFor(tokens),
        titleSmall: _cardTitleFor(tokens).copyWith(fontSize: 14),
        // Body: 14-pt primary reading text, 14-pt secondary text.
        bodyMedium: _body.copyWith(color: tokens.textPrimary),
        bodySmall: _secondaryFor(tokens),
        // Labels: 14-pt button/emphasis text, 12-pt captions and helper copy.
        labelLarge: _secondary.copyWith(
          fontWeight: FontWeight.w600,
          color: tokens.textPrimary,
        ),
        labelSmall: _secondaryFor(tokens).copyWith(fontSize: 12),
      ),
    );
  }

  static TextStyle _cardTitleFor(AppTokens t) =>
      _cardTitle.copyWith(color: t.textPrimary);

  static ThemeData get lightTheme => _build(AppTokens.light);

  static ThemeData get darkTheme => _build(AppTokens.dark);

  /// Returns [base] with the school's runtime branding accent flowing into
  /// every role that defaults to the fallback blue: [ColorScheme.primary],
  /// the elevated-button background, and the input focus border. Roles that
  /// already read the accent explicitly (chips, tags, hero gradients) are
  /// untouched.
  ///
  /// With the default/unconfigured branding the accent *is*
  /// [AppColors.primaryBlue], so the result is pixel-identical to [base] —
  /// existing screens (including ones owned by other workstreams) render
  /// exactly as before until a school configures its own color.
  static ThemeData withAccent(ThemeData base, Color accent) {
    final tokens = base.extension<AppTokens>();
    return base.copyWith(
      colorScheme: base.colorScheme.copyWith(primary: accent),
      elevatedButtonTheme: _elevatedButtonTheme(accent),
      inputDecorationTheme: tokens == null
          ? base.inputDecorationTheme
          : _inputDecorationThemeFor(tokens, accent),
    );
  }
}