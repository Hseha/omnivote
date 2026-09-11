class Student {
  final String id;
  final String name;
  final String studentId;
  final String email;
  final String? role;
  final String? gradeLevel;
  final String? yearLevel;
  final String? blockNumber;
  final String? course;
  final String? homeroom;
  final String? avatarUrl;
  final bool hasVoted;

  const Student({
    required this.id,
    required this.name,
    required this.studentId,
    required this.email,
    this.role,
    this.gradeLevel,
    this.yearLevel,
    this.blockNumber,
    this.course,
    this.homeroom,
    this.avatarUrl,
    this.hasVoted = false,
  });

  /// Tolerant parser for the Laravel user payload.
  ///
  /// The API omits UI-only keys (`grade_level`, `course`, `homeroom`) entirely
  /// and returns null for optional columns (`year_level`, `block_number`,
  /// `email_verified_at`, `voted_at`), so every lookup must fall back instead
  /// of being forced into a non-nullable `String` — a null there used to throw
  /// `type 'Null' is not a subtype of type 'String'` and surface as a bogus
  /// "Invalid credentials" login error.
  factory Student.fromJson(Map<String, dynamic> json) {
    String? strOf(dynamic value) => value?.toString();

    return Student(
      id: (json['id'] ?? '').toString(),
      name: (json['name'] ?? json['full_name'] ?? '').toString(),
      studentId: (json['student_id'] ?? json['studentId'] ?? '').toString(),
      email: (json['email'] ?? '').toString(),
      role: strOf(json['role']),
      gradeLevel: strOf(json['grade_level'] ?? json['gradeLevel']),
      yearLevel: strOf(json['year_level'] ?? json['yearLevel']),
      blockNumber: strOf(json['block_number'] ?? json['blockNumber']),
      course: strOf(json['course']),
      homeroom: strOf(json['homeroom']),
      avatarUrl: strOf(json['avatar_url'] ?? json['photo_url']),
      hasVoted: json['has_voted'] == true || json['has_voted'] == 1,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'student_id': studentId,
      'email': email,
      'role': role,
      'grade_level': gradeLevel,
      'year_level': yearLevel,
      'block_number': blockNumber,
      'course': course,
      'homeroom': homeroom,
      'avatar_url': avatarUrl,
      'has_voted': hasVoted,
    };
  }
}
