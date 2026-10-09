import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/constants/api_constants.dart';
import 'api_client.dart';

final notificationServiceProvider = Provider<NotificationService>((ref) {
  return NotificationService(ref.read(apiClientProvider));
});

/// Thin HTTP layer for the student notification center. Auth (bearer token) and
/// the runtime base URL are applied by [apiClientProvider]'s interceptors, so
/// this class only names the routes.
class NotificationService {
  final Dio _dio;

  NotificationService(this._dio);

  Future<Response> getNotifications() async {
    return await _dio.get(ApiConstants.notifications);
  }

  /// Marks one notification read when [id] is given, otherwise every unread
  /// notification for the caller.
  Future<Response> markRead({int? id}) async {
    return await _dio.post(
      ApiConstants.notificationsRead,
      data: <String, dynamic>{'id': ?id},
    );
  }
}
