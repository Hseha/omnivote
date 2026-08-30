enum ElectionPhase { registration, votingOpen, votingClosed, unknown }

class ElectionStatus {
  final ElectionPhase phase;
  final String? phaseLabel;
  final DateTime? votingOpensAt;
  final DateTime? votingClosesAt;

  const ElectionStatus({
    this.phase = ElectionPhase.unknown,
    this.phaseLabel,
    this.votingOpensAt,
    this.votingClosesAt,
  });

  bool get isVotingOpen => phase == ElectionPhase.votingOpen;
  bool get isVotingClosed => phase == ElectionPhase.votingClosed;
  bool get isRegistration => phase == ElectionPhase.registration;

  factory ElectionStatus.fromJson(Map<String, dynamic> json) {
    final phase = _parsePhase(
      json['phase'] ?? json['election_phase'] ?? json['phase_label'] ?? '',
    );
    return ElectionStatus(
      phase: phase,
      phaseLabel: (json['phase_label'] ?? json['phase']) as String?,
      votingOpensAt: _parseDate(json['voting_opens_at']),
      votingClosesAt: _parseDate(json['voting_closes_at']),
    );
  }

  static ElectionPhase _parsePhase(Object? raw) {
    switch (raw?.toString().toLowerCase()) {
      case 'registration':
        return ElectionPhase.registration;
      case 'voting_open':
      case 'voting open':
      case 'active':
        return ElectionPhase.votingOpen;
      case 'voting_closed':
      case 'voting closed':
      case 'closed':
        return ElectionPhase.votingClosed;
      default:
        return ElectionPhase.unknown;
    }
  }

  static DateTime? _parseDate(Object? raw) {
    if (raw == null || raw.toString().isEmpty) return null;
    return DateTime.tryParse(raw.toString());
  }
}
