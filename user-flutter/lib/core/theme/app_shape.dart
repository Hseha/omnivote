import 'package:flutter/material.dart';

import 'app_spacing.dart';

/// Single corner-radius scale + elevation policy.
///
/// Radii: sm/md/lg/xl = 8/12/16/24.
/// - 8  — fields, chips, small pills, button shapes.
/// - 12 — list cards, ballot rows, section cards.
/// - 16 — hero cards, dialogs, bottom sheets.
/// - 24 — reserved for large feature surfaces (splash logo tile, …).
///
/// Elevation policy (both themes): cards sit flat (`elevation 0`) on a
/// 1-px token border — the established OmniVote look, kept deliberately so
/// dark mode needs no shadow tuning. Real elevation is reserved for floating
/// layers: dialogs, bottom sheets and menus, which use [AppElevation.raised].
abstract final class AppRadius {
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 24;

  static BorderRadius get smAll => BorderRadius.circular(sm);
  static BorderRadius get mdAll => BorderRadius.circular(md);
  static BorderRadius get lgAll => BorderRadius.circular(lg);
  static BorderRadius get xlAll => BorderRadius.circular(xl);
}

/// Elevation tokens. [card] is the flat bordered look used by every in-flow
/// card; [raised] is for floating layers (dialogs, sheets, menus, snack bars).
abstract final class AppElevation {
  static const double card = 0;
  static const double raised = 3;
}

/// Component-internal metrics that are deliberately *not* part of the
/// [AppSpacing] rhythm: they describe a component's own anatomy, so they live
/// with the system rather than as literals inside screens.
abstract final class AppMetrics {
  /// Default in-card padding (cards read denser than screen gutters).
  static const double cardPadding = 20;

  /// Compact row padding for list rows and tiles.
  static const double rowPaddingV = 12;
  static const double rowPaddingH = 14;

  /// Minimum tap target edge (WCAG 2.5.8 / Apple HIG 44 pt).
  static const double minTapTarget = 44;

  /// Standard progress-bar thickness (ballot progress, result bars).
  static const double barThickness = 8;

  /// Progress-bar corner radius: half the thickness, i.e. fully rounded bars.
  static const double barRadius = barThickness / 2;

  /// Avatar radii used across the app (list row / tile / profile hero).
  static const double avatarSm = 18;
  static const double avatarMd = 22;
  static const double avatarLg = 44;
  static const double avatarXl = 64;

  /// Tag/pill padding ("Latest", position tags, seat-count pills).
  static const EdgeInsets tagPadding = EdgeInsets.symmetric(
    horizontal: AppSpacing.sm,
    vertical: 2,
  );
}
