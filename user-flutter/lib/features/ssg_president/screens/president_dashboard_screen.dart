import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/error_message.dart';
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
    final setupPending =
        ref.watch(authProvider.select((state) => state.ssgSetupPending));

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
            if (setupPending) const _SetupPasswordBanner(),
            if (setupPending) const SizedBox(height: AppSpacing.md),
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

/// Non-blocking prompt for a freshly-granted President to redeem their one-time
/// setup link. Tapping it opens the notification feed where the link lives; it
/// deliberately does not gate the dashboard, so the feed stays reachable.
class _SetupPasswordBanner extends StatelessWidget {
  const _SetupPasswordBanner();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      color: scheme.errorContainer,
      child: ListTile(
        leading: Icon(Icons.lock_reset, color: scheme.onErrorContainer),
        title: Text(
          'Set your password',
          style: TextStyle(
            color: scheme.onErrorContainer,
            fontWeight: FontWeight.bold,
          ),
        ),
        subtitle: Text(
          'Open your notifications and tap the setup link to choose your own '
          'password. It can be used once.',
          style: TextStyle(color: scheme.onErrorContainer),
        ),
        trailing: Icon(Icons.chevron_right, color: scheme.onErrorContainer),
        onTap: () => context.push('/notifications'),
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
      return Center(
        child: Text(apiErrorMessage(
          state.error!,
          fallback: 'Could not load the officer roster.',
        )),
      );
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
      return Center(
        child: Text(apiErrorMessage(
          state.error!,
          fallback: 'Could not load announcements.',
        )),
      );
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
