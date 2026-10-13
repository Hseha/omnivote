import '../../core/utils/safe_json.dart';
import 'position_model.dart';

/// Finalized election outcome for an approved candidacy, exposed by
/// `GET /api/candidacy/me` as `election_outcome` only once polls close
/// (the same disclosure gate public `/api/results` uses).
class CandidacyElectionOutcome {
  /// `pending` (not a winner) | `elected` | `tied`.
  final String status;

  /// Final rank within the position; null before results are finalized.
  final int? rank;
  final int votes;
  final bool certified;

  const CandidacyElectionOutcome({
    required this.status,
    this.rank,
    required this.votes,
    required this.certified,
  });

  bool get isElected => status == 'elected' || certified;
  bool get isTied => status == 'tied';

  factory CandidacyElectionOutcome.fromJson(Map<String, dynamic> json) {
    return CandidacyElectionOutcome(
      status: safeString(json['status'], fallback: 'pending'),
      rank: json['rank'] is int
          ? json['rank'] as int
          : int.tryParse('${json['rank']}'),
      votes: safeInt(json['votes']),
      certified: json['certified'] == true,
    );
  }
}

/// Represents a student's application to run as a candidate.
///
/// Server-side contract (see `Candidate`/`CandidateApplicationRequest`):
///   POST /api/candidate/apply  -> `{ position_id, slogan, platform_statement, photo, certify }`
///   GET  /api/candidacy/me     -> `none | pending | approved | rejected | withdrawn`
///                                 plus position, rejection reason, seat
///                                 competition and (post-close) outcome.
class CandidacyApplication {
  final String positionId;

  /// Full position payload from `GET /api/candidacy/me` (`candidate.position`),
  /// null on bare apply responses that only carry `position_id`.
  final Position? position;
  final String? slogan;
  final String platformStatement;
  final String? partyName;
  final String? photoPath;
  final String? photoUrl;
  final String? status;

  /// Committee-supplied rejection explanation, shown on the My Candidacy
  /// screen. Null unless the application was rejected with a reason.
  final String? rejectionReason;

  /// ISO timestamps from the server; null on bare apply responses.
  final String? createdAt;
  final String? updatedAt;

  /// Pending + approved candidates in this position's race, from
  /// `GET /api/candidacy/me` (`applicants_count`). Null when unknown.
  final int? applicantsCount;

  final CandidacyElectionOutcome? electionOutcome;

  const CandidacyApplication({
    required this.positionId,
    this.position,
    this.slogan,
    required this.platformStatement,
    this.partyName,
    this.photoPath,
    this.photoUrl,
    this.status,
    this.rejectionReason,
    this.createdAt,
    this.updatedAt,
    this.applicantsCount,
    this.electionOutcome,
  });

  factory CandidacyApplication.fromJson(Map<String, dynamic> json) {
    final rawPosition = json['position'];
    return CandidacyApplication(
      positionId: (json['position_id'] ?? json['positionId'] ?? '').toString(),
      position: rawPosition is Map
          ? Position.fromJson(Map<String, dynamic>.from(rawPosition))
          : null,
      slogan: json['slogan'] as String?,
      platformStatement:
          (json['platform_statement'] ?? json['platformStatement'] ?? '').toString(),
      partyName: (json['party_name'] ?? json['partyName']) as String?,
      photoPath: (json['photo_path'] ?? json['photoPath']) as String?,
      photoUrl: (json['photo_url'] ?? json['photoUrl']) as String?,
      status: (json['status'] ?? json['approval_status']) as String?,
      rejectionReason: (json['rejection_reason'] ?? json['rejectionReason'])
          as String?,
      createdAt: (json['created_at'] ?? json['createdAt']) as String?,
      updatedAt: (json['updated_at'] ?? json['updatedAt']) as String?,
      applicantsCount: json['applicants_count'] is int
          ? json['applicants_count'] as int
          : int.tryParse('${json['applicants_count'] ?? json['applicantsCount']}'),
      electionOutcome: json['election_outcome'] is Map
          ? CandidacyElectionOutcome.fromJson(
              Map<String, dynamic>.from(json['election_outcome'] as Map),
            )
          : (json['electionOutcome'] is Map
              ? CandidacyElectionOutcome.fromJson(
                  Map<String, dynamic>.from(json['electionOutcome'] as Map),
                )
              : null),
    );
  }
}
