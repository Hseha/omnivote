import 'dart:async';

import 'package:dio/dio.dart';

/// Retries idempotent GETs that failed on a transient network problem.
///
/// Student traffic is concentrated in one short voting window on shared school
/// Wi-Fi, so a refused connection or a 503 from a cold PHP-FPM pool is normal
/// and a single retry usually turns a red "Cannot reach the API server" state
/// into a successful load. Two rules keep that from becoming a liability:
///
///  * **GET only.** A retried `POST /vote`, `/auth/login` or
///    `/auth/change-password` is how a student ends up submitting twice or
///    seeing a phantom error, so those are never replayed automatically.
///  * **Bounded added latency.** A retry only happens when the failure came
///    back quickly ([slowFailureCutoff]), which is what a refusal or an
///    immediate reset looks like. A 10 s connect timeout is *not* retried, so a
///    genuinely dead network still shows its error when it always did instead
///    of after an extra timeout.
class IdempotentRetryInterceptor extends Interceptor {
  IdempotentRetryInterceptor({
    required this.dio,
    this.maxAttempts = 2,
    this.retryDelay = const Duration(milliseconds: 400),
    this.slowFailureCutoff = const Duration(seconds: 3),
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now;

  /// Dio instance used to replay the request.
  final Dio dio;

  /// Total attempts including the first (2 = one retry).
  final int maxAttempts;

  /// Delay before a replay: long enough for a dropped socket to clear, short
  /// enough that a student does not notice it.
  final Duration retryDelay;

  /// A failure that took longer than this to arrive is treated as a real
  /// network outage rather than a blip, and is not retried.
  final Duration slowFailureCutoff;

  final DateTime Function() _clock;

  static const String _attemptKey = 'omnivote_retry_attempt';
  static const String _startedKey = 'omnivote_retry_started_at';

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.extra.putIfAbsent(_attemptKey, () => 0);
    options.extra.putIfAbsent(
      _startedKey,
      () => _clock().microsecondsSinceEpoch,
    );
    return handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final request = err.requestOptions;
    final attempt = (request.extra[_attemptKey] as int?) ?? 0;

    if (attempt + 1 >= maxAttempts || !_isRetryable(err) || _wasSlow(request)) {
      return handler.next(err);
    }

    await Future<void>.delayed(retryDelay);

    request.extra[_attemptKey] = attempt + 1;
    // The clock restarts for the replayed attempt, so each attempt gets its own
    // "did this fail fast?" budget.
    request.extra[_startedKey] = _clock().microsecondsSinceEpoch;

    try {
      final response = await dio.fetch<dynamic>(request);
      return handler.resolve(response);
    } on DioException catch (retryError) {
      return handler.next(retryError);
    }
  }

  bool _wasSlow(RequestOptions request) {
    final startedAt = request.extra[_startedKey] as int?;
    if (startedAt == null) return false;
    final elapsed = Duration(
      microseconds: _clock().microsecondsSinceEpoch - startedAt,
    );
    return elapsed > slowFailureCutoff;
  }

  /// Read requests whose failure looks transient.
  bool _isRetryable(DioException err) {
    // Never replay anything that changes server state.
    final method = err.requestOptions.method.toUpperCase();
    if (method != 'GET' && method != 'HEAD') return false;

    switch (err.type) {
      case DioExceptionType.connectionError:
        return true;
      case DioExceptionType.badResponse:
        final status = err.response?.statusCode;
        // A gateway/cold-pool hiccup, not a rejection of the request itself.
        return status == 502 || status == 503 || status == 504;
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.transformTimeout:
      case DioExceptionType.cancel:
      case DioExceptionType.badCertificate:
      case DioExceptionType.unknown:
        return false;
    }
  }
}
