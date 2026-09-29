import 'dart:collection';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/painting.dart';

/// Memoised decoding for `data:image/…;base64,…` avatars and logos.
///
/// Avatars picked in the admin SPA's Settings → Profile arrive as inline base64
/// data URLs, which means two costs that used to be paid on **every rebuild of
/// every row**:
///
///  1. `base64Decode` of the whole payload (a 1024×1024 JPEG is ~100 KB of
///     base64 → ~75 KB of bytes, allocated fresh each time), and
///  2. a **full-resolution** bitmap decode by the engine — a 30 px avatar was
///     being decoded at 1024 px and then downscaled for display.
///
/// [bytesOf] turns (1) into a one-time cost per URL, and [providerFor] feeds the
/// engine a [ResizeImage] so (2) decodes straight to the size the widget
/// actually paints. Because the same [Uint8List] instance is reused, the
/// resulting [MemoryImage] compares equal to the previous one, so
/// [PaintingBinding.imageCache] also serves the decoded bitmap on rebuilds
/// instead of re-decoding it.
///
/// The map is small on purpose: it caches *decodes*, not bitmaps (the engine's
/// image cache already does that, with its own byte budget). Entries are
/// evicted oldest-first once [maxEntries] is exceeded.
class DataImageCache {
  DataImageCache._();

  /// Upper bound on retained base64 decodes (~32 avatars). Avatars are small, so
  /// this is kilobytes of `dart:typed_data`, not megabytes.
  static const int maxEntries = 32;

  static final LinkedHashMap<String, Uint8List> _decoded =
      LinkedHashMap<String, Uint8List>();

  /// For tests: forget every memoised decode.
  @visibleForTesting
  static void clear() => _decoded.clear();

  /// Number of memoised entries (for tests).
  @visibleForTesting
  static int get length => _decoded.length;

  /// The decoded bytes of a `data:image/…;base64,…` URL, or null when the value
  /// is not decodable (no comma, empty payload, invalid base64).
  ///
  /// A null result is *not* cached: garbage input is rare, and caching a failure
  /// would need a separate sentinel map for no real benefit.
  static Uint8List? bytesOf(String dataUrl) {
    final cached = _decoded.remove(dataUrl);
    if (cached != null) {
      // Re-insert so the map stays in least-recently-used order.
      _decoded[dataUrl] = cached;
      return cached;
    }

    final decoded = _decode(dataUrl);
    if (decoded == null) return null;

    _decoded[dataUrl] = decoded;
    while (_decoded.length > maxEntries) {
      _decoded.remove(_decoded.keys.first);
    }
    return decoded;
  }

  /// An image provider for [dataUrl] that decodes at most [cacheWidth] ×
  /// [cacheHeight] device pixels (the avatar box × device pixel ratio), or null
  /// when there is nothing decodable to paint.
  static ImageProvider? providerFor(
    String dataUrl, {
    required int cacheWidth,
    required int cacheHeight,
  }) {
    final bytes = bytesOf(dataUrl);
    if (bytes == null) return null;

    final image = MemoryImage(bytes);
    // ResizeImage asserts positive targets, and a 0 would mean "unbounded" to
    // the engine anyway.
    if (cacheWidth <= 0 || cacheHeight <= 0) return image;
    return ResizeImage(image, width: cacheWidth, height: cacheHeight);
  }

  static Uint8List? _decode(String dataUrl) {
    final comma = dataUrl.indexOf(',');
    if (comma < 0) return null;
    final payload = dataUrl.substring(comma + 1).trim();
    if (payload.isEmpty) return null;
    try {
      return base64Decode(payload);
    } catch (_) {
      return null;
    }
  }
}
