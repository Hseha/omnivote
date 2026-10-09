import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_spacing.dart';
import '../../core/utils/launch_url.dart';
import '../../core/widgets/app_button.dart';
import '../../core/widgets/app_card.dart';
import '../../features/app_update/providers/app_update_provider.dart';

/// Dashboard banner shown when a newer student-app release exists.
///
/// Renders as a compact card above the phase hero with "What's new" and
/// "Download" actions, plus a dismiss button for the session. When nothing is
/// pending it renders nothing (including absorbing no vertical space), so the
/// dashboard looks identical to before this feature shipped.
class AppUpdateBanner extends ConsumerWidget {
  const AppUpdateBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final available =
        ref.watch(appUpdateProvider.select((s) => s.available));
    final dismissed =
        ref.watch(appUpdateProvider.select((s) => s.dismissed));
    final latest = ref.watch(appUpdateProvider.select((s) => s.latest));

    if (!available || dismissed || latest == null) {
      return const SizedBox.shrink();
    }

    final scheme = Theme.of(context).colorScheme;

    return Column(
      children: [
        AppCard(
          borderColor: latest.isTestChannel
              ? const Color(0xFFB26A00)
              : scheme.primary.withValues(alpha: 0.5),
          child: IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Icon(
                  Icons.system_update_alt,
                  color: latest.isTestChannel
                      ? const Color(0xFFB26A00)
                      : scheme.primary,
                  size: 28,
                ),
                AppSpacing.hMd,
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        latest.isTestChannel
                            ? 'Test build v${latest.version} is ready'
                            : 'OmniVote v${latest.version} is ready',
                        style: Theme.of(
                          context,
                        ).textTheme.titleSmall!.copyWith(fontWeight: FontWeight.bold),
                      ),
                      AppSpacing.vXs,
                      Text(
                        latest.isTestChannel
                            ? 'A newer test build is out for device testing.'
                            : 'A newer version is available to download.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      AppSpacing.vSm,
                      Row(
                        children: [
                          AppButton.text(
                            label: 'What\'s new',
                            onPressed: () => context.push('/app-update'),
                          ),
                          AppSpacing.hSm,
                          AppButton.text(
                            label: 'Download',
                            icon: Icons.download,
                            onPressed: () =>
                                launchExternalUrl(latest.htmlUrl),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                IconButton(
                  tooltip: 'Dismiss',
                  icon: const Icon(Icons.close),
                  onPressed: ref
                      .read(appUpdateProvider.notifier)
                      .dismissBanner,
                ),
              ],
            ),
          ),
        ),
        AppSpacing.vMd,
      ],
    );
  }
}