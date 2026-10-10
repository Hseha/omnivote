import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/utils/network_error.dart';
import '../../../data/models/vote_receipt_model.dart';
import '../../../data/repositories/vote_repository.dart';

class VotingState {
  final bool isSubmitting;
  final VoteReceipt? receipt;
  final String? errorMessage;

  /// True when the server reported this ballot was already recorded (409).
  /// The client may have lost the original submit response, so the vote DID
  /// happen even though no fresh receipt token came back — this lets the UI
  /// show a confirmation instead of an error.
  final bool alreadyVoted;

  /// Server time the ballot was recorded, from the 409 body (`voted_at`).
  final String? votedAt;

  const VotingState({
    this.isSubmitting = false,
    this.receipt,
    this.errorMessage,
    this.alreadyVoted = false,
    this.votedAt,
  });

  VotingState copyWith({
    bool? isSubmitting,
    VoteReceipt? receipt,
    String? errorMessage,
    bool clearError = false,
    bool? alreadyVoted,
    String? votedAt,
  }) {
    return VotingState(
      isSubmitting: isSubmitting ?? this.isSubmitting,
      receipt: receipt ?? this.receipt,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      alreadyVoted: alreadyVoted ?? this.alreadyVoted,
      votedAt: votedAt ?? this.votedAt,
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
  ///
  /// A `409 Already voted` from the server is NOT an error: it means the
  /// ballot was recorded by an earlier attempt (e.g. the submit response was
  /// lost on school Wi-Fi). It resolves to `true` with [VotingState.alreadyVoted]
  /// set so the UI shows a confirmation rather than a failure.
  ///
  /// A *timeout* during submission is also verified before any retry is
  /// offered: the status endpoint is consulted, and only a still-open ballot
  /// yields a retryable error message. The offline message ("You're offline.
  /// Your vote was not sent. Reconnect and try again.") is shown directly,
  /// because a timeout is the only case where the outcome is genuinely unknown.
  Future<bool> submitBallot(Map<String, dynamic> selections) async {
    // Double-tap guard: a submission is already in flight. The UI disables the
    // control via isSubmitting; this is the state-level safety net so a stray
    // second call can never start another POST.
    if (state.isSubmitting) return false;

    state = state.copyWith(isSubmitting: true, clearError: true);
    try {
      final receipt = await _repository.submit(selections: selections);
      state = state.copyWith(isSubmitting: false, receipt: receipt);
      return true;
    } on DioException catch (e) {
      final failure = classifyNetworkError(
        e,
        context: NetworkErrorContext.vote,
      );
      final statusCode = e.response?.statusCode;
      final data = e.response?.data;
      if (statusCode == 409) {
        final votedAt = data is Map ? data['voted_at']?.toString() : null;
        state = state.copyWith(
          isSubmitting: false,
          alreadyVoted: true,
          votedAt: votedAt,
          clearError: true,
        );
        return true;
      }
      if (failure.needsStatusCheck) {
        // The submit timed out: the ballot may or may not have been recorded.
        // Consult the status endpoint BEFORE showing any retry option. The
        // spinner stays up (isSubmitting) until the answer is known.
        final status = await _repository.checkStatus();
        if (status != null && status.isVotingClosed) {
          // The ballot field is closed — the timed-out submit was recorded.
          // Confirmation, not error; no retry is offered.
          state = state.copyWith(
            isSubmitting: false,
            alreadyVoted: true,
            clearError: true,
          );
          return true;
        }
        // The ballot is still open: the submit did not land. Show the
        // plain-language message and let the student retry.
        state = state.copyWith(
          isSubmitting: false,
          errorMessage: failure.message,
        );
        return false;
      }
      // Offline / server / auth failures: the classifier's message is the
      // user-facing one.
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: failure.message,
      );
      return false;
    } catch (e) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: 'Something went wrong. Please try again.',
      );
      return false;
    }
  }

  Future<String?> savedReceipt() => _repository.getSavedReceipt();

  Future<bool> verifyReceipt(String token) => _repository.verify(token);
}
