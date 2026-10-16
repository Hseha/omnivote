import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:omnivote/core/theme/app_theme.dart';
import 'package:omnivote/data/models/candidacy_application_model.dart';
import 'package:omnivote/data/models/notification_model.dart';
import 'package:omnivote/data/models/position_model.dart';
import 'package:omnivote/data/models/student_model.dart';
import 'package:omnivote/data/repositories/auth_repository.dart';
import 'package:omnivote/data/repositories/candidacy_repository.dart';
import 'package:omnivote/data/repositories/notification_repository.dart';
import 'package:omnivote/data/services/candidacy_service.dart';
import 'package:omnivote/data/services/notification_service.dart';
import 'package:omnivote/features/auth/providers/auth_provider.dart';
import 'package:omnivote/features/candidacy/screens/candidacy_apply_screen.dart';
import 'package:omnivote/features/candidates/providers/candidates_provider.dart';
import 'package:omnivote/features/notifications/providers/notifications_provider.dart';

/// Covers the frontend half of the candidacy seat cap (docs/HANDOFF_CANDIDACY_SEAT_CAP.md
/// §3, §7): the "Seat taken" popup for a `seat_taken` 409, the untouched
/// snackbar for `application_exists`, and the availability-driven taken-seat
/// labels in the position picker.

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
  @override
  Future<void> setupSsgPassword({
    required String token,
    required String newPassword,
  }) async {}
}

class _FakeCandidacyRepository extends CandidacyRepository {
  _FakeCandidacyRepository({this.submitError, this.availability})
      : super(CandidacyService(Dio()));

  final Object? submitError;
  final Map<int, Map<String, int>>? availability;

  @override
  Future<CandidacyApplication> submit({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) async {
    if (submitError != null) throw submitError!;
    return CandidacyApplication(
      positionId: positionId,
      platformStatement: platformStatement,
      partyName: partyName,
      status: 'pending',
    );
  }

  @override
  Future<CandidacyApplication?> getMyApplication() async => null;

  @override
  Future<Map<int, Map<String, int>>> getAvailability() async {
    // A null map models the endpoint failing/being absent (pre-backend).
    if (availability == null) {
      throw DioException(
        requestOptions: RequestOptions(path: '/candidacy/availability'),
        response: Response(
          requestOptions: RequestOptions(path: '/candidacy/availability'),
          statusCode: 404,
        ),
      );
    }
    return availability!;
  }
}

/// The app bar's notification bell would otherwise reach the real API (and spin
/// up the poll ticker), so it is stubbed out for these screen tests.
class _FakeNotificationRepository extends NotificationRepository {
  _FakeNotificationRepository() : super(NotificationService(Dio()));

  @override
  Future<NotificationFeed> getFeed() async =>
      const NotificationFeed(items: [], unread: 0);

  @override
  Future<void> markRead({int? id}) async {}
}

const _student = Student(
  id: '1',
  name: 'Ana Cruz',
  studentId: 'STU-001',
  email: 'ana@example.edu',
  department: 'College of Computer Studies',
  course: 'BSIT',
);

final _positions = <Position>[
  Position(
    id: '3',
    slug: 'president',
    label: 'President',
    tier: PositionTier.national,
    description: '',
  ),
];

Future<void> _pump(
  WidgetTester tester, {
  Object? submitError,
  Map<int, Map<String, int>>? availability,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        authProvider.overrideWith((ref) {
          final notifier = AuthNotifier(_StubAuthRepository());
          notifier.state = AuthState(student: _student);
          return notifier;
        }),
        candidacyRepositoryProvider.overrideWithValue(
          _FakeCandidacyRepository(
            submitError: submitError,
            availability: availability,
          ),
        ),
        positionsProvider.overrideWith((ref) async => _positions),
        partiesProvider.overrideWith((ref) async => ['ASLE', 'SVEA']),
        notificationsProvider.overrideWith(
          (ref) => NotificationsNotifier(_FakeNotificationRepository()),
        ),
      ],
      child: MaterialApp(
        theme: AppTheme.lightTheme,
        home: const CandidacyApplyScreen(),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _selectParty(WidgetTester tester, String party) async {
  // The party dropdown is the second DropdownButtonFormField on the form.
  await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
  await tester.pumpAndSettle();
  await tester.tap(find.text(party).last);
  await tester.pumpAndSettle();
}

Future<void> _fillForm(WidgetTester tester) async {
  await tester.tap(find.byType(DropdownButtonFormField<String>).at(0));
  await tester.pumpAndSettle();
  await tester.tap(find.text('President (National)').last);
  await tester.pumpAndSettle();

  await _selectParty(tester, 'ASLE');

  await tester.enterText(
    find.ancestor(
      of: find.text('Campaign Platform / Statement *'),
      matching: find.byType(TextFormField),
    ),
    'A platform worth voting for.',
  );
  await tester.pumpAndSettle();

  final certify = find.byType(CheckboxListTile);
  await tester.ensureVisible(certify);
  await tester.pumpAndSettle();
  await tester.tap(certify);
  await tester.pumpAndSettle();
}

Future<void> _submit(WidgetTester tester) async {
  final submit = find.text('Submit Application');
  await tester.ensureVisible(submit);
  await tester.pumpAndSettle();
  await tester.tap(submit);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('seat_taken 409 shows the "Seat taken" dialog', (tester) async {
    await _pump(
      tester,
      submitError: const CandidacyException(
        'Sorry — the ASLE seat for President is already taken.',
        code: 'seat_taken',
      ),
      availability: {
        3: {'ASLE': 0, 'SVEA': 0},
      },
    );

    await _fillForm(tester);
    await _submit(tester);

    expect(find.text('Seat taken'), findsOneWidget);
    expect(
      find.textContaining('already taken'),
      findsWidgets,
      reason: 'the server sentence is shown inside the dialog',
    );

    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();
    expect(find.text('Seat taken'), findsNothing);
  });

  testWidgets('application_exists 409 keeps the snackbar (no popup)',
      (tester) async {
    await _pump(
      tester,
      submitError: const CandidacyException(
        'You already have an active application.',
        code: 'application_exists',
      ),
      availability: {
        3: {'ASLE': 0, 'SVEA': 0},
      },
    );

    await _fillForm(tester);
    await _submit(tester);

    expect(find.text('Seat taken'), findsNothing,
        reason: 'only seat_taken opens the popup');
    expect(
      find.text('You already have a candidacy application under review.'),
      findsOneWidget,
    );
  });

  testWidgets('taken seat is labelled for the party that filled it and clear '
      'for the other', (tester) async {
    await _pump(
      tester,
      availability: {
        3: {'ASLE': 1, 'SVEA': 0},
      },
    );

    await _selectParty(tester, 'ASLE');
    await tester.tap(find.byType(DropdownButtonFormField<String>).at(0));
    await tester.pumpAndSettle();
    expect(
      find.text('President (National) — ASLE seat taken'),
      findsWidgets,
      reason: 'ASLE already holds the president seat',
    );

    // Dismiss the open menu, then switch to the party with the seat free.
    await tester.tapAt(const Offset(5, 5));
    await tester.pumpAndSettle();

    await _selectParty(tester, 'SVEA');
    await tester.tap(find.byType(DropdownButtonFormField<String>).at(0));
    await tester.pumpAndSettle();
    expect(find.text('President (National) — ASLE seat taken'), findsNothing);
    expect(find.text('President (National)'), findsWidgets);
  });

  testWidgets('independent ("No party") candidate fills the reserved _none '
      'bucket', (tester) async {
    await _pump(
      tester,
      availability: {
        3: {'_none': 1, 'ASLE': 0, 'SVEA': 0},
      },
    );

    await _selectParty(tester, 'No party');
    await tester.tap(find.byType(DropdownButtonFormField<String>).at(0));
    await tester.pumpAndSettle();
    expect(
      find.text('President (National) — No party seat taken'),
      findsWidgets,
      reason: 'the independent seat is keyed `_none`, matching the server',
    );
  });

  testWidgets('availability failure keeps every position selectable',
      (tester) async {
    await _pump(tester, availability: null);

    expect(find.text('Submit Application'), findsOneWidget);
    expect(
      find.textContaining('Seat availability could not be loaded'),
      findsOneWidget,
      reason: 'a graceful message replaces the labels',
    );

    await tester.tap(find.byType(DropdownButtonFormField<String>).at(0));
    await tester.pumpAndSettle();
    expect(find.text('President (National)'), findsWidgets,
        reason: 'no position is disabled when availability is unknown');
  });
}
