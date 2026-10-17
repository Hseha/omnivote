import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/models/student_model.dart';
import 'package:omnivote/features/auth/providers/auth_provider.dart';

/// SSG President routing (award flow):
///
/// After the admin promotes a certified winner, the mobile app must (a) carry
/// the `role` off the login/restore payload and (b) land the President on the
/// shared student dashboard — the seated President keeps the full student app;
/// the governance console is web-only and reached from the one-time setup link.
void main() {
  group('destinationForRole', () {
    test('routes an SSG President to the shared student dashboard', () {
      final state = AuthState(student: Student.fromJson(_payload), role: 'ssg_president');

      expect(destinationForRole(state), '/dashboard');
    });

    test('routes a student to the shared dashboard', () {
      final state = AuthState(student: Student.fromJson(_payload), role: 'student');

      expect(destinationForRole(state), '/dashboard');
    });

    test('defaults to the dashboard when no role is known', () {
      expect(destinationForRole(AuthState()), '/dashboard');
      expect(destinationForRole(AuthState(role: null)), '/dashboard');
    });
  });

  group('AuthState.role', () {
    test('is kept by default through copyWith and replaced when given', () {
      final state = AuthState(role: 'ssg_president');

      expect(state.copyWith().role, 'ssg_president');
      expect(state.copyWith(role: 'student').role, 'student');
      expect(state.copyWith(role: null).role, isNull);
    });
  });

  group('Student.role from the login payload', () {
    test('parses the promoted president role', () {
      final student = Student.fromJson({..._payload, 'role': 'ssg_president'});

      expect(student.role, 'ssg_president');
    });

    test('parses an ordinary student role', () {
      final student = Student.fromJson(_payload);

      expect(student.role, 'student');
    });
  });
}

/// The exact `student` payload shape POST /api/auth/login returns.
const _payload = <String, dynamic>{
  'id': 56,
  'student_id': 'TEST-0001',
  'role': 'student',
  'year_level': null,
  'block_number': null,
  'has_voted': false,
  'voted_at': null,
  'name': 'Test Student',
  'email': 'test.student@omnivote.test',
  'email_verified_at': null,
  'created_at': '2026-09-08T08:52:07.000000Z',
  'updated_at': '2026-09-08T08:52:07.000000Z',
};
