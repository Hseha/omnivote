/// Shared JSON parsing helpers used across the model layer.
///
/// The backend emits snake_case wire keys, sometimes as numeric strings, and
/// optional columns are frequently `null` — every lookup must tolerate that
/// instead of being forced into a non-nullable type (a null there used to
/// crash with `type 'Null' is not a subtype of type 'String'`).
library;

/// Parses a value that may be an int, a numeric string, or missing.
int safeInt(Object? value, {int fallback = 0}) {
  if (value == null) return fallback;
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(value.toString()) ?? fallback;
}

/// Coerces any value to its string form, tolerating nulls.
String safeString(Object? value, {String fallback = ''}) =>
    value?.toString() ?? fallback;

/// Returns [url] only when it has an http/https scheme so remote image URLs
/// fetched from the API can't trigger arbitrary scheme fetches.
String? safeHttpImageUrl(String? url) {
  final trimmed = url?.trim();
  if (trimmed == null || trimmed.isEmpty) return null;
  final lower = trimmed.toLowerCase();
  if (lower.startsWith('https://') || lower.startsWith('http://')) {
    return trimmed;
  }
  return null;
}