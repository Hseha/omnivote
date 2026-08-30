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
  Future<Response> submitVote(Map<String, dynamic> selections) async {
    return await _dio.post(
      ApiConstants.voteSubmit,
      data: {'selections': selections},
    );
  }

  Future<Response> getMyBallot() async {
    return await _dio.get(ApiConstants.ballotMe);
  }

  Future<Response> verifyReceipt(String receiptToken) async {
    return await _dio.post(
      ApiConstants.verifyResult,
      data: {'receipt_token': receiptToken},
    );
  }
}
