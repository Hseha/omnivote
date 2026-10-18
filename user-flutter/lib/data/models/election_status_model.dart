enum ElectionPhase { registration, registrationClosed, votingOpen, votingClosed, unknown }

class ElectionStatus {
  final ElectionPhase phase;
  final String? phaseLabel;
  final DateTime? votingOpensAt;
  final DateTime? votingClosesAt;
  final DateTime? registrationOpensAt;
  final DateTime? registrationClosesAt;
  final DateTime? serverTime;

  const ElectionStatus({
    this.phase = ElectionPhase.unknown,
    this.phaseLabel,
    this.votingOpensAt,
    this.votingClosesAt,
    this.registrationOpensAt,
    this.registrationClosesAt,
    this.serverTime,
  });

  bool get isVotingOpen => phase == ElectionPhase.votingOpen;
  bool get isVotingClosed => phase == ElectionPhase.votingClosed;
  bool get isRegistration => phase == ElectionPhase.registration;
  bool get isRegistrationClosed => phase == ElectionPhase.registrationClosed;

  /// Durations until each boundary, or null when the target is unknown or
  /// already past. Screens use these to drive live countdown timers.
  Duration? get registrationClosesIn {
    final target = registrationClosesAt;
    if (target == null) return null;
    final remaining = target.difference(DateTime.now());
    return remaining.isNegative ? Duration.zero : remaining;
  }

  Duration? get votingOpensIn {
    final target = votingOpensAt;
    if (target == null) return null;
    final remaining = target.difference(DateTime.now());
    return remaining.isNegative ? Duration.zero : remaining;
  }

  Duration? get votingClosesIn {
    final target = votingClosesAt;
    if (target == null) return null;
    final remaining = target.difference(DateTime.now());
    return remaining.isNegative ? Duration.zero : remaining;
  }

  factory ElectionStatus.fromJson(Map<String, dynamic> json) {
    final phase = _parsePhase(
      json['phase'] ?? json['election_phase'] ?? json['phase_label'] ?? '',
    );
    return ElectionStatus(
      phase: phase,
      phaseLabel: (json['phase_label'] ?? json['phase']) as String?,
      votingOpensAt: _parseDate(json['voting_opens_at']),
      votingClosesAt: _parseDate(json['voting_closes_at']),
      registrationOpensAt: _parseDate(json['registration_opens_at']),
      registrationClosesAt: _parseDate(json['registration_closes_at']),
      serverTime: _parseDate(json['server_time']),
    );
  }

  static ElectionPhase _parsePhase(Object? raw) {
    switch (raw?.toString().toLowerCase()) {
      case 'registration':
        return ElectionPhase.registration;
      case 'registration_closed':
      case 'registration closed':
        return ElectionPhase.registrationClosed;
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
