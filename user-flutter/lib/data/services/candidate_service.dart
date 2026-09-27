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
    String? department,
    String? party,
    String? search,
    String? grade,
    int? perPage,
    int? page,
  }) async {
    return await _dio.get(
      ApiConstants.candidates,
      queryParameters: {
        'position': ?positionId,
        'tier': ?tier,
        'department': ?department,
        'party': ?party,
        'search': ?search,
        'grade': ?grade,
        'per_page': ?perPage,
        'page': ?page,
      },
    );
  }

  Future<Response> getDepartments() async {
    return await _dio.get(ApiConstants.departments);
  }

  Future<Response> getParties() async {
    return await _dio.get(ApiConstants.parties);
  }

  Future<Response> getCandidate(String id) async {
    return await _dio.get('${ApiConstants.candidates}/$id');
  }
}
