import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import 'api_client.dart';
import 'retry_interceptor.dart';

final voteServiceProvider = Provider<VoteService>((ref) {
  return VoteService(ref.read(apiClientProvider));
});

class VoteService {
  final Dio _dio;

  VoteService(this._dio);

  /// Submits a voted ballot. [selections] maps `position_key -> candidate_ref`
  /// (or a list of refs for multi-select positions).
  ///
  /// Backed by POST /api/vote (legacy contract) which now performs full
  /// candidate validation and records the ballot on the anonymous ledger.
  /// Marked no-retry: a vote POST must never be replayed, the duplicate is
  /// detected server-side as a 409 and must be read as a confirmation.
  Future<Response> submitVote(Map<String, dynamic> selections) async {
    return await _dio.post(
      ApiConstants.voteSubmit,
      data: {'selections': selections},
      options: Options(extra: {IdempotentRetryInterceptor.noRetryKey: true}),
    );
  }

  /// Submits a voted ballot via the documented contract path
  /// (POST /api/ballot/me/submit). Kept as the canonical "My Ballot" submit.
  /// Marked no-retry for the same reason as [submitVote]: a ballot must never
  /// be transmitted twice.
  Future<Response> submitBallot(Map<String, dynamic> selections) async {
    return await _dio.post(
      ApiConstants.ballotSubmit,
      data: {'selections': selections},
      options: Options(extra: {IdempotentRetryInterceptor.noRetryKey: true}),
    );
  }

  /// Fetches the current user's draft/submitted ballot: `{ status, selections }`.
  Future<Response> getMyBallot() async {
    return await _dio.get(ApiConstants.ballotMe);
  }

  /// Upserts the draft selections for one position (PUT /api/ballot/me).
  Future<Response> saveDraft(Map<String, dynamic> selections) async {
    return await _dio.put(ApiConstants.ballotMe, data: {
      'selections': selections,
    });
  }

  Future<Response> verifyReceipt(String receiptToken) async {
    return await _dio.post(
      ApiConstants.verifyResult,
      data: {'receipt_token': receiptToken},
    );
  }
}
