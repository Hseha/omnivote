/// A draft ballot: maps a `position_key` to the list of selected candidate
/// references/ids. Supports single-select (1) and multi-select (senator, up to
/// 12) contests.
class Ballot {
  final Map<String, List<String>> selections;

  const Ballot({this.selections = const {}});

  Ballot copyWith({Map<String, List<String>>? selections}) {
    return Ballot(selections: selections ?? this.selections);
  }

  bool get isEmpty => selections.isEmpty;

  /// Whether a given position has been filled (respecting multi-select seats).
  bool isEmptyPosition(String positionKey) {
    return (selections[positionKey] ?? []).isEmpty;
  }

  /// Serializes to the wire shape expected by the submit endpoint:
  /// `{ selections: { position_key: candidate_ref } }`
  Map<String, dynamic> toSubmitPayload() {
    final compact = <String, dynamic>{};
    selections.forEach((key, value) {
      if (value.isNotEmpty) {
        // For multi-select positions we send a single candidate per position
        // entry; the backend `/vote` endpoint maps one candidate per position.
        if (value.length == 1) {
          compact[key] = value.first;
        } else {
          compact[key] = value;
        }
      }
    });
    return {'selections': compact};
  }
}
