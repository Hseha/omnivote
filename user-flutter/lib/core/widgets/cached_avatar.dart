import 'dart:convert';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import '../constants/app_colors.dart';
import '../theme/app_tokens.dart';
import '../utils/safe_json.dart';

/// Renders a remote avatar/photo with disk/memory caching and explicit
/// placeholder/error fallbacks (perf #2 + sec #5 in the Flutter audit).
///
/// Source-agnostic on purpose so panel avatars picked in the admin SPA's
/// Settings → Profile render correctly wherever they surface (header,
/// announcements, etc.):
///  - `http(s)` SVG (DiceBear pixel-art presets) is rewritten to DiceBear's
///    `/png` variant, because Flutter's image pipeline cannot decode SVG;
///  - `data:image/*;base64,…` values are decoded into memory;
///  - anything else (including non-http(s) jibberish) renders the fallback
///    instead of being handed to an image provider.
///
/// Fallback behaviour is safe by design: a URL that is empty or not http(s)
/// renders the placeholder instead of being handed to an image provider.
class CachedAvatar extends StatelessWidget {
  final String? imageUrl;
  final double radius;
  final IconData fallbackIcon;
  final String? initials;

  const CachedAvatar({
    super.key,
    required this.imageUrl,
    this.radius = 24,
    this.fallbackIcon = Icons.person,
    this.initials,
  });

  @override
  Widget build(BuildContext context) {
    final url = imageUrl?.trim();
    final size = radius * 2;

    if (url == null || url.isEmpty) {
      return ClipOval(
        child: SizedBox(width: size, height: size, child: _fallback(context, size: size)),
      );
    }

    final lower = url.toLowerCase();

    Widget? child;

    if (lower.startsWith('data:image/')) {
      child = _dataImage(context, url, size);
    } else {
      final renderable = _renderableHttpUrl(url);
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
          errorWidget: (context, url, error) => _fallback(context, size: size),
        );
      }
    }

    return ClipOval(
      child: SizedBox(
        width: size,
        height: size,
        child: child ?? _fallback(context, size: size),
      ),
    );
  }

  /// Decodes a `data:image/png;base64,…` avatar (the admin photo upload) into
  /// an in-memory image, or null on garbage input.
  Widget? _dataImage(BuildContext context, String url, double size) {
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
        errorBuilder: (context, error, stackTrace) => _fallback(context, size: size),
      );
    } catch (_) {
      return null;
    }
  }

  /// Rewrites a DiceBear SVG URL to its PNG variant so Flutter can decode it;
  /// plain http(s) images pass through untouched; anything else is rejected.
  String? _renderableHttpUrl(String? url) {
    final safe = safeHttpImageUrl(url);
    if (safe == null) return null;
    final lower = safe.toLowerCase();
    final svgSegment = lower.indexOf('/svg');
    if (svgSegment >= 0) {
      return '${safe.substring(0, svgSegment)}/png${safe.substring(svgSegment + 4)}';
    }
    final svgFile = lower.indexOf('.svg');
    if (svgFile >= 0) {
      return '${safe.substring(0, svgFile)}.png${safe.substring(svgFile + 4)}';
    }
    return safe;
  }

  // "Michael Cruz" -> "MC", "Dexter" -> "D"
  static String _initialsOf(String name) {
    final parts = name
        .split(RegExp(r'\s+'))
        .where((p) => p.isNotEmpty)
        .toList();
    if (parts.isEmpty) return '';
    if (parts.length == 1) return parts.first[0];
    return '${parts.first[0]}${parts.last[0]}';
  }

  Widget _fallback(BuildContext context, {required double size}) {
    final text = initials?.trim();
    if (text != null && text.isNotEmpty) {
      return Container(
        width: size,
        height: size,
        color: AppColors.primaryBlue,
        alignment: Alignment.center,
        child: Text(
          _initialsOf(text),
          style: TextStyle(
            color: Colors.white,
            fontSize: size * 0.35,
            fontWeight: FontWeight.w600,
          ),
        ),
      );
    }
    return Container(
      width: size,
      height: size,
      color: context.appBackground,
      child: Icon(fallbackIcon, size: size * 0.6, color: context.appTextSecondary),
    );
  }
}