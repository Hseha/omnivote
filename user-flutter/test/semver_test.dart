import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/utils/semver.dart';

void main() {
  group('SemVer.tryParse', () {
    test('parses stable release tags', () {
      expect(SemVer.tryParse('v1.2.0'), const SemVer(1, 2, 0));
      expect(SemVer.tryParse('v0.0.1'), const SemVer(0, 0, 1));
    });

    test('parses device-test prerelease tags ignoring the channel', () {
      expect(SemVer.tryParse('v1.2.0-test.3'), const SemVer(1, 2, 0));
      expect(SemVer.tryParse('v2.0.0-test.1'), const SemVer(2, 0, 0));
    });

    test('parses the installed pubspec version ignoring the build number', () {
      expect(SemVer.tryParse('1.1.0+2'), const SemVer(1, 1, 0));
      expect(SemVer.tryParse('1.1.0'), const SemVer(1, 1, 0));
    });

    test('accepts surrounding whitespace and a bare v prefix', () {
      expect(SemVer.tryParse('  v1.2.3  '), const SemVer(1, 2, 3));
    });

    test('returns null for anything without a clean X.Y.Z core', () {
      expect(SemVer.tryParse(null), isNull);
      expect(SemVer.tryParse(''), isNull);
      expect(SemVer.tryParse('v'), isNull);
      expect(SemVer.tryParse('1.2'), isNull);
      expect(SemVer.tryParse('1.2.3.4'), isNull);
      expect(SemVer.tryParse('a.b.c'), isNull);
    });
  });

  group('SemVer.compareTo', () {
    test('orders by major then minor then patch', () {
      const a = SemVer(1, 2, 0);
      const b = SemVer(1, 3, 0);
      const c = SemVer(2, 0, 0);

      expect(a.compareTo(a), 0);
      expect(a.compareTo(b), lessThan(0));
      expect(b.compareTo(a), greaterThan(0));
      expect(b.compareTo(c), lessThan(0));
      expect(c.compareTo(a), greaterThan(0));
    });

    test('ignores channel/build suffixes in equality', () {
      expect(
        SemVer.tryParse('v1.2.0') == SemVer.tryParse('1.2.0+5'),
        isTrue,
      );
    });
  });
}