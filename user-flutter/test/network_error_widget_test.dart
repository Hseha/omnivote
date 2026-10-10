import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/utils/network_error.dart';
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
  _FakeVoteRepository({ElectionStatusService? statusService, this.throwError})
      : super(
          VoteService(Dio()),
          _FakeStorage(),
          statusService ?? _FakeElectionStatusService(),
        );

  Object? throwError;

  @override
  Future<VoteReceipt> submit({required Map<String, dynamic> selections}) async {
    if (throwError != null) throw throwError!;
    return const VoteReceipt(receiptToken: 'abc123');
  }
}

/// A minimal screen wired to the real [VotingNotifier] so the widget tests
/// assert what a student would actually see after each failure type.
class _VoteScreen extends ConsumerWidget {
  const _VoteScreen();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(votingProvider);
    return Scaffold(
      body: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          if (state.isSubmitting) const Text('Submitting…'),
          if (state.alreadyVoted) const Text('Your Vote Is Recorded'),
          if (state.errorMessage != null) Text(state.errorMessage!),
          if (!state.isSubmitting && !state.alreadyVoted)
            ElevatedButton(
              onPressed: () => ref
                  .read(votingProvider.notifier)
                  .submitBallot({'president': 'ref-ana'}),
              child: const Text('Submit Ballot'),
            ),
        ],
      ),
    );
  }
}

Future<void> _pumpVoteScreen(
  WidgetTester tester, {
  required Object? throwError,
  String phase = 'voting_open',
}) async {
  final repo = _FakeVoteRepository(
    statusService: _FakeElectionStatusService()..phase = phase,
    throwError: throwError,
  );
  await tester.pumpWidget(
    ProviderScope(
      overrides: [voteRepositoryProvider.overrideWithValue(repo)],
      child: const MaterialApp(home: _VoteScreen()),
    ),
  );
}

void main() {
  group('classifyNetworkError', () {
    test('vote context maps no-connection to the vote message', () {
      final f = classifyNetworkError(
        DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.connectionError,
        ),
        context: NetworkErrorContext.vote,
      );
      expect(f.message,
          "You're offline. Your vote was not sent. Reconnect and try again.");
      expect(f.isOffline, isTrue);
      expect(f.needsStatusCheck, isFalse);
    });

    test('vote context maps a timeout to the confirm-then-check message', () {
      final f = classifyNetworkError(
        DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.receiveTimeout,
        ),
        context: NetworkErrorContext.vote,
      );
      expect(
        f.message,
        "We couldn't confirm your vote. Check your status before trying again.",
      );
      expect(f.needsStatusCheck, isTrue);
      expect(f.isTimeout, isTrue);
    });

    test('401 maps to the re-login prompt', () {
      final f = classifyNetworkError(
        DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.badResponse,
          response: Response(
            requestOptions: RequestOptions(path: '/ballot/me/submit'),
            statusCode: 401,
          ),
        ),
      );
      expect(f.message, 'Your session has expired. Please sign in again.');
      expect(f.needsReauth, isTrue);
      expect(f.isAuth, isTrue);
    });

    test('403 maps to the re-login prompt', () {
      final f = classifyNetworkError(
        DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.badResponse,
          response: Response(
            requestOptions: RequestOptions(path: '/ballot/me/submit'),
            statusCode: 403,
          ),
        ),
      );
      expect(f.needsReauth, isTrue);
    });

    test('5xx maps to the busy-server message', () {
      final f = classifyNetworkError(
        DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.badResponse,
          response: Response(
            requestOptions: RequestOptions(path: '/ballot/me/submit'),
            statusCode: 503,
          ),
        ),
      );
      expect(f.message,
          'The school server is busy right now. Please try again in a moment.');
      expect(f.isRetryable, isTrue);
    });

    test('409 maps to the already-recorded message', () {
      final f = classifyNetworkError(
        DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.badResponse,
          response: Response(
            requestOptions: RequestOptions(path: '/ballot/me/submit'),
            statusCode: 409,
          ),
        ),
      );
      expect(f.message, 'Your ballot was already recorded.');
      expect(f.isAlreadyVoted, isTrue);
    });

    test('login context maps no-connection to a login message', () {
      final f = classifyNetworkError(
        DioException(
          requestOptions: RequestOptions(path: '/auth/login'),
          type: DioExceptionType.connectionError,
        ),
        context: NetworkErrorContext.login,
      );
      expect(
        f.message,
        "Couldn't reach the school server. Check your connection and try again.",
      );
    });
  });

  group('vote submit widget', () {
    testWidgets('timeout + closed ballot shows the recorded confirmation',
        (tester) async {
      await _pumpVoteScreen(
        tester,
        throwError: DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.receiveTimeout,
        ),
        phase: 'voting_closed',
      );

      await tester.tap(find.text('Submit Ballot'));
      await tester.pumpAndSettle();

      expect(find.text('Your Vote Is Recorded'), findsOneWidget);
      expect(find.text('Submit Ballot'), findsNothing,
          reason: 'a verified vote offers no retry button');
    });

    testWidgets('timeout + open ballot shows the retry message',
        (tester) async {
      await _pumpVoteScreen(
        tester,
        throwError: DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.receiveTimeout,
        ),
        phase: 'voting_open',
      );

      await tester.tap(find.text('Submit Ballot'));
      await tester.pumpAndSettle();

      expect(
        find.text("We couldn't confirm your vote. Check your status before trying again."),
        findsOneWidget,
      );
      expect(find.text('Submit Ballot'), findsOneWidget,
          reason: 'a retry option is offered when the ballot is still open');
    });

    testWidgets('offline shows the offline message', (tester) async {
      await _pumpVoteScreen(
        tester,
        throwError: DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.connectionError,
        ),
      );

      await tester.tap(find.text('Submit Ballot'));
      await tester.pumpAndSettle();

      expect(
        find.text("You're offline. Your vote was not sent. Reconnect and try again."),
        findsOneWidget,
      );
    });

    testWidgets('401 shows the re-login prompt', (tester) async {
      await _pumpVoteScreen(
        tester,
        throwError: DioException(
          requestOptions: RequestOptions(path: '/ballot/me/submit'),
          type: DioExceptionType.badResponse,
          response: Response(
            requestOptions: RequestOptions(path: '/ballot/me/submit'),
            statusCode: 401,
          ),
        ),
      );

      await tester.tap(find.text('Submit Ballot'));
      await tester.pumpAndSettle();

      expect(
        find.text('Your session has expired. Please sign in again.'),
        findsOneWidget,
      );
    });
  });
}
