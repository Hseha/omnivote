import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../auth/providers/auth_provider.dart';
import '../providers/dashboard_provider.dart';
import '../widgets/registration_banner.dart';
import '../widgets/registration_details_card.dart';
import '../widgets/turnout_progress.dart';
import '../widgets/eligibility_faq_card.dart';

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final authState = ref.watch(authProvider);
    final registrationAsync = ref.watch(registrationDataProvider);
    final student = authState.student;

    if (student == null) {
      return const Scaffold(body: Center(child: Text('Not authenticated')));
    }

    return Scaffold(
      backgroundColor: AppColors.backgroundGray,
      appBar: const TopBar(title: 'Dashboard'),
      body: registrationAsync.when(
        data: (registration) => ListView(
          padding: const EdgeInsets.all(16.0),
          children: [
            RegistrationBanner(
              registrationDate: registration.registrationDate,
            ),
            const SizedBox(height: 24),
            RegistrationDetailsCard(
              student: student,
              registrationDate: registration.registrationDate,
              eligibilityStatus: registration.eligibilityStatus,
            ),
            const SizedBox(height: 16),
            TurnoutProgress(
              turnout: registration.turnout,
            ),
            const SizedBox(height: 24),
            ElevatedButton.icon(
              onPressed: () => context.go('/candidates'),
              icon: const Icon(Icons.people),
              label: const Text('View All Candidates'),
            ),
            const SizedBox(height: 32),
            const EligibilityFAQCard(),
            const SizedBox(height: 40),
          ],
        ),
        loading: () => const LoadingIndicator(),
        error: (error, stack) => Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.error_outline, size: 48, color: AppColors.errorRed),
              const SizedBox(height: 16),
              Text('Error loading dashboard: $error'),
              const SizedBox(height: 16),
              ElevatedButton(
                onPressed: () => ref.refresh(registrationDataProvider),
                child: const Text('Retry'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
