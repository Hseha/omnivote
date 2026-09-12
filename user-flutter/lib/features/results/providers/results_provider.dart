import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/election_result_model.dart';
import '../../../data/repositories/result_repository.dart';
import '../../dashboard/providers/election_status_provider.dart';

final resultsProvider = FutureProvider<List<ElectionResult>>((ref) async {
  final status = await ref.watch(electionStatusProvider.future);
  if (!status.isVotingClosed) return const [];
  return await ref.watch(resultRepositoryProvider).getResults();
});

final verifyReceiptProvider =
    FutureProvider.family<bool, String>((ref, token) async {
  return await ref.watch(resultRepositoryProvider).verify(token);
});
