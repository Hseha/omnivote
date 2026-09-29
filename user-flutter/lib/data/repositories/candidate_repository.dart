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
    String? department,
    String? party,
    String? search,
    String? grade,
    int? perPage,
    int? page,
  }) async {
    final response = await _candidateService.getCandidates(
      positionId: positionId,
      tier: tier,
      department: department,
      party: party,
      search: search,
      grade: grade,
      perPage: perPage,
      page: page,
    );
    final List<dynamic> data = response.data['data'] ?? response.data;
    return data.map((json) => Candidate.fromJson(json)).toList();
  }

  /// Fetches *every* approved candidate across all pages of GET /candidates.
  /// Used to resolve opaque `candidate_ref` values on the My Ballot screen,
  /// where missing rows would otherwise render as the literal "Candidate".
  Future<List<Candidate>> getAllCandidates() async {
    final all = <Candidate>[];
    var page = 1;
    while (true) {
      final List<Candidate> batch = await getCandidates(
        perPage: 100,
        page: page,
      );
      all.addAll(batch);
      if (batch.length < 100) break;
      page++;
    }
    return all;
  }

  /// Fetches *every* approved candidate in a tier (optionally one party) across
  /// all pages. The grouped national checklist needs this: a single page would
  /// silently drop candidates off the end of the ballot as party count grows,
  /// which reads as "that person is not running" to the voter.
  Future<List<Candidate>> getAllCandidatesForTier({
    required String tier,
    String? party,
  }) async {
    final all = <Candidate>[];
    var page = 1;
    while (true) {
      final List<Candidate> batch = await getCandidates(
        tier: tier,
        party: party,
        perPage: 100,
        page: page,
      );
      all.addAll(batch);
      if (batch.length < 100) break;
      page++;
    }
    return all;
  }

  Future<List<String>> getDepartments() async {
    final response = await _candidateService.getDepartments();
    final List<dynamic> data = response.data['data'] ?? response.data;
    return data.map((e) => e.toString()).toList();
  }

  Future<List<String>> getParties() async {
    final response = await _candidateService.getParties();
    final List<dynamic> data = response.data['data'] ?? response.data;
    return data.map((json) => (json['name'] ?? json).toString()).toList();
  }

  Future<Candidate> getCandidate(String id) async {
    final response = await _candidateService.getCandidate(id);
    return Candidate.fromJson(response.data);
  }
}
