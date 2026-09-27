import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import 'api_client.dart';

final brandingServiceProvider = Provider<BrandingService>((ref) {
  return BrandingService(ref.read(apiClientProvider));
});

class BrandingService {
  final Dio _dio;

  BrandingService(this._dio);

  /// Fetches the public branding payload (`{ branding: {...} }`). Public, so
  /// it must be callable without an auth token.
  Future<Response> get() async {
    return await _dio.get(ApiConstants.branding);
  }
}