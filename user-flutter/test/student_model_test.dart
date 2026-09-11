import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/models/student_model.dart';

void main() {
  // The exact `student` payload returned by POST /api/auth/login for a
  // registrar-imported account: several UI keys are absent (grade_level,
  // course, homeroom) and several columns are null (year_level, block_number,
  // email_verified_at, voted_at). Parsing this must not throw — it used to
  // fail with `type 'Null' is not a subtype of type 'String'`, which the auth
  // provider then reported as a bogus "Invalid credentials" error.
  final loginPayload = <String, dynamic>{
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

  test('parses the real /api/auth/login payload without throwing', () {
    final student = Student.fromJson(loginPayload);

    expect(student.id, '56');
    expect(student.name, 'Test Student');
    expect(student.studentId, 'TEST-0001');
    expect(student.email, 'test.student@omnivote.test');
    expect(student.role, 'student');
    expect(student.gradeLevel, isNull);
    expect(student.yearLevel, isNull);
    expect(student.blockNumber, isNull);
    expect(student.hasVoted, isFalse);
  });

  test('parses optional section metadata when present', () {
    final student = Student.fromJson({
      ...loginPayload,
      'year_level': '11',
      'block_number': '2',
    });

    expect(student.yearLevel, '11');
    expect(student.blockNumber, '2');
  });

  test('toJson -> fromJson round-trips core fields', () {
    final student = Student.fromJson(loginPayload);
    final restored = Student.fromJson(student.toJson());

    expect(restored.id, student.id);
    expect(restored.name, student.name);
    expect(restored.studentId, student.studentId);
    expect(restored.email, student.email);
    expect(restored.hasVoted, student.hasVoted);
  });
}