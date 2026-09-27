import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'secure_storage_service.dart';

/// Persists the "Remember me" credentials from the login screen.
///
/// The email/username is stored in plain [SharedPreferences] (it is echoed
/// back into the form and is not secret), while the password only ever goes
/// through [SecureStorageService] — Android Keystore / iOS Keychain — so a
/// remembered login never writes the password to plaintext storage.
class LoginPrefs {
  LoginPrefs(this._secure);

  static const String _emailKey = 'remember_me_email';
  static const String _passwordKey = 'remember_me_password';

  final SecureStorageService _secure;

  /// The remembered pair, or null when nothing valid was ever saved.
  Future<({String email, String password})?> load() async {
    final prefs = await SharedPreferences.getInstance();
    final email = prefs.getString(_emailKey);
    final password = await _secure.readValue(_passwordKey);

    if (email == null ||
        email.isEmpty ||
        password == null ||
        password.isEmpty) {
      return null;
    }
    return (email: email, password: password);
  }

  Future<void> save({required String email, required String password}) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_emailKey, email);
    await _secure.saveValue(_passwordKey, password);
  }

  /// Forgets the remembered login (checkbox unticked before signing in).
  Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_emailKey);
    await _secure.deleteValue(_passwordKey);
  }
}

final loginPrefsProvider = Provider<LoginPrefs>((ref) {
  return LoginPrefs(ref.read(secureStorageServiceProvider));
});