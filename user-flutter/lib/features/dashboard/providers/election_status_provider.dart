import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/models/election_status_model.dart';
import '../../../data/services/api_config.dart';
import '../../../data/services/election_status_service.dart';
import 'election_status_poller.dart';

/// How often the phase is re-checked while the app is in the foreground.
const Duration kElectionStatusPollInterval = Duration(seconds: 30);

/// Emits a tick every [kElectionStatusPollInterval] while the app is visible.
/// `electionStatusProvider` watches it so every screen showing the phase
/// (StatusBadge, vote/ballot/results screens) re-polls automatically while it is
/// open — the phase flips on its own the moment a configured window boundary is
/// crossed, instead of only on pull-to-refresh.
///
/// The timer **pauses while the app is backgrounded** and re-checks immediately
/// on resume. A student who leaves the app in their pocket for an hour used to
/// bill 120 status requests against a metered school/mobile connection without
/// anyone looking at the result; now the first thing they see on resume is a
/// freshly checked phase, which is the only moment the answer matters.
final electionStatusTickerProvider = StreamProvider<int>((ref) {
  final controller = StreamController<int>();
  Timer? timer;
  var tick = 0;

  void emit() {
    if (!controller.isClosed) controller.add(++tick);
  }

  void start() {
    timer ??= Timer.periodic(kElectionStatusPollInterval, (_) => emit());
  }

  void stop() {
    timer?.cancel();
    timer = null;
  }

  start();

  AppLifecycleListener? lifecycle;
  try {
    lifecycle = AppLifecycleListener(
      onStateChange: (state) {
        if (state == AppLifecycleState.resumed) {
          start();
          // A stale phase is worse than a spinner: check before the student can
          // act on what they are looking at.
          emit();
        } else {
          stop();
        }
      },
    );
  } catch (_) {
    // No widget binding (a pure unit test): keep ticking. The timer is still
    // cancelled by ref.onDispose, so nothing leaks.
  }

  ref.onDispose(() {
    lifecycle?.dispose();
    stop();
    controller.close();
  });

  return controller.stream;
});

/// Bumping this counter forces every `electionStatusProvider` watcher to
/// re-run `GET /election/status`. Screens use it for pull-to-refresh and the
/// "Retry" paths so a transient outage can't permanently degrade phase state
/// (audit §3 #7: FutureProviders cache errors/fallbacks forever). A bump also
/// clears any error backoff, so an explicit retry always reaches the API.
final electionStatusEpochProvider = StateProvider<int>((ref) => 0);

/// The single poller shared by every phase observer: it de-duplicates in-flight
/// requests and applies the error backoff (see [ElectionStatusPoller]).
final electionStatusPollerProvider = Provider<ElectionStatusPoller>((ref) {
  return ElectionStatusPoller();
});

/// Holds the current election phase. Falls back to an unknown/offline phase
/// when the status endpoint is unreachable so screens can degrade gracefully.
final electionStatusProvider = FutureProvider<ElectionStatus>((ref) async {
  // Re-fetch whenever the epoch bumps (manual refresh / pull-to-refresh) or a
  // 30 s poll tick arrives (automatic phase transitions while a screen opens).
  final epoch = ref.watch(electionStatusEpochProvider);
  ref.watch(electionStatusTickerProvider);

  final service = ref.watch(electionStatusServiceProvider);
  final poller = ref.watch(electionStatusPollerProvider);

  // A changed base URL means a different backend entirely: don't let the old
  // one's failures (or its last phase) colour the new one.
  ref.listen(apiBaseUrlProvider, (previous, next) => poller.reset());

  return poller.poll(epoch: epoch, fetch: () => _fetchStatus(service));
});

/// Reads the phase, or null when the endpoint could not be read. A null feeds
/// the poller's backoff; the observable fallback (`unknown`) is applied there.
Future<ElectionStatus?> _fetchStatus(ElectionStatusService service) async {
  try {
    final response = await service.getStatus();
    final data = response.data;
    final json = data is Map
        ? Map<String, dynamic>.from(data)
        : const <String, dynamic>{};
    return ElectionStatus.fromJson(json);
  } catch (_) {
    return null;
  }
}
