import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/section_header.dart';
import '../../candidacy/providers/candidacy_provider.dart';
import 'eligibility_faq.dart';

/// 2x2 quick-action grid. Candidates, receipt verification and run-for-office
/// navigate; the Eligibility FAQ tile expands the existing [EligibilityFAQ]
/// widget in place. Tiles are [AppCard]s (ripple tap feedback, token radii)
/// under a shared [SectionHeader].
class QuickActionsGrid extends ConsumerStatefulWidget {
  const QuickActionsGrid({super.key});

  @override
  ConsumerState<QuickActionsGrid> createState() => _QuickActionsGridState();
}

class _QuickActionsGridState extends ConsumerState<QuickActionsGrid> {
  bool _showFaq = false;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SectionHeader(title: 'Quick actions'),
        AppSpacing.vSm,
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: AppSpacing.sm,
          crossAxisSpacing: AppSpacing.sm,
          childAspectRatio: 1.5,
          children: [
            _ActionTile(
              icon: Icons.people_outline,
              label: 'Candidates',
              onTap: () => context.go('/candidates'),
            ),
            _ActionTile(
              icon: Icons.receipt_long_outlined,
              label: 'Verify receipt',
              onTap: () => context.go('/results'),
            ),
            // Status-aware: "Run for office" before applying, "My Candidacy"
            // (with a status dot) once an application exists — the latter
            // deep-links into the dedicated hub instead of the apply form.
            _CandidacyActionTile(),
            _ActionTile(
              icon: _showFaq ? Icons.expand_less : Icons.expand_more,
              label: 'Eligibility FAQ',
              onTap: () => setState(() => _showFaq = !_showFaq),
            ),
          ],
        ),
        if (_showFaq) ...[
          AppSpacing.vSm,
          const EligibilityFAQ(),
        ],
      ],
    );
  }
}

class _ActionTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  const _ActionTile({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return AppCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(
        horizontal: AppMetrics.rowPaddingH,
        vertical: AppMetrics.rowPaddingV,
      ),
      child: Row(
        children: [
          Icon(icon, color: Theme.of(context).colorScheme.primary),
          AppSpacing.hSm,
          Expanded(
            child: Text(
              label,
              style: AppTextStyles.of(context).titleSmall,
            ),
          ),
        ],
      ),
    );
  }
}

/// The candidacy quick action: "Run for office" before an application
/// exists, "My Candidacy" (with a status dot) afterwards. Backed by the
/// cached [myApplicationProvider] so the label survives tab switches and
/// updates right after a submit/withdraw invalidates it.
class _CandidacyActionTile extends ConsumerWidget {
  const _CandidacyActionTile();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final application = ref.watch(myApplicationProvider).valueOrNull;
    final status = application?.status;

    final (label, dotColor) = switch (status) {
      'approved' => (
        'My Candidacy · Approved',
        AppColors.successGreen,
      ),
      'rejected' => (
        'My Candidacy · Rejected',
        Theme.of(context).colorScheme.error,
      ),
      'pending' => (
        'My Candidacy · Under review',
        Theme.of(context).colorScheme.primary,
      ),
      _ => ('Run for office', null),
    };

    // Applied students land on the dedicated hub; everyone else gets the
    // apply form.
    final destination = status == null || status == 'none'
        ? '/candidacy'
        : '/candidacy/status';

    return AppCard(
      onTap: () => context.go(destination),
      padding: const EdgeInsets.symmetric(
        horizontal: AppMetrics.rowPaddingH,
        vertical: AppMetrics.rowPaddingV,
      ),
      child: Row(
        children: [
          Icon(
            Icons.how_to_vote_outlined,
            color: Theme.of(context).colorScheme.primary,
          ),
          AppSpacing.hSm,
          Expanded(
            child: Text(
              label,
              style: AppTextStyles.of(context).titleSmall,
            ),
          ),
          if (dotColor != null)
            Container(
              width: 10,
              height: 10,
              decoration: BoxDecoration(
                color: dotColor,
                shape: BoxShape.circle,
              ),
            ),
        ],
      ),
    );
  }
}

