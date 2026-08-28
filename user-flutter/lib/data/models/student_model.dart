class Student {
  final String id;
  final String name;
  final String studentId;
  final String gradeLevel;
  final String course;
  final String homeroom;
  final String? avatarUrl;
  final String email;

  Student({
    required this.id,
    required this.name,
    required this.studentId,
    required this.gradeLevel,
    required this.course,
    required this.homeroom,
    this.avatarUrl,
    required this.email,
  });

  factory Student.fromJson(Map<String, dynamic> json) {
    return Student(
      id: json['id'].toString(),
      name: json['name'],
      studentId: json['student_id'],
      gradeLevel: json['grade_level'],
      course: json['course'],
      homeroom: json['homeroom'],
      avatarUrl: json['avatar_url'],
      email: json['email'],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'student_id': studentId,
      'grade_level': gradeLevel,
      'course': course,
      'homeroom': homeroom,
      'avatar_url': avatarUrl,
      'email': email,
    };
  }
}
