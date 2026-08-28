import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import 'api_client.dart';

final Provider<AuthService> authServiceProvider = Provider<AuthService>((ref) {
  return AuthService(ref.read(apiClientProvider));
});

class AuthService {
  final Dio _dio;

  AuthService(this._dio);

  Future<Response> login(String studentId, String password) async {
    return await _dio.post(ApiConstants.login, data: {
      'student_id': studentId,
      'password': password,
    });
  }

  Future<Response> register(Map<String, dynamic> data) async {
    return await _dio.post(ApiConstants.register, data: data);
  }

  Future<Response> logout() async {
    return await _dio.post(ApiConstants.logout);
  }

  Future<Response> getMe() async {
    return await _dio.get(ApiConstants.me);
  }
}
