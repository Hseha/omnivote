import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/constants/api_constants.dart';
import 'package:omnivote/data/models/election_status_model.dart';
import 'package:omnivote/data/services/election_status_service.dart';
import 'package:omnivote/features/dashboard/providers/election_status_provider.dart';

/// Regression guard for the 30 s poll flicker.
///
/// `electionStatusProvider` re-runs on a timer, and while it refetches the
/// provider sits in `AsyncLoading`. The subtle part — and the actual bug — is
/// that this state carries `isRefreshing == false`, so
/// `AsyncValue.when(skipLoadingOnRefresh: true)` does NOT suppress it. Any
/// screen that maps that state to a spinner blanks its whole body every 30 s,
/// which on the ballot looked like the app switching off and on and wiped the
/// voter's in-progress selections.
///
/// Screens must therefore read the retained `.value` and only fall back to a
/// spinner when there is genuinely no value yet.
class _FakeStatusService implements ElectionStatusService {
  /// Slow enough that the in-flight window is observable without racing.
  _FakeStatusService() : delay = const Duration(milliseconds: 40);

  final Duration delay;

  @override
  Future<Response> getStatus() async {
    await Future<void>.delayed(delay);
    return Response<dynamic>(
      requestOptions: RequestOptions(path: ApiConstants.electionStatus),
      statusCode: 200,
      data: const {'phase': 'voting_open'},
    );
  }
}

void main() {
  test(
    'an in-flight poll is loading but NOT a refresh, yet keeps its value',
    () async {
      final tick = StreamController<int>.broadcast();
      final container = ProviderContainer(
        overrides: [
          electionStatusServiceProvider.overrideWithValue(_FakeStatusService()),
          electionStatusTickerProvider.overrideWith((ref) => tick.stream),
        ],
      );
      addTearDown(container.dispose);
      addTearDown(tick.close);

      await container.read(electionStatusProvider.future);

      // Tick without awaiting, then sample the in-flight window.
      tick.add(1);
      await Future<void>.delayed(const Duration(milliseconds: 10));
      final inflight = container.read(electionStatusProvider);

      expect(
        inflight.isLoading,
        isTrue,
        reason: 'a re-run puts the provider back into a loading state',
      );

      // This is the crux: it is a loading state, but NOT a refresh, so
      // skipLoadingOnRefresh will not hold the previous data on screen.
      expect(
        inflight.isRefreshing,
        isFalse,
        reason: 'skipLoadingOnRefresh only suppresses isRefreshing states',
      );

      // The usable value survives, so reading .value keeps the UI painted.
      expect(
        inflight.value?.phase,
        ElectionPhase.votingOpen,
        reason: 'the retained phase is what screens must render mid-poll',
      );

      await container.read(electionStatusProvider.future);
      expect(container.read(electionStatusProvider).isLoading, isFalse);
    },
  );

  test('a genuine first load has no value to fall back on', () async {
    final tick = StreamController<int>.broadcast();
    final container = ProviderContainer(
      overrides: [
        electionStatusServiceProvider.overrideWithValue(_FakeStatusService()),
        electionStatusTickerProvider.overrideWith((ref) => tick.stream),
      ],
    );
    addTearDown(container.dispose);
    addTearDown(tick.close);

    // Read synchronously during the very first, unresolved fetch.
    final first = container.read(electionStatusProvider);

    expect(first.isLoading, isTrue);
    expect(
      first.value,
      isNull,
      reason: 'screens show a spinner only in this state',
    );

    await container.read(electionStatusProvider.future);
  });
}
