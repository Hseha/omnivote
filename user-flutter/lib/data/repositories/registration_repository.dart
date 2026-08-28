import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/registration_model.dart';
import '../services/registration_service.dart';

final registrationRepositoryProvider = Provider<RegistrationRepository>((ref) {
  return RegistrationRepository(ref.read(registrationServiceProvider));
});

class RegistrationRepository {
  final RegistrationService _registrationService;

  RegistrationRepository(this._registrationService);

  Future<Registration> getMyRegistration() async {
    final response = await _registrationService.getMyRegistration();
    return Registration.fromJson(response.data);
  }
}
