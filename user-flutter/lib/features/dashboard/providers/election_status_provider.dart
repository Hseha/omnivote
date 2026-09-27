import 'dart:async';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/services/election_status_service.dart';

/// Emits a tick every 30 s. `electionStatusProvider` watches it so every
/// screen showing the phase (StatusBadge, vote/ballot/results screens)
/// re-polls automatically while it is open — the phase flips on its own the
/// moment a configured window boundary is crossed, instead of only on
/// pull-to-refresh.
final electionStatusTickerProvider = StreamProvider<int>((ref) {
  final controller = StreamController<int>.broadcast();
  var tick = 0;
  final timer = Timer.periodic(const Duration(seconds: 30), (_) {
    if (!controller.isClosed) controller.add(++tick);
  });
  ref.onDispose(() {
    timer.cancel();
    controller.close();
  });
  return controller.stream;
});

/// Bumping this counter forces every `electionStatusProvider` watcher to
/// re-run `GET /election/status`. Screens use it for pull-to-refresh and the
/// "Retry" paths so a transient outage can't permanently degrade phase state
/// (audit §3 #7: FutureProviders cache errors/fallbacks forever).
final electionStatusEpochProvider = StateProvider<int>((ref) => 0);

/// Holds the current election phase. Falls back to an unknown/offline phase
/// when the status endpoint is unreachable so screens can degrade gracefully.
final electionStatusProvider = FutureProvider<ElectionStatus>((ref) {
  // Re-fetch whenever the epoch bumps (manual refresh / pull-to-refresh) or a
  // 30 s poll tick arrives (automatic phase transitions while a screen opens).
  ref.watch(electionStatusEpochProvider);
  ref.watch(electionStatusTickerProvider);

  final service = ref.watch(electionStatusServiceProvider);
  return _fetchStatus(service);
});

Future<ElectionStatus> _fetchStatus(ElectionStatusService service) async {
  try {
    final response = await service.getStatus();
    final data = response.data;
    final json = data is Map
        ? Map<String, dynamic>.from(data)
        : const <String, dynamic>{};
    return ElectionStatus.fromJson(json);
  } catch (_) {
    return const ElectionStatus(phase: ElectionPhase.unknown);
  }
}
