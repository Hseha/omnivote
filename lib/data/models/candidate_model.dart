import 'position_model.dart';

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
  });

  factory Candidate.fromJson(Map<String, dynamic> json) {
    return Candidate(
      id: json['id'],
      name: json['name'],
      photoUrl: json['photoUrl'],
      position: Position.fromJson(json['position']),
      gradeLine: json['gradeLine'],
      slogan: json['slogan'],
      platformPoints: List<String>.from(json['platformPoints'] ?? []),
      qualifications: List<String>.from(json['qualifications'] ?? []),
      videoUrl: json['videoUrl'],
      party: json['party'],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'photoUrl': photoUrl,
      'position': position.toJson(),
      'gradeLine': gradeLine,
      'slogan': slogan,
      'platformPoints': platformPoints,
      'qualifications': qualifications,
      'videoUrl': videoUrl,
      'party': party,
    };
  }
}
