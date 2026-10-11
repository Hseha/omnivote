import '../../../data/models/candidate_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/models/student_model.dart';

/// Client-side mirror of the server's electorate rules.
///
/// The server is authoritative — `VoteController::resolveSelections()` re-checks
/// every seat and every nominee at submit time and answers `422` for a ballot
/// that breaches them. This exists purely so the ballot does not *offer* a
/// choice the server will reject.
///
/// That distinction matters: with a seat scoped per college, an unfiltered list
/// shows every student all the candidates. The rest are for other colleges, so
/// the student can pick one, watch the submit fail, and never learn why. The
/// seat may also carry a SECOND scope dimension (the Year Level Representative
/// is scoped by college AND year level), so a seat is in scope only when EVERY
/// dimension lines up.
class ElectorateScope {
  /// The scope dimensions a seat is restricted by. Global seats return an empty
  /// list. Mirrors `Position::scopeDimensions()`.
  static List<({String type, String? value})> _dimensions(Position position) {
    final dims = <({String type, String? value})>[];

    void add(String? type, String? value) {
      final t = type?.trim().toLowerCase();
      if (t == null || t.isEmpty || t == 'global') return;
      if (!const {'year_level', 'department', 'course'}.contains(t)) return;
      final v = (value != null && value.trim().isNotEmpty) ? value.trim() : null;
      dims.add((type: t, value: v));
    }

    add(position.scopeType, position.scopeValue);
    add(position.scopeType2, position.scopeValue2);

    return dims;
  }

  /// The voter attribute a scope type is evaluated against.
  static String? _voterValueFor(String type, Student? voter) {
    if (voter == null) return null;

    return switch (type) {
      'year_level' => voter.yearLevel,
      'department' => voter.department,
      'course' => voter.course,
      _ => null,
    };
  }

  /// The same attribute as carried by a candidate's own record.
  ///
  /// Returns null when the wire does not carry it — the candidate payload
  /// exposes `department` and `grade_level` (year level) but not `course`, so a
  /// course-scoped dimension cannot be verified client-side.
  static String? _candidateValueFor(String type, Candidate candidate) {
    return switch (type) {
      'year_level' => candidate.gradeLine,
      'department' => candidate.department,
      _ => null,
    };
  }

  /// Whether a voter may vote in this seat at all: every scope dimension must
  /// line up. A voter whose attribute is unknown is refused rather than allowed,
  /// so a missing profile cannot become a way around the restriction.
  static bool voterMayVote(Position position, Student? voter) {
    final dims = _dimensions(position);
    if (dims.isEmpty) return true;
    if (voter == null) return false;

    for (final dim in dims) {
      final voterValue = _voterValueFor(dim.type, voter);
      if (voterValue == null || voterValue.trim().isEmpty) return false;

      final required = dim.value ?? voterValue;
      if (!_matches(voterValue, required)) return false;
    }

    return true;
  }

  /// Whether a candidate is offered on this voter's ballot for this seat.
  ///
  /// The voter must be in scope, and every scope dimension the candidate's own
  /// record carries must match the voter's. A dimension the client cannot read
  /// off the candidate is skipped (the server re-checks the real column) rather
  /// than hiding a legitimate pick.
  static bool candidateIsOnBallot({
    required Position position,
    required Candidate candidate,
    required Student? voter,
  }) {
    if (!voterMayVote(position, voter)) return false;

    final dims = _dimensions(position);
    if (dims.isEmpty) return true;

    for (final dim in dims) {
      final candidateValue = _candidateValueFor(dim.type, candidate);
      if (candidateValue == null || candidateValue.trim().isEmpty) {
        continue;
      }

      final voterValue = _voterValueFor(dim.type, voter);
      if (voterValue == null || voterValue.trim().isEmpty) return false;

      final required = dim.value ?? voterValue;
      if (!_matches(candidateValue, required)) return false;
    }

    return true;
  }

  /// Case- and whitespace-insensitive comparison, mirroring
  /// `Position::valuesMatch()`.
  static bool _matches(String a, String b) {
    return a.trim().toLowerCase() == b.trim().toLowerCase();
  }
}
