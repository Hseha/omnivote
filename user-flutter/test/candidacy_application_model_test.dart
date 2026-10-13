import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/models/candidacy_application_model.dart';
import 'package:omnivote/data/models/position_model.dart';

/// Coverage for the enriched `GET /api/candidacy/me` payload the My Candidacy
/// screen reads: position object, rejection reason, seat competition, and
/// the post-close election outcome — plus the bare apply-response shape,
/// which only carries the campaign fields.
void main() {
  group('CandidacyApplication.fromJson (enriched /candidacy/me)', () {
    // Mirrors what CandidacyRepository.getMyApplication produces: the
    // `candidate` map with the top-level context keys merged in.
    final enriched = <String, dynamic>{
      ...<String, dynamic>{
        'id': '42',
        'position_id': '3',
        'position': <String, dynamic>{
          'id': 3,
          'slug': 'senator',
          'label': 'Senator',
          'tier': 'national',
          'seat_count': 12,
        },
        'slogan': 'Loud halls, clean books.',
        'party_name': 'ASLE',
        'platform_statement': 'P1\nP2',
        'photo_path': 'candidates/ria.png',
        'photo_url': 'https://example.test/storage/candidates/ria.png',
        'approval_status': 'rejected',
        'rejection_reason': 'Missing required eligibility documents.',
        'created_at': '2026-10-01T09:00:00.000Z',
        'updated_at': '2026-10-05T09:00:00.000Z',
      },
      'applicants_count': 7,
      'election_outcome': null,
    };

    test('parses the position, reason, timestamps and race size', () {
      final application = CandidacyApplication.fromJson(enriched);

      expect(application.positionId, '3');
      expect(application.status, 'rejected');
      expect(application.rejectionReason, 'Missing required eligibility documents.');
      expect(application.createdAt, '2026-10-01T09:00:00.000Z');
      expect(application.updatedAt, '2026-10-05T09:00:00.000Z');
      expect(application.applicantsCount, 7);
      expect(application.photoUrl,
          'https://example.test/storage/candidates/ria.png');
      expect(application.electionOutcome, isNull);

      final position = application.position;
      expect(position, isNotNull);
      expect(position!.slug, 'senator');
      expect(position.label, 'Senator');
      expect(position.seatCount, 12);
      expect(position.tier, PositionTier.national);
    });

    test('parses the finalized outcome once polls close', () {
      final closed = Map<String, dynamic>.from(enriched);
      closed['status'] = 'approved';
      closed['election_outcome'] = <String, dynamic>{
        'status': 'elected',
        'rank': 1,
        'votes': 43,
        'certified': true,
      };

      final outcome = CandidacyApplication.fromJson(closed).electionOutcome;
      expect(outcome, isNotNull);
      expect(outcome!.status, 'elected');
      expect(outcome.rank, 1);
      expect(outcome.votes, 43);
      expect(outcome.certified, isTrue);
      expect(outcome.isElected, isTrue);
    });

    test('a tied outcome is flagged for the tie-break badge', () {
      final tied = Map<String, dynamic>.from(enriched);
      tied['election_outcome'] = <String, dynamic>{
        'status': 'tied',
        'rank': 6,
        'votes': 30,
        'certified': false,
      };

      final outcome = CandidacyApplication.fromJson(tied).electionOutcome!;
      expect(outcome.isTied, isTrue);
      expect(outcome.isElected, isFalse);
    });

    test('outcome getters default to not-winner before results', () {
      final outcome = CandidacyElectionOutcome.fromJson(const {});
      expect(outcome.status, 'pending');
      expect(outcome.rank, isNull);
      expect(outcome.votes, 0);
      expect(outcome.isElected, isFalse);
      expect(outcome.isTied, isFalse);
    });

    test('the bare apply response (campaign fields only) still parses', () {
      final application = CandidacyApplication.fromJson(const {
        'position_id': '3',
        'slogan': 'Serve first',
        'platform_statement': 'Clean halls.',
        'approval_status': 'pending',
      });

      expect(application.positionId, '3');
      expect(application.slogan, 'Serve first');
      expect(application.platformStatement, 'Clean halls.');
      expect(application.status, 'pending');
      // Context fields the apply response does not carry stay null.
      expect(application.position, isNull);
      expect(application.rejectionReason, isNull);
      expect(application.createdAt, isNull);
      expect(application.applicantsCount, isNull);
      expect(application.electionOutcome, isNull);
    });
  });
}
