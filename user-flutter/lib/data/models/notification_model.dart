import '../../core/utils/safe_json.dart';

/// An in-app notification from `GET /api/notifications`.
///
/// Named `AppNotification` rather than `Notification` to avoid colliding with
/// Flutter's `Notification` widget class. The backend writes one row per
/// platform event (phase change, announcement, candidacy status, vote
/// confirmation, account/eligibility change, or an admin broadcast) and scopes
/// every row to a single recipient.
class AppNotification {
  final int id;

  /// Semantic tone used for the leading icon/colour:
  /// `info` / `success` / `warning` / `danger`.
  final String type;
  final String title;
  final String body;

  /// App route the notification points at (e.g. `/ballot`), or null when it is
  /// informational only.
  final String? link;
  final bool read;
  final String? createdAt;

  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    this.link,
    this.read = false,
    this.createdAt,
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    return AppNotification(
      id: safeInt(json['id']),
      type: safeString(json['type'], fallback: 'info'),
      title: safeString(json['title'], fallback: 'Notification'),
      body: safeString(json['body']),
      link: () {
        final raw = safeString(json['link']).trim();
        return raw.isEmpty ? null : raw;
      }(),
      read: json['read'] == true,
      createdAt: json['created_at']?.toString(),
    );
  }

  AppNotification copyWith({bool? read}) {
    return AppNotification(
      id: id,
      type: type,
      title: title,
      body: body,
      link: link,
      read: read ?? this.read,
      createdAt: createdAt,
    );
  }
}

/// One page of the caller's notification feed plus the authoritative unread
/// count (`unread` counts *all* unread rows, not just the returned page).
class NotificationFeed {
  final List<AppNotification> items;
  final int unread;

  const NotificationFeed({required this.items, required this.unread});
}
