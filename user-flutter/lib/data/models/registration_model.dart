class Registration {
  final DateTime registrationDate;
  final String eligibilityStatus;
  final Turnout turnout;

  Registration({
    required this.registrationDate,
    required this.eligibilityStatus,
    required this.turnout,
  });

  factory Registration.fromJson(Map<String, dynamic> json) {
    return Registration(
      registrationDate: DateTime.parse(json['registration_date']),
      eligibilityStatus: json['eligibility_status'],
      turnout: Turnout.fromJson(json['turnout']),
    );
  }
}

class Turnout {
  final int registeredStudents;
  final int totalStudents;
  final int actualBallotsCast;

  Turnout({
    required this.registeredStudents,
    required this.totalStudents,
    required this.actualBallotsCast,
  });

  factory Turnout.fromJson(Map<String, dynamic> json) {
    return Turnout(
      registeredStudents: json['registered_students'],
      totalStudents: json['total_students'],
      actualBallotsCast: json['actual_ballots_cast'],
    );
  }
}
