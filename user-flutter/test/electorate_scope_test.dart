import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/models/candidate_model.dart';
import 'package:omnivote/data/models/position_model.dart';
import 'package:omnivote/data/models/student_model.dart';
import 'package:omnivote/features/voting/electorate_scope.dart';

Position _position({
  required String tier,
  String scopeType = 'global',
  String? scopeValue,
  String slug = 'governor',
}) {
  return Position.fromJson({
    'id': slug,
    'slug': slug,
    'label': slug,
    'tier': tier,
    'seat_count': 1,
    'scope_type': scopeType,
    'scope_value': scopeValue,
  });
}

Candidate _candidate({String? department, String? gradeLine}) {
  return Candidate.fromJson({
    'id': 'c$department$gradeLine',
    'candidate_ref': 'ref-$department-$gradeLine',
    'name': 'Nominee',
    'department': department,
    'grade_level': gradeLine,
  });
}

Student _voter({String? department, String? yearLevel, String? course}) {
  return Student.fromJson({
    'id': '1',
    'name': 'Student',
    'student_id': '2024-01001',
    'email': 's@example.com',
    'department': department,
    'year_level': yearLevel,
    'course': course,
  });
}

void main() {
  group('national seats stay open to everyone', () {
    final president = _position(tier: 'national', slug: 'president');

    test('any student may vote and see any candidate', () {
      final voter = _voter(
        department: 'College of Arts and Sciences',
        yearLevel: '1',
      );

      expect(ElectorateScope.voterMayVote(president, voter), isTrue);
      expect(
        ElectorateScope.candidateIsOnBallot(
          position: president,
          candidate: _candidate(department: 'College of Computer Studies'),
          voter: voter,
        ),
        isTrue,
      );
    });

    test('a missing scope is treated as global, not restricted', () {
      final legacy = Position.fromJson({
        'id': 'president',
        'label': 'President',
        'tier': 'national',
        'seat_count': 1,
      });

      expect(legacy.scopeType, 'global');
      expect(ElectorateScope.voterMayVote(legacy, null), isTrue);
    });
  });

  group('provincial seats are decided per college', () {
    final governor = _position(tier: 'provincial', scopeType: 'department');

    test('a student sees only their own college', () {
      final voter = _voter(department: 'College of Arts and Sciences');

      expect(
        ElectorateScope.candidateIsOnBallot(
          position: governor,
          candidate: _candidate(department: 'College of Arts and Sciences'),
          voter: voter,
        ),
        isTrue,
      );
      for (final other in const [
        'College of Computer Studies',
        'College of Teacher Education',
        'College of Office Administration',
      ]) {
        expect(
          ElectorateScope.candidateIsOnBallot(
            position: governor,
            candidate: _candidate(department: other),
            voter: voter,
          ),
          isFalse,
          reason: '$other must not be votable by an Arts and Sciences student',
        );
      }
    });

    test('the same nominee is votable by their own college only', () {
      final nominee = _candidate(department: 'College of Computer Studies');

      expect(
        ElectorateScope.candidateIsOnBallot(
          position: governor,
          candidate: nominee,
          voter: _voter(department: 'College of Computer Studies'),
        ),
        isTrue,
      );
      expect(
        ElectorateScope.candidateIsOnBallot(
          position: governor,
          candidate: nominee,
          voter: _voter(department: 'College of Arts and Sciences'),
        ),
        isFalse,
      );
    });

    test('college matching ignores case and surrounding whitespace', () {
      expect(
        ElectorateScope.candidateIsOnBallot(
          position: governor,
          candidate: _candidate(department: '  college of ARTS AND SCIENCES '),
          voter: _voter(department: 'College of Arts and Sciences'),
        ),
        isTrue,
      );
    });
  });

  group('the year-level representative is decided per year', () {
    final ylr = _position(
      tier: 'national',
      scopeType: 'year_level',
      slug: 'year_level_representative',
    );

    test('a 1st-year student sees only the 1st-year nominee', () {
      final voter = _voter(
        department: 'College of Arts and Sciences',
        yearLevel: '1',
      );

      expect(
        ElectorateScope.candidateIsOnBallot(
          position: ylr,
          candidate: _candidate(gradeLine: '1'),
          voter: voter,
        ),
        isTrue,
      );
      for (final year in const ['2', '3', '4']) {
        expect(
          ElectorateScope.candidateIsOnBallot(
            position: ylr,
            candidate: _candidate(gradeLine: year),
            voter: voter,
          ),
          isFalse,
          reason: 'a $year-year nominee must not appear on a 1st-year ballot',
        );
      }
    });

    test('the year is what matters, not the college', () {
      // A 1st-year representative may be nominated from any college: it is a
      // national-tier seat contested by year, not by college.
      expect(
        ElectorateScope.candidateIsOnBallot(
          position: ylr,
          candidate: _candidate(
            department: 'College of Computer Studies',
            gradeLine: '1',
          ),
          voter: _voter(
            department: 'College of Arts and Sciences',
            yearLevel: '1',
          ),
        ),
        isTrue,
      );
    });

    test('senior-high numbering is not silently equal to a college year', () {
      // The bug this guards: '1' !== '11', so a student stored under the old
      // convention must not appear to match a 1st-year nominee.
      expect(
        ElectorateScope.candidateIsOnBallot(
          position: ylr,
          candidate: _candidate(gradeLine: '1'),
          voter: _voter(yearLevel: '11'),
        ),
        isFalse,
      );
    });
  });

  group('an incomplete profile is refused, not waved through', () {
    test('a student with no year level cannot take a year-level seat', () {
      final ylr = _position(
        tier: 'national',
        scopeType: 'year_level',
        slug: 'year_level_representative',
      );

      expect(
        ElectorateScope.voterMayVote(ylr, _voter(yearLevel: null)),
        isFalse,
      );
      expect(ElectorateScope.voterMayVote(ylr, _voter(yearLevel: '')), isFalse);
    });

    test('a student with no college cannot take a provincial seat', () {
      final governor = _position(tier: 'provincial', scopeType: 'department');

      expect(
        ElectorateScope.voterMayVote(governor, _voter(department: null)),
        isFalse,
      );
      expect(ElectorateScope.voterMayVote(governor, null), isFalse);
    });
  });

  group('a literal scope value pins the seat', () {
    test('only students holding that exact value may vote', () {
      final pinned = _position(
        tier: 'provincial',
        scopeType: 'department',
        scopeValue: 'College of Computer Studies',
      );

      expect(
        ElectorateScope.voterMayVote(
          pinned,
          _voter(department: 'College of Computer Studies'),
        ),
        isTrue,
      );
      expect(
        ElectorateScope.voterMayVote(
          pinned,
          _voter(department: 'College of Arts and Sciences'),
        ),
        isFalse,
      );
    });

    test('a blank scope value means "your own value", not "no restriction"', () {
      // A blank string must resolve to the voter's own attribute. Treating it
      // as empty/global would silently reopen the whole provincial tier.
      final own = _position(
        tier: 'provincial',
        scopeType: 'department',
        scopeValue: '  ',
      );
      expect(own.scopeValue, isNull);

      // Every student is in the electorate for their OWN college's race — that
      // is one independent election per college, not one shared race.
      for (final college in const [
        'College of Arts and Sciences',
        'College of Computer Studies',
      ]) {
        expect(
          ElectorateScope.voterMayVote(own, _voter(department: college)),
          isTrue,
          reason: '$college must elect its own governor',
        );
      }

      // What "own value" must NOT do is let one college's nominee onto another
      // college's ballot.
      expect(
        ElectorateScope.candidateIsOnBallot(
          position: own,
          candidate: _candidate(department: 'College of Arts and Sciences'),
          voter: _voter(department: 'College of Computer Studies'),
        ),
        isFalse,
      );
    });
  });

  group('candidate attributes the app cannot see', () {
    test('a course-scoped nominee is left to the server, not hidden', () {
      // The candidate payload carries department and grade_level but no course,
      // so the client cannot verify a course-scoped nominee. Hiding them all
      // would blank the seat; the server re-checks the real column anyway.
      final byCourse = _position(
        tier: 'national',
        scopeType: 'course',
        slug: 'board_rep',
      );
      final voter = _voter(course: 'BSIT');

      expect(ElectorateScope.voterMayVote(byCourse, voter), isTrue);
      expect(
        ElectorateScope.candidateIsOnBallot(
          position: byCourse,
          candidate: _candidate(),
          voter: voter,
        ),
        isTrue,
      );
    });
  });
}
