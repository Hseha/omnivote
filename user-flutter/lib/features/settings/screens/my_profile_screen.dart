import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/theme/app_tokens.dart';
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
    final student = ref.watch(authProvider).student;
    final registrationAsync = ref.watch(registrationDataProvider);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'My Profile'),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                children: [
                  CachedAvatar(
                    imageUrl: student?.avatarUrl,
                    radius: 44,
                    fallbackIcon: Icons.person,
                  ),
                  const SizedBox(height: 16),
                  Text(
                    student?.name ?? '—',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.bold,
                      color: context.appTextPrimary,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    student?.email ?? '—',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: context.appTextSecondary),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          _InfoRow(
            icon: Icons.badge_outlined,
            label: 'Student ID',
            value: student?.studentId,
          ),
          _InfoRow(
            icon: Icons.how_to_vote_outlined,
            label: 'Vote status',
            value: student?.hasVoted == true ? 'Already voted' : 'Not yet voted',
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
          const SizedBox(height: 24),
          OutlinedButton.icon(
            onPressed: () => ref.read(authProvider.notifier).logout(),
            icon: const Icon(Icons.logout),
            label: const Text('Log Out'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.errorRed,
              side: const BorderSide(color: AppColors.errorRed),
              padding: const EdgeInsets.symmetric(vertical: 14),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Logging out signs you out of this device only. '
            'Voter registration and eligibility are managed by your school registrar.',
            textAlign: TextAlign.center,
            style: TextStyle(color: context.appTextSecondary, fontSize: 12),
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
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        child: Row(
          children: [
            Icon(icon, size: 20, color: AppColors.primaryBlue),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                label,
                style: TextStyle(
                  color: context.appTextSecondary,
                  fontSize: 14,
                ),
              ),
            ),
            Text(
              value == null || value!.isEmpty ? '—' : value!,
              textAlign: TextAlign.end,
              style: TextStyle(
                fontWeight: FontWeight.w600,
                fontSize: 14,
                color: context.appTextPrimary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}