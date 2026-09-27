import '../../core/utils/safe_json.dart';

/// A published announcement shown to students (public `GET /api/announcements`).
/// The system auto-creates/renews one of these when results are finalized
/// (`title == 'Official Election Results'`), so winners reach the user app
/// without any manual posting.
class Announcement {
  final int id;
  final String title;
  final String body;
  final String? authorName;
  final String? authorAvatarUrl;
  final String? publishedAt;

  const Announcement({
    required this.id,
    required this.title,
    required this.body,
    this.authorName,
    this.authorAvatarUrl,
    this.publishedAt,
  });

  factory Announcement.fromJson(Map<String, dynamic> json) {
    return Announcement(
      id: safeInt(json['id']),
      title: safeString(json['title'], fallback: 'Announcement'),
      body: safeString(json['body']),
      authorName: json['author'] is Map
          ? safeString((json['author'] as Map)['name'])
          : null,
      authorAvatarUrl: json['author'] is Map
          ? ((json['author'] as Map)['avatar_url']?.toString())
          : null,
      publishedAt: json['published_at']?.toString(),
    );
  }
}