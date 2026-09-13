import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/constants/api_constants.dart';
import 'package:omnivote/data/models/election_status_model.dart';
import 'package:omnivote/data/services/election_status_service.dart';
import 'package:omnivote/features/dashboard/providers/election_status_provider.dart';

/// The phase provider must keep working after the epoch-refresh refactor
/// (audit §3 #7): it parses server phases, falls back to `unknown` on network
/// failure, and a failed fetch is not cached forever — the epoch bump refetches.
class _FakeStatusService implements ElectionStatusService {
  final Object? error;
  final Map<String, dynamic>? payload;

  _FakeStatusService({this.error, this.payload});

  @override
  Future<Response> getStatus() async {
    if (error != null) throw error!;
    return Response<dynamic>(
      requestOptions: RequestOptions(path: ApiConstants.electionStatus),
      statusCode: 200,
      data: payload ?? const <String, dynamic>{},
    );
  }
}

void main() {
  test('electionStatusProvider parses the voting_close phase', () async {
    final container = ProviderContainer(
      overrides: [
        electionStatusServiceProvider.overrideWithValue(
          _FakeStatusService(payload: {'phase': 'voting_closed', 'phase_label': 'Voting Closed'}),
        ),
      ],
    );
    addTearDown(container.dispose);

    final status = await container.read(electionStatusProvider.future);

    expect(status.phase, ElectionPhase.votingClosed);
    expect(status.isVotingClosed, isTrue);
  });

  test('electionStatusProvider degrades to unknown on network failure', () async {
    final container = ProviderContainer(
      overrides: [
        electionStatusServiceProvider.overrideWithValue(
          _FakeStatusService(
            error: DioException(
              type: DioExceptionType.connectionError,
              requestOptions: RequestOptions(path: ApiConstants.electionStatus),
            ),
          ),
        ),
      ],
    );
    addTearDown(container.dispose);

    final status = await container.read(electionStatusProvider.future);

    expect(status.phase, ElectionPhase.unknown);
  });

  test('bumping the epoch makes electionStatusProvider re-run', () async {
    final counting =
        _CountingStatusService(payload: {'phase': 'voting_open'});
    final container = ProviderContainer(
      overrides: [electionStatusServiceProvider.overrideWithValue(counting)],
    );
    addTearDown(container.dispose);

    await container.read(electionStatusProvider.future);
    expect(counting.calls, 1);

    // A failed/unknown fetch must not be cached forever: the epoch bump forces
    // a fresh GET /election/status (audit §3 #7).
    container.read(electionStatusEpochProvider.notifier).state++;
    await container.read(electionStatusProvider.future);

    expect(counting.calls, 2);
    expect(container.read(electionStatusProvider).value?.phase,
        ElectionPhase.votingOpen);
  });
}

class _CountingStatusService implements ElectionStatusService {
  final Map<String, dynamic> payload;
  int calls = 0;

  _CountingStatusService({required this.payload});

  @override
  Future<Response> getStatus() async {
    calls++;
    return Response<dynamic>(
      requestOptions: RequestOptions(path: ApiConstants.electionStatus),
      statusCode: 200,
      data: payload,
    );
  }
}