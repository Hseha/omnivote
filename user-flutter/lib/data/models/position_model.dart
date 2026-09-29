import '../../core/utils/safe_json.dart';

enum PositionTier { national, provincial }

class Position {
  final String id;
  final String slug;
  final String label;
  final PositionTier tier;
  final int seatCount;
  final String description;

  /// Who may vote in this seat, as declared by the registrar.
  ///
  /// `global` is every student; `year_level` / `department` / `course` restrict
  /// the seat to one electorate, and [scopeValue] pins it to a literal value or
  /// stays null to mean "each voter's own value for that attribute".
  ///
  /// The server is authoritative on this and re-checks every submission; the
  /// client copies it only so the ballot can hide seats and candidates the
  /// voter has no business seeing, instead of offering a row that 422s.
  final String scopeType;
  final String? scopeValue;

  Position({
    required this.id,
    this.slug = '',
    required this.label,
    required this.tier,
    this.seatCount = 1,
    required this.description,
    this.scopeType = 'global',
    this.scopeValue,
  });

  factory Position.fromJson(Map<String, dynamic> json) {
    return Position(
      id: (json['id'] ?? json['slug'] ?? '').toString(),
      slug: (json['slug'] ?? json['id'] ?? '').toString(),
      label: (json['label'] ?? json['name'] ?? '').toString(),
      tier: _parseTier(json['tier'] ?? json['position_tier']),
      seatCount: safeInt(json['seat_count'] ?? json['seatCount'], fallback: 1),
      description: (json['description'] ?? '').toString(),
      scopeType: _parseScopeType(json['scope_type'] ?? json['scopeType']),
      scopeValue: _parseScopeValue(json['scope_value'] ?? json['scopeValue']),
    );
  }

  /// A missing/unknown scope is treated as `global`, matching the server's
  /// `($this->scope_type ?? SCOPE_GLOBAL)` fallback. Guessing "restricted"
  /// instead would hide the whole national ballot from a stale client.
  static String _parseScopeType(Object? raw) {
    if (raw is String) {
      final value = raw.trim().toLowerCase();
      if (const {'year_level', 'department', 'course'}.contains(value)) {
        return value;
      }
    }
    return 'global';
  }

  static String? _parseScopeValue(Object? raw) {
    if (raw is! String) return null;
    final value = raw.trim();
    return value.isEmpty ? null : value;
  }

  static PositionTier _parseTier(Object? raw) {
    if (raw is String) {
      if (raw.toLowerCase() == 'provincial') return PositionTier.provincial;
      if (raw.toLowerCase() == 'national') return PositionTier.national;
    }
    return PositionTier.national;
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'label': label,
      'tier': tier.name,
      'seat_count': seatCount,
      'description': description,
      'scope_type': scopeType,
      'scope_value': scopeValue,
    };
  }
}
