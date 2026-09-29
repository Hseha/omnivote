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

  /// 16-pt secondary text: profile grade lines, slogans, emphasized
  /// descriptions. Larger than body but still secondary to titles.
  TextStyle get subtitle => TextStyle(
        fontSize: 16,
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

  // ---------------------------------------------------------------------------
  // Material 3 role aliases. These delegate to ThemeData.textTheme (assembled
  // in AppTheme) so screens can use canonical M3 roles directly. The legacy
  // getters above are kept so existing screens keep compiling unchanged; new
  // and redesigned code should prefer these roles.
  // ---------------------------------------------------------------------------

  /// Large hero numerals and display copy (countdowns, result percentages).
  TextStyle get displaySmall =>
      Theme.of(context).textTheme.displaySmall!;

  /// Screen titles inside app bars and large section headers.
  TextStyle get headlineSmall =>
      Theme.of(context).textTheme.headlineSmall!;

  /// Profile names and other 22-pt emphasized headings.
  TextStyle get headlineMedium =>
      Theme.of(context).textTheme.headlineMedium!;

  /// Card and dialog titles.
  TextStyle get titleMedium =>
      Theme.of(context).textTheme.titleMedium!;

  /// 28-pt screen headers (profile names, large section titles).
  TextStyle get titleLarge =>
      Theme.of(context).textTheme.titleLarge!;

  /// Emphasized 14-pt labels (counts, metadata, chip text when selected).
  TextStyle get titleSmall =>
      Theme.of(context).textTheme.titleSmall!;

  /// Default reading text.
  TextStyle get bodyMedium =>
      Theme.of(context).textTheme.bodyMedium!;

  /// Secondary 14-pt text.
  TextStyle get bodySmall => Theme.of(context).textTheme.bodySmall!;

  /// Captions, footnotes, helper copy (12-pt secondary).
  TextStyle get labelSmall =>
      Theme.of(context).textTheme.labelSmall!;

  /// Button labels and emphasized 14-pt primary text.
  TextStyle get labelLarge =>
      Theme.of(context).textTheme.labelLarge!;
}