import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/services/election_status_service.dart';

/// Bumping this counter forces every `electionStatusProvider` watcher to
/// re-run `GET /election/status`. Screens use it for pull-to-refresh and the
/// "Retry" paths so a transient outage can't permanently degrade phase state
/// (audit §3 #7: FutureProviders cache errors/fallbacks forever).
final electionStatusEpochProvider = StateProvider<int>((ref) => 0);

/// Holds the current election phase. Falls back to an unknown/offline phase
/// when the status endpoint is unreachable so screens can degrade gracefully.
final electionStatusProvider = FutureProvider<ElectionStatus>((ref) {
  // Re-fetch whenever the epoch bumps (manual refresh / pull-to-refresh).
  ref.watch(electionStatusEpochProvider);

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
