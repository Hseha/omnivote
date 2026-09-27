import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/features/auth/providers/auth_provider.dart';

/// Covers the Retry-After plumbing added for assessment M-3.
///
/// The backend answers a backed-off account with the same `401 Invalid
/// credentials` body as an unknown account, and reports the wait only in a
/// header. These tests pin the two halves of that contract on the client: the
/// hint is shown when (and only when) the header is present, and it never says
/// anything about whether the account exists.
void main() {
  DioException responseWithHeaders(Map<String, List<String>> headers) {
    return DioException(
      requestOptions: RequestOptions(path: '/auth/login'),
      response: Response<dynamic>(
        requestOptions: RequestOptions(path: '/auth/login'),
        statusCode: 401,
        headers: Headers.fromMap(headers),
      ),
    );
  }

  group('retryAfterSecondsFrom', () {
    test('reads the header when the server sent one', () {
      final error = responseWithHeaders({
        'retry-after': ['90'],
      });

      expect(retryAfterSecondsFrom(error), 90);
    });

    test('accepts a capitalised Retry-After', () {
      final error = responseWithHeaders({
        'Retry-After': ['45'],
      });

      expect(retryAfterSecondsFrom(error), 45);
    });

    test('returns null when the header is absent, as for a wrong password', () {
      // An ordinary bad password gets no header, which is exactly why the hint
      // must not appear in that case.
      final error = responseWithHeaders({});

      expect(retryAfterSecondsFrom(error), isNull);
    });

    test('returns null for a non-positive or unparseable value', () {
      expect(
        retryAfterSecondsFrom(responseWithHeaders({'retry-after': ['0']})),
        isNull,
      );
      expect(
        retryAfterSecondsFrom(responseWithHeaders({'retry-after': ['soon']})),
        isNull,
      );
    });

    test('returns null for an error with no response at all', () {
      final error = DioException(
        requestOptions: RequestOptions(path: '/auth/login'),
        type: DioExceptionType.connectionError,
      );

      expect(retryAfterSecondsFrom(error), isNull);
    });

    test('returns null for a non-Dio error', () {
      expect(retryAfterSecondsFrom(StateError('boom')), isNull);
    });
  });

  group('backoffHint', () {
    test('is null without a retry-after, so a wrong password is not blamed', () {
      expect(backoffHint(null), isNull);
    });

    test('reports seconds for a short backoff', () {
      expect(backoffHint(30), 'Too many attempts. Try again in 30 second(s).');
    });

    test('reports whole minutes, rounded up, for a long backoff', () {
      expect(backoffHint(60), 'Too many attempts. Try again in about 1 minute(s).');
      expect(
        backoffHint(3600),
        'Too many attempts. Try again in about 60 minute(s).',
      );
      // Rounds up rather than truncating, so the student never retries early.
      expect(backoffHint(61), 'Too many attempts. Try again in about 2 minute(s).');
    });

    test('never mentions the account, so the text is not an existence oracle', () {
      // A thrown-away backoffSeconds is not tied to any real account here, but
      // the wording is the thing under test: it describes the wait only.
      for (final hint in [
        backoffHint(30),
        backoffHint(600),
      ]) {
        expect(hint, isNotNull);
        final text = hint!.toLowerCase();
        for (final leak in [
          'locked',
          'not found',
          'does not exist',
          'unknown user',
          'no such',
        ]) {
          expect(text, isNot(contains(leak)));
        }
      }
    });
  });
}
