import 'package:flutter/material.dart';

import '../theme/app_shape.dart';
import '../theme/app_spacing.dart';
import '../theme/app_tokens.dart';

/// The single card primitive. Flat surface + 1-px token border + 12-pt radius
/// ([AppRadius.md]), zero elevation — the established OmniVote card look, now
/// in one place so every list row, tile and section card matches.
///
/// Pass [onTap] for tappable cards: it wraps content in an [InkWell] with a
/// matching border radius so every tappable surface gets a ripple (never a
/// ripple-less `GestureDetector`). [padding] defaults to the card metric (20).
class AppCard extends StatelessWidget {
  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry padding;
  final double borderRadius;
  final Color? color;

  /// Overrides the default token border — e.g. a semantic color for status
  /// banners. Defaults to the theme border.
  final Color? borderColor;

  const AppCard({
    super.key,
    required this.child,
    this.onTap,
    this.padding = const EdgeInsets.all(AppMetrics.cardPadding),
    this.borderRadius = AppRadius.md,
    this.color,
    this.borderColor,
  });

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(borderRadius);
    final body = Padding(padding: padding, child: child);
    final side =
        BorderSide(color: borderColor ?? context.appBorder);
    final shape = RoundedRectangleBorder(
      borderRadius: radius,
      side: side,
    );

    if (onTap == null) {
      return Card(
        elevation: AppElevation.card,
        margin: EdgeInsets.zero,
        color: color ?? context.appSurface,
        shape: shape,
        child: body,
      );
    }
    return Card(
      elevation: AppElevation.card,
      margin: EdgeInsets.zero,
      color: color ?? context.appSurface,
      shape: shape,
      child: InkWell(
        borderRadius: radius,
        onTap: onTap,
        child: body,
      ),
    );
  }
}

/// Section spacing helper: the standard 24-pt gap between card sections,
/// replacing bare `SizedBox(height: 24)` between sections.
class AppSectionGap extends StatelessWidget {
  const AppSectionGap({super.key});

  @override
  Widget build(BuildContext context) => AppSpacing.vLg;
}
