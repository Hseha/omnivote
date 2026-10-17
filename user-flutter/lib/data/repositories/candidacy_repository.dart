import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/candidacy_application_model.dart';
import '../services/candidacy_service.dart';

final candidacyRepositoryProvider = Provider<CandidacyRepository>((ref) {
  return CandidacyRepository(ref.read(candidacyServiceProvider));
});

/// A rejected candidacy action that carries the server's machine-readable
/// `code` alongside the human message.
///
/// The apply/withdraw endpoints answer a seat-cap violation with
/// `{code: "seat_taken"}` and an already-active application with
/// `{code: "application_exists"}`. The screen keys its "Seat taken" popup on
/// [code] alone, so the raw string is surfaced rather than flattened into a
/// generic 409 message. `code` is a literal on both sides of the contract by
/// design — there is no shared constants file across the repo halves.
class CandidacyException implements Exception {
  final String? code;
  final String message;

  const CandidacyException(this.message, {this.code});

  @override
  String toString() => message;
}

class CandidacyRepository {
  final CandidacyService _candidacyService;

  CandidacyRepository(this._candidacyService);

  Future<CandidacyApplication> submit({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) async {
    try {
      final response = await _candidacyService.submit(
        positionId: positionId,
        slogan: slogan,
        platformStatement: platformStatement,
        partyName: partyName,
        photoBytes: photoBytes,
        photoName: photoName,
      );
      return CandidacyApplication.fromJson(
        _candidateMap(response.data),
      );
    } catch (error) {
      throw _translate(error);
    }
  }

  /// Edits the caller's campaign (server keeps the position locked once
  /// approved).
  Future<CandidacyApplication> update({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) async {
    try {
      final response = await _candidacyService.update(
        positionId: positionId,
        slogan: slogan,
        platformStatement: platformStatement,
        partyName: partyName,
        photoBytes: photoBytes,
        photoName: photoName,
      );
      return CandidacyApplication.fromJson(
        _candidateMap(response.data),
      );
    } catch (error) {
      throw _translate(error);
    }
  }

  /// Withdraws/forfeits the caller's candidacy. Returns the resulting status.
  Future<String> withdraw() async {
    try {
      final response = await _candidacyService.withdraw();
      final candidate = _candidateMap(response.data);
      return candidate['approval_status']?.toString() ?? 'withdrawn';
    } catch (error) {
      throw _translate(error);
    }
  }

  /// Per-party application counts per position (`GET /candidacy/availability`),
  /// keyed by position id → party name → count. An absent position or party
  /// means zero, so the caller can compare against `Position.seatCount`.
  ///
  /// The null-party ("No party") bucket is reported under the empty-string key
  /// by the availability endpoint; callers look it up via `party ?? ''`.
  Future<Map<int, Map<String, int>>> getAvailability() async {
    final response = await _candidacyService.getAvailability();
    final data = response.data;
    final positions = data is Map ? data['positions'] : null;
    if (positions is! List) return const {};

    final result = <int, Map<String, int>>{};
    for (final entry in positions) {
      if (entry is! Map) continue;
      final id = int.tryParse(entry['id']?.toString() ?? '');
      if (id == null) continue;
      final counts = <String, int>{};
      final rawCounts = entry['party_counts'];
      if (rawCounts is Map) {
        rawCounts.forEach((key, value) {
          final count = value is int ? value : int.tryParse('$value');
          if (count != null) counts['$key'] = count;
        });
      }
      result[id] = counts;
    }
    return result;
  }

  /// Submits a problem / bug report from the apply screen so admins can
  /// investigate the root cause.
  Future<Response> submitProblemReport({
    required String screen,
    required String action,
    required String errorCode,
    required String errorMessage,
    required Map<String, dynamic> context,
    required String description,
  }) async {
    try {
      return await _candidacyService.submitProblemReport(
        screen: screen,
        action: action,
        errorCode: errorCode,
        errorMessage: errorMessage,
        context: context,
        description: description,
      );
    } catch (error) {
      throw _translate(error);
    }
  }

  /// Wraps a `{code, message}` error body in [CandidacyException]; any other
  /// failure is returned unchanged so existing Dio/error handling still runs.
  static Object _translate(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map) {
        final code = data['code']?.toString();
        if (code != null && code.isNotEmpty) {
          final message =
              data['message']?.toString() ?? 'Request failed. Please try again.';
          return CandidacyException(message, code: code);
        }
      }
    }
    return error;
  }

  /// Returns the full application for the current student, or `null` when
  /// they have never applied (`GET /candidacy/me` → `{status: none}`).
  ///
  /// The endpoint answers with context fields at the top level
  /// (`rejection_reason`, `created_at`, `updated_at`, `applicants_count`,
  /// `election_outcome`) and the campaign fields nested under `candidate`;
  /// both layers are merged into one [CandidacyApplication] so the screen
  /// reads a single object.
  Future<CandidacyApplication?> getMyApplication() async {
    final response = await _candidacyService.getMyApplication();
    final data = response.data;
    if (data is! Map) return null;
    if ((data['status'] ?? data['approval_status']) == 'none') return null;
    final candidate = data['candidate'];
    if (candidate is! Map) return null;

    final merged = Map<String, dynamic>.from(candidate);
    for (final key in const [
      'rejection_reason',
      'created_at',
      'updated_at',
      'applicants_count',
      'election_outcome',
    ]) {
      if (data.containsKey(key)) merged[key] = data[key];
    }
    return CandidacyApplication.fromJson(merged);
  }

  /// Returns `none` when no application exists, otherwise the status string.
  Future<String> getApplicationStatus() async {
    final application = await getMyApplication();
    return application?.status ?? 'none';
  }

  static Map<String, dynamic> _candidateMap(dynamic responseData) {
    final candidate = responseData is Map ? responseData['candidate'] : null;
    return Map<String, dynamic>.from(candidate as Map? ?? const {});
  }
}
