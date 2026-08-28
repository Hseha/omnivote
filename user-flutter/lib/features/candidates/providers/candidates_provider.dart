import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/candidate_repository.dart';

final positionsProvider = FutureProvider<List<Position>>((ref) async {
  try {
    return await ref.watch(candidateRepositoryProvider).getPositions();
  } catch (e) {
    // Mock positions if backend is down
    return [
      Position(
        id: 'president',
        label: 'Presidential Candidate',
        tier: PositionTier.school,
        description: 'Vote for the student body leader.',
      ),
      Position(
        id: 'senator',
        label: 'Senator',
        tier: PositionTier.school,
        seatCount: 12,
        description: 'Select up to 12 senators.',
      ),
    ];
  }
});

class CandidatesFilter {
  final PositionTier tier;
  final String? positionId;
  final String search;
  final String? grade;

  CandidatesFilter({
    this.tier = PositionTier.school,
    this.positionId,
    this.search = '',
    this.grade,
  });

  CandidatesFilter copyWith({
    PositionTier? tier,
    String? positionId,
    String? search,
    String? grade,
  }) {
    return CandidatesFilter(
      tier: tier ?? this.tier,
      positionId: positionId ?? this.positionId,
      search: search ?? this.search,
      grade: grade ?? this.grade,
    );
  }
}

final candidatesFilterProvider = StateProvider<CandidatesFilter>((ref) => CandidatesFilter());

final filteredCandidatesProvider = FutureProvider<List<Candidate>>((ref) async {
  final filter = ref.watch(candidatesFilterProvider);
  
  try {
    final repository = ref.watch(candidateRepositoryProvider);
    return await repository.getCandidates(
      positionId: filter.positionId,
      tier: filter.tier.name,
      search: filter.search.isNotEmpty ? filter.search : null,
      grade: filter.grade != 'All Grades' ? filter.grade : null,
    );
  } catch (e) {
    // Mock candidates if backend is down
    return [
      Candidate(
        id: 'c1',
        name: 'Alice Smith',
        photoUrl: 'https://i.pravatar.cc/150?u=alice',
        position: Position(
          id: 'president',
          label: 'Presidential Candidate',
          tier: PositionTier.school,
          description: '...',
        ),
        gradeLine: 'Grade 12 Student',
        slogan: 'Progress for all.',
        platformPoints: ['United Student Voice', 'Digital Portal Access'],
        qualifications: ['Class President', 'Honor Student'],
      ),
      Candidate(
        id: 'c2',
        name: 'Bob Jones',
        photoUrl: 'https://i.pravatar.cc/150?u=bob',
        position: Position(
          id: 'president',
          label: 'Presidential Candidate',
          tier: PositionTier.school,
          description: '...',
        ),
        gradeLine: 'Grade 11 Student',
        slogan: 'Better campus, better future.',
        platformPoints: ['Green Initiative', 'Sports Facility Upgrade'],
        qualifications: ['Student Council Member', 'Varsity Captain'],
      ),
    ];
  }
});
