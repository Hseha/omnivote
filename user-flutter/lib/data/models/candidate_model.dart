import 'position_model.dart';

/// Parses a JSON candidate. The backend emits snake_case wire keys, but the
/// original client used camelCase; both are tolerated here so the model keeps
/// working regardless of which shape the API returns.
class Candidate {
  final String id;
  final String name;
  final String photoUrl;
  final Position position;
  final String gradeLine;
  final String slogan;
  final List<String> platformPoints;
  final List<String> qualifications;
  final String? videoUrl;
  final String? party;

  /// Lowercase approval status emitted on the wire (`pending|approved|rejected`).
  final String? approvalStatus;

  Candidate({
    required this.id,
    required this.name,
    required this.photoUrl,
    required this.position,
    required this.gradeLine,
    required this.slogan,
    required this.platformPoints,
    required this.qualifications,
    this.videoUrl,
    this.party,
    this.approvalStatus,
  });

  factory Candidate.fromJson(Map<String, dynamic> json) {
    final rawPosition = json['position'];
    Position position;
    if (rawPosition is Map<String, dynamic>) {
      position = Position.fromJson(rawPosition);
    } else {
      // A bare/string position identifier (e.g. position_id on candidacy).
      position = Position.fromJson({
        'id': rawPosition,
        'label': (json['position_label'] ?? json['position_name'] ?? '') as Object?,
        'description': '',
      });
    }

    Object? valueOr(Map<String, dynamic> map, List<String> keys) {
      for (final k in keys) {
        if (map.containsKey(k)) return map[k];
      }
      return null;
    }

    return Candidate(
      id: (valueOr(json, const ['id']) ?? '').toString(),
      name: (valueOr(json, const ['name', 'full_name']) ?? '').toString(),
      photoUrl:
          (valueOr(json, const ['photo_url', 'photoUrl', 'avatar']) ?? '').toString(),
      position: position,
      gradeLine:
          (valueOr(json, const ['grade_line', 'gradeLevel', 'gradeLine']) ?? '').toString(),
      slogan: (valueOr(json, const ['slogan']) ?? '').toString(),
      platformPoints: List<String>.from(
        valueOr(json, const ['platform_points', 'platformPoints']) as List? ?? const [],
      ),
      qualifications: List<String>.from(
        valueOr(json, const ['qualifications']) as List? ?? const [],
      ),
      videoUrl: (valueOr(json, const ['video_url', 'videoUrl']) as String?),
      party: (valueOr(json, const ['party_name', 'party']) as String?),
      approvalStatus:
          (valueOr(json, const ['approval_status', 'status']) as String?),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'photo_url': photoUrl,
      'position': position.toJson(),
      'grade_line': gradeLine,
      'slogan': slogan,
      'platform_points': platformPoints,
      'qualifications': qualifications,
      'video_url': videoUrl,
      'party_name': party,
      'approval_status': approvalStatus,
    };
  }
}
