import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/candidate_repository.dart';
import '../../auth/providers/auth_provider.dart';

final positionsProvider = FutureProvider<List<Position>>((ref) async {
  return await ref.watch(candidateRepositoryProvider).getPositions();
});

final departmentsProvider = FutureProvider<List<String>>((ref) async {
  return await ref.watch(candidateRepositoryProvider).getDepartments();
});

final partiesProvider = FutureProvider<List<String>>((ref) async {
  return await ref.watch(candidateRepositoryProvider).getParties();
});

class CandidatesFilter {
  final PositionTier tier;
  final String? positionId;
  final String? department;
  final String? party;
  final String search;
  final String? grade;

  CandidatesFilter({
    this.tier = PositionTier.national,
    this.positionId,
    this.department,
    this.party,
    this.search = '',
    this.grade,
  });

  CandidatesFilter copyWith({
    PositionTier? tier,
    String? positionId,
    String? department,
    String? party,
    String? search,
    String? grade,
    bool clearPositionId = false,
    bool clearDepartment = false,
    bool clearParty = false,
  }) {
    return CandidatesFilter(
      tier: tier ?? this.tier,
      positionId: clearPositionId ? null : (positionId ?? this.positionId),
      department: clearDepartment ? null : (department ?? this.department),
      party: clearParty ? null : (party ?? this.party),
      search: search ?? this.search,
      grade: grade ?? this.grade,
    );
  }
}

final candidatesFilterProvider = StateProvider<CandidatesFilter>((ref) => CandidatesFilter());

final filteredCandidatesProvider = FutureProvider<List<Candidate>>((ref) async {
  final filter = ref.watch(candidatesFilterProvider);
  final student = ref.watch(authProvider).student;
  final repository = ref.watch(candidateRepositoryProvider);

  // Provincial candidates are departmental races: a student always sees only
  // their own department's slate (when known), plus whatever party they pick.
  final isProvincial = filter.tier == PositionTier.provincial;
  final studentDepartment = student?.department?.trim();
  final hasOwnDepartment = isProvincial &&
      studentDepartment != null &&
      studentDepartment.isNotEmpty;
  final department = hasOwnDepartment ? studentDepartment : filter.department;

  return await repository.getCandidates(
    positionId: filter.positionId,
    tier: filter.tier.name,
    department: department,
    party: filter.party,
    search: filter.search.isNotEmpty ? filter.search : null,
    grade: filter.grade != 'All Grades' ? filter.grade : null,
    perPage: 100,
  );
});