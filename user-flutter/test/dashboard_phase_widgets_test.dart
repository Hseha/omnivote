import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:omnivote/core/theme/app_theme.dart';
import 'package:omnivote/data/models/branding_model.dart';
import 'package:omnivote/data/models/election_status_model.dart';
import 'package:omnivote/data/models/position_model.dart';
import 'package:omnivote/data/models/student_model.dart';
import 'package:omnivote/data/repositories/auth_repository.dart';
import 'package:omnivote/features/auth/providers/auth_provider.dart';
import 'package:omnivote/features/ballot/screens/my_ballot_screen.dart';
import 'package:omnivote/features/candidates/providers/candidates_provider.dart';
import 'package:omnivote/features/dashboard/providers/election_status_provider.dart';
import 'package:omnivote/features/dashboard/widgets/ballot_progress_tile.dart';
import 'package:omnivote/features/dashboard/widgets/election_countdown.dart';
import 'package:omnivote/features/dashboard/widgets/phase_hero_card.dart';
import 'package:omnivote/features/settings/providers/branding_provider.dart';

/// No network, no navigation: every provider the dashboard reads is stubbed and
/// the hero's buttons are rendered (their `context.go` callbacks only run on a
/// tap, which these tests never perform).
class _StubAuthRepository implements AuthRepository {
  @override
  Future<LoginResult?> getAuthenticatedUser() async => null;
  @override
  Future<LoginResult> login({required String email, required String password}) {
    throw UnimplementedError();
  }
  @override
  Future<void> logout() async {}
  @override
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {}
  @override
  Future<void> resetPasswordWithCode({
    required String studentId,
    required String code,
    required String newPassword,
  }) async {}
}

const _student = Student(
  id: '1',
  name: 'Ana Cruz',
  studentId: 'STU-001',
  email: 'ana@example.edu',
  department: 'College of Computer Studies',
  course: 'BSIT',
);

const _studentWhoVoted = Student(
  id: '1',
  name: 'Ana Cruz',
  studentId: 'STU-001',
  email: 'ana@example.edu',
  department: 'College of Computer Studies',
  course: 'BSIT',
  hasVoted: true,
);

final _positions = <Position>[
  Position(
    id: 'president',
    slug: 'president',
    label: 'President',
    tier: PositionTier.national,
    description: '',
  ),
  Position(
    id: 'senator',
    slug: 'senator',
    label: 'Senator',
    tier: PositionTier.national,
    seatCount: 12,
    description: '',
  ),
];

Future<void> _pumpHero(
  WidgetTester tester, {
  required ElectionStatus status,
  bool hasVoted = false,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        authProvider.overrideWith(
          (ref) {
            final notifier = AuthNotifier(_StubAuthRepository());
            notifier.state = AuthState(
              student: hasVoted ? _studentWhoVoted : _student,
            );
            return notifier;
          },
        ),
        electionStatusProvider.overrideWith((ref) async => status),
        brandingProvider.overrideWith(
          (ref) async => const Branding(primaryColor: Color(0xFF2F5EFF)),
        ),
      ],
      child: MaterialApp(
        theme: AppTheme.lightTheme,
        home: const Scaffold(body: SingleChildScrollView(child: PhaseHeroCard())),
      ),
    ),
  );

  // Let the overridden FutureProviders deliver their values.
  await tester.pump();
}

class _CountHost extends ConsumerStatefulWidget {
  const _CountHost({
    required this.target,
    required this.onBuild,
    required this.now,
  });

  final DateTime target;
  final void Function() onBuild;
  final DateTime Function() now;

  @override
  ConsumerState<_CountHost> createState() => _CountHostState();
}

class _CountHostState extends ConsumerState<_CountHost> {
  @override
  Widget build(BuildContext context) {
    widget.onBuild();
    return ElectionCountdown(target: widget.target, now: widget.now);
  }
}

void main() {
  group('PhaseHeroCard', () {
    testWidgets('registration phase shows the confirm-eligibility CTA',
        (tester) async {
      await _pumpHero(
        tester,
        status: const ElectionStatus(phase: ElectionPhase.registration),
      );

      expect(find.textContaining('Confirm eligibility'), findsOneWidget);
      expect(find.text('Register'), findsOneWidget);
      expect(find.text('Voting opens in'), findsNothing);
    });

    testWidgets(
        'registrationClosed shows a countdown to votingOpensAt when known',
        (tester) async {
      await _pumpHero(
        tester,
        status: ElectionStatus(
          phase: ElectionPhase.registrationClosed,
          votingOpensAt: DateTime.now().add(const Duration(minutes: 30)),
        ),
      );

      expect(find.textContaining('Voting opens in'), findsOneWidget);
      expect(find.byType(ElectionCountdown), findsOneWidget);

      // Unmount to cancel the countdown timer before the test ends.
      await tester.pumpWidget(const MaterialApp(home: SizedBox()));
    });

    testWidgets(
        'registrationClosed with no bound shows plain copy, no countdown',
        (tester) async {
      await _pumpHero(
        tester,
        status: const ElectionStatus(phase: ElectionPhase.registrationClosed),
      );

      expect(find.byType(ElectionCountdown), findsNothing);
      expect(find.textContaining('Voting opens soon'), findsOneWidget);
    });

    testWidgets('votingOpen + not voted shows countdown and Vote now CTA',
        (tester) async {
      await _pumpHero(
        tester,
        status: ElectionStatus(
          phase: ElectionPhase.votingOpen,
          votingClosesAt: DateTime.now().add(const Duration(minutes: 60)),
        ),
      );

      expect(find.textContaining('Voting closes in'), findsOneWidget);
      expect(find.text('Vote now'), findsOneWidget);
      expect(find.byType(ElectionCountdown), findsOneWidget);

      await tester.pumpWidget(const MaterialApp(home: SizedBox()));
    });

    testWidgets('votingOpen + hasVoted shows the receipt-verification CTA',
        (tester) async {
      await _pumpHero(
        tester,
        status: const ElectionStatus(phase: ElectionPhase.votingOpen),
        hasVoted: true,
      );

      expect(find.textContaining('Verify your receipt'), findsOneWidget);
      expect(find.text('Vote now'), findsNothing);
    });

    testWidgets('votingClosed shows the view-results CTA', (tester) async {
      await _pumpHero(
        tester,
        status: const ElectionStatus(phase: ElectionPhase.votingClosed),
      );

      expect(find.textContaining('View results'), findsOneWidget);
      expect(find.text('Results'), findsOneWidget);
    });

    testWidgets('unknown phase shows unavailable copy and no CTA',
        (tester) async {
      await _pumpHero(
        tester,
        status: const ElectionStatus(phase: ElectionPhase.unknown),
      );

      expect(find.textContaining('unavailable'), findsOneWidget);
      expect(find.text('Vote now'), findsNothing);
    });
  });

  group('BallotProgressTile', () {
    testWidgets('reports filled vs eligible positions', (tester) async {
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            authProvider.overrideWith(
              (ref) {
                final notifier = AuthNotifier(_StubAuthRepository());
                notifier.state = AuthState(student: _student);
                return notifier;
              },
            ),
            electionStatusProvider.overrideWith(
              (ref) async =>
                  const ElectionStatus(phase: ElectionPhase.votingOpen),
            ),
            positionsProvider.overrideWith((ref) async => _positions),
            myBallotProvider.overrideWith(
              (ref) async => {
                'selections': {'president': ['ref-1']},
              },
            ),
          ],
          child: MaterialApp(
            theme: AppTheme.lightTheme,
            home: const Scaffold(
              body: BallotProgressTile(),
            ),
          ),
        ),
      );

      await tester.pump();
      expect(find.text('1 of 2 positions'), findsOneWidget);
    });
  });

  group('ElectionCountdown isolated rebuild', () {
    testWidgets('a tick rebuilds the countdown, not its host', (tester) async {
      var now = DateTime(2026, 9, 29, 10, 0, 0);
      var hostBuilds = 0;

      await tester.pumpWidget(
        ProviderScope(
          child: MaterialApp(
            theme: AppTheme.lightTheme,
            home: Scaffold(
              body: _CountHost(
                target: now.add(const Duration(seconds: 90)),
                now: () => now,
                onBuild: () => hostBuilds++,
              ),
            ),
          ),
        ),
      );

      expect(find.textContaining('01:30'), findsOneWidget);
      final buildsAfterMount = hostBuilds;
      expect(buildsAfterMount, greaterThanOrEqualTo(1));

      // Advance the fake clock one second: the countdown's 1 s timer fires and
      // setState()s only its own subtree.
      now = now.add(const Duration(seconds: 1));
      await tester.pump(const Duration(seconds: 1));

      expect(find.textContaining('01:29'), findsOneWidget);
      expect(hostBuilds, buildsAfterMount,
          reason: 'the host must not rebuild when the countdown ticks');

      await tester.pumpWidget(const MaterialApp(home: SizedBox()));
    });
  });
}