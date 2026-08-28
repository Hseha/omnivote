import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/candidate_model.dart';
import '../models/position_model.dart';
import '../services/candidate_service.dart';

final candidateRepositoryProvider = Provider<CandidateRepository>((ref) {
  return CandidateRepository(ref.read(candidateServiceProvider));
});

class CandidateRepository {
  final CandidateService _candidateService;

  CandidateRepository(this._candidateService);

  Future<List<Position>> getPositions() async {
    final response = await _candidateService.getPositions();
    final List<dynamic> data = response.data;
    return data.map((json) => Position.fromJson(json)).toList();
  }

  Future<List<Candidate>> getCandidates({
    String? positionId,
    String? tier,
    String? search,
    String? grade,
  }) async {
    final response = await _candidateService.getCandidates(
      positionId: positionId,
      tier: tier,
      search: search,
      grade: grade,
    );
    final List<dynamic> data = response.data['data'] ?? response.data;
    return data.map((json) => Candidate.fromJson(json)).toList();
  }

  Future<Candidate> getCandidate(String id) async {
    final response = await _candidateService.getCandidate(id);
    return Candidate.fromJson(response.data);
  }
}
