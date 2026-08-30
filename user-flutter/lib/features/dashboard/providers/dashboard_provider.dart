import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/registration_model.dart';
import '../../../data/repositories/registration_repository.dart';

final registrationDataProvider = FutureProvider<Registration>((ref) async {
  try {
    final repository = ref.read(registrationRepositoryProvider);
    return await repository.getMyRegistration();
  } catch (e) {
    // Return mock data if backend is not available
    return Registration(
      registrationDate: DateTime.now().subtract(const Duration(days: 5)),
      eligibilityStatus: 'Eligible Voter',
      turnout: Turnout(
        registeredStudents: 1250,
        totalStudents: 1500,
        actualBallotsCast: 840,
      ),
    );
  }
});
