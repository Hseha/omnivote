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
    final candidate = response.data['candidate'];
    return CandidacyApplication.fromJson(
      Map<String, dynamic>.from(candidate as Map? ?? const {}),
    );
  }

  /// Returns `none` when no application exists, otherwise the status string.
  Future<String> getApplicationStatus() async {
    final response = await _candidacyService.getMyApplication();
    final data = response.data;
    if (data == null) return 'none';
    if (data is String) return data;
    if (data is Map) {
      return (data['status'] ?? data['approval_status'] ?? 'none').toString();
    }
    return 'none';
  }
}
