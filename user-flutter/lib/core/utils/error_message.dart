import 'package:dio/dio.dart';

/// Maps a thrown error to a user-safe message.
///
/// Mirrors the categorization previously inlined in `auth_provider`; raw
/// `DioException`/`StateError` internals (URIs, hosts, server payloads) never
/// leak into the UI.
String apiErrorMessage(Object error, {String fallback = 'Request failed'}) {
  if (error is DioException) {
    final data = error.response?.data;
    if (data is Map && data['message'] is String) {
      return data['message'] as String;
    }
    switch (error.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.connectionError:
        return 'Cannot reach the API server. Check that the backend is running.';
      case DioExceptionType.badCertificate:
        return 'Server certificate could not be trusted.';
      default:
        return '$fallback (HTTP ${error.response?.statusCode ?? 'error'})';
    }
  }
  if (error is StateError) return error.message;
  return '$fallback: $error';
}