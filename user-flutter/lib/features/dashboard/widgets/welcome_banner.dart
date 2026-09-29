import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_tokens.dart';
import '../../../data/models/election_status_model.dart';
import '../../auth/providers/auth_provider.dart';
import '../../settings/providers/branding_provider.dart';
import '../providers/election_status_provider.dart';

/// Dashboard header: a time-of-day greeting with the student's name, the
/// current election phase, and the student's department/course. The live clock
/// and phase badge moved out of the top bar into this single banner.
class WelcomeBanner extends ConsumerWidget {
  const WelcomeBanner({super.key});

  String _greeting() {
    final hour = DateTime.now().hour;
    if (hour < 12) return 'Good Morning';
    if (hour < 17) return 'Good Afternoon';
    return 'Good Evening';
  }

  String _phaseLine(ElectionPhase phase, bool hasVoted) {
    switch (phase) {
      case ElectionPhase.registration:
        return 'Registration is open — confirm your eligibility';
      case ElectionPhase.registrationClosed:
        return 'Registration is closed — voting opens soon';
      case ElectionPhase.votingOpen:
        return hasVoted
            ? 'Voting is open — you have already cast your ballot'
            : 'Voting is open — tap Vote Now to cast your ballot';
      case ElectionPhase.votingClosed:
        return 'Voting has ended — results are available';
      case ElectionPhase.unknown:
        return 'Election status unavailable right now';
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final student = ref.watch(authProvider.select((state) => state.student));

    // Phase and accent are read as a value-derived record so the banner does
    // not rebuild on the status poll's loading window (it keeps painting the
    // last known phase) and only repaints when the phase or the brand colour
    // actually changes. The previous `switch (statusAsync)` also flipped the
    // copy to "status unavailable" for the length of every 30 s refetch.
    final phase = ref.watch(
      electionStatusProvider.select(
        (state) => state.valueOrNull?.phase ?? ElectionPhase.unknown,
      ),
    );
    final accent = ref.watch(
          brandingProvider.select((state) => state.valueOrNull?.primaryColor),
        ) ??
        const Color(0xFF2F5EFF);

    if (student == null) return const SizedBox.shrink();

    final firstName = student.name.trim().split(' ').first;
    final phaseLine = _phaseLine(phase, student.hasVoted);

    final identityBits = <String>[
      if (student.department?.trim().isNotEmpty ?? false)
        student.department!.trim(),
      if (student.course?.trim().isNotEmpty ?? false) student.course!.trim(),
    ];

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [accent.withValues(alpha: 0.12), context.appSurface],
        ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: accent.withValues(alpha: 0.25)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${_greeting()}, $firstName!',
            style: TextStyle(
              fontSize: 20,
              fontWeight: FontWeight.bold,
              color: context.appTextPrimary,
            ),
          ),
          const SizedBox(height: 8),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.campaign_outlined, size: 16, color: accent),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  phaseLine,
                  style: TextStyle(
                    fontSize: 13,
                    color: context.appTextSecondary,
                    height: 1.4,
                  ),
                ),
              ),
            ],
          ),
          if (identityBits.isNotEmpty) ...[
            const SizedBox(height: 10),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.school_outlined, size: 16, color: accent),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    identityBits.join(' · '),
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
