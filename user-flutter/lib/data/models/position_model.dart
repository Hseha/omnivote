enum PositionTier { school, provincial }

class Position {
  final String id;
  final String label;
  final PositionTier tier;
  final int seatCount;
  final String description;

  Position({
    required this.id,
    required this.label,
    required this.tier,
    this.seatCount = 1,
    required this.description,
  });

  factory Position.fromJson(Map<String, dynamic> json) {
    return Position(
      id: (json['id'] ?? json['slug'] ?? '').toString(),
      label: (json['label'] ?? json['name'] ?? '').toString(),
      tier: _parseTier(json['tier'] ?? json['position_tier']),
      seatCount: (json['seat_count'] ?? json['seatCount'] ?? 1) as int,
      description: (json['description'] ?? '').toString(),
    );
  }

  static PositionTier _parseTier(Object? raw) {
    if (raw is String) {
      if (raw.toLowerCase() == 'provincial') return PositionTier.provincial;
    }
    return PositionTier.school;
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'label': label,
      'tier': tier.name,
      'seat_count': seatCount,
      'description': description,
    };
  }
}
