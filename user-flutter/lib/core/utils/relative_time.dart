/// Formats an ISO-8601 timestamp as a short "time ago" label.
///
/// Deliberately dependency-free (no `intl`): the notification feed only needs
/// the coarse buckets below, and the app's `pubspec` does not carry `intl`.
/// Returns an empty string when [iso] is null or unparseable so a malformed
/// server value degrades to "no timestamp" instead of crashing the feed.
///
/// [now] is injectable for deterministic tests.
String relativeTimeFromIso(String? iso, {DateTime? now}) {
  if (iso == null || iso.isEmpty) return '';

  final parsed = DateTime.tryParse(iso);
  if (parsed == null) return '';

  final local = parsed.toLocal();
  final reference = now ?? DateTime.now();
  final diff = reference.difference(local);

  if (diff.isNegative || diff.inSeconds < 60) return 'Just now';
  if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
  if (diff.inHours < 24) return '${diff.inHours}h ago';
  if (diff.inDays < 7) return '${diff.inDays}d ago';

  final month = local.month.toString().padLeft(2, '0');
  final day = local.day.toString().padLeft(2, '0');
  return '${local.year}-$month-$day';
}
