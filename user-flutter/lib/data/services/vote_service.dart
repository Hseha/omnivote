import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import 'api_client.dart';

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
  Future<Response> submitVote(Map<String, dynamic> selections) async {
    return await _dio.post(
      ApiConstants.voteSubmit,
      data: {'selections': selections},
    );
  }

  /// Submits a voted ballot via the documented contract path
  /// (POST /api/ballot/me/submit). Kept as the canonical "My Ballot" submit.
  Future<Response> submitBallot(Map<String, dynamic> selections) async {
    return await _dio.post(
      ApiConstants.ballotSubmit,
      data: {'selections': selections},
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
