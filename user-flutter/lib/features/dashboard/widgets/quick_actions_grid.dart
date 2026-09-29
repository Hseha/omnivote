import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/section_header.dart';
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
            _ActionTile(
              icon: Icons.how_to_vote_outlined,
              label: 'Run for office',
              onTap: () => context.go('/candidacy'),
            ),
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
