import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/models/vote_receipt_model.dart';
import 'package:omnivote/data/repositories/vote_repository.dart';
import 'package:omnivote/data/services/election_status_service.dart';
import 'package:omnivote/data/services/secure_storage_service.dart';
import 'package:omnivote/data/services/vote_service.dart';
import 'package:omnivote/features/voting/providers/voting_provider.dart';

class _FakeStorage extends SecureStorageService {
  _FakeStorage() : super(const FlutterSecureStorage());

  @override
  Future<void> saveValue(String key, String value) async {}

  @override
  Future<String?> readValue(String key) async => null;
}

/// Stands in for GET /election/status during the timeout-then-status-check
/// flow. [phase] is the only field the classifier's vote path cares about.
class _FakeElectionStatusService extends ElectionStatusService {
  _FakeElectionStatusService() : super(Dio());

  String phase = 'voting_open';

  @override
  Future<Response> getStatus() async {
    return Response(
      data: {'phase': phase},
      statusCode: 200,
      requestOptions: RequestOptions(path: '/election/status'),
    );
  }
}

class _FakeVoteRepository extends VoteRepository {
  _FakeVoteRepository({ElectionStatusService? statusService})
      : super(
          VoteService(Dio()),
          _FakeStorage(),
          statusService ?? _FakeElectionStatusService(),
        );

  int submitCalls = 0;
  Object? throwError;
  Completer<void>? gate;

  @override
  Future<VoteReceipt> submit({required Map<String, dynamic> selections}) async {
    submitCalls++;
    if (gate != null) await gate!.future;
    if (throwError != null) throw throwError!;
    return const VoteReceipt(receiptToken: 'abc123');
  }
}

DioException _conflict409({String votedAt = '2026-10-10T00:30:00+00:00'}) {
  final options = RequestOptions(path: '/ballot/me/submit');
  return DioException(
    requestOptions: options,
    type: DioExceptionType.badResponse,
    response: Response(
      requestOptions: options,
      statusCode: 409,
      data: {'message': 'Already voted', 'voted_at': votedAt},
    ),
  );
}

DioException _timeout() {
  final options = RequestOptions(path: '/ballot/me/submit');
  return DioException(
    requestOptions: options,
    type: DioExceptionType.receiveTimeout,
  );
}

DioException _offline() {
  final options = RequestOptions(path: '/ballot/me/submit');
  return DioException(
    requestOptions: options,
    type: DioExceptionType.connectionError,
    error: 'connection reset by peer',
  );
}

DioException _sessionExpired() {
  final options = RequestOptions(path: '/ballot/me/submit');
  return DioException(
    requestOptions: options,
    type: DioExceptionType.badResponse,
    response: Response(
      requestOptions: options,
      statusCode: 401,
      data: {'message': 'Unauthenticated.'},
    ),
  );
}

void main() {
  group('VotingNotifier.submitBallot', () {
    test('409 Already voted is a confirmation, not an error', () async {
      final repo = _FakeVoteRepository()..throwError = _conflict409();
      final notifier = VotingNotifier(repo);

      final success = await notifier.submitBallot({'president': 'ref-ana'});

      expect(success, isTrue, reason: 'a recorded vote must read as success');
      final state = notifier.state;
      expect(state.errorMessage, isNull);
      expect(state.isSubmitting, isFalse);
      expect(state.receipt, isNull, reason: 'no fresh token on a 409');
      expect(state.alreadyVoted, isTrue);
      expect(state.votedAt, '2026-10-10T00:30:00+00:00');
    });

    test('a fresh submit still returns the receipt and stays a success',
        () async {
      final notifier = VotingNotifier(_FakeVoteRepository());

      final success = await notifier.submitBallot({'president': 'ref-ana'});

      expect(success, isTrue);
      expect(notifier.state.receipt?.receiptToken, 'abc123');
      expect(notifier.state.alreadyVoted, isFalse);
      expect(notifier.state.errorMessage, isNull);
    });

    test('a genuine failure still reports the server message', () async {
      final options = RequestOptions(path: '/ballot/me/submit');
      final repo = _FakeVoteRepository()
        ..throwError = DioException(
          requestOptions: options,
          type: DioExceptionType.badResponse,
          response: Response(
            requestOptions: options,
            statusCode: 422,
            data: {'message': 'Invalid or unapproved candidate.'},
          ),
        );
      final notifier = VotingNotifier(repo);

      final success = await notifier.submitBallot({'president': 'ref-ana'});

      expect(success, isFalse);
      expect(notifier.state.errorMessage, 'Invalid or unapproved candidate.');
      expect(notifier.state.alreadyVoted, isFalse);
    });

    test('a second submit while one is in flight is refused (double-tap)',
        () async {
      final repo = _FakeVoteRepository()..gate = Completer<void>();
      final notifier = VotingNotifier(repo);

      final first = notifier.submitBallot({'president': 'ref-ana'});
      // Second call lands while isSubmitting is true.
      final second = await notifier.submitBallot({'president': 'ref-ana'});
      repo.gate!.complete();
      final firstOutcome = await first;

      expect(second, isFalse, reason: 'double-tap must not start a second POST');
      expect(firstOutcome, isTrue);
      expect(repo.submitCalls, 1, reason: 'only one network submit was issued');
      expect(notifier.state.isSubmitting, isFalse);
    });

    test('a vote timeout is verified with the status endpoint before any retry',
        () async {
      // The submit timed out; the status endpoint says the polls are
      // closed, so the ballot was recorded. Confirmation, not error, and
      // no retry is offered.
      final status = _FakeElectionStatusService()..phase = 'voting_closed';
      final repo = _FakeVoteRepository(statusService: status)
        ..throwError = _timeout();
      final notifier = VotingNotifier(repo);

      final success = await notifier.submitBallot({'president': 'ref-ana'});

      expect(success, isTrue, reason: 'a verified-recorded vote reads as success');
      final state = notifier.state;
      expect(state.isSubmitting, isFalse);
      expect(state.alreadyVoted, isTrue);
      expect(state.errorMessage, isNull, reason: 'no retry option on a verified vote');
    });

    test('a vote timeout with an open ballot yields a retryable message',
        () async {
      final status = _FakeElectionStatusService()..phase = 'voting_open';
      final repo = _FakeVoteRepository(statusService: status)
        ..throwError = _timeout();
      final notifier = VotingNotifier(repo);

      final success = await notifier.submitBallot({'president': 'ref-ana'});

      expect(success, isFalse);
      final state = notifier.state;
      expect(state.isSubmitting, isFalse);
      expect(state.alreadyVoted, isFalse);
      expect(
        state.errorMessage,
        "We couldn't confirm your vote. Check your status before trying again.",
      );
    });

    test('an offline vote failure shows the offline message', () async {
      final repo = _FakeVoteRepository()..throwError = _offline();
      final notifier = VotingNotifier(repo);

      final success = await notifier.submitBallot({'president': 'ref-ana'});

      expect(success, isFalse);
      expect(
        notifier.state.errorMessage,
        "You're offline. Your vote was not sent. Reconnect and try again.",
      );
    });

    test('a 401 vote failure prompts re-login', () async {
      final repo = _FakeVoteRepository()..throwError = _sessionExpired();
      final notifier = VotingNotifier(repo);

      final success = await notifier.submitBallot({'president': 'ref-ana'});

      expect(success, isFalse);
      expect(
        notifier.state.errorMessage,
        'Your session has expired. Please sign in again.',
      );
    });
  });
}
