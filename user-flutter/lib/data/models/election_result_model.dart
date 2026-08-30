class ElectionCandidateResult {
  final String name;
  final String? positionKey;
  final int votes;

  const ElectionCandidateResult({
    required this.name,
    this.positionKey,
    required this.votes,
  });

  factory ElectionCandidateResult.fromJson(Map<String, dynamic> json) {
    return ElectionCandidateResult(
      name: (json['name'] ?? json['candidate_name'] ?? '').toString(),
      positionKey: (json['position_key'] ?? json['position']) as String?,
      votes: (json['votes'] ?? json['count'] ?? 0) as int,
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
