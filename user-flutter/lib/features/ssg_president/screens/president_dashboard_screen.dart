import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/top_bar.dart';
import '../../app_update/providers/app_update_provider.dart';
import '../../auth/providers/auth_provider.dart';
import '../providers/president_providers.dart';
import '../../dashboard/providers/announcements_provider.dart';

/// Dedicated dashboard for the SSG President (single-seat governance role).
///
/// Contents: role banner + own record, elected officer roster, published
/// announcements. The `/ssg/officers` and `/ssg/announcements` endpoints are
/// guarded by the `ssg_president` catalog, so this screen is only reachable
/// for a server-promoted President.
class PresidentDashboardScreen extends ConsumerWidget {
  const PresidentDashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final student = ref.watch(authProvider.select((state) => state.student));
    final role = ref.watch(authProvider.select((state) => state.role));

    if (student == null) {
      return const Scaffold(body: Center(child: Text('Not authenticated')));
    }

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: TopBar(title: 'SSG President'),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(presidentOfficersProvider);
          ref.invalidate(announcementsProvider);
          ref.read(appUpdateProvider.notifier).check();
        },
        child: ListView(
          padding: EdgeInsets.all(AppSpacing.xs),
          children: [
            _RoleBanner(
              name: student.name,
              studentId: student.studentId,
              role: role ?? '',
            ),
            const SizedBox(height: AppSpacing.md),
            _OfficerRosterSection(ref: ref),
            const SizedBox(height: AppSpacing.md),
            _AnnouncementsSection(ref: ref),
          ],
        ),
      ),
    );
  }
}

class _RoleBanner extends StatelessWidget {
  final String name;
  final String studentId;
  final String role;

  const _RoleBanner({required this.name, required this.studentId, required this.role});

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Row(
          children: [
            Icon(Icons.supervisor_account, color: primary),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('SSG President', style: Theme.of(context).textTheme.labelLarge),
                  Text('Signed in as', style: Theme.of(context).textTheme.bodySmall),
                  Text(name, style: Theme.of(context).textTheme.titleMedium),
                  Text('Handle: $studentId', style: Theme.of(context).textTheme.bodySmall),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _OfficerRosterSection extends StatelessWidget {
  final WidgetRef ref;

  const _OfficerRosterSection({required this.ref});

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(presidentOfficersProvider);
    if (state.isLoading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (state.hasError) {
      return Center(child: Text('Could not load roster: ${state.error}'));
    }
    if (state.value == null || state.value!.isEmpty) {
      return const Center(child: Text('No officers on roster yet.'));
    }
    return Column(
      children: state.value!.map((o) {
        return ListTile(
          title: Text(o['position_label'] ?? 'Officer'),
          subtitle: Text('${o['name'] ?? ''}  •  ${o['votes'] ?? ''} votes'),
        );
      }).toList(),
    );
  }
}

class _AnnouncementsSection extends StatelessWidget {
  final WidgetRef ref;

  const _AnnouncementsSection({required this.ref});

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(announcementsProvider);
    if (state.isLoading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (state.hasError) {
      return Center(child: Text('Could not load announcements: ${state.error}'));
    }
    if (state.value == null || state.value!.isEmpty) {
      return const Center(child: Text('No announcements yet.'));
    }
    return Column(
      children: state.value!.map((a) {
        return ListTile(
          title: Text(a.title),
          subtitle: Text(a.body),
        );
      }).toList(),
    );
  }
}
