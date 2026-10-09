import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Fetches published student-app releases from the public GitHub Releases API.
///
/// Deliberately a separate, plain [Dio] rather than [apiClientProvider]: the
/// app's API client re-points the base URL and attaches our backend Bearer
/// token, which must never be sent to github.com.
final releaseServiceProvider = Provider<ReleaseService>((ref) {
  return ReleaseService(ReleaseService.createDio());
});

class ReleaseService {
  final Dio _dio;

  ReleaseService(this._dio);

  static Dio createDio() {
    return Dio(
      BaseOptions(
        baseUrl: 'https://api.github.com/repos/Hseha/omnivote',
        connectTimeout: const Duration(seconds: 10),
        receiveTimeout: const Duration(seconds: 10),
        headers: {
          'Accept': 'application/vnd.github+json',
          'User-Agent': 'omnivote-student-app',
        },
      ),
    );
  }

  Future<Response> fetchReleases({int perPage = 10}) async {
    return await _dio.get('/releases', queryParameters: {'per_page': perPage});
  }
}