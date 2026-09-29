import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/theme/brand_accent.dart';
import '../../../core/widgets/app_button.dart';
import '../../../data/models/election_status_model.dart';
import '../../auth/providers/auth_provider.dart';
import '../providers/election_status_provider.dart';
import 'election_countdown.dart';

/// Phase-aware dashboard hero: greeting + identity, the 4-segment phase
/// stepper, and the phase-dependent body (CTA or countdown).
///
/// Design-system notes: all spacing/radii/type come from tokens; CTAs are
/// [AppButton] (48-pt targets, disabled/loading states for free); the accent
/// is the runtime school brand color via [BrandAccentRefX]. Copy and
/// navigation are unchanged from the previous iteration.
class PhaseHeroCard extends ConsumerWidget {
  const PhaseHeroCard({super.key});

  String _greeting() {
    final hour = DateTime.now().hour;
    if (hour < 12) return 'Good Morning';
    if (hour < 17) return 'Good Afternoon';
    return 'Good Evening';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final student = ref.watch(authProvider.select((state) => state.student));
    final phase = ref.watch(
      electionStatusProvider.select(
        (state) => state.valueOrNull?.phase ?? ElectionPhase.unknown,
      ),
    );
    final votingOpensAt = ref.watch(
      electionStatusProvider.select((state) => state.valueOrNull?.votingOpensAt),
    );
    final votingClosesAt = ref.watch(
      electionStatusProvider.select(
        (state) => state.valueOrNull?.votingClosesAt,
      ),
    );
    final accent = ref.brandAccent();

    if (student == null) return const SizedBox.shrink();

    final appText = AppTextStyles.of(context);
    final firstName = student.name.trim().split(' ').first;
    final hasVoted = student.hasVoted;

    final identityBits = <String>[
      if (student.department?.trim().isNotEmpty ?? false)
        student.department!.trim(),
      if (student.course?.trim().isNotEmpty ?? false) student.course!.trim(),
    ];

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppMetrics.cardPadding),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [accent.withValues(alpha: 0.12), context.appSurface],
        ),
        borderRadius: AppRadius.lgAll,
        border: Border.all(color: accent.withValues(alpha: 0.25)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Greeting + identity, as before.
          Text(
            '${_greeting()}, $firstName!',
            style: appText.headlineSmall,
          ),
          if (identityBits.isNotEmpty) ...[
            AppSpacing.vXs,
            Text(
              identityBits.join(' · '),
              style: appText.bodySmall,
            ),
          ],
          AppSpacing.vMd,
          _PhaseStepper(phase: phase, accent: accent),
          AppSpacing.vMd,
          _PhaseContent(
            phase: phase,
            hasVoted: hasVoted,
            votingOpensAt: votingOpensAt,
            votingClosesAt: votingClosesAt,
          ),
        ],
      ),
    );
  }
}

/// The "Register / Closed / Voting / Results" strip. Reads the phase once and
/// repaints when it changes, never on a per-second tick.
class _PhaseStepper extends StatelessWidget {
  const _PhaseStepper({required this.phase, required this.accent});

  final ElectionPhase phase;
  final Color accent;

  static const _labels = ['Register', 'Closed', 'Voting', 'Results'];

  int get _activeIndex => switch (phase) {
        ElectionPhase.registration => 0,
        ElectionPhase.registrationClosed => 1,
        ElectionPhase.votingOpen => 2,
        ElectionPhase.votingClosed => 3,
        ElectionPhase.unknown => -1,
      };

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final active = _activeIndex;
    return Row(
      children: [
        for (var i = 0; i < _labels.length; i++) ...[
          if (i > 0) AppSpacing.hXs,
          Expanded(
            child: Column(
              children: [
                Container(
                  height: AppSpacing.xs,
                  decoration: BoxDecoration(
                    color: active >= 0 && i <= active
                        ? accent
                        : context.appBorder,
                    borderRadius: AppRadius.smAll,
                  ),
                ),
                AppSpacing.vXs,
                Text(
                  _labels[i],
                  style: appText.labelSmall.copyWith(
                    fontWeight:
                        i == active ? FontWeight.bold : FontWeight.w500,
                    color: i == active ? accent : null,
                  ),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}

/// The phase-dependent body of the hero: a CTA per phase, and countdowns
/// between the window boundaries. Copy and targets are unchanged.
class _PhaseContent extends StatelessWidget {
  const _PhaseContent({
    required this.phase,
    required this.hasVoted,
    required this.votingOpensAt,
    required this.votingClosesAt,
  });

  final ElectionPhase phase;
  final bool hasVoted;
  final DateTime? votingOpensAt;
  final DateTime? votingClosesAt;

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    switch (phase) {
      case ElectionPhase.registration:
        return _CopyWithCta(
          message:
              'Registration is open — check your eligibility before voting starts.',
          ctaLabel: 'Confirm eligibility',
          ctaIcon: Icons.verified_user_outlined,
          onPressed: () => context.go('/profile'),
        );
      case ElectionPhase.registrationClosed:
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (votingOpensAt != null)
              ElectionCountdown(
                target: votingOpensAt!,
                label: 'Voting opens in',
              )
            else
              Text('Voting opens soon.', style: appText.bodySmall),
            AppSpacing.vSm,
            Text(
              'Registration has closed. Your eligibility was checked when you registered.',
              style: appText.bodySmall,
            ),
          ],
        );
      case ElectionPhase.votingOpen:
        if (hasVoted) {
          return _CopyWithCta(
            message: 'You have voted. Thanks for taking part!',
            ctaLabel: 'Verify your receipt',
            ctaIcon: Icons.done_all,
            onPressed: () => context.go('/results'),
          );
        }
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (votingClosesAt != null)
              ElectionCountdown(
                target: votingClosesAt!,
                label: 'Voting closes in',
              ),
            AppSpacing.vSm,
            AppButton.primary(
              label: 'Vote now',
              icon: Icons.how_to_vote,
              onPressed: () => context.go('/vote-now'),
            ),
          ],
        );
      case ElectionPhase.votingClosed:
        return _CopyWithCta(
          message:
              'Voting has ended. See the official results and verify receipts.',
          ctaLabel: 'View results',
          ctaIcon: Icons.bar_chart,
          onPressed: () => context.go('/results'),
        );
      case ElectionPhase.unknown:
        return Text(
          'Election status is unavailable right now. Pull to refresh.',
          style: appText.bodySmall,
        );
    }
  }
}

/// A short line of copy plus a full-width CTA, shared by the non-countdown
/// phases.
class _CopyWithCta extends StatelessWidget {
  const _CopyWithCta({
    required this.message,
    required this.ctaLabel,
    required this.ctaIcon,
    required this.onPressed,
  });

  final String message;
  final String ctaLabel;
  final IconData ctaIcon;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(message, style: AppTextStyles.of(context).bodySmall),
        AppSpacing.vSm,
        AppButton.primary(
          label: ctaLabel,
          icon: ctaIcon,
          onPressed: onPressed,
        ),
      ],
    );
  }
}
