import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/election_result_model.dart';
import '../../../data/repositories/result_repository.dart';
import '../../dashboard/providers/election_status_provider.dart';

/// Per-position tallies once `voting_closed`.
///
/// Watches the election status future, so a phase flip (voting_open →
/// voting_closed) or an epoch bump (pull-to-refresh in `ResultsScreen`)
/// recomputes this provider instead of caching the pre-close result forever.
final resultsProvider = FutureProvider<List<ElectionResult>>((ref) async {
  final status = await ref.watch(electionStatusProvider.future);
  if (!status.isVotingClosed) return const [];
  return await ref.watch(resultRepositoryProvider).getResults();
});

final verifyReceiptProvider =
    FutureProvider.family<bool, String>((ref, token) async {
  return await ref.watch(resultRepositoryProvider).verify(token);
});
