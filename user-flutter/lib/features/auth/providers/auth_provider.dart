import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/providers/auth_event_provider.dart';
import '../../../data/models/student_model.dart';
import '../../../data/repositories/auth_repository.dart';

final StateNotifierProvider<AuthNotifier, AuthState> authProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  final notifier = AuthNotifier(ref.read(authRepositoryProvider));

  // Listen for unauthorized events from the API client
  ref.listen(authEventProvider, (previous, next) {
    if (next == AuthEvent.unauthorized) {
      notifier.handleUnauthorized();
      // Reset the event
      ref.read(authEventProvider.notifier).state = null;
    }
  });

  return notifier;
});

class AuthState {
  final Student? student;
  final bool isLoading;
  final String? errorMessage;

  AuthState({this.student, this.isLoading = false, this.errorMessage});

  AuthState copyWith({
    Student? student,
    bool? isLoading,
    String? errorMessage,
  }) {
    return AuthState(
      student: student ?? this.student,
      isLoading: isLoading ?? this.isLoading,
      errorMessage: errorMessage ?? this.errorMessage,
    );
  }

  bool get isAuthenticated => student != null;
}

class AuthNotifier extends StateNotifier<AuthState> {
  final AuthRepository _authRepository;

  AuthNotifier(this._authRepository) : super(AuthState());

  Future<void> checkAuth() async {
    state = state.copyWith(isLoading: true);
    final student = await _authRepository.getAuthenticatedUser();
    state = state.copyWith(student: student, isLoading: false);
  }

  Future<bool> login({required String email, required String password}) async {
    state = state.copyWith(isLoading: true, errorMessage: null);

    try {
      final student = await _authRepository.login(email: email, password: password);
      state = state.copyWith(student: student, isLoading: false);
      return true;
    } catch (e) {
      state = state.copyWith(isLoading: false, errorMessage: 'Invalid credentials');
      return false;
    }
  }

  Future<bool> register(Map<String, dynamic> data) async {
    state = state.copyWith(isLoading: true, errorMessage: null);
    try {
      final student = await _authRepository.register(data);
      state = state.copyWith(student: student, isLoading: false);
      return true;
    } catch (e) {
      state = state.copyWith(isLoading: false, errorMessage: 'Registration failed');
      return false;
    }
  }

  Future<void> logout() async {
    state = state.copyWith(isLoading: true);
    await _authRepository.logout();
    state = AuthState();
  }

  /// Logs out programmatically (e.g. server 401 without clearing during an
  /// active login attempt). Used by the global unauthorized listener.
  Future<void> handleUnauthorized() async {
    if (state.student == null) return;
    await logout();
  }
}
