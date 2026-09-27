import 'dart:convert';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import '../constants/app_colors.dart';
import '../theme/app_tokens.dart';
import '../utils/safe_json.dart';

/// Renders the admin-configured brand logo (Settings → Branding → logoUrl)
/// inside a rounded square, with explicit fallbacks like [CachedAvatar]:
///  - `http(s)` images pass through the safe-url guard,
///  - `data:image/*;base64,…` values are decoded into memory,
///  - anything else (empty, non-http, malformed) shows the default icon.
///
/// Sized for the pre-login brand mark: [size] is the box edge, [tint] colors
/// the default icon when no logo is configured.
class BrandLogo extends StatelessWidget {
  final String? logoUrl;
  final double size;
  final Color tint;

  const BrandLogo({
    super.key,
    required this.logoUrl,
    this.size = 72,
    this.tint = AppColors.primaryBlue,
  });

  @override
  Widget build(BuildContext context) {
    final url = logoUrl?.trim() ?? '';

    if (url.isEmpty) {
      return _brandBox(
        context,
        child: _defaultIcon(context),
      );
    }

    final lower = url.toLowerCase();

    Widget? child;
    if (lower.startsWith('data:image/')) {
      child = _dataImage(context, url);
    } else {
      final renderable = safeHttpImageUrl(url);
      if (renderable != null) {
        child = CachedNetworkImage(
          imageUrl: renderable,
          fit: BoxFit.cover,
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
          errorWidget: (context, url, error) => _defaultIcon(context),
        );
      }
    }

    return _brandBox(context, child: child ?? _defaultIcon(context));
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

  Widget _defaultIcon(BuildContext context) {
    return Container(
      color: tint,
      width: size,
      height: size,
      child: Icon(
        Icons.how_to_vote,
        size: size * 0.52,
        color: Colors.white,
      ),
    );
  }

  Widget? _dataImage(BuildContext context, String url) {
    final comma = url.indexOf(',');
    if (comma < 0) return null;
    final bytes = url.substring(comma + 1).trim();
    if (bytes.isEmpty) return null;
    try {
      final decoded = base64Decode(bytes);
      return Image.memory(
        decoded,
        fit: BoxFit.cover,
        gaplessPlayback: true,
        errorBuilder: (context, error, stackTrace) => _defaultIcon(context),
      );
    } catch (_) {
      return null;
    }
  }
}