import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/vote_receipt_model.dart';
import '../../../data/repositories/vote_repository.dart';

class VotingState {
  final bool isSubmitting;
  final VoteReceipt? receipt;
  final String? errorMessage;

  const VotingState({this.isSubmitting = false, this.receipt, this.errorMessage});

  VotingState copyWith({
    bool? isSubmitting,
    VoteReceipt? receipt,
    String? errorMessage,
    bool clearError = false,
  }) {
    return VotingState(
      isSubmitting: isSubmitting ?? this.isSubmitting,
      receipt: receipt ?? this.receipt,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
    );
  }
}

final votingProvider = StateNotifierProvider<VotingNotifier, VotingState>(
  (ref) => VotingNotifier(ref.read(voteRepositoryProvider)),
);

class VotingNotifier extends StateNotifier<VotingState> {
  final VoteRepository _repository;

  VotingNotifier(this._repository) : super(const VotingState());

  /// Submits a ballot. `selections` is a map of `position_key -> candidate_ref`
  /// (or a list of refs). On success the receipt token is stored.
  Future<bool> submitBallot(Map<String, dynamic> selections) async {
    state = state.copyWith(isSubmitting: true, clearError: true);
    try {
      final receipt = await _repository.submit(selections: selections);
      state = state.copyWith(isSubmitting: false, receipt: receipt);
      return true;
    } catch (e) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: 'Vote submission failed. Please try again.',
      );
      return false;
    }
  }

  Future<String?> savedReceipt() => _repository.getSavedReceipt();

  Future<bool> verifyReceipt(String token) => _repository.verify(token);
}
