import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/election_result_model.dart';
import '../../../data/repositories/result_repository.dart';

final resultsProvider = FutureProvider<List<ElectionResult>>((ref) async {
  return await ref.watch(resultRepositoryProvider).getResults();
});

final verifyReceiptProvider =
    FutureProvider.family<bool, String>((ref, token) async {
  return await ref.watch(resultRepositoryProvider).verify(token);
});
