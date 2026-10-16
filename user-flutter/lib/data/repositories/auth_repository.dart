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
      ssgSetupPending: student.ssgSetupPending,
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

  /// Redeems the SSG President's one-time in-app setup link and sets the
  /// password they chose. The link is single-use and expires; the server keeps
  /// the current session valid and revokes any other tokens.
  Future<void> setupSsgPassword({
    required String token,
    required String newPassword,
  }) async {
    await _authService.setupSsgPassword(token: token, newPassword: newPassword);
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
      final student = Student.fromJson(map);
      return LoginResult(
        student: student,
        mustChangePassword: map['must_change_password'] == true,
        ssgSetupPending: student.ssgSetupPending,
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

  /// True for a freshly-granted SSG President who still holds a one-time setup
  /// link (in their in-app notifications) and has not yet set a password.
  final bool ssgSetupPending;

  const LoginResult({
    required this.student,
    required this.mustChangePassword,
    this.ssgSetupPending = false,
  });
}
