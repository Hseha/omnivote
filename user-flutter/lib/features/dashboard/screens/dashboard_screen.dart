import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/launch_url.dart';
import '../../../core/widgets/app_update_banner.dart';
import '../../../core/widgets/top_bar.dart';
import '../../app_update/providers/app_update_provider.dart';
import '../../auth/providers/auth_provider.dart';
import '../providers/announcements_provider.dart';
import '../providers/election_status_provider.dart';
import '../widgets/announcements_carousel.dart';
import '../widgets/ballot_progress_tile.dart';
import '../widgets/phase_hero_card.dart';
import '../widgets/quick_actions_grid.dart';

/// Dashboard: a phase-aware hero (greeting + stepper + countdown/CTA), ballot
/// progress, a quick-action grid (which now hosts the eligibility FAQ) and the
/// published announcements as a scrollable row. Registration details and the
/// other screens (Vote Now, Candidates, My Ballot, Results) are still one tap
/// away in the bottom navigation, so this tab stays compact and scroll-free.
class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final student = ref.watch(authProvider.select((state) => state.student));

    if (student == null) {
      return const Scaffold(body: Center(child: Text('Not authenticated')));
    }

    // One-time "update available" dialog: armed by the provider the first time
    // a newer release is detected, and only once per version (the provider
    // records it in shared preferences when the dialog is shown).
    ref.listen<String?>(
      appUpdateProvider.select((state) => state.dialogVersion),
      (previous, version) {
        if (version == null || previous == version) return;
        _showUpdateDialog(context, ref, version);
        ref.read(appUpdateProvider.notifier).markDialogShown();
      },
    );

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Dashboard'),
      body: RefreshIndicator(
        onRefresh: () async {
          // Fresh announcements + a phase re-check (also refreshes results)
          // + a release re-check whenever the student pulls down.
          ref.invalidate(announcementsProvider);
          ref.read(electionStatusEpochProvider.notifier).state++;
          ref.read(appUpdateProvider.notifier).check();
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: AppSpacing.screenPadding,
          children: [
            const AppUpdateBanner(),
            const PhaseHeroCard(),
            AppSpacing.vMd,
            const BallotProgressTile(),
            AppSpacing.vMd,
            const QuickActionsGrid(),
            AppSpacing.vLg,
            const AnnouncementsCarousel(),
            AppSpacing.vSm,
          ],
        ),
      ),
    );
  }

  static void _showUpdateDialog(
    BuildContext context,
    WidgetRef ref,
    String version,
  ) {
    final latest = ref.read(appUpdateProvider).latest;
    if (latest == null) return;

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!context.mounted) return;
      showDialog<void>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          icon: Icon(
            Icons.system_update_alt,
            size: 40,
            color: Theme.of(dialogContext).colorScheme.primary,
          ),
          title: Text(
            latest.isTestChannel
                ? 'Test build v$version ready'
                : 'Update available',
          ),
          content: SingleChildScrollView(
            child: Text(
              latest.isTestChannel
                  ? 'OmniVote v$version is out for device testing.'
                  : 'OmniVote v$version is ready to download.',
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(),
              child: const Text('Later'),
            ),
            TextButton(
              onPressed: () {
                Navigator.of(dialogContext).pop();
                launchExternalUrl(latest.htmlUrl);
              },
              child: const Text('Download'),
            ),
            FilledButton(
              onPressed: () {
                Navigator.of(dialogContext).pop();
                context.push('/app-update');
              },
              child: const Text('What\'s new'),
            ),
          ],
        ),
      );
    });
  }
}
