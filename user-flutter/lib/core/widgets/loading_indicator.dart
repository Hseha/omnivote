import 'package:flutter/material.dart';

/// Centered progress spinner in the theme's primary role — which follows the
/// school branding accent at runtime (see `AppTheme.withAccent`) — instead of
/// a hardcoded blue.
///
/// Prefer [LoadingSkeleton] for content-shaped loading states; use this only
/// for indeterminate waits with no layout to preview (splash, tiny inline
/// waits).
class LoadingIndicator extends StatelessWidget {
  const LoadingIndicator({super.key});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: CircularProgressIndicator(
        color: Theme.of(context).colorScheme.primary,
      ),
    );
  }
}
