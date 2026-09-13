import '../../core/utils/safe_json.dart';

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
      registrationDate:
          DateTime.tryParse(json['registration_date']?.toString() ?? '') ??
              DateTime.now(),
      eligibilityStatus: safeString(json['eligibility_status']),
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
      // Tolerant parsing: backend may serialize counts as ints or strings.
      registeredStudents: safeInt(json['registered_students']),
      totalStudents: safeInt(json['total_students']),
      actualBallotsCast: safeInt(json['actual_ballots_cast']),
    );
  }
}
