import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import 'api_client.dart';

final electionStatusServiceProvider = Provider<ElectionStatusService>((ref) {
  return ElectionStatusService(ref.read(apiClientProvider));
});

class ElectionStatusService {
  final Dio _dio;

  ElectionStatusService(this._dio);

  Future<Response> getStatus() async {
    return await _dio.get(
      ApiConstants.electionStatus,
      // A background 30 s poll: never flash the global loading overlay for it.
      options: Options(extra: {noLoadingOverlayKey: true}),
    );
  }
}
