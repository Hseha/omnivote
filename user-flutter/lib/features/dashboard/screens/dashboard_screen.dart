import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/top_bar.dart';
import '../../auth/providers/auth_provider.dart';
import '../providers/announcements_provider.dart';
import '../providers/election_status_provider.dart';
import '../widgets/announcements_card.dart';
import '../widgets/welcome_banner.dart';

/// Dashboard: a welcome banner (greeting + phase + department/course) and the
/// published announcements. Registration details live in My Profile; the
/// other screens (Vote Now, Candidates, My Ballot, Results) are one tap away
/// in the bottom navigation, so this tab stays compact and scroll-free.
class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final student = ref.watch(authProvider).student;

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
          padding: const EdgeInsets.all(16.0),
          children: const [
            WelcomeBanner(),
            SizedBox(height: 20),
            AnnouncementsCard(),
            SizedBox(height: 24),
          ],
        ),
      ),
    );
  }
}