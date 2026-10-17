import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/utils/error_message.dart';
import '../../../data/models/candidacy_application_model.dart';
import '../../../data/repositories/candidacy_repository.dart';

/// Holds form state and submission progress for the candidacy application.
class CandidacyState {
  final bool isSubmitting;
  final String? errorMessage;

  /// Machine-readable failure code from the server, when one was sent
  /// (`seat_taken`, `application_exists`). The screen shows the "Seat taken"
  /// popup for `seat_taken`; everything else keeps its snackbar.
  final String? errorCode;
  final CandidacyApplication? submittedApplication;

  const CandidacyState({
    this.isSubmitting = false,
    this.errorMessage,
    this.errorCode,
    this.submittedApplication,
  });

  CandidacyState copyWith({
    bool? isSubmitting,
    String? errorMessage,
    String? errorCode,
    CandidacyApplication? submittedApplication,
    bool clearError = false,
  }) {
    return CandidacyState(
      isSubmitting: isSubmitting ?? this.isSubmitting,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      errorCode: clearError ? null : (errorCode ?? this.errorCode),
      submittedApplication: submittedApplication ?? this.submittedApplication,
    );
  }
}

final candidacyProvider = StateNotifierProvider<CandidacyNotifier, CandidacyState>(
  (ref) => CandidacyNotifier(ref.read(candidacyRepositoryProvider), ref),
);

/// Per-party application counts per position (position id → party → count),
/// consumed by the apply screen to label seats a party has already filled.
///
/// A failure is non-fatal: the screen falls back to "everything selectable"
/// and still relies on the server's authoritative 409.
final candidacyAvailabilityProvider =
    FutureProvider<Map<int, Map<String, int>>>((ref) async {
  return await ref.watch(candidacyRepositoryProvider).getAvailability();
});

/// The caller's full application (`GET /api/candidacy/me`), or `null` when
/// they have never applied. Shared by the My Candidacy screen and the
/// dashboard quick-action tile, and invalidated after submit / edit /
/// withdraw so both consumers stay in sync.
final myApplicationProvider =
    FutureProvider<CandidacyApplication?>((ref) async {
  return await ref.watch(candidacyRepositoryProvider).getMyApplication();
});

class CandidacyNotifier extends StateNotifier<CandidacyState> {
  final CandidacyRepository _repository;

  /// Kept so every successful mutation can invalidate [myApplicationProvider]:
  /// the My Candidacy screen and the dashboard tile both read it and must
  /// not show a stale status after a submit / edit / withdraw.
  final Ref _ref;

  CandidacyNotifier(this._repository, this._ref) : super(const CandidacyState());

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
      _ref.invalidate(myApplicationProvider);
      return true;
    } on CandidacyException catch (e) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: _candidacyErrorMessage(e),
        errorCode: e.code,
      );
      return false;
    } catch (e) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: _candidacyErrorMessage(e),
      );
      return false;
    }
  }

  Future<bool> update({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) async {
    state = state.copyWith(isSubmitting: true, clearError: true);
    try {
      final application = await _repository.update(
        positionId: positionId,
        slogan: slogan,
        platformStatement: platformStatement,
        partyName: partyName,
        photoBytes: photoBytes,
        photoName: photoName,
      );
      state = state.copyWith(isSubmitting: false, submittedApplication: application);
      _ref.invalidate(myApplicationProvider);
      return true;
    } on CandidacyException catch (e) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: _candidacyErrorMessage(e),
        errorCode: e.code,
      );
      return false;
    } catch (e) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: _candidacyErrorMessage(e),
      );
      return false;
    }
  }

  /// Withdraws/forfeits the candidate's application. Returns `true` on
  /// success (the caller flips its local status to `withdrawn`).
  Future<bool> withdraw() async {
    state = state.copyWith(isSubmitting: true, clearError: true);
    try {
      await _repository.withdraw();
      state = state.copyWith(isSubmitting: false);
      _ref.invalidate(myApplicationProvider);
      return true;
    } on CandidacyException catch (e) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: _candidacyErrorMessage(e),
        errorCode: e.code,
      );
      return false;
    } catch (e) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: _candidacyErrorMessage(e),
      );
      return false;
    }
  }

  /// Loads the caller's full application (null when they have none).
  Future<CandidacyApplication?> myApplication() async {
    try {
      return await _repository.getMyApplication();
    } catch (_) {
      return null;
    }
  }

  /// Surfaces the real failure instead of masking every error: server message,
  /// phase rejection (403), and duplicate application (409) each get a
  /// distinct, actionable message (audit §2 #9).
  String _candidacyErrorMessage(Object error) {
    if (error is CandidacyException) {
      // A coded rejection carries its own message. `application_exists` keeps
      // the established phrasing; `seat_taken` uses the server's "seat already
      // taken" sentence, which the screen repeats inside the popup.
      return error.code == 'application_exists'
          ? 'You already have a candidacy application under review.'
          : error.message;
    }
    if (error is DioException) {
      final statusCode = error.response?.statusCode;
      final data = error.response?.data;
      final serverMessage = data is Map ? data['message']?.toString() : null;
      if (statusCode == 409) {
        return 'You already have a candidacy application under review.';
      }
      if (statusCode == 403) {
        return _phaseMessage(data is Map ? data['phase']?.toString() : null);
      }
      if (serverMessage != null && serverMessage.isNotEmpty) {
        return serverMessage;
      }
    }
    return apiErrorMessage(error, fallback: 'Request failed. Please try again.');
  }

  /// Phase-aware rejection copy. The backend returns 403 with the `phase` name
  /// when a candidacy mutation is blocked by the election window
  /// (CheckPhase middleware / assertCandidacyEditable). A missing or
  /// unrecognised phase falls back to a generic message so the student always
  /// gets a clear explanation instead of "Action not allowed".
  static String _phaseMessage(String? phase) {
    switch (phase) {
      case 'registration':
        // Only the edit/withdraw half of the flow reaches here during
        // registration (apply is allowed); the generic fallback still works.
        return 'Candidacy changes are only available until voting closes.';
      case 'registration_closed':
        return 'Registration is closed. You cannot submit or edit your candidacy application right now.';
      case 'voting_open':
        return 'Registration is closed. You can edit an existing application during voting, but new applications are no longer accepted.';
      case 'voting_closed':
        return 'Voting has closed. You cannot edit your candidacy application right now.';
      default:
        return 'The election phase is not configured yet, or the status could not be determined. Please try again later.';
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
