import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import 'api_client.dart';

final registrationServiceProvider = Provider<RegistrationService>((ref) {
  return RegistrationService(ref.read(apiClientProvider));
});

class RegistrationService {
  final Dio _dio;

  RegistrationService(this._dio);

  Future<Response> getMyRegistration() async {
    return await _dio.get(ApiConstants.registrationMe);
  }
}
