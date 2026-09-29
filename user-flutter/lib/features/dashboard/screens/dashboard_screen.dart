import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/top_bar.dart';
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

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Dashboard'),
      body: RefreshIndicator(
        onRefresh: () async {
          // Fresh announcements + a phase re-check (also refreshes results)
          // whenever the student pulls down.
          ref.invalidate(announcementsProvider);
          ref.read(electionStatusEpochProvider.notifier).state++;
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: AppSpacing.screenPadding,
          children: const [
            PhaseHeroCard(),
            AppSpacing.vMd,
            BallotProgressTile(),
            AppSpacing.vMd,
            QuickActionsGrid(),
            AppSpacing.vLg,
            AnnouncementsCarousel(),
            AppSpacing.vSm,
          ],
        ),
      ),
    );
  }
}
