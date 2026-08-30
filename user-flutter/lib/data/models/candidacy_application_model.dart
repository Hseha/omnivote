/// Represents a student's application to run as a candidate.
///
/// Server-side contract (see `Candidate`/`CandidateApplicationRequest`):
///   POST /api/candidate/apply  -> `{ position_id, slogan, platform_statement, photo, certify }`
///   GET  /api/candidacy/me     -> `none | pending | approved | rejected`
class CandidacyApplication {
  final String positionId;
  final String? slogan;
  final String platformStatement;
  final String? partyName;
  final String? photoPath;
  final String? status;

  const CandidacyApplication({
    required this.positionId,
    this.slogan,
    required this.platformStatement,
    this.partyName,
    this.photoPath,
    this.status,
  });

  factory CandidacyApplication.fromJson(Map<String, dynamic> json) {
    return CandidacyApplication(
      positionId: (json['position_id'] ?? json['positionId'] ?? '').toString(),
      slogan: json['slogan'] as String?,
      platformStatement:
          (json['platform_statement'] ?? json['platformStatement'] ?? '').toString(),
      partyName: (json['party_name'] ?? json['partyName']) as String?,
      photoPath: (json['photo_path'] ?? json['photoPath']) as String?,
      status: (json['status'] ?? json['approval_status']) as String?,
    );
  }
}
