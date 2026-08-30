import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/candidacy_application_model.dart';
import '../../../data/repositories/candidacy_repository.dart';

/// Holds form state and submission progress for the candidacy application.
class CandidacyState {
  final bool isSubmitting;
  final String? errorMessage;
  final CandidacyApplication? submittedApplication;

  const CandidacyState({
    this.isSubmitting = false,
    this.errorMessage,
    this.submittedApplication,
  });

  CandidacyState copyWith({
    bool? isSubmitting,
    String? errorMessage,
    CandidacyApplication? submittedApplication,
    bool clearError = false,
  }) {
    return CandidacyState(
      isSubmitting: isSubmitting ?? this.isSubmitting,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      submittedApplication: submittedApplication ?? this.submittedApplication,
    );
  }
}

final candidacyProvider = StateNotifierProvider<CandidacyNotifier, CandidacyState>(
  (ref) => CandidacyNotifier(ref.read(candidacyRepositoryProvider)),
);

class CandidacyNotifier extends StateNotifier<CandidacyState> {
  final CandidacyRepository _repository;

  CandidacyNotifier(this._repository) : super(const CandidacyState());

  Future<bool> submit({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) async {
    state = state.copyWith(isSubmitting: true, clearError: true);
    try {
      final application = await _repository.submit(
        positionId: positionId,
        slogan: slogan,
        platformStatement: platformStatement,
        partyName: partyName,
        photoBytes: photoBytes,
        photoName: photoName,
      );
      state = state.copyWith(isSubmitting: false, submittedApplication: application);
      return true;
    } catch (e) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: 'Application failed. Please try again.',
      );
      return false;
    }
  }

  Future<String?> applicationStatus() async {
    try {
      return await _repository.getApplicationStatus();
    } catch (_) {
      return null;
    }
  }
}
