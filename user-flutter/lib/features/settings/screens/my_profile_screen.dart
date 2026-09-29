import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/top_bar.dart';
import '../../auth/providers/auth_provider.dart';
import '../../dashboard/providers/dashboard_provider.dart';

/// "My Profile" (docs/03_APP_FLOW.md: reachable from the avatar menu).
///
/// Read-only view of the student's identity and registration status, plus the
/// app's Log Out action (Avatar → My Profile → Log Out). Account details come
/// from the authenticated session; eligibility from the registration payload.
class MyProfileScreen extends ConsumerWidget {
  const MyProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final student = ref.watch(authProvider.select((state) => state.student));
    final registrationAsync = ref.watch(registrationDataProvider);
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'My Profile'),
      body: ListView(
        padding: AppSpacing.screenPadding,
        children: [
          AppCard(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              children: [
                CachedAvatar(
                  imageUrl: student?.avatarUrl,
                  radius: AppMetrics.avatarLg,
                  fallbackIcon: Icons.person,
                ),
                AppSpacing.vMd,
                Text(
                  student?.name ?? '—',
                  textAlign: TextAlign.center,
                  style: appText.headlineMedium,
                ),
                AppSpacing.vXs,
                Text(
                  student?.email ?? '—',
                  textAlign: TextAlign.center,
                  style: appText.bodySmall,
                ),
              ],
            ),
          ),
          AppSpacing.vMd,
          _InfoRow(
            icon: Icons.badge_outlined,
            label: 'Student ID',
            value: student?.studentId,
          ),
          _InfoRow(
            icon: Icons.how_to_vote_outlined,
            label: 'Vote status',
            value:
                student?.hasVoted == true ? 'Already voted' : 'Not yet voted',
          ),
          _InfoRow(
            icon: Icons.school_outlined,
            label: 'Year Level',
            value: student?.yearLevel,
          ),
          _InfoRow(
            icon: Icons.view_module_outlined,
            label: 'Block',
            value: student?.blockNumber,
          ),
          _InfoRow(
            icon: Icons.menu_book_outlined,
            label: 'Course',
            value: student?.course,
          ),
          _InfoRow(
            icon: Icons.verified_outlined,
            label: 'Eligibility',
            value: switch (registrationAsync) {
              AsyncData(:final value) => value.eligibilityStatus,
              _ => '—',
            },
          ),
          AppSpacing.vLg,
          OutlinedButton.icon(
            onPressed: () => ref.read(authProvider.notifier).logout(),
            icon: const Icon(Icons.logout),
            label: const Text('Log Out'),
            style: OutlinedButton.styleFrom(
              foregroundColor: scheme.error,
              side: BorderSide(color: scheme.error),
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(
                borderRadius: AppRadius.smAll,
              ),
            ),
          ),
          AppSpacing.vSm,
          Text(
            'Logging out signs you out of this device only. '
            'Voter registration and eligibility are managed by your school registrar.',
            textAlign: TextAlign.center,
            style: appText.labelSmall,
          ),
        ],
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String? value;

  const _InfoRow({
    required this.icon,
    required this.label,
    required this.value,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: AppCard(
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppMetrics.rowPaddingV,
        ),
        child: Row(
          children: [
            Icon(icon, size: 20, color: Theme.of(context).colorScheme.primary),
            AppSpacing.hSm,
            Expanded(
              child: Text(
                label,
                style: appText.bodySmall,
              ),
            ),
            Text(
              value == null || value!.isEmpty ? '—' : value!,
              textAlign: TextAlign.end,
              style: appText.labelLarge,
            ),
          ],
        ),
      ),
    );
  }
}
