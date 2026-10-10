import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/providers/auth_event_provider.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/utils/network_error.dart';
import '../../../data/models/student_model.dart';
import '../../../data/repositories/auth_repository.dart';

final StateNotifierProvider<AuthNotifier, AuthState> authProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  final notifier = AuthNotifier(ref.read(authRepositoryProvider));

  // Listen for unauthorized events from the API client
  ref.listen(authEventProvider, (previous, next) {
    if (next is AuthEvent) {
      notifier.handleUnauthorized(next.reason);
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
  /// True when the account was provisioned with a temporary password and the
  /// student must set a new one before using the app (registrar imports).
  final bool mustChangePassword;
  /// Seconds remaining in a login backoff, from the `Retry-After` header.
  ///
  /// SECURITY (assessment M-3). The backend answers a backed-off account with the
  /// same `401 Invalid credentials` body as an unknown account, and signals the
  /// wait only through this header, so the response itself is not an
  /// account-existence oracle. Carrying it into the UI lets a real student who
  /// is genuinely backing off see *why* sign-in is failing, without widening the
  /// body-based oracle.
  final int? retryAfterSeconds;

  AuthState({
    this.student,
    this.isLoading = false,
    this.errorMessage,
    this.mustChangePassword = false,
    this.retryAfterSeconds,
  });

  AuthState copyWith({
    Student? student,
    bool? isLoading,
    String? errorMessage,
    Object? mustChangePassword = _unset,
    Object? retryAfterSeconds = _unset,
  }) {
    return AuthState(
      student: student ?? this.student,
      isLoading: isLoading ?? this.isLoading,
      errorMessage: errorMessage ?? this.errorMessage,
      mustChangePassword: identical(mustChangePassword, _unset)
          ? this.mustChangePassword
          : mustChangePassword as bool,
      retryAfterSeconds: identical(retryAfterSeconds, _unset)
          ? this.retryAfterSeconds
          : retryAfterSeconds as int?,
    );
  }

  bool get isAuthenticated => student != null;
}

/// Sentinel so `copyWith(mustChangePassword: false)` can clear the flag.
const Object _unset = Object();

/// Reads the `Retry-After` header off a failed login, if the server sent one.
///
/// The backend sets it in two places: the account backoff (assessment M-3) and
/// the per-IP / per-account rate limiter. Both are honest back-pressure signals
/// for a legitimate client, so the UI shows the wait rather than leaving the
/// student staring at an unexplained "Invalid credentials".
///
/// Returns null when the header is absent or unparseable, which is the case for
/// an ordinary wrong-password attempt.
int? retryAfterSecondsFrom(Object error) {
  if (error is! DioException) return null;

  final headers = error.response?.headers;
  if (headers == null) return null;

  final raw = headers.value('retry-after') ?? headers.value('Retry-After');
  if (raw == null) return null;

  final seconds = int.tryParse(raw.trim());
  return (seconds == null || seconds <= 0) ? null : seconds;
}

/// Renders a backoff wait for the sign-in error banner.
///
/// Deliberately describes the wait and nothing about the account, so the banner
/// cannot be used to tell a real handle from a made-up one.
String? backoffHint(int? retryAfterSeconds) {
  if (retryAfterSeconds == null) return null;

  if (retryAfterSeconds < 60) {
    return 'Too many attempts. Try again in $retryAfterSeconds second(s).';
  }

  final minutes = (retryAfterSeconds / 60).ceil();
  return 'Too many attempts. Try again in about $minutes minute(s).';
}

class AuthNotifier extends StateNotifier<AuthState> {
  final AuthRepository _authRepository;

  AuthNotifier(this._authRepository) : super(AuthState());

  Future<void> checkAuth() async {
    state = state.copyWith(isLoading: true);
    final result = await _authRepository.getAuthenticatedUser();
    state = AuthState(
      student: result?.student,
      mustChangePassword: result?.mustChangePassword ?? false,
      isLoading: false,
    );
  }

  Future<bool> login({required String email, required String password}) async {
    state = state.copyWith(
      isLoading: true,
      errorMessage: null,
      retryAfterSeconds: null,
    );

    try {
      final result = await _authRepository.login(email: email, password: password);
      state = AuthState(
        student: result.student,
        mustChangePassword: result.mustChangePassword,
      );
      return true;
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: apiErrorMessage(
          e,
          fallback: 'Login failed',
          context: NetworkErrorContext.login,
        ),
        retryAfterSeconds: retryAfterSecondsFrom(e),
      );
      return false;
    }
  }

  /// Completes the forced first-login password rotation. The backend keeps the
  /// current session valid and revokes any other tokens.
  Future<bool> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    state = state.copyWith(isLoading: true, errorMessage: null);

    try {
      await _authRepository.changePassword(
        currentPassword: currentPassword,
        newPassword: newPassword,
      );
      state = state.copyWith(isLoading: false, mustChangePassword: false);
      return true;
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: apiErrorMessage(e, fallback: 'Could not update password'),
      );
      return false;
    }
  }

  /// Self-service recovery for a student who cannot sign in (assessment M-3).
  ///
  /// Redeems the registrar-issued activation code and sets a new password. Does
  /// not establish a session: the endpoint is unauthenticated and the code is
  /// burned on use, so the student signs in normally afterwards.
  ///
  /// The backend answers identically whether the code was valid or not, so a
  /// `false` return genuinely means "the request was rejected" (e.g. rate
  /// limited or a malformed field) rather than "that student ID does not exist".
  Future<bool> resetPasswordWithCode({
    required String studentId,
    required String code,
    required String newPassword,
  }) async {
    state = state.copyWith(isLoading: true, errorMessage: null);

    try {
      await _authRepository.resetPasswordWithCode(
        studentId: studentId,
        code: code,
        newPassword: newPassword,
      );
      state = state.copyWith(isLoading: false);
      return true;
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: apiErrorMessage(
          e,
          fallback: 'Could not reset your password. Check the code and try again.',
        ),
      );
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
  ///
  /// A `vote` reason means the 401 arrived during a ballot submit: the vote
  /// screen keeps the selections held in memory and shows a re-login prompt,
  /// so this must NOT tear down the session or the student would lose the
  /// in-flight ballot before being asked to sign in.
  Future<void> handleUnauthorized(String reason) async {
    if (reason == 'vote') return;
    if (state.student == null) return;
    await logout();
  }
}
