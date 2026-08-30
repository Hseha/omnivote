import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import 'api_client.dart';

final resultServiceProvider = Provider<ResultService>((ref) {
  return ResultService(ref.read(apiClientProvider));
});

class ResultService {
  final Dio _dio;

  ResultService(this._dio);

  Future<Response> getResults() async {
    return await _dio.get(ApiConstants.results);
  }

  Future<Response> verify(String receiptToken) async {
    return await _dio.post(
      ApiConstants.verifyResult,
      data: {'receipt_token': receiptToken},
    );
  }
}
