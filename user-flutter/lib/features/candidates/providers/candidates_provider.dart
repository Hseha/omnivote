import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/candidate_repository.dart';

final positionsProvider = FutureProvider<List<Position>>((ref) async {
  return await ref.watch(candidateRepositoryProvider).getPositions();
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
    bool clearPositionId = false,
  }) {
    return CandidatesFilter(
      tier: tier ?? this.tier,
      positionId: clearPositionId ? null : (positionId ?? this.positionId),
      search: search ?? this.search,
      grade: grade ?? this.grade,
    );
  }
}

final candidatesFilterProvider = StateProvider<CandidatesFilter>((ref) => CandidatesFilter());

final filteredCandidatesProvider = FutureProvider<List<Candidate>>((ref) async {
  final filter = ref.watch(candidatesFilterProvider);
  
  final repository = ref.watch(candidateRepositoryProvider);
  return await repository.getCandidates(
    positionId: filter.positionId,
    tier: filter.tier.name,
    search: filter.search.isNotEmpty ? filter.search : null,
    grade: filter.grade != 'All Grades' ? filter.grade : null,
  );
});
