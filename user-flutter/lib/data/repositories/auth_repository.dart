import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/student_model.dart';
import '../services/auth_service.dart';
import '../services/secure_storage_service.dart';

final Provider<AuthRepository> authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepository(
    ref.read(authServiceProvider),
    ref.read(secureStorageServiceProvider),
  );
});

class AuthRepository {
  final AuthService _authService;
  final SecureStorageService _storage;

  AuthRepository(this._authService, this._storage);

  Future<Student> login(String studentId, String password) async {
    final response = await _authService.login(studentId, password);
    final token = response.data['token'];
    final student = Student.fromJson(response.data['student']);
    
    await _storage.saveToken(token);
    return student;
  }

  Future<Student> register(Map<String, dynamic> data) async {
    final response = await _authService.register(data);
    final token = response.data['token'];
    final student = Student.fromJson(response.data['student']);
    
    await _storage.saveToken(token);
    return student;
  }

  Future<void> logout() async {
    try {
      await _authService.logout();
    } finally {
      await _storage.deleteToken();
    }
  }

  Future<Student?> getAuthenticatedUser() async {
    final token = await _storage.getToken();
    if (token == null) return null;

    try {
      final response = await _authService.getMe();
      return Student.fromJson(response.data);
    } catch (e) {
      await _storage.deleteToken();
      return null;
    }
  }
}
