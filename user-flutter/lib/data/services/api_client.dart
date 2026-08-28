import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import '../../core/providers/auth_event_provider.dart';
import 'secure_storage_service.dart';

final Provider<Dio> apiClientProvider = Provider<Dio>((ref) {
  final dio = Dio(BaseOptions(
    baseUrl: ApiConstants.baseUrl,
    connectTimeout: const Duration(seconds: 10),
    receiveTimeout: const Duration(seconds: 10),
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
  ));

  dio.interceptors.add(InterceptorsWrapper(
    onRequest: (options, handler) async {
      final storage = ref.read(secureStorageServiceProvider);
      final token = await storage.getToken();
      if (token != null) {
        options.headers['Authorization'] = 'Bearer $token';
      }
      return handler.next(options);
    },
    onError: (DioException e, handler) {
      if (e.response?.statusCode == 401) {
        // Signal unauthorized event to break circular dependency
        ref.read(authEventProvider.notifier).state = AuthEvent.unauthorized;
      }
      return handler.next(e);
    },
  ));

  return dio;
});

class ApiValidationException implements Exception {
  final String message;
  final Map<String, dynamic>? errors;

  ApiValidationException(this.message, this.errors);

  @override
  String toString() => 'ApiValidationException: $message';
}
