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

  Future<LoginResult> login({required String email, required String password}) async {
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
    return LoginResult(
      student: student,
      mustChangePassword: response.data['must_change_password'] == true,
    );
  }

  Future<void> logout() async {
    try {
      await _authService.logout();
    } finally {
      await _tokenStore.delete();
    }
  }

  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    await _authService.changePassword(
      currentPassword: currentPassword,
      newPassword: newPassword,
    );
  }

  /// Sets a new password using a registrar-issued activation code.
  ///
  /// Runs before the student has a token, so it deliberately does NOT log the
  /// user in. The code is single-use, so the screen sends the student back to
  /// /login to sign in with the password they just chose.
  Future<void> resetPasswordWithCode({
    required String studentId,
    required String code,
    required String newPassword,
  }) async {
    await _authService.resetPasswordWithCode(
      studentId: studentId,
      code: code,
      newPassword: newPassword,
    );
  }

  Future<LoginResult?> getAuthenticatedUser() async {
    final token = await _tokenStore.read();
    if (token == null || token.isEmpty) return null;

    try {
      final response = await _authService.getMe();
      // The backend `me` endpoint returns `{ user: {...} }`; tolerate a bare
      // student object as well for forward compatibility.
      final raw = response.data;
      final studentData = raw is Map && raw.containsKey('user') ? raw['user'] : raw;
      final map = Map<String, dynamic>.from(studentData as Map);
      return LoginResult(
        student: Student.fromJson(map),
        mustChangePassword: map['must_change_password'] == true,
      );
    } catch (_) {
      await _tokenStore.delete();
      return null;
    }
  }
}

/// A successful login plus the server's first-login password-rotation flag.
///
/// Registrar-imported accounts get a random temporary password and must change
/// it before using the app; the flag drives the forced change-password screen.
class LoginResult {
  final Student student;
  final bool mustChangePassword;

  const LoginResult({required this.student, required this.mustChangePassword});
}
