import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/utils/relative_time.dart';

void main() {
  final now = DateTime.utc(2026, 10, 9, 12, 0, 0);

  test('renders coarse buckets', () {
    expect(
      relativeTimeFromIso('2026-10-09T11:59:30Z', now: now),
      'Just now',
    );
    expect(
      relativeTimeFromIso('2026-10-09T11:30:00Z', now: now),
      '30m ago',
    );
    expect(
      relativeTimeFromIso('2026-10-09T06:00:00Z', now: now),
      '6h ago',
    );
    expect(
      relativeTimeFromIso('2026-10-06T12:00:00Z', now: now),
      '3d ago',
    );
  });

  test('falls back to an absolute date beyond a week', () {
    expect(
      relativeTimeFromIso('2026-09-01T08:00:00Z', now: now),
      '2026-09-01',
    );
  });

  test('degrades to empty for null or unparseable values', () {
    expect(relativeTimeFromIso(null, now: now), '');
    expect(relativeTimeFromIso('', now: now), '');
    expect(relativeTimeFromIso('garbage', now: now), '');
  });

  test('treats a future timestamp as Just now', () {
    expect(
      relativeTimeFromIso('2026-10-09T12:05:00Z', now: now),
      'Just now',
    );
  });
}
