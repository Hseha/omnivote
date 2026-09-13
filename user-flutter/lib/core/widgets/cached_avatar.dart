import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import '../constants/app_colors.dart';
import '../utils/safe_json.dart';

/// Renders a remote avatar/photo with disk/memory caching and explicit
/// placeholder/error fallbacks (perf #2 + sec #5 in the Flutter audit).
///
/// Fallback behaviour is safe by design: a URL that is empty or not http(s)
/// renders the placeholder instead of being handed to an image provider.
class CachedAvatar extends StatelessWidget {
  final String? imageUrl;
  final double radius;
  final IconData fallbackIcon;

  const CachedAvatar({
    super.key,
    required this.imageUrl,
    this.radius = 24,
    this.fallbackIcon = Icons.person,
  });

  @override
  Widget build(BuildContext context) {
    final url = safeHttpImageUrl(imageUrl);
    final size = radius * 2;

    return ClipOval(
      child: SizedBox(
        width: size,
        height: size,
        child: url != null
            ? CachedNetworkImage(
                imageUrl: url,
                fit: BoxFit.cover,
                placeholder: (context, url) => Container(
                  color: AppColors.backgroundGray,
                  child: const Center(
                    child: SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  ),
                ),
                errorWidget: (context, url, error) =>
                    _Fallback(size: size, icon: fallbackIcon),
              )
            : _Fallback(size: size, icon: fallbackIcon),
      ),
    );
  }
}

class _Fallback extends StatelessWidget {
  final double size;
  final IconData icon;

  const _Fallback({required this.size, required this.icon});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      color: AppColors.backgroundGray,
      child: Icon(icon, size: size * 0.6, color: AppColors.textSecondary),
    );
  }
}