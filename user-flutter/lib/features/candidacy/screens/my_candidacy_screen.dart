import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/relative_time.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_skeleton.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidacy_application_model.dart';
import '../../auth/providers/auth_provider.dart';
import '../../candidates/screens/candidate_profile_screen.dart';
import '../../dashboard/providers/election_status_provider.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/position_model.dart';
import '../providers/candidacy_provider.dart';

/// My Candidacy — the dedicated hub for students who have applied.
///
/// Backed by the enriched `GET /api/candidacy/me`, the screen shows:
///   - a color-coded status hero + an Applied → Review → Decision timeline,
///   - the exact application the committee sees (photo, position, party,
///     slogan, platform statement),
///   - seat competition ("N candidates · M seats") for the chosen position,
///   - the committee's rejection reason, when one was supplied,
///   - the finalized election outcome (votes / rank / certified) once polls
///     close and results are finalized,
///   - actions: edit / withdraw (via the existing apply flow), review
///     updates in Notifications, and a preview of the public profile.
class MyCandidacyScreen extends ConsumerStatefulWidget {
  const MyCandidacyScreen({super.key});

  @override
  ConsumerState<MyCandidacyScreen> createState() => _MyCandidacyScreenState();
}

class _MyCandidacyScreenState extends ConsumerState<MyCandidacyScreen> {
  @override
  void initState() {
    super.initState();
    // Every open refetches: an admin approve / reject / reset since the last
    // visit must show up without waiting for the notification poll or an app
    // restart. Deferred to a microtask so the provider is not modified while
    // the widget tree is still building.
    Future.microtask(() => ref.invalidate(myApplicationProvider));
  }

  @override
  Widget build(BuildContext context) {
    final applicationAsync = ref.watch(myApplicationProvider);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'My Candidacy'),
      body: applicationAsync.when(
        data: (application) {
          // Withdrawn/never-applied students are re-eligible: point them at
          // the apply form instead of a dead status page.
          if (application == null || application.status == 'withdrawn') {
            return _EmptyOrWithdrawn(application: application);
          }
          return _ApplicationView(application: application);
        },
        loading: () => ListView(
          padding: AppSpacing.screenPadding,
          children: [
            LoadingSkeleton.card(height: 120),
            AppSpacing.vMd,
            LoadingSkeleton.lines(count: 3),
            AppSpacing.vMd,
            LoadingSkeleton.card(height: 160),
          ],
        ),
        error: (err, stack) => ErrorState(
          message:
              'Could not load your candidacy. Check your connection and retry.',
          onRetry: () => ref.invalidate(myApplicationProvider),
        ),
      ),
    );
  }
}

class _ApplicationView extends ConsumerWidget {
  final CandidacyApplication application;

  const _ApplicationView({required this.application});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final phase =
        ref.watch(electionStatusProvider.select((s) => s.valueOrNull?.phase));
    final submitting = ref.watch(
      candidacyProvider.select((s) => s.isSubmitting),
    );

    final status = application.status ?? 'pending';
    final active = status == 'pending' || status == 'approved';

    return ListView(
      padding: AppSpacing.screenPadding,
      children: [
        _StatusHero(status: status),
        AppSpacing.vMd,
        _ReviewTimeline(
          status: status,
          submittedAt: application.createdAt,
          updatedAt: application.updatedAt,
        ),
        if (status == 'rejected' &&
            (application.rejectionReason ?? '').trim().isNotEmpty) ...[
          AppSpacing.vMd,
          _RejectionReasonCard(reason: application.rejectionReason!.trim()),
        ],
        if (application.electionOutcome != null) ...[
          AppSpacing.vMd,
          _OutcomeCard(outcome: application.electionOutcome!),
        ],
        AppSpacing.vMd,
        _ApplicationSummaryCard(application: application),
        if (application.applicantsCount != null) ...[
          AppSpacing.vMd,
          _CompetitionCard(application: application),
        ],
        AppSpacing.vLg,
        _NextStepsCard(status: status, phaseName: phase?.name),
        AppSpacing.vLg,
        if (active) ...[
          AppButton.primary(
            label: 'Edit my campaign',
            onPressed: submitting
                ? null
                : () => context.go('/candidacy?edit=1'),
          ),
          AppSpacing.vMd,
          AppButton.secondary(
            label: 'Withdraw candidacy',
            icon: Icons.person_off_outlined,
            onPressed:
                submitting ? null : () => _confirmWithdraw(context, ref),
          ),
          AppSpacing.vMd,
        ],
        if (status == 'rejected') ...[
          AppButton.primary(
            label: 'Apply again',
            onPressed: () => context.go('/candidacy'),
          ),
          AppSpacing.vMd,
        ],
        AppButton.secondary(
          label: 'Review updates',
          icon: Icons.notifications_outlined,
          onPressed: () => context.push('/notifications'),
        ),
        if (status == 'approved') ...[
          AppSpacing.vMd,
          AppButton.secondary(
            label: 'Preview public profile',
            icon: Icons.visibility_outlined,
            onPressed: () => _previewProfile(context, ref),
          ),
        ],
        AppSpacing.vMd,
        AppButton.text(
          label: 'Back to Dashboard',
          onPressed: () => context.go('/dashboard'),
        ),
        AppSpacing.vLg,
      ],
    );
  }

  /// Pushes the same Candidate Profile screen the Candidates tab uses, built
  /// from this application payload, so the applicant sees exactly what voters
  /// will see.
  void _previewProfile(BuildContext context, WidgetRef ref) {
    final student = ref.read(authProvider).student;
    final position = application.position;
    if (position == null) return;

    final statementLines = application.platformStatement
        .split('\n')
        .map((line) => line.trim())
        .where((line) => line.isNotEmpty)
        .toList();

    final candidate = Candidate(
      id: 'me',
      candidateRef: 'me',
      name: student?.name ?? '',
      photoUrl: application.photoUrl ?? '',
      position: position,
      gradeLine: student?.yearLevel ?? '',
      slogan: application.slogan ?? '',
      platformPoints: statementLines,
      qualifications: const [],
      party: application.partyName,
      department: student?.department,
      approvalStatus: application.status,
    );

    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => CandidateProfileScreen(candidate: candidate),
      ),
    );
  }

  Future<void> _confirmWithdraw(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Withdraw your candidacy?'),
        content: const Text(
          'You will stop appearing as a candidate. You can re-apply any '
          'time before voting closes.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('Keep running'),
          ),
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: Text(
              'Withdraw',
              style: TextStyle(
                color: Theme.of(dialogContext).colorScheme.error,
              ),
            ),
          ),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;

    final success = await ref.read(candidacyProvider.notifier).withdraw();
    // The notifier already invalidated myApplicationProvider, so this build
    // cycle picks up the withdrawn state (the re-apply invitation below).
    if (!success && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Could not withdraw the candidacy right now.'),
          backgroundColor: AppColors.errorRed,
        ),
      );
    }
  }
}


/// Never applied (null) — an invitation to run. Withdrawn — a goodbye with
/// the same re-apply escape hatch the apply screen offers.
class _EmptyOrWithdrawn extends StatelessWidget {
  final CandidacyApplication? application;

  const _EmptyOrWithdrawn({required this.application});

  @override
  Widget build(BuildContext context) {
    final withdrawn = application != null;
    return Center(
      child: Padding(
        padding: AppSpacing.screenPadding,
        child: EmptyState(
          icon: withdrawn ? Icons.person_off_outlined : Icons.how_to_vote,
          message: withdrawn
              ? 'You withdrew your candidacy'
              : 'You have not applied yet',
          subMessage: withdrawn
              ? 'You can re-apply any time before voting closes.'
              : 'File your application to run in the election.',
          actionLabel: withdrawn ? 'Apply again' : 'Apply for Candidacy',
          onAction: () => context.go('/candidacy'),
        ),
      ),
    );
  }
}

/// Color-coded status banner: icon, badge, and a one-line explanation of
/// what the status means for the applicant.
class _StatusHero extends StatelessWidget {
  final String status;

  const _StatusHero({required this.status});

  Color _colorOf(BuildContext context) => switch (status) {
        'approved' => AppColors.successGreen,
        'rejected' => Theme.of(context).colorScheme.error,
        _ => Theme.of(context).colorScheme.primary,
      };

  IconData get _icon => switch (status) {
        'approved' => Icons.verified_outlined,
        'rejected' => Icons.cancel_outlined,
        _ => Icons.hourglass_top,
      };

  String get _badge => switch (status) {
        'approved' => 'APPROVED CANDIDATE',
        'rejected' => 'NOT APPROVED',
        _ => 'UNDER REVIEW',
      };

  String get _message => switch (status) {
        'approved' =>
          'You are on the ballot. Voters will see your profile in the Candidates list.',
        'rejected' =>
          'The election committee did not approve this application. You can apply again.',
        _ =>
          'The election committee is reviewing your application. You will be notified once a decision is made.',
      };

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final color = _colorOf(context);
    return AppCard(
      borderColor: color,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(_icon, size: 40, color: color),
          AppSpacing.hMd,
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _badge,
                  style: appText.tag.copyWith(
                    color: color,
                    letterSpacing: 1.0,
                  ),
                ),
                AppSpacing.vSm,
                Text(_message, style: appText.bodySmall),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Applied → Under review → Decision stepper with the submission timestamp.
class _ReviewTimeline extends StatelessWidget {
  final String status;
  final String? submittedAt;
  final String? updatedAt;

  const _ReviewTimeline({
    required this.status,
    required this.submittedAt,
    required this.updatedAt,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    final decided = status == 'approved' || status == 'rejected';

    final submittedLabel = relativeTimeFromIso(submittedAt);
    final decidedLabel = relativeTimeFromIso(updatedAt);

    final steps = <({String title, String subtitle, bool done, bool? current})>[
      (
        title: 'Application submitted',
        subtitle: submittedLabel.isEmpty
            ? 'Received by the Election Committee'
            : 'Submitted $submittedLabel',
        done: true,
        current: false,
      ),
      (
        title: 'Committee review',
        subtitle: decided
            ? 'Review completed'
            : 'The Election Committee is auditing your application',
        done: decided,
        current: !decided,
      ),
      (
        title: switch (status) {
          'approved' => 'Approved — you are on the ballot',
          'rejected' => 'Not approved',
          _ => 'Decision',
        },
        subtitle: decided
            ? (decidedLabel.isEmpty
                ? 'Decision recorded'
                : 'Decided $decidedLabel')
            : 'Pending — you will be notified',
        done: decided,
        current: !decided,
      ),
    ];

    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Review progress', style: appText.titleSmall),
          AppSpacing.vSm,
          for (var i = 0; i < steps.length; i++) ...[
            _TimelineRow(
              step: steps[i],
              accent: scheme.primary,
              isLast: i == steps.length - 1,
            ),
          ],
        ],
      ),
    );
  }
}

class _TimelineRow extends StatelessWidget {
  final ({
    String title,
    String subtitle,
    bool done,
    bool? current,
  }) step;
  final Color accent;
  final bool isLast;

  const _TimelineRow({
    required this.step,
    required this.accent,
    required this.isLast,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final color = step.done ? accent : context.appTextSecondary;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Column(
            children: [
              Icon(
                step.done ? Icons.check_circle : Icons.radio_button_unchecked,
                size: 20,
                color: color,
              ),
              if (!isLast)
                Expanded(
                  child: Container(
                    width: 2,
                    color: context.appBorder,
                  ),
                ),
            ],
          ),
          AppSpacing.hSm,
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: isLast ? 0 : AppSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    step.title,
                    style: appText.bodyMedium.copyWith(
                      fontWeight: FontWeight.w600,
                      color: step.current == true ? accent : null,
                    ),
                  ),
                  AppSpacing.vXs,
                  Text(step.subtitle, style: appText.bodySmall),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// The committee's explanation for a rejection, quoted verbatim.
class _RejectionReasonCard extends StatelessWidget {
  final String reason;

  const _RejectionReasonCard({required this.reason});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    return AppCard(
      borderColor: scheme.error,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.info_outline, size: 20, color: scheme.error),
              AppSpacing.hSm,
              Text(
                'Reason from the Election Committee',
                style: appText.titleSmall.copyWith(color: scheme.error),
              ),
            ],
          ),
          AppSpacing.vSm,
          Text(reason, style: appText.bodyMedium),
        ],
      ),
    );
  }
}

/// Finalized election outcome (only present once polls close), highlighting
/// a win / tie and showing the certified badge.
class _OutcomeCard extends StatelessWidget {
  final CandidacyElectionOutcome outcome;

  const _OutcomeCard({required this.outcome});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    final color = outcome.certified
        ? AppColors.successGreen
        : outcome.isElected
            ? AppColors.successGreen
            : outcome.isTied
                ? scheme.primary
                : scheme.outline;

    final headline = outcome.certified
        ? 'Certified winner'
        : outcome.isElected
            ? 'Elected'
            : outcome.isTied
                ? 'Tied — awaiting tie-break'
                : 'Not elected';

    return AppCard(
      borderColor: color,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                outcome.isElected
                    ? Icons.emoji_events_outlined
                    : outcome.isTied
                        ? Icons.balance_outlined
                        : Icons.how_to_vote_outlined,
                size: 24,
                color: color,
              ),
              AppSpacing.hSm,
              Expanded(
                child: Text(
                  headline,
                  style: appText.titleMedium.copyWith(color: color),
                ),
              ),
            ],
          ),
          AppSpacing.vMd,
          Row(
            children: [
              _OutcomeStat(label: 'Votes', value: '${outcome.votes}'),
              AppSpacing.hMd,
              _OutcomeStat(
                label: 'Rank',
                value: outcome.rank != null ? '#${outcome.rank}' : '—',
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _OutcomeStat extends StatelessWidget {
  final String label;
  final String value;

  const _OutcomeStat({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Expanded(
      child: Container(
        padding: AppMetrics.tagPadding,
        decoration: BoxDecoration(
          color: context.appTagBg,
          borderRadius: AppRadius.smAll,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: appText.labelSmall),
            AppSpacing.vXs,
            Text(value, style: appText.titleMedium),
          ],
        ),
      ),
    );
  }
}

/// "What the committee sees": photo, position, party, slogan, and the full
/// platform statement, exactly as submitted.
class _ApplicationSummaryCard extends StatelessWidget {
  final CandidacyApplication application;

  const _ApplicationSummaryCard({required this.application});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final position = application.position;
    final positionLabel = position?.label ?? application.positionId;
    final tierLabel = position == null
        ? null
        : position.tier == PositionTier.provincial
            ? 'Provincial'
            : 'National';

    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Your application', style: appText.titleSmall),
          AppSpacing.vMd,
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: AppMetrics.avatarLg * 2,
                height: AppMetrics.avatarLg * 2,
                child: CachedAvatar(
                  imageUrl: application.photoUrl,
                  radius: AppMetrics.avatarLg,
                  fallbackIcon: Icons.person,
                ),
              ),
              AppSpacing.hMd,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      padding: AppMetrics.tagPadding,
                      decoration: BoxDecoration(
                        color: context.appTagBg,
                        borderRadius: AppRadius.smAll,
                      ),
                      child: Text(
                        (tierLabel != null
                                ? '$positionLabel · $tierLabel'
                                : positionLabel)
                            .toUpperCase(),
                        style: appText.tag.copyWith(letterSpacing: 0.8),
                      ),
                    ),
                    if ((application.partyName ?? '').trim().isNotEmpty) ...[
                      AppSpacing.vXs,
                      Text(
                        application.partyName!.trim(),
                        style: appText.bodyMedium,
                      ),
                    ],
                    if ((application.slogan ?? '').trim().isNotEmpty) ...[
                      AppSpacing.vXs,
                      Text(
                        '"${application.slogan!.trim()}"',
                        style: appText.bodySmall.copyWith(
                          fontStyle: FontStyle.italic,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          AppSpacing.vMd,
          Text(
            'Campaign platform',
            style: appText.labelSmall.copyWith(fontWeight: FontWeight.w600),
          ),
          AppSpacing.vXs,
          Text(application.platformStatement, style: appText.bodyMedium),
        ],
      ),
    );
  }
}

/// Seat competition: how many candidates are in the race vs. the seat cap.
class _CompetitionCard extends StatelessWidget {
  final CandidacyApplication application;

  const _CompetitionCard({required this.application});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final seats = application.position?.seatCount ?? 1;
    final applicants = application.applicantsCount ?? 0;
    return AppCard(
      child: Row(
        children: [
          Icon(
            Icons.groups_outlined,
            color: Theme.of(context).colorScheme.primary,
          ),
          AppSpacing.hMd,
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '$applicants applicant${applicants == 1 ? '' : 's'} · '
                  '$seats seat${seats == 1 ? '' : 's'}',
                  style: appText.titleSmall,
                ),
                AppSpacing.vXs,
                Text(
                  'Everyone in the race for this position, including you.',
                  style: appText.bodySmall,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Phase-aware "what happens next" copy so the applicant knows what to expect.
class _NextStepsCard extends StatelessWidget {
  final String status;
  final String? phaseName;

  const _NextStepsCard({required this.status, required this.phaseName});

  String get _copy {
    final pollsOpen = phaseName == 'voting_open';
    final pollsClosed = phaseName == 'voting_closed';
    return switch (status) {
      'approved' => pollsOpen
          ? 'Voting is open — your profile is live in the Candidates list '
              'and on the ballot. Good luck!'
          : pollsClosed
              ? 'Voting has closed. Results appear on the Results tab once '
                  'the Election Committee finalizes them.'
              : 'You are on the ballot. Voters will see your profile once '
                  'voting opens.',
      'rejected' =>
        'You can fix your application and apply again while registration '
            'is open. Check the Notifications tab for updates.',
      _ => pollsOpen || pollsClosed
          ? 'The committee will decide before voting opens. You will get a '
              'notification either way.'
          : 'The committee reviews applications during registration. You '
              'will be notified once a decision is made.',
    };
  }

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('What happens next', style: appText.titleSmall),
          AppSpacing.vSm,
          Text(_copy, style: appText.bodySmall),
        ],
      ),
    );
  }
}

