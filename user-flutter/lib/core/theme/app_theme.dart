import 'package:flutter/material.dart';
import '../constants/app_colors.dart';
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
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
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

  static ElevatedButtonThemeData _elevatedButtonTheme() =>
      ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primaryBlue,
          foregroundColor: Colors.white,
          minimumSize: const Size(double.infinity, 48),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(8),
          ),
          textStyle: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w600,
          ),
        ),
      );

  static InputDecorationTheme _inputDecorationThemeFor(AppTokens t) =>
      InputDecorationTheme(
        filled: true,
        fillColor: t.surface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(8),
          borderSide: BorderSide(color: t.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(8),
          borderSide: BorderSide(color: t.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(8),
          borderSide: const BorderSide(color: AppColors.primaryBlue, width: 2),
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
        titleLarge: _pageTitleFor(tokens).copyWith(fontSize: 28),
        headlineSmall: _pageTitleFor(tokens),
        titleMedium: _cardTitleFor(tokens),
        bodyMedium: _body.copyWith(color: tokens.textPrimary),
        bodySmall: _secondaryFor(tokens),
        labelLarge: _secondary.copyWith(
          fontWeight: FontWeight.w600,
          color: tokens.textPrimary,
        ),
      ),
    );
  }

  static TextStyle _cardTitleFor(AppTokens t) =>
      _cardTitle.copyWith(color: t.textPrimary);

  static ThemeData get lightTheme => _build(AppTokens.light);

  static ThemeData get darkTheme => _build(AppTokens.dark);
}