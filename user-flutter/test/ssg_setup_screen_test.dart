import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:omnivote/core/theme/app_theme.dart';
import 'package:omnivote/data/models/student_model.dart';
import 'package:omnivote/data/repositories/auth_repository.dart';
import 'package:omnivote/features/auth/providers/auth_provider.dart';
import 'package:omnivote/features/ssg_president/screens/ssg_setup_screen.dart';

/// Covers the mobile half of the SSG President credential handoff: the setup
/// screen reads the one-time token from the notification link's query string
/// and redeems it to set the President's own password.
class _FakeAuthRepository implements AuthRepository {
  _FakeAuthRepository({this.succeed = true});

  final bool succeed;
  String? capturedToken;
  String? capturedPassword;

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
  }) async {
    capturedToken = token;
    capturedPassword = newPassword;
    if (!succeed) throw Exception('invite expired');
  }
}

const _president = Student(
  id: '1',
  name: 'Reyes, Miguel',
  studentId: '2024-0001',
  email: 'miguel.reyes',
  role: 'ssg_president',
  ssgSetupPending: true,
);

Future<void> _pump(
  WidgetTester tester,
  _FakeAuthRepository repository, {
  String location = '/ssg-setup?token=abc123',
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        authProvider.overrideWith((ref) {
          final notifier = AuthNotifier(repository);
          notifier.state = AuthState(
            student: _president,
            role: 'ssg_president',
            ssgSetupPending: true,
          );
          return notifier;
        }),
      ],
      child: MaterialApp.router(
        theme: AppTheme.lightTheme,
        routerConfig: GoRouter(
          initialLocation: location,
          routes: [
            GoRoute(
              path: '/ssg-setup',
              builder: (context, state) => const SsgSetupScreen(),
            ),
            GoRoute(
              path: '/ssg-president',
              builder: (context, state) =>
                  const Scaffold(body: Text('president-dashboard')),
            ),
            GoRoute(
              path: '/notifications',
              builder: (context, state) =>
                  const Scaffold(body: Text('notifications')),
            ),
          ],
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  group('SsgSetupScreen', () {
    testWidgets('redeems the query-string token with the chosen password',
        (tester) async {
      final repository = _FakeAuthRepository();
      await _pump(tester, repository);

      expect(find.text('Choose your password'), findsOneWidget);
      expect(find.byType(TextFormField), findsNWidgets(2));

      await tester.enterText(find.byType(TextFormField).at(0), 'Newpass1');
      await tester.enterText(find.byType(TextFormField).at(1), 'Newpass1');
      await tester.ensureVisible(find.text('Set password'));
      await tester.tap(find.text('Set password'));
      await tester.pump();
      await tester.pumpAndSettle();

      expect(repository.capturedToken, 'abc123');
      expect(repository.capturedPassword, 'Newpass1');
      // Success advances the President into their dashboard.
      expect(find.text('president-dashboard'), findsOneWidget);
    });

    testWidgets('blocks a too-weak password before calling the API',
        (tester) async {
      final repository = _FakeAuthRepository();
      await _pump(tester, repository);

      await tester.enterText(find.byType(TextFormField).at(0), 'weak');
      await tester.enterText(find.byType(TextFormField).at(1), 'weak');
      await tester.ensureVisible(find.text('Set password'));
      await tester.tap(find.text('Set password'));
      await tester.pumpAndSettle();

      expect(repository.capturedToken, isNull);
      expect(find.text('Use at least 8 characters'), findsOneWidget);
    });

    testWidgets('a failed redemption keeps the President on the screen',
        (tester) async {
      final repository = _FakeAuthRepository(succeed: false);
      await _pump(tester, repository);

      await tester.enterText(find.byType(TextFormField).at(0), 'Newpass1');
      await tester.enterText(find.byType(TextFormField).at(1), 'Newpass1');
      await tester.ensureVisible(find.text('Set password'));
      await tester.tap(find.text('Set password'));
      await tester.pumpAndSettle();

      expect(repository.capturedToken, 'abc123');
      expect(find.text('Choose your password'), findsOneWidget);
      expect(find.text('president-dashboard'), findsNothing);
    });

    testWidgets('shows a recovery message when no token is present',
        (tester) async {
      await _pump(tester, _FakeAuthRepository(), location: '/ssg-setup');

      expect(find.text('Open the setup link'), findsOneWidget);
      expect(find.byType(TextFormField), findsNothing);
    });
  });
}
