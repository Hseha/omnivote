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
  Color brandAccent() {
    return watch(
          brandingProvider.select(
            (state) => state.valueOrNull?.primaryColor,
          ),
        ) ??
        AppColors.primaryBlue;
  }
}
