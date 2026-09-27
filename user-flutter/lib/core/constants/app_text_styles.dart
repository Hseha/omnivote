import 'package:flutter/material.dart';
import '../theme/app_tokens.dart';

/// Primary text styles, resolved from the active theme so dark mode keeps
/// reading contrast automatically.
class AppTextStyles {
  const AppTextStyles._(this.context);

  final BuildContext context;

  /// Text styles derived from the current theme.
  factory AppTextStyles.of(BuildContext context) => AppTextStyles._(context);

  TextStyle get pageTitle => TextStyle(
        fontSize: 24,
        fontWeight: FontWeight.bold,
        color: context.appTextPrimary,
      );

  TextStyle get cardTitle => TextStyle(
        fontSize: 16,
        fontWeight: FontWeight.bold,
        color: context.appTextPrimary,
      );

  TextStyle get body => TextStyle(
        fontSize: 14,
        color: context.appTextPrimary,
      );

  TextStyle get secondary => TextStyle(
        fontSize: 14,
        color: context.appTextSecondary,
      );

  TextStyle get slogan => TextStyle(
        fontSize: 14,
        fontStyle: FontStyle.italic,
        color: context.appTextSecondary,
      );

  TextStyle get tag => TextStyle(
        fontSize: 11,
        fontWeight: FontWeight.bold,
        letterSpacing: 1.2,
        color: context.appTagFg,
      );
}