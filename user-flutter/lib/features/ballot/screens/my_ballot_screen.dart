import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_skeleton.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/candidate_repository.dart';
import '../../../data/repositories/vote_repository.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../../dashboard/providers/election_status_provider.dart';
import '../../voting/providers/voting_provider.dart';

/// The student's persisted ballot (GET /api/ballot/me).
final myBallotProvider = FutureProvider<Map<String, dynamic>>((ref) async {
  final data = await ref.watch(voteRepositoryProvider).getMyBallot();
  if (data == null) {
    return {'status': 'draft', 'selections': <String, dynamic>{}};
  }
  return data;
});

/// The receipt token persisted locally at submit time. Restores the receipt
/// after a relaunch if the server payload ever lacks one for a submitted
/// ballot (audit §5 #8).
final savedReceiptProvider = FutureProvider<String?>((ref) async {
  return await ref.watch(voteRepositoryProvider).getSavedReceipt();
});

/// Every approved candidate (all positions, all pages) so draft rows can
/// resolve the opaque `candidate_ref` values back to display names.
final allApprovedCandidatesProvider = FutureProvider<List<Candidate>>((
  ref,
) async {
  return await ref.watch(candidateRepositoryProvider).getAllCandidates();
});

/// My Ballot: read-only summary of the in-progress (or submitted) ballot with
/// a Submit action once voting is open. After submission the digital receipt
/// token is shown so it can be verified on the Results tab.
class MyBallotScreen extends ConsumerWidget {
  const MyBallotScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ballotAsync = ref.watch(myBallotProvider);
    // Only the receipt and the saved fallback are read here; the submit button
    // lives deeper, so watching the whole VotingState here rebuilt the whole
    // screen on every isSubmitting toggle.
    final receiptToken = ref.watch(
      votingProvider.select((state) => state.receipt?.receiptToken),
    );
    final savedReceipt = ref.watch(
      savedReceiptProvider.select((state) => state.valueOrNull),
    );

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'My Ballot'),
      body: ballotAsync.when(
        data: (ballot) {
          final status = (ballot['status'] ?? 'draft').toString();
          final selections =
              (ballot['selections'] as Map?)?.cast<String, dynamic>() ?? {};

          if (status == 'submitted') {
            final serverToken = (ballot['receipt_token'] ?? '').toString();
            final token = serverToken.isNotEmpty
                ? serverToken
                : (receiptToken ?? savedReceipt ?? '');
            return _SubmittedBallot(receiptToken: token);
          }

          return _DraftBallot(selections: selections);
        },
        loading: () => ListView(
          padding: AppSpacing.screenPadding,
          children: [
            LoadingSkeleton.row(),
            AppSpacing.vSm,
            LoadingSkeleton.row(),
            AppSpacing.vSm,
            LoadingSkeleton.row(),
          ],
        ),
        error: (err, stack) => ErrorState(
          message: apiErrorMessage(err, fallback: 'Could not load your ballot.'),
          onRetry: () => ref.invalidate(myBallotProvider),
        ),
      ),
    );
  }
}

class _DraftBallot extends ConsumerWidget {
  final Map<String, dynamic> selections;

  const _DraftBallot({required this.selections});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final positionsAsync = ref.watch(positionsProvider);
    final candidatesAsync = ref.watch(allApprovedCandidatesProvider);
    final receiptToken = ref.watch(
      votingProvider.select((state) => state.receipt?.receiptToken),
    );
    // Only the receipt and the in-flight flag drive this screen; watching the
    // whole VotingState rebuilt every ballot row on each submit-state change.
    final isSubmitting = ref.watch(
      votingProvider.select((state) => state.isSubmitting),
    );
    // Read the phase (and its label) rather than the AsyncValue: the 30 s poll
    // re-runs the provider through a loading state, and `when`/orElse would
    // report "voting closed" and grey out the submit button mid-poll. Selecting
    // the retained fields also means a tick of that poll no longer rebuilds
    // this screen at all.
    final phase = ref.watch(
      electionStatusProvider.select((state) => state.valueOrNull?.phase),
    );
    final phaseLabel = ref.watch(
      electionStatusProvider.select((state) => state.valueOrNull?.phaseLabel),
    );
    final statusError = ref.watch(
      electionStatusProvider.select((state) => state.error),
    );
    final votingOpen = phase == ElectionPhase.votingOpen;

    if (receiptToken != null) {
      return _SubmittedBallot(receiptToken: receiptToken);
    }

    return Column(
      children: [
        Expanded(
          // One lazy scroll view instead of a `ListView.builder(shrinkWrap:
          // true, physics: NeverScrollableScrollPhysics)` nested inside a
          // `ListView`: `shrinkWrap` forces a full layout pass over every row to
          // size itself, and the outer list built its entire child array too. A
          // sliver list builds only the rows that are on screen.
          child: CustomScrollView(
            slivers: _ballotSlivers(context, ref, positionsAsync, candidatesAsync),
          ),
        ),
        if (!votingOpen)
          _phaseNotice(
            context,
            phase: phase,
            phaseLabel: phaseLabel,
            error: statusError,
          ),
        SafeArea(
          top: false,
          child: Padding(
            padding: AppSpacing.screenPadding,
            child: AppButton.primary(
              label: isSubmitting ? 'Submitting…' : 'Submit Ballot',
              icon: Icons.how_to_vote,
              onPressed: !votingOpen || isSubmitting || selections.isEmpty
                  ? null
                  : () => _submit(context, ref),
              isLoading: isSubmitting,
            ),
          ),
        ),
      ],
    );
  }

  /// The ballot rows as slivers.
  ///
  /// Branch order mirrors the `when` chain this replaced (loading then error,
  /// positions then the candidate lookup) so the screen still shows a preview
  /// while positions load and names whichever of the two requests failed.
  List<Widget> _ballotSlivers(
    BuildContext context,
    WidgetRef ref,
    AsyncValue<List<Position>> positionsAsync,
    AsyncValue<List<Candidate>> candidatesAsync,
  ) {
    if (positionsAsync.isLoading) {
      return [
        SliverPadding(
          padding: AppSpacing.screenPadding,
          sliver: SliverList.list(
            children: [
              LoadingSkeleton.row(),
              AppSpacing.vSm,
              LoadingSkeleton.row(),
              AppSpacing.vSm,
              LoadingSkeleton.row(),
            ],
          ),
        ),
      ];
    }
    if (positionsAsync.hasError) {
      return [
        SliverToBoxAdapter(
          child: ErrorState(
            message: apiErrorMessage(
              positionsAsync.error!,
              fallback: 'Could not load positions for your ballot.',
            ),
            onRetry: () => ref.invalidate(positionsProvider),
          ),
        ),
      ];
    }

    if (selections.isEmpty) {
      return [
        SliverToBoxAdapter(
          child: EmptyState(
            message: 'Your ballot is empty.',
            subMessage:
                'Use Vote Now or the Candidates screen to add selections.',
            icon: Icons.how_to_vote_outlined,
            actionLabel: 'Vote Now',
            onAction: () => context.go('/vote-now'),
          ),
        ),
      ];
    }

    if (candidatesAsync.isLoading) {
      return [
        SliverPadding(
          padding: AppSpacing.screenPadding,
          sliver: SliverList.list(
            children: [
              LoadingSkeleton.row(),
              AppSpacing.vSm,
              LoadingSkeleton.row(),
              AppSpacing.vSm,
              LoadingSkeleton.row(),
            ],
          ),
        ),
      ];
    }
    if (candidatesAsync.hasError) {
      return [
        SliverToBoxAdapter(
          child: ErrorState(
            message: apiErrorMessage(
              candidatesAsync.error!,
              fallback: 'Could not load candidates for your ballot.',
            ),
            onRetry: () => ref.invalidate(allApprovedCandidatesProvider),
          ),
        ),
      ];
    }

    final candidates = candidatesAsync.valueOrNull ?? const <Candidate>[];
    final positions = positionsAsync.valueOrNull ?? const <Position>[];
    final byRef = {for (final c in candidates) c.candidateRef: c};
    // Resolve the opaque position slugs/ids once (audit §5 #9: each row used to
    // re-scan the whole list linearly).
    final byPositionKey = <String, Position>{};
    for (final p in positions) {
      byPositionKey[p.id] = p;
      if (p.slug.isNotEmpty) byPositionKey[p.slug] = p;
    }

    final entries = selections.entries.toList(growable: false);

    return [
      SliverPadding(
        padding: const EdgeInsets.all(16),
        sliver: SliverList.builder(
          itemCount: entries.length,
          itemBuilder: (context, index) {
            final entry = entries[index];
            final value = entry.value;
            return _BallotRow(
              // Keyed by position so a recycled row can never paint the previous
              // position's candidates while the draft changes.
              key: ValueKey(entry.key),
              positionSlug: entry.key,
              refs: value is List
                  ? value.map((e) => e.toString()).toList()
                  : [value.toString()],
              byPositionKey: byPositionKey,
              byRef: byRef,
            );
          },
        ),
      ),
    ];
  }

  /// Why submission is disabled, shown above the (disabled) submit button.
  ///
  /// The error branch matches the old async `when` precedence, and while a poll
  /// is in flight the retained phase keeps the notice on screen instead of
  /// blinking it off for the length of every status check.
  Widget _phaseNotice(
    BuildContext context, {
    required ElectionPhase? phase,
    required String? phaseLabel,
    required Object? error,
  }) {
    final notice = AppTextStyles.of(context).labelSmall.copyWith(
          color: Theme.of(context).colorScheme.error,
        );
    if (error != null) {
      return Padding(
        padding: AppSpacing.screenPaddingHorizontal,
        child: Text(
          'Unable to confirm the election phase. Ballot submission is disabled.',
          textAlign: TextAlign.center,
          style: notice,
        ),
      );
    }
    if (phase == null) {
      // Nothing has loaded yet; the spinner above says so already.
      return const SizedBox.shrink();
    }
    return Padding(
      padding: AppSpacing.screenPaddingHorizontal,
      child: Text(
        phaseLabel == null
            ? 'Ballot submission is unavailable until voting opens.'
            : 'Ballot submission is unavailable: $phaseLabel.',
        textAlign: TextAlign.center,
        style: notice,
      ),
    );
  }

  Future<void> _submit(BuildContext context, WidgetRef ref) async {
    final electionStatus = ref.read(electionStatusProvider).value;
    if (electionStatus == null || !electionStatus.isVotingOpen) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Voting is closed. Your ballot was not submitted.'),
        ),
      );
      return;
    }

    final selections = ref.read(myBallotProvider).value?['selections'];
    if (selections is! Map) return;

    final success = await ref
        .read(votingProvider.notifier)
        .submitBallot(Map<String, dynamic>.from(selections));
    if (!context.mounted) return;

    final receipt = ref.read(votingProvider).receipt;
    if (success && receipt != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Ballot submitted. Keep your receipt token!'),
        ),
      );
      ref.invalidate(myBallotProvider);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            ref.read(votingProvider).errorMessage ??
                'Ballot submission failed.',
          ),
        ),
      );
    }
  }
}

class _BallotRow extends StatelessWidget {
  final String positionSlug;
  final List<String> refs;
  final Map<String, Position> byPositionKey;
  final Map<String, Candidate> byRef;

  const _BallotRow({
    super.key,
    required this.positionSlug,
    required this.refs,
    required this.byPositionKey,
    required this.byRef,
  });

  @override
  Widget build(BuildContext context) {
    final position = byPositionKey[positionSlug];
    final label = position?.label ?? positionSlug;
    final appText = AppTextStyles.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: AppCard(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: appText.titleSmall,
            ),
            AppSpacing.vSm,
            ...refs.map(
              (ref) => Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: Row(
                  children: [
                    const Icon(
                      Icons.check_circle,
                      size: 16,
                      color: AppColors.successGreen,
                    ),
                    AppSpacing.hSm,
                    Expanded(
                      child: Text(
                        byRef[ref]?.name ?? 'Candidate',
                        style: appText.bodySmall,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SubmittedBallot extends StatelessWidget {
  final String receiptToken;

  const _SubmittedBallot({required this.receiptToken});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.verified, size: 72, color: AppColors.successGreen),
            AppSpacing.vMd,
            Text(
              'Ballot Submitted',
              style: appText.headlineMedium,
            ),
            AppSpacing.vSm,
            Text(
              'Your digital receipt token (use it on the Results tab to verify your vote was counted — it never reveals your choices):',
              textAlign: TextAlign.center,
              style: appText.bodySmall,
            ),
            AppSpacing.vMd,
            SelectableText(
              receiptToken.isEmpty ? '(receipt pending)' : receiptToken,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: 'monospace',
                fontWeight: FontWeight.bold,
                color: appText.bodyMedium.color,
              ),
            ),
            AppSpacing.vLg,
            AppButton.primary(
              label: 'View Results & Verify',
              icon: Icons.bar_chart,
              onPressed: () => context.go('/results'),
            ),
          ],
        ),
      ),
    );
  }
}
