import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/registration_model.dart';
import '../../../data/repositories/registration_repository.dart';

final registrationDataProvider = FutureProvider<Registration>((ref) async {
  final repository = ref.read(registrationRepositoryProvider);
  return await repository.getMyRegistration();
});
