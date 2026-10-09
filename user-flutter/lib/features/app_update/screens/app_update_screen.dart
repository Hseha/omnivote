import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/launch_url.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../providers/app_update_provider.dart';

/// What's-New / update screen: the installed version, the newest published
/// release (stable or device-test), its notes and a download link.
class AppUpdateScreen extends ConsumerWidget {
  const AppUpdateScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(appUpdateProvider);
    final notifier = ref.read(appUpdateProvider.notifier);
    final appText = AppTextStyles.of(context);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'App Updates'),
      body: ListView(
        padding: AppSpacing.screenPadding,
        children: [
          Text('Your version', style: appText.titleMedium),
          AppSpacing.vXs,
          Text(
            state.installed == null
                ? 'Unknown'
                : 'Installed: v${state.installed}',
            style: appText.bodySmall,
          ),
          AppSpacing.vMd,
          _latestSection(context, ref, state, notifier),
          AppSpacing.vMd,
          AppButton.secondary(
            label: state.checking ? 'Checking…' : 'Check for updates',
            icon: Icons.refresh,
            isLoading: state.checking,
            onPressed: () async {
              await notifier.check(force: true);
              if (context.mounted &&
                  !ref.read(appUpdateProvider).available) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('You\'re up to date.')),
                );
              }
            },
          ),
          AppSpacing.vMd,
        ],
      ),
    );
  }

  Widget _latestSection(
    BuildContext context,
    WidgetRef ref,
    AppUpdateState state,
    AppUpdateNotifier notifier,
  ) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;

    if (state.checking && state.latest == null) {
      return const SizedBox(height: 160, child: LoadingIndicator());
    }
    if (state.latest == null) {
      return ErrorState(
        message:
            'Could not check for updates right now. Check your connection and try again.',
        onRetry: () => notifier.check(force: true),
      );
    }

    final latest = state.latest!;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'v${latest.version}',
                  style: appText.titleLarge,
                ),
              ),
              if (latest.isTestChannel)
                _ChannelBadge(label: 'TEST BUILD', color: const Color(0xFFB26A00))
              else
                _ChannelBadge(label: 'STABLE', color: scheme.primary),
            ],
          ),
          if (latest.publishedAt != null) ...[
            AppSpacing.vXs,
            Text(
              'Published ${_formatDate(latest.publishedAt!)}',
              style: appText.labelSmall,
            ),
          ],
          if (latest.isTestChannel) ...[
            AppSpacing.vSm,
            Text(
              'This is a device-test build for the field-testing group, not the '
              'public release.',
              style: appText.bodySmall,
            ),
          ],
          AppSpacing.vMd,
          AppButton.primary(
            label: 'Download APK',
            icon: Icons.download,
            onPressed: () => launchExternalUrl(latest.htmlUrl),
          ),
          if (latest.notes.trim().isNotEmpty) ...[
            AppSpacing.vMd,
            Divider(color: context.appBorder),
            AppSpacing.vMd,
            Text('What\'s new', style: appText.titleMedium),
            AppSpacing.vSm,
            SelectableText(latest.notes, style: appText.bodyMedium),
          ],
          if (state.available) ...[
            AppSpacing.vMd,
            Text(
              'A newer version than your installed build is available.',
              style: appText.bodySmall,
            ),
          ],
        ],
      ),
    );
  }

  static String _formatDate(DateTime value) {
    final local = value.toLocal();
    final month = local.month.toString().padLeft(2, '0');
    final day = local.day.toString().padLeft(2, '0');
    return '${local.year}-$month-$day';
  }
}

class _ChannelBadge extends StatelessWidget {
  final String label;
  final Color color;

  const _ChannelBadge({required this.label, required this.color});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: AppSpacing.xs,
      ),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        label,
        style: Theme.of(
          context,
        ).textTheme.labelSmall!.copyWith(color: color, fontWeight: FontWeight.bold),
      ),
    );
  }
}