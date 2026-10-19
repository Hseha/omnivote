import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:omnivote/core/theme/app_theme.dart';
import 'package:omnivote/data/models/announcement_model.dart';
import 'package:omnivote/features/dashboard/providers/announcements_provider.dart';
import 'package:omnivote/features/dashboard/widgets/announcements_carousel.dart';

/// The carousel card previews an announcement in two lines of body. A long
/// post — the results announcement lists every elected officer — has to be
/// readable in full, so tapping the card opens it in a sheet.
///
/// No network: the announcements provider is overridden, so the carousel
/// renders straight from the given list.
Widget _harness(List<Announcement> announcements) {
  return ProviderScope(
    overrides: [
      announcementsProvider.overrideWith((ref) async => announcements),
    ],
    child: MaterialApp(
      theme: AppTheme.lightTheme,
      home: Scaffold(
        body: SingleChildScrollView(
          child: AnnouncementsCarousel(),
        ),
      ),
    ),
  );
}

/// The body texts on screen. A card preview is clamped to two lines; the
/// sheet shows the body whole.
List<Text> _bodyTexts(WidgetTester tester, String needle) =>
    tester.widgetList<Text>(find.textContaining(needle)).toList();

void main() {
  const body = 'The election results are in.\n'
      'Still awaiting a decision:\n'
      '• Governor (College of Computer Studies): TIE for a seat';

  testWidgets('the card previews the body in two lines', (tester) async {
    await tester.pumpWidget(_harness([
      const Announcement(
        id: 1,
        title: 'Official Election Results',
        body: body,
        authorName: 'Staff',
      ),
    ]));
    await tester.pumpAndSettle();

    expect(find.text('Official Election Results'), findsOneWidget);

    // The body is on the card, but clamped — it is a preview, not the post.
    final previews = _bodyTexts(tester, 'Still awaiting a decision');
    expect(previews, hasLength(1));
    expect(previews.single.maxLines, 2);
  });

  testWidgets('tapping a card opens the whole announcement', (tester) async {
    await tester.pumpWidget(_harness([
      const Announcement(
        id: 1,
        title: 'Official Election Results',
        body: body,
        authorName: 'Staff',
      ),
    ]));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Official Election Results'));
    await tester.pumpAndSettle();

    // The sheet shows the body in full, including the lines the card
    // truncated away.
    final shown = _bodyTexts(tester, 'Still awaiting a decision');
    expect(shown, hasLength(2));
    expect(
      shown.any((text) => text.maxLines == null),
      isTrue,
      reason: 'the sheet must show the body unclamped',
    );
  });

  testWidgets('a short announcement still opens without losing anything',
      (tester) async {
    await tester.pumpWidget(_harness([
      const Announcement(
        id: 1,
        title: 'Library hours',
        body: 'The library closes early on Friday.',
        authorName: 'Staff',
      ),
    ]));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Library hours'));
    await tester.pumpAndSettle();

    final shown = _bodyTexts(tester, 'The library closes early on Friday.');
    expect(shown, hasLength(2));
    expect(shown.any((text) => text.maxLines == null), isTrue);
  });
}
