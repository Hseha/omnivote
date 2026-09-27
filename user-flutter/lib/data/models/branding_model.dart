import 'package:flutter/material.dart';
import '../../core/constants/app_colors.dart';

/// Public branding served by GET /api/branding (SettingsController::branding).
///
/// Only the non-sensitive branding section is exposed, so this model is safe
/// to hydrate before a user signs in. Every field falls back to the app's own
/// defaults so an unconfigured or unreachable backend never breaks the chrome.
class Branding {
  final String siteName;
  final String logoUrl;
  final Color primaryColor;
  final Color secondaryColor;
  final String headerText;
  final String footerText;

  const Branding({
    this.siteName = 'OmniVote',
    this.logoUrl = '',
    this.primaryColor = AppColors.primaryBlue,
    this.secondaryColor = AppColors.textSecondary,
    this.headerText = '',
    this.footerText = '',
  });

  factory Branding.fromJson(Map<String, dynamic> json) {
    return Branding(
      siteName: _string(json['siteName']) ?? 'OmniVote',
      logoUrl: _string(json['logoUrl']) ?? '',
      primaryColor: _color(json['primaryColor']) ?? AppColors.primaryBlue,
      secondaryColor: _color(json['secondaryColor']) != null
          ? _color(json['secondaryColor'])!
          : AppColors.textSecondary,
      headerText: _string(json['headerText']) ?? '',
      footerText: _string(json['footerText']) ?? '',
    );
  }

  static String? _string(Object? value) {
    if (value is String && value.trim().isNotEmpty) return value.trim();
    return null;
  }

  /// "Safe" fallback is a [Branding] with defaults; used when the endpoint is
  /// unreachable or a parse error slips through.
  static Branding fallback() => const Branding();

  static Color? _color(Object? value) {
    final raw = _string(value);
    if (raw == null) return null;

    var hex = raw.replaceFirst('#', '');
    if (hex.length == 6) hex = 'FF$hex';
    if (hex.length != 8) return null;

    final parsed = int.tryParse(hex, radix: 16);
    if (parsed == null) return null;

    return Color(parsed);
  }
}