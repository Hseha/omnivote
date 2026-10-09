import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/settings/providers/branding_provider.dart';
import '../constants/app_colors.dart';

/// Reads the school's runtime branding accent for presentation code.
///
/// The accent arrives over the network (`brandingProvider`), so widgets must
/// never bake in [AppColors.primaryBlue] directly — always read it through
/// here. Falls back to the default blue while branding is loading or when the
/// backend never configured one, which keeps every deployment rendering
/// sensibly.
extension BrandAccentRefX on WidgetRef {
  /// The configured branding accent, or the default blue when the backend
  /// never configured one (or is still loading).
  Color brandAccent() {
    return brandAccentOrNull() ?? AppColors.primaryBlue;
  }

  /// The school's configured branding accent, or null when branding is still
  /// loading or the backend never sent a primary color.
  ///
  /// Code that must fall back to a theme-pack accent uses this instead of
  /// [brandAccent] so a default (unconfigured) deployment renders the pack it
  /// was given rather than always reverting to the blue fallback.
  Color? brandAccentOrNull() {
    final branding = watch(
      brandingProvider.select((state) => state.valueOrNull),
    );
    return (branding != null && branding.primaryConfigured)
        ? branding.primaryColor
        : null;
  }
}
