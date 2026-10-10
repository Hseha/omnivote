import 'package:dio/dio.dart';

import 'network_error.dart';

/// Maps a thrown error to a user-safe message.
///
/// Delegates to the single classification helper in [network_error.dart] so
/// every screen, provider and repository resolves a DioException through
/// one mapping. Raw `DioException`/`StateError` internals (URIs, hosts,
/// server payloads) never leak into the UI.
String apiErrorMessage(
  Object error, {
  String fallback = 'Request failed',
  NetworkErrorContext context = NetworkErrorContext.general,
}) {
  if (error is DioException) {
    return classifyNetworkError(error, context: context).message;
  }
  if (error is StateError) return error.message;
  return fallback;
}
