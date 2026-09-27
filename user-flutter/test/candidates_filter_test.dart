import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/models/position_model.dart';
import 'package:omnivote/features/candidates/providers/candidates_provider.dart';

void main() {
  group('CandidatesFilter', () {
    test('defaults to the national tier with no position', () {
      final filter = CandidatesFilter();
      expect(filter.tier, PositionTier.national);
      expect(filter.positionId, isNull);
      expect(filter.department, isNull);
      expect(filter.party, isNull);
      expect(filter.search, '');
      expect(filter.grade, isNull);
    });

    test('copyWith overrides and preserves fields', () {
      final filter = CandidatesFilter();
      final next = filter.copyWith(
        tier: PositionTier.provincial,
        positionId: 'senator',
        department: 'College of Computer Studies',
        party: 'ASLE',
        search: 'maria',
        grade: 'Grade 11',
      );

      expect(next.tier, PositionTier.provincial);
      expect(next.positionId, 'senator');
      expect(next.department, 'College of Computer Studies');
      expect(next.party, 'ASLE');
      expect(next.search, 'maria');
      expect(next.grade, 'Grade 11');
    });

    test('clearPositionId clears an existing position id', () {
      final filter = CandidatesFilter(positionId: 'senator');
      final next = filter.copyWith(clearPositionId: true);
      expect(next.positionId, isNull);
      expect(next.tier, PositionTier.national);
    });

    test('clearDepartment and clearParty clear their fields', () {
      final filter = CandidatesFilter(department: 'CCS', party: 'SVEA');
      final next = filter.copyWith(clearDepartment: true, clearParty: true);
      expect(next.department, isNull);
      expect(next.party, isNull);
    });
  });
}