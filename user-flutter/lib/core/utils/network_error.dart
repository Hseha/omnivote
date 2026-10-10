import 'package:dio/dio.dart';

/// How a client reached a server-side failure. The classifier uses this to pick
/// the plain-language message below, because the same HTTP status means
/// different things to a voter standing at a ballot vs. a student trying to
/// log in.
enum NetworkErrorContext {
  /// Generic API call (dashboard, positions, ballot, profile, results, …).
  general,

  /// A ballot submission. Timeout and offline failures get vote-specific
  /// messaging, and a timeout is followed by a status check before any retry
  /// option is shown.
  vote,

  /// Sign-in.
  login,
}

/// Plain-language result of mapping one network failure to what the student
/// actually sees. One place — this file — owns the mapping from
/// `DioException`/`DioExceptionType` to user-visible text.
///
/// Nothing here ever logs a token, a student id, or a ballot selection: the
/// [rawCause] field is diagnostic-only and is never persisted, logged, or
/// displayed.
class NetworkFailure {
  final String message;
  final bool isOffline;
  final bool isTimeout;
  final bool isServer;
  final bool isClient;
  final bool isAuth;
  final bool isAlreadyVoted;
  final bool needsStatusCheck;
  final bool needsReauth;
  final bool isRetryable;
  final String? rawCause;

  const NetworkFailure({
    required this.message,
    this.isOffline = false,
    this.isTimeout = false,
    this.isServer = false,
    this.isClient = false,
    this.isAuth = false,
    this.isAlreadyVoted = false,
    this.needsStatusCheck = false,
    this.needsReauth = false,
    this.isRetryable = false,
    this.rawCause,
  });
}

/// The single error-classification helper for the student app.
///
/// Maps every `DioException` (and the HTTP statuses behind it) to a plain,
/// non-technical message plus the flags the UI needs to act on it:
///
///  * **No connection** (refused / reset / DNS): *"You're offline. Your vote
///    was not sent. Reconnect and try again."
///  * **Timeout during a vote submission**: *"We couldn't confirm your vote.
///    Check your status before trying again."* — [NetworkFailure.needsStatusCheck]
///    is true so the submit flow calls the status endpoint and only then shows
///    a retry option.
///  * **Session expired (401/403)**: *"Your session has expired. Please sign
///    in again."* — the vote screen keeps the selections held in memory and
///    prompts re-login.
///  * **5xx / 429**: generic-but-nice retry hints.
///  * **409**: already-recorded confirmation.
///
/// Usage:
///
///   final failure = classifyNetworkError(dioError,
///       context: NetworkErrorContext.vote);
///   showMessage(failure.message);          // plain-language text
///   if (failure.needsStatusCheck) { ... }  // vote timeout: ask the server first
NetworkFailure classifyNetworkError(
  DioException error, {
  NetworkErrorContext context = NetworkErrorContext.general,
}) {
  final status = error.response?.statusCode;
  final data = error.response?.data;

  // ── Transport failures ──────────────────────────────────────────────
  if (error.type == DioExceptionType.connectionError) {
    return NetworkFailure(
      message: context == NetworkErrorContext.vote
          ? "You're offline. Your vote was not sent. Reconnect and try again."
          : context == NetworkErrorContext.login
              ? "Couldn't reach the school server. Check your connection and try again."
              : "You're offline. Reconnect and try again.",
      isOffline: true,
    );
  }

  // Timed out before a full response arrived: the request may or may not have
  // been processed by the server, so the UI must verify rather than guess.
  if (error.type == DioExceptionType.connectionTimeout ||
      error.type == DioExceptionType.sendTimeout ||
      error.type == DioExceptionType.receiveTimeout ||
      error.type == DioExceptionType.transformTimeout) {
    if (context == NetworkErrorContext.vote) {
      return NetworkFailure(
        message:
            "We couldn't confirm your vote. Check your status before trying again.",
        isTimeout: true,
        needsStatusCheck: true,
      );
    }
    return NetworkFailure(
      message: context == NetworkErrorContext.login
          ? "Couldn't reach the school server. Check your connection and try again."
          : "We couldn't reach the school server right now. Please try again.",
      isOffline: true,
      isTimeout: true,
    );
  }

  if (error.type == DioExceptionType.badCertificate) {
    return const NetworkFailure(
      message: 'The school server certificate could not be trusted. Try again later.',
    );
  }

  // ── HTTP responses ─────────────────────────────────────────────────
  if (status != null) {
    if (status >= 500) {
      return NetworkFailure(
        message: 'The school server is busy right now. Please try again in a moment.',
        isServer: true,
        isRetryable: true,
      );
    }
    if (status == 429) {
      return NetworkFailure(
        message: 'Please wait a moment and try again.',
        isRetryable: true,
        rawCause: 'Too many attempts',
      );
    }
    if (status == 401 || status == 403) {
      // At sign-in a 401 means the credentials were rejected, not an expired
      // session — surface the server's own message (the backend answers a
      // backed-off account with the same body) and do not prompt a re-login.
      if (context == NetworkErrorContext.login) {
        return NetworkFailure(
          message: data is Map && data['message'] is String
              ? (data['message'] as String)
              : 'Invalid email or password. Please try again.',
          isAuth: true,
          needsReauth: false,
          isRetryable: false,
        );
      }
      return NetworkFailure(
        message: 'Your session has expired. Please sign in again.',
        isAuth: true,
        needsReauth: true,
        isRetryable: false,
      );
    }
    if (status == 409) {
      return const NetworkFailure(
        message: 'Your ballot was already recorded.',
        isAlreadyVoted: true,
      );
    }
    // Every other 4xx is a client mistake: do not retry, show a short message.
    return NetworkFailure(
      message: data is Map && data['message'] is String
          ? (data['message'] as String)
          : 'That request can\'t be completed right now.',
      isClient: true,
      isRetryable: false,
    );
  }

  // Untyped failure — keep the existing fallback behaviour for anything else.
  return NetworkFailure(
    message: context == NetworkErrorContext.vote
        ? "You're offline. Your vote was not sent. Reconnect and try again."
        : context == NetworkErrorContext.login
            ? "Couldn't reach the school server. Check your connection and try again."
            : 'Something went wrong. Please try again.',
    isOffline: true,
  );
}
