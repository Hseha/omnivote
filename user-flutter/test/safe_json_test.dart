import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/utils/safe_json.dart';

void main() {
  group('safeInt', () {
    test('accepts ints and numeric strings', () {
      expect(safeInt(42), 42);
      expect(safeInt('42'), 42);
      expect(safeInt(3.7), 3);
    });

    test('falls back to the default for null/garbage', () {
      expect(safeInt(null), 0);
      expect(safeInt('n/a'), 0);
      expect(safeInt('abc', fallback: 7), 7);
    });
  });

  group('safeString', () {
    test('coerces values and tolerates null', () {
      expect(safeString('ok'), 'ok');
      expect(safeString(12), '12');
      expect(safeString(null), '');
      expect(safeString(null, fallback: 'fb'), 'fb');
    });
  });

  group('safeHttpImageUrl', () {
    test('accepts http(s) URLs', () {
      expect(safeHttpImageUrl('https://x.test/a.png'), 'https://x.test/a.png');
      expect(safeHttpImageUrl('http://x.test/a.png'), 'http://x.test/a.png');
    });

    test('rejects empty, relative and non-http schemes', () {
      expect(safeHttpImageUrl(null), isNull);
      expect(safeHttpImageUrl(''), isNull);
      expect(safeHttpImageUrl('  '), isNull);
      expect(safeHttpImageUrl('/local/a.png'), isNull);
      expect(safeHttpImageUrl('file:///etc/passwd'), isNull);
      expect(safeHttpImageUrl('javascript:alert(1)'), isNull);
    });
  });
}