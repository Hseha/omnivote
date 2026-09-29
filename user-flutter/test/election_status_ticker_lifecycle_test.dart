import 'package:dio/dio.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/constants/api_constants.dart';
import 'package:omnivote/data/services/election_status_service.dart';
import 'package:omnivote/features/dashboard/providers/election_status_provider.dart';

/// The 30 s phase poll must follow the app lifecycle: it stops while the app is
/// backgrounded (a student leaving the app open must not keep paying for status
/// requests on a metered connection) and checks immediately on resume, so the
/// phase they come back to is never the one from before they left.
class _CountingStatusService implements ElectionStatusService {
  int calls = 0;

  @override
  Future<Response> getStatus() async {
    calls++;
    return Response<dynamic>(
      requestOptions: RequestOptions(path: ApiConstants.electionStatus),
      statusCode: 200,
      data: const {'phase': 'voting_open'},
    );
  }
}

void main() {
  testWidgets(
      'the status ticker pauses when backgrounded and re-checks on resume',
      (tester) async {
    final service = _CountingStatusService();
    final container = ProviderContainer(
      overrides: [electionStatusServiceProvider.overrideWithValue(service)],
    );
    // A screen watching the phase (every screen does) is what actually triggers
    // the refetch on a tick — an unread provider stays dirty and costs nothing.
    container.listen(electionStatusProvider, (_, _) {});

    await container.read(electionStatusProvider.future);
    expect(service.calls, 1);

    // One full poll cycle while in the foreground.
    await tester.pump(kElectionStatusPollInterval);
    expect(service.calls, 2, reason: 'the foreground poll must keep running');

    // Backgrounded: the Android transition sequence is
    // resumed → inactive → hidden → paused. The timer is cancelled, so no
    // further requests happen while nothing is on screen.
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    await tester.pump(kElectionStatusPollInterval * 4);
    expect(service.calls, 2,
        reason: 'no status requests while the app is in the background');

    // Resumed: the very first thing that happens is a fresh check.
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump(const Duration(milliseconds: 50));
    expect(service.calls, 3,
        reason: 'the phase must be re-checked as soon as the student returns');

    // And the regular cadence continues from there.
    await tester.pump(kElectionStatusPollInterval);
    expect(service.calls, 4);

    // Disposed inside the test body so the ticker's timer is cancelled before
    // the binding checks for pending timers.
    container.dispose();
  });
}
