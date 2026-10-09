import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/candidacy_application_model.dart';
import '../services/candidacy_service.dart';

final candidacyRepositoryProvider = Provider<CandidacyRepository>((ref) {
  return CandidacyRepository(ref.read(candidacyServiceProvider));
});

class CandidacyRepository {
  final CandidacyService _candidacyService;

  CandidacyRepository(this._candidacyService);

  Future<CandidacyApplication> submit({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) async {
    final response = await _candidacyService.submit(
      positionId: positionId,
      slogan: slogan,
      platformStatement: platformStatement,
      partyName: partyName,
      photoBytes: photoBytes,
      photoName: photoName,
    );
    return CandidacyApplication.fromJson(
      _candidateMap(response.data),
    );
  }

  /// Edits the caller's campaign (server keeps the position locked once
  /// approved).
  Future<CandidacyApplication> update({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) async {
    final response = await _candidacyService.update(
      positionId: positionId,
      slogan: slogan,
      platformStatement: platformStatement,
      partyName: partyName,
      photoBytes: photoBytes,
      photoName: photoName,
    );
    return CandidacyApplication.fromJson(
      _candidateMap(response.data),
    );
  }

  /// Withdraws/forfeits the caller's candidacy. Returns the resulting status.
  Future<String> withdraw() async {
    final response = await _candidacyService.withdraw();
    final candidate = _candidateMap(response.data);
    return candidate['approval_status']?.toString() ?? 'withdrawn';
  }

  /// Returns the full application for the current student, or `null` when
  /// they have never applied (`GET /candidacy/me` → `{status: none}`).
  Future<CandidacyApplication?> getMyApplication() async {
    final response = await _candidacyService.getMyApplication();
    final data = response.data;
    if (data is! Map) return null;
    if ((data['status'] ?? data['approval_status']) == 'none') return null;
    final candidate = data['candidate'];
    if (candidate is! Map) return null;
    return CandidacyApplication.fromJson(
      Map<String, dynamic>.from(candidate),
    );
  }

  /// Returns `none` when no application exists, otherwise the status string.
  Future<String> getApplicationStatus() async {
    final application = await getMyApplication();
    return application?.status ?? 'none';
  }

  static Map<String, dynamic> _candidateMap(dynamic responseData) {
    final candidate = responseData is Map ? responseData['candidate'] : null;
    return Map<String, dynamic>.from(candidate as Map? ?? const {});
  }
}
