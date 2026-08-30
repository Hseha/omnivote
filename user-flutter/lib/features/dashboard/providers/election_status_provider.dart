import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/services/election_status_service.dart';

/// Holds the current election phase. Falls back to an unknown/offline phase
/// when the status endpoint is unreachable so screens can degrade gracefully.
final electionStatusProvider = FutureProvider<ElectionStatus>((ref) async {
  final service = ref.watch(electionStatusServiceProvider);
  try {
    final response = await service.getStatus();
    final data = response.data;
    final json = data is Map ? Map<String, dynamic>.from(data) : const <String, dynamic>{};
    return ElectionStatus.fromJson(json);
  } catch (_) {
    return const ElectionStatus(phase: ElectionPhase.unknown);
  }
});
