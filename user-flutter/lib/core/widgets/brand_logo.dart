import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../theme/app_tokens.dart';
import '../utils/data_image_cache.dart';
import '../utils/safe_json.dart';

/// Renders the admin-configured brand logo (Settings → Branding → logoUrl)
/// inside a rounded square, with explicit fallbacks like [CachedAvatar]:
///  - `http(s)` images pass through the safe-url guard,
///  - `data:image/*;base64,…` values are decoded into memory,
///  - anything else (empty, non-http, malformed) shows the OmniVote mark.
///
/// Sized for the pre-login brand mark: [size] is the box edge.
class BrandLogo extends StatelessWidget {
  final String? logoUrl;
  final double size;

  const BrandLogo({super.key, required this.logoUrl, this.size = 72});

  @override
  Widget build(BuildContext context) {
    final url = logoUrl?.trim() ?? '';

    if (url.isEmpty) {
      return _brandBox(context, child: _defaultLogo());
    }

    final lower = url.toLowerCase();
    final pixels = _targetPixels(context);

    Widget? child;
    if (lower.startsWith('data:image/')) {
      child = _dataImage(context, url, pixels);
    } else {
      final renderable = safeHttpImageUrl(url);
      if (renderable != null) {
        child = CachedNetworkImage(
          imageUrl: renderable,
          fit: BoxFit.cover,
          // The splash logo is 84 logical px wide; decoding the uploaded
          // (often 1024 px) artwork at full resolution wastes both the one-off
          // decode and its resident memory for the whole session.
          memCacheWidth: pixels,
          memCacheHeight: pixels,
          placeholder: (context, url) => Container(
            color: context.appBackground,
            child: const Center(
              child: SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ),
          ),
          errorWidget: (context, url, error) => _defaultLogo(),
        );
      }
    }

    return _brandBox(context, child: child ?? _defaultLogo());
  }

  Widget _brandBox(BuildContext context, {required Widget child}) {
    return Container(
      width: size,
      height: size,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * 0.28),
        color: context.appBackground,
        border: Border.all(color: context.appBorder),
      ),
      child: child,
    );
  }

  Widget _defaultLogo() {
    return Image.asset(
      'assets/branding/omnivote_logo_4_monogram.png',
      fit: BoxFit.contain,
    );
  }

  /// Decodes an inline `data:image/*;base64,…` logo (admin Settings →
  /// Branding), or null on garbage input.
  ///
  /// Memoised per URL and decoded at the logo box's real pixel size — see
  /// [DataImageCache].
  Widget? _dataImage(BuildContext context, String url, int pixels) {
    final provider = DataImageCache.providerFor(
      url,
      cacheWidth: pixels,
      cacheHeight: pixels,
    );
    if (provider == null) return null;
    return Image(
      image: provider,
      fit: BoxFit.cover,
      gaplessPlayback: true,
      errorBuilder: (context, error, stackTrace) => _defaultLogo(),
    );
  }

  /// The logo box edge in device pixels, used as the decode target.
  int _targetPixels(BuildContext context) {
    final ratio = MediaQuery.maybeDevicePixelRatioOf(context) ?? 1.0;
    return (size * ratio).round();
  }
}
