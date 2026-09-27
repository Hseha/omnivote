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

  Future<Response> login({required String email, required String password}) async {
    return await _dio.post(ApiConstants.login, data: {
      'email': email,
      'password': password,
    });
  }

  Future<Response> logout() async {
    return await _dio.post(ApiConstants.logout);
  }

  Future<Response> getMe() async {
    return await _dio.get(ApiConstants.me);
  }

  Future<Response> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    return await _dio.post(ApiConstants.changePassword, data: {
      'current_password': currentPassword,
      'password': newPassword,
      'password_confirmation': newPassword,
    });
  }

  /// Redeems a registrar-issued activation code to set a new password.
  ///
  /// Deliberately unauthenticated: the student cannot sign in precisely because
  /// the account is locked out, so this must work without a bearer token. The
  /// backend answers with a generic success message either way and relies on the
  /// returned token-less body for UX, not for confirmation.
  Future<Response> resetPasswordWithCode({
    required String studentId,
    required String code,
    required String newPassword,
  }) async {
    return await _dio.post(ApiConstants.resetPasswordWithCode, data: {
      'student_id': studentId,
      'code': code,
      'password': newPassword,
      'password_confirmation': newPassword,
    });
  }
}
