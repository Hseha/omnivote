import '../../core/utils/safe_json.dart';

class ElectionCandidateResult {
  final String name;
  final String? positionKey;
  final int votes;
  final int? candidateId;

  /// Backend verdict after results are finalized: `elected`, `tied`, or
  /// `pending` (not officially decided yet).
  final String? electionStatus;

  /// 1-based seat rank when the candidate won outright.
  final int? winnerRank;

  final bool certifiedWinner;

  const ElectionCandidateResult({
    required this.name,
    this.positionKey,
    required this.votes,
    this.candidateId,
    this.electionStatus,
    this.winnerRank,
    this.certifiedWinner = false,
  });

  bool get isElected => electionStatus == 'elected' || certifiedWinner;

  bool get isTied => electionStatus == 'tied';

  factory ElectionCandidateResult.fromJson(Map<String, dynamic> json) {
    return ElectionCandidateResult(
      name: (json['name'] ?? json['candidate_name'] ?? '').toString(),
      positionKey: (json['position_key'] ?? json['position']) as String?,
      votes: safeInt(json['votes'] ?? json['count']),
      candidateId: json['id'] is int
          ? json['id'] as int
          : int.tryParse('${json['id'] ?? ''}'),
      electionStatus: json['election_status'] as String?,
      winnerRank: json['winner_rank'] is int
          ? json['winner_rank'] as int
          : int.tryParse('${json['winner_rank'] ?? ''}'),
      certifiedWinner: json['certified_winner'] == true,
    );
  }
}

class ElectionResult {
  final String positionKey;
  final String? positionLabel;
  final List<ElectionCandidateResult> candidates;

  const ElectionResult({
    required this.positionKey,
    this.positionLabel,
    required this.candidates,
  });

  factory ElectionResult.fromJson(Map<String, dynamic> json) {
    return ElectionResult(
      positionKey: (json['position_key'] ?? json['position'] ?? '').toString(),
      positionLabel: (json['position_label'] ?? json['label'] ?? json['position']) as String?,
      candidates: (json['candidates'] as List? ?? const [])
          .map((c) => ElectionCandidateResult.fromJson(Map<String, dynamic>.from(c as Map)))
          .toList(),
    );
  }
}