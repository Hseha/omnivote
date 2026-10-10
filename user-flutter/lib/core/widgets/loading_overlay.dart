import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../services/loading_service.dart';

/// Full-screen overlay shown for *every* network call in the app.
///
/// The repositories report their in-flight calls through [LoadingController];
/// this widget turns that single counter into one global spinner, so no
/// screen has to invent its own loading state. When a call runs past the
/// hang timeout the spinner is replaced by a 'still waiting' card with a
/// dismissal, so the spinner always ends and a student never stares at a
/// blank white screen.
class LoadingOverlay extends ConsumerWidget {
  const LoadingOverlay({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(loadingServiceProvider);
    if (!state.isLoading && !state.stale) {
      return const SizedBox.shrink();
    }

    final stale = state.stale;

    return Positioned.fill(
      child: AbsorbPointer(
        absorbing: !stale,
        child: Container(
          color: stale ? const Color(0x99000000) : const Color(0x14000000),
          alignment: Alignment.center,
          child: Material(
            color: Colors.transparent,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 24),
              decoration: BoxDecoration(
                color: Theme.of(context).cardColor,
                borderRadius: BorderRadius.circular(16),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (!stale) ...[
                    const SizedBox(
                      width: 28,
                      height: 28,
                      child: CircularProgressIndicator(strokeWidth: 3),
                    ),
                    const SizedBox(height: 16),
                    const Text('Loading…'),
                  ] else ...[
                    const Icon(Icons.cloud_off, size: 32),
                    const SizedBox(height: 12),
                    const Text(
                      'The school server is taking longer than usual. '
                      'Check your connection and try again.',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 16),
                    TextButton(
                      onPressed: () =>
                          ref.read(loadingServiceProvider.notifier).forceIdle(),
                      child: const Text('Dismiss'),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
