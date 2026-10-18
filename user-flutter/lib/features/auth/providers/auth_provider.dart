import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/providers/auth_event_provider.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/utils/network_error.dart';
import '../../../data/models/student_model.dart';
import '../../../data/repositories/auth_repository.dart';
import '../../../data/services/login_prefs.dart';

final StateNotifierProvider<AuthNotifier, AuthState> authProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  final notifier = AuthNotifier(
    ref.read(authRepositoryProvider),
    loginPrefs: ref.read(loginPrefsProvider),
  );

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

  /// The user's role (student | ssg_president). Mirrors the backend's
  /// `users.role` payload; it only turns true after a successful login.
  final String? role;

  /// True for a freshly-granted SSG President who still holds a one-time setup
  /// link (in their in-app notifications) and has not yet set their own
  /// password. Non-blocking: it drives a dashboard prompt, not a route gate, so
  /// the notification feed carrying the link stays reachable.
  final bool ssgSetupPending;

  AuthState({
    this.student,
    this.isLoading = false,
    this.errorMessage,
    this.mustChangePassword = false,
    this.retryAfterSeconds,
    this.role,
    this.ssgSetupPending = false,
  });

  AuthState copyWith({
    Student? student,
    bool? isLoading,
    String? errorMessage,
    Object? mustChangePassword = _unset,
    Object? retryAfterSeconds = _unset,
    Object? role = _unset,
    Object? ssgSetupPending = _unset,
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
      role: identical(role, _unset)
          ? this.role
          : role as String?,
      ssgSetupPending: identical(ssgSetupPending, _unset)
          ? this.ssgSetupPending
          : ssgSetupPending as bool,
    );
  }

  bool get isAuthenticated => student != null;
}

/// Where an authenticated account lands on first navigation.
///
/// Every account — including the seated SSG President — lands on the shared
/// student dashboard. The President keeps the full student experience in the
/// app; their governance console (officer roster + announcements) now lives in
/// the web console, reached from the one-time setup link, not by a separate
/// mobile shell. Shared by the login listener in `app.dart` and the cold-start
/// bootstrap in the splash screen so both paths always agree.
String destinationForRole(AuthState state) {
  return '/dashboard';
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

  /// Remembered logins for the "Remember me" prefill. Cleared on every
  /// sign-out so a shared phone never shows one account's credentials to
  /// the next person on the login screen (device isolation).
  final LoginPrefs? _loginPrefs;

  AuthNotifier(this._authRepository, {this._loginPrefs}) : super(AuthState());

  Future<void> checkAuth() async {
    state = state.copyWith(isLoading: true);
    final result = await _authRepository.getAuthenticatedUser();
    state = AuthState(
      student: result?.student,
      mustChangePassword: result?.mustChangePassword ?? false,
      isLoading: false,
      role: result?.student.role,
      ssgSetupPending: result?.ssgSetupPending ?? false,
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
        role: result.student.role,
        ssgSetupPending: result.ssgSetupPending,
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

  /// Redeems the SSG President's one-time setup link and sets their password.
  ///
  /// Authenticated (the President already signed in) and token-bound: the
  /// `token` is the secret from the in-app notification link, not a session
  /// credential. On success the `ssg_setup_pending` prompt is cleared; the
  /// existing session stays valid.
  Future<bool> setupSsgPassword({
    required String token,
    required String newPassword,
  }) async {
    state = state.copyWith(isLoading: true, errorMessage: null);

    try {
      await _authRepository.setupSsgPassword(
        token: token,
        newPassword: newPassword,
      );
      state = state.copyWith(isLoading: false, ssgSetupPending: false);
      return true;
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: apiErrorMessage(
          e,
          fallback: 'Could not set your password. The link may have expired.',
        ),
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

  /// Flips the session's `hasVoted` flag the moment the server accepts a
  /// ballot (or reports one already recorded). Local-only, no network:
  /// Vote Now watches this flag to switch to the already-voted notice, and
  /// it used to stay false until the next login, which made the vote tab
  /// look like a clean slate right after submitting.
  void markVoted() {
    final student = state.student;
    if (student == null || student.hasVoted) return;
    state = state.copyWith(student: student.copyWith(hasVoted: true));
  }

  /// Signs out: revokes the server token, drops the local token, and forgets
  /// any remembered credentials, then resets to the signed-out state.
  Future<void> logout() async {
    state = state.copyWith(isLoading: true);
    await _authRepository.logout();
    // Strict device isolation: the previous account must leave nothing
    // behind for the next sign-in on this phone — in particular not its
    // remembered email/password pair, which would otherwise prefill the
    // login form (and one-tap-sign-in) for whoever opens it next.
    //
    // "Remember me" still pre-fills after a silent session death (token
    // expiry on a cold start never runs through here), so the feature keeps
    // working for the returning owner in its main scenario.
    await _loginPrefs?.clear();
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
