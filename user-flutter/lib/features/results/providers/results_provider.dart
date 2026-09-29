import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/models/election_result_model.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/repositories/result_repository.dart';
import '../../dashboard/providers/election_status_provider.dart';

/// Per-position tallies once `voting_closed`.
///
/// Watches the election *phase* (not the status future), so a phase flip
/// (voting_open → voting_closed) or an epoch bump (pull-to-refresh in
/// `ResultsScreen`) recomputes this provider instead of caching the pre-close
/// result forever.
///
/// The distinction matters: watching `electionStatusProvider.future` re-ran this
/// provider on **every** 30 s status poll — while `voting_closed` that meant
/// `GET /api/results` every 30 s, per open app, with the whole school opening
/// the screen at once. Selecting the retained phase re-runs it only when the
/// phase actually changes, which is all the phase gating needs; fresh numbers
/// come from the screen's pull-to-refresh (which invalidates this provider).
final resultsProvider = FutureProvider<List<ElectionResult>>((ref) async {
  final phase = ref.watch(
    electionStatusProvider.select((state) => state.valueOrNull?.phase),
  );
  if (phase != ElectionPhase.votingClosed) return const [];
  return await ref.watch(resultRepositoryProvider).getResults();
});

/// Receipt verification for the token typed on the Results screen.
///
/// `autoDispose` because the family is keyed by the *token*: without it, every
/// pasted token kept a resolved provider alive for the rest of the session.
final verifyReceiptProvider = FutureProvider.autoDispose.family<bool, String>((
  ref,
  token,
) async {
  return await ref.watch(resultRepositoryProvider).verify(token);
});
