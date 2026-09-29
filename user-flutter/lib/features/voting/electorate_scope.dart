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
/// That distinction matters: with provincial seats scoped per college, an
/// unfiltered list shows every student all ten candidates for Governor. Eight of
/// those are for other colleges, so the student can pick one, watch the submit
/// fail, and learn nothing about why. Mirroring the rule turns a dead end into
/// a list that simply contains their own college.
abstract final class ElectorateScope {
  /// Case- and whitespace-insensitive, matching `Position::valuesMatch()`.
  static bool _matches(String? a, String? b) {
    if (a == null || b == null) return false;
    return a.trim().toLowerCase() == b.trim().toLowerCase();
  }

  /// The value a voter must hold to take part in [position], or null when the
  /// voter cannot. A literal `scope_value` wins; otherwise the seat resolves to
  /// the voter's own value, matching `Position::requiredValueFor()`.
  static String? _requiredValue(Position position, Student? voter) {
    final literal = position.scopeValue;
    if (literal != null && literal.isNotEmpty) return literal;

    final own = _voterValueFor(position, voter);
    if (own == null || own.isEmpty) return null;

    return own;
  }

  /// The voter attribute a scope is evaluated against.
  static String? _voterValueFor(Position position, Student? voter) {
    if (voter == null) return null;

    return switch (position.scopeType) {
      'year_level' => voter.yearLevel,
      'department' => voter.department,
      'course' => voter.course,
      _ => null,
    };
  }

  /// The same attribute as carried by a candidate's own record.
  ///
  /// Returns null when the wire does not carry it — the candidate payload
  /// exposes `department` and `grade_level` but not `course`, so a course-scoped
  /// seat cannot be verified client-side.
  static String? _candidateValueFor(Position position, Candidate candidate) {
    return switch (position.scopeType) {
      'year_level' => candidate.gradeLine,
      'department' => candidate.department,
      'course' => null,
      _ => null,
    };
  }

  /// Whether [voter] may vote in [position] at all.
  ///
  /// A voter whose profile lacks the attribute is refused rather than allowed —
  /// the same fail-closed direction the server takes, so an incomplete profile
  /// cannot become a way around the restriction.
  static bool voterMayVote(Position position, Student? voter) {
    if (position.scopeType == 'global') return true;

    final required = _requiredValue(position, voter);
    if (required == null) return false;

    return _matches(_voterValueFor(position, voter), required);
  }

  /// Whether [candidate] appears on [voter]'s ballot for [position].
  ///
  /// Both sides matter: the voter must be in the electorate *and* the nominee
  /// must belong to it. A year-level representative is nominated from any
  /// college, so only the year has to line up; a governor must be from the
  /// voter's own college.
  static bool candidateIsOnBallot({
    required Position position,
    required Candidate candidate,
    required Student? voter,
  }) {
    if (!voterMayVote(position, voter)) return false;
    if (position.scopeType == 'global') return true;

    final candidateValue = _candidateValueFor(position, candidate);
    if (candidateValue == null || candidateValue.trim().isEmpty) {
      // The client cannot read this attribute off the candidate, so it must not
      // conclude the nominee is ineligible and hide a legitimate pick. Defer to
      // the server, which re-checks the real column.
      return true;
    }

    final required = _requiredValue(position, voter);
    if (required == null) return false;

    return _matches(candidateValue, required);
  }
}
