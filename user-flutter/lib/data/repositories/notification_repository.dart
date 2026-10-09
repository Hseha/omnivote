import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/utils/safe_json.dart';
import '../models/notification_model.dart';
import '../services/notification_service.dart';

final notificationRepositoryProvider = Provider<NotificationRepository>((ref) {
  return NotificationRepository(ref.read(notificationServiceProvider));
});

class NotificationRepository {
  final NotificationService _notificationService;

  NotificationRepository(this._notificationService);

  /// Reads the caller's feed. Tolerates both the documented
  /// `{ notifications, unread }` envelope and a bare list (older backends),
  /// falling back to counting unread rows client-side when the server omits
  /// the authoritative total.
  Future<NotificationFeed> getFeed() async {
    final response = await _notificationService.getNotifications();
    final data = response.data;

    final List<dynamic> raw;
    if (data is Map && data['notifications'] is List) {
      raw = data['notifications'] as List;
    } else if (data is List) {
      raw = data;
    } else {
      raw = const [];
    }

    final items = raw
        .whereType<Map>()
        .map((n) => AppNotification.fromJson(Map<String, dynamic>.from(n)))
        .toList();

    final serverUnread =
        data is Map && data['unread'] != null ? safeInt(data['unread']) : null;
    final unread =
        serverUnread ?? items.where((n) => !n.read).length;

    return NotificationFeed(items: items, unread: unread);
  }

  /// Marks one notification read ([id] given) or all of them.
  Future<void> markRead({int? id}) async {
    await _notificationService.markRead(id: id);
  }
}
