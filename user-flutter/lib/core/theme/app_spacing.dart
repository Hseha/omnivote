import 'package:flutter/material.dart';

/// Single spacing scale for the whole app.
///
/// Steps follow an 8-pt rhythm (with 4-pt halves for tight inline gaps):
/// xs/sm/md/lg/xl = 4/8/16/24/32. Anything layout-related — screen padding,
/// gaps between sections, sliver insets — must come from here, never from a
/// bare literal like `SizedBox(height: 13)`.
///
/// Component-internal micro-values that are not part of the rhythm (card
/// padding 20, chip row gap 6, avatar ring widths, …) live next to the
/// component or in [AppShape]-adjacent tokens — not as one-off literals in
/// screens. The rule is: no raw doubles in widgets, every number names its
/// intent by coming from a token.
abstract final class AppSpacing {
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;

  /// Standard screen edge padding (16) and section separation (24).
  static const EdgeInsets screenPadding = EdgeInsets.all(md);
  static const EdgeInsets screenPaddingHorizontal =
      EdgeInsets.symmetric(horizontal: md);

  /// Gaps, as widgets, so screens read `AppSpacing.gapMd` instead of
  /// `const SizedBox(height: 16)`.
  static const Widget gapXs = SizedBox(height: xs, width: xs);
  static const Widget gapSm = SizedBox(height: sm, width: sm);
  static const Widget gapMd = SizedBox(height: md, width: md);
  static const Widget gapLg = SizedBox(height: lg, width: lg);
  static const Widget gapXl = SizedBox(height: xl, width: xl);

  /// Vertical-only gaps (the common case in Columns).
  static const Widget vXs = SizedBox(height: xs);
  static const Widget vSm = SizedBox(height: sm);
  static const Widget vMd = SizedBox(height: md);
  static const Widget vLg = SizedBox(height: lg);
  static const Widget vXl = SizedBox(height: xl);

  /// Horizontal-only gaps (the common case in Rows).
  static const Widget hXs = SizedBox(width: xs);
  static const Widget hSm = SizedBox(width: sm);
  static const Widget hMd = SizedBox(width: md);
  static const Widget hLg = SizedBox(width: lg);
  static const Widget hXl = SizedBox(width: xl);
}
