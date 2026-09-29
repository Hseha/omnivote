import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:omnivote/core/theme/app_theme.dart';
import 'package:omnivote/data/models/election_status_model.dart';
import 'package:omnivote/features/dashboard/providers/announcements_provider.dart';
import 'package:omnivote/features/dashboard/providers/election_status_provider.dart';
import 'package:omnivote/features/results/providers/results_provider.dart';
import 'package:omnivote/features/results/screens/results_screen.dart';

/// Regression test for the on-device stress run: a bogus receipt token must
/// produce the "No matching vote" row once the verifier resolves.
void main() {
  Future<void> pumpResults(
    WidgetTester tester, {
    required Map<String, bool> verdicts,
  }) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          electionStatusProvider.overrideWith(
            (ref) async =>
                const ElectionStatus(phase: ElectionPhase.votingClosed),
          ),
          resultsProvider.overrideWith((ref) async => []),
          announcementsProvider.overrideWith((ref) async => []),
          for (final entry in verdicts.entries)
            verifyReceiptProvider(entry.key)
                .overrideWith((ref) async => entry.value),
        ],
        child: MaterialApp(
          theme: AppTheme.lightTheme,
          home: const ResultsScreen(),
        ),
      ),
    );
    await tester.pump();
  }

  testWidgets('bogus receipt token shows the not-found result', (tester) async {
    await pumpResults(tester, verdicts: {'BOGUS-RECEIPT-0000': false});

    await tester.enterText(find.byType(TextFormField), 'BOGUS-RECEIPT-0000');
    await tester.tap(find.text('Verify Token'));
    await tester.pumpAndSettle();

    expect(find.textContaining('No matching vote'), findsOneWidget);
  });

  testWidgets('SQL-injection-shaped token is inert and shows not-found',
      (tester) async {
    const probe = "' OR 1=1-- ";
    await pumpResults(tester, verdicts: {probe.trim(): false});

    await tester.enterText(find.byType(TextFormField), probe);
    await tester.tap(find.text('Verify Token'));
    await tester.pumpAndSettle();

    // Treated as an opaque string: no rows, no crash, honest message.
    expect(find.textContaining('No matching vote'), findsOneWidget);
  });

  testWidgets('oversized token does not break layout and shows not-found',
      (tester) async {
    final probe = 'T' * 500;
    await pumpResults(tester, verdicts: {probe: false});

    await tester.enterText(find.byType(TextFormField), probe);
    await tester.tap(find.text('Verify Token'));
    await tester.pumpAndSettle();

    expect(find.textContaining('No matching vote'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('empty submit is a no-op (no verification attempted)',
      (tester) async {
    await pumpResults(tester, verdicts: {});

    // Field left empty: the guard in onVerify must swallow the tap without
    // watching any provider or showing any result row.
    await tester.tap(find.text('Verify Token'));
    await tester.pumpAndSettle();

    expect(find.textContaining('No matching vote'), findsNothing);
    expect(
      find.text('Your vote was counted in the ledger.'),
      findsNothing,
    );
    expect(tester.takeException(), isNull);
  });
}
