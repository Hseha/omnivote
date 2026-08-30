import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import 'api_client.dart';

final candidateServiceProvider = Provider<CandidateService>((ref) {
  return CandidateService(ref.read(apiClientProvider));
});

class CandidateService {
  final Dio _dio;

  CandidateService(this._dio);

  Future<Response> getPositions() async {
    return await _dio.get(ApiConstants.positions);
  }

  Future<Response> getCandidates({
    String? positionId,
    String? tier,
    String? search,
    String? grade,
  }) async {
    return await _dio.get(
      ApiConstants.candidates,
      queryParameters: {
        'position': ?positionId,
        'tier': ?tier,
        'search': ?search,
        'grade': ?grade,
      },
    );
  }

  Future<Response> getCandidate(String id) async {
    return await _dio.get('${ApiConstants.candidates}/$id');
  }
}
