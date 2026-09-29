import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/painting.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/utils/data_image_cache.dart';

/// Inline `data:image/…;base64,…` avatars used to be decoded (at full
/// resolution) inside every `build()` call. The cache must decode each URL once,
/// hand out the same byte list so the engine's image cache can hit, and size the
/// decode to the widget instead of the uploaded image.
void main() {
  final payload = base64Encode(List<int>.generate(512, (i) => i % 251));
  final dataUrl = 'data:image/png;base64,$payload';

  setUp(DataImageCache.clear);

  group('bytesOf', () {
    test('decodes once and reuses the identical byte list', () {
      final first = DataImageCache.bytesOf(dataUrl);
      final second = DataImageCache.bytesOf(dataUrl);

      expect(first, isNotNull);
      expect(first, Uint8List.fromList(List<int>.generate(512, (i) => i % 251)));
      expect(identical(first, second), isTrue,
          reason: 'a rebuild must reuse the memoised decode, not run it again');
      expect(DataImageCache.length, 1);
    });

    test('treats a different payload as a different entry', () {
      final other = 'data:image/png;base64,${base64Encode([1, 2, 3])}';

      final a = DataImageCache.bytesOf(dataUrl);
      final b = DataImageCache.bytesOf(other);

      expect(DataImageCache.length, 2);
      expect(identical(a, b), isFalse);
      expect(b, [1, 2, 3]);
    });

    test('rejects malformed input without caching a failure', () {
      expect(DataImageCache.bytesOf('not-a-data-url'), isNull);
      expect(DataImageCache.bytesOf('data:image/png;base64,'), isNull);
      expect(DataImageCache.bytesOf('data:image/png;base64,!!!not-base64!!!'),
          isNull);
      expect(DataImageCache.length, 0);
    });

    test('evicts the least recently used entry past the ceiling', () {
      for (var i = 0; i < DataImageCache.maxEntries + 1; i++) {
        DataImageCache.bytesOf('data:image/png;base64,${base64Encode([i])}');
      }

      expect(DataImageCache.length, DataImageCache.maxEntries);
    });
  });

  group('providerFor', () {
    test('wraps the memoised bytes in a ResizeImage capped to the widget size',
        () {
      final provider = DataImageCache.providerFor(
        dataUrl,
        cacheWidth: 48,
        cacheHeight: 96,
      );

      expect(provider, isA<ResizeImage>());
      final resize = provider! as ResizeImage;
      expect(resize.width, 48);
      expect(resize.height, 96);
      expect(resize.imageProvider, isA<MemoryImage>());
      expect(
        (resize.imageProvider as MemoryImage).bytes,
        DataImageCache.bytesOf(dataUrl),
        reason: 'the wrapped provider must hold the memoised decode so the '
            'engine image cache sees the same key on rebuilds',
      );
    });

    test('two providers for the same URL compare equal so the image cache hits',
        () {
      final first = DataImageCache.providerFor(dataUrl,
          cacheWidth: 48, cacheHeight: 48)!;
      final second = DataImageCache.providerFor(dataUrl,
          cacheWidth: 48, cacheHeight: 48)!;

      expect(first == second, isTrue);
    });

    test('returns null when there is nothing decodable', () {
      expect(
        DataImageCache.providerFor('junk', cacheWidth: 10, cacheHeight: 10),
        isNull,
      );
    });

    test('falls back to the plain MemoryImage when the size is unusable', () {
      final provider =
          DataImageCache.providerFor(dataUrl, cacheWidth: 0, cacheHeight: 0);

      expect(provider, isA<MemoryImage>());
      expect(provider, isNot(isA<ResizeImage>()));
    });
  });
}
