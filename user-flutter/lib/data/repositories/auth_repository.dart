import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/student_model.dart';
import '../services/api_client.dart';
import '../services/auth_service.dart';

final Provider<AuthRepository> authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepository(
    ref.read(authServiceProvider),
    ref.read(tokenStoreProvider),
  );
});

class AuthRepository {
  final AuthService _authService;
  final TokenStore _tokenStore;

  AuthRepository(this._authService, this._tokenStore);

  Future<Student> login({required String email, required String password}) async {
    final response = await _authService.login(email: email, password: password);
    final token = response.data['token'] as String?;
    final studentData = response.data['student'];

    if (token == null || token.isEmpty) {
      throw StateError('Server did not return an access token');
    }

    final student = Student.fromJson(
      Map<String, dynamic>.from(studentData as Map),
    );

    await _tokenStore.save(token);
    return student;
  }

  Future<Student> register(Map<String, dynamic> data) async {
    final response = await _authService.register(data);
    final token = response.data['token'] as String?;
    final studentData = response.data['student'];

    if (token == null || token.isEmpty) {
      throw StateError('Server did not return an access token');
    }

    final student = Student.fromJson(
      Map<String, dynamic>.from(studentData as Map),
    );

    await _tokenStore.save(token);
    return student;
  }

  Future<void> logout() async {
    try {
      await _authService.logout();
    } finally {
      await _tokenStore.delete();
    }
  }

  Future<Student?> getAuthenticatedUser() async {
    final token = await _tokenStore.read();
    if (token == null || token.isEmpty) return null;

    try {
      final response = await _authService.getMe();
      // The backend `me` endpoint returns `{ user: {...} }`; tolerate a bare
      // student object as well for forward compatibility.
      final raw = response.data;
      final studentData = raw is Map && raw.containsKey('user') ? raw['user'] : raw;
      return Student.fromJson(Map<String, dynamic>.from(studentData as Map));
    } catch (_) {
      await _tokenStore.delete();
      return null;
    }
  }
}
