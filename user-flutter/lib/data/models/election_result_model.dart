import '../../core/utils/safe_json.dart';

class ElectionCandidateResult {
  final String name;
  final String? positionKey;
  final int votes;
  final int? candidateId;

  /// Party label (e.g. ASLE, SVEA) when the candidate runs under one.
  final String? partyName;

  /// `national` or `provincial` — drives the winners-first grouping.
  final String? tier;

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
    this.partyName,
    this.tier,
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
      partyName: (json['party_name'] ?? json['party'])?.toString(),
      tier: json['tier']?.toString(),
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

  /// Electorate label for a scoped seat ("CCS · Year 1"); null for a
  /// global seat.
  final String? scopeLabel;

  /// `national` or `provincial`; falls back to the first candidate's tier.
  final String? tier;

  final List<ElectionCandidateResult> candidates;

  const ElectionResult({
    required this.positionKey,
    this.positionLabel,
    this.scopeLabel,
    this.tier,
    required this.candidates,
  });

  bool get isScoped => scopeLabel != null && scopeLabel!.isNotEmpty;

  /// The position's plain title with any " — {scope}" suffix stripped, so
  /// the 20 college-year groups of one scoped seat can nest under a single
  /// master card.
  String get baseLabel {
    final label = positionLabel ?? positionKey;
    final suffix = scopeLabel;
    if (suffix == null || suffix.isEmpty) return label;
    final marker = ' — $suffix';
    return label.endsWith(marker)
        ? label.substring(0, label.length - marker.length)
        : label;
  }

  factory ElectionResult.fromJson(Map<String, dynamic> json) {
    final candidates = (json['candidates'] as List? ?? const [])
        .map((c) => ElectionCandidateResult.fromJson(Map<String, dynamic>.from(c as Map)))
        .toList();
    return ElectionResult(
      positionKey: (json['position_key'] ?? json['position'] ?? '').toString(),
      positionLabel: (json['position_label'] ?? json['label'] ?? json['position']) as String?,
      scopeLabel: json['scope_label'] as String?,
      tier: json['tier']?.toString() ?? (candidates.isNotEmpty ? candidates.first.tier : null),
      candidates: candidates,
    );
  }
}