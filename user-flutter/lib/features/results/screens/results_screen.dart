import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/app_text_field.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_skeleton.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/election_result_model.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/repositories/result_repository.dart';
import '../../dashboard/providers/announcements_provider.dart';
import '../../dashboard/providers/election_status_provider.dart';
import '../providers/results_provider.dart';

/// Election Results: official winners first, once `voting_closed` AND an admin
/// has run Finalize Winners (the API returns 403 `results_not_published`
/// until then — students never see provisional tallies). The default view is
/// a calm winners-per-position list grouped National → Provincial; the full
/// per-candidate tallies stay collapsed behind "View full results". An
/// anonymous receipt-token verification box is always available.
class ResultsScreen extends ConsumerStatefulWidget {
  const ResultsScreen({super.key});

  @override
  ConsumerState<ResultsScreen> createState() => _ResultsScreenState();
}

class _ResultsScreenState extends ConsumerState<ResultsScreen> {
  final TextEditingController _receiptController = TextEditingController();
  String? _verifyToken;
  bool _showFullTallies = false;

  @override
  void dispose() {
    _receiptController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Only the phase drives this screen's structure. Watching the whole
    // AsyncValue rebuilt the entire results list twice per 30 s status poll
    // (loading → data), and again whenever an unrelated field of the status
    // payload changed. The 30 s poll re-runs the provider, which lands in
    // AsyncLoading with isRefreshing == false — a state `skipLoadingOnRefresh`
    // does not suppress — so the select reads through to the retained value and
    // the list keeps painting the last known phase instead of blinking.
    final phase = ref.watch(
      electionStatusProvider.select((state) => state.valueOrNull?.phase),
    );
    final statusLoading = ref.watch(
      electionStatusProvider.select((state) => state.isLoading),
    );
    final statusError = ref.watch(
      electionStatusProvider.select((state) => state.error),
    );
    final resultsAsync = ref.watch(resultsProvider);

    // Audits §3 #7 & §2 #8: a stale/offline status must not leave this screen
    // (or the results cache) stuck — pull-to-refresh re-fetches status and
    // lets resultsProvider recompute when the phase flips.
    Future<void> refreshAll() async {
      ref.read(electionStatusEpochProvider.notifier).state++;
      ref.invalidate(resultsProvider);
    }

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Election Results'),
      body: phase != null
          ? _buildForStatus(context, phase, resultsAsync, refreshAll)
          : _statusPlaceholder(statusLoading, statusError, refreshAll),
    );
  }

  /// The phase has not been read yet: skeleton preview, error with retry, or
  /// (unreachable data-with-no-value) nothing — the same precedence as the
  /// old async `when`.
  Widget _statusPlaceholder(
    bool loading,
    Object? error,
    Future<void> Function() refreshAll,
  ) {
    if (loading) {
      return ListView(
        padding: AppSpacing.screenPadding,
        children: [
          LoadingSkeleton.card(),
          AppSpacing.vMd,
          LoadingSkeleton.card(),
        ],
      );
    }
    if (error != null) {
      return ErrorState(
        message: apiErrorMessage(
          error,
          fallback: 'Could not check election status.',
        ),
        onRetry: () => refreshAll(),
      );
    }
    return const SizedBox.shrink();
  }

  Widget _buildForStatus(
    BuildContext context,
    ElectionPhase phase,
    AsyncValue<List<ElectionResult>> resultsAsync,
    Future<void> Function() refreshAll,
  ) {
    if (phase != ElectionPhase.votingClosed) {
      // Before the polls close there are no per-candidate numbers, but
      // POST /api/results/verify works at any time, so the receipt
      // verifier is shown now too.
      return ListView(
        padding: AppSpacing.screenPadding,
        children: [
          _NotYetPublished(onRefresh: refreshAll),
          AppSpacing.vMd,
          ..._verifierWidgets(),
        ],
      );
    }
    return RefreshIndicator(
      onRefresh: refreshAll,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: AppSpacing.screenPadding,
        children: [
          resultsAsync.when(
            data: (results) {
              if (results.isEmpty) {
                return const EmptyState(
                  message: 'No results published yet',
                  subMessage:
                      'Winners appear here once election officials finalize the results.',
                  icon: Icons.bar_chart_outlined,
                );
              }
              final runs = _positionRuns(results);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const _AnnouncementBanner(),
                  // Calm default: one compact winner card per position,
                  // National first then Provincial. The full per-candidate
                  // tallies stay collapsed so the list never buries the
                  // outcome under twenty electorate sub-blocks.
                  ..._winnersSections(runs),
                  AppSpacing.vMd,
                  _FullResultsToggle(
                    expanded: _showFullTallies,
                    positionCount: runs.length,
                    onToggle: () =>
                        setState(() => _showFullTallies = !_showFullTallies),
                  ),
                  if (_showFullTallies) ...[
                    AppSpacing.vSm,
                    // One group per electorate for a scoped seat, already in
                    // ballot order; consecutive same-position scoped groups
                    // collapse into a single master card.
                    ..._masterCardSections(results),
                  ],
                ],
              );
            },
            loading: () => Column(
              children: [
                LoadingSkeleton.card(),
                AppSpacing.vMd,
                LoadingSkeleton.card(),
              ],
            ),
            error: (err, stack) {
              if (err is ResultsNotPublishedException) {
                return const _AwaitingFinalization();
              }
              return ErrorState(
                message: apiErrorMessage(
                  err,
                  fallback:
                      'Results are not available yet or the API is unreachable.',
                ),
                onRetry: () => refreshAll(),
              );
            },
          ),
          AppSpacing.vMd,
          ..._verifierWidgets(),
        ],
      ),
    );
  }

  /// Receipt-verification widgets, shared by every phase branch of this
  /// screen so the verifier stays reachable without scrolling past the
  /// whole ballot.
  List<Widget> _verifierWidgets() {
    return [
      _ReceiptVerifier(
        controller: _receiptController,
        onVerify: () {
          final token = _receiptController.text.trim();
          if (token.isNotEmpty) setState(() => _verifyToken = token);
        },
      ),
      if (_verifyToken != null) _VerificationResult(token: _verifyToken!),
    ];
  }

  Widget _buildResultCard(ElectionResult result) {
    final appText = AppTextStyles.of(context);
    final maxVotes = result.candidates.isNotEmpty
        ? result.candidates.map((c) => c.votes).reduce((a, b) => a > b ? a : b)
        : 0;
    final totalVotes = result.candidates.fold<int>(
      0,
      (sum, c) => sum + c.votes,
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: AppCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              result.positionLabel ?? result.positionKey,
              style: appText.titleMedium,
            ),
            AppSpacing.vSm,
            ...result.candidates.map(
              (candidate) => _CandidateTallyRow(
                candidate: candidate,
                maxVotes: maxVotes,
                totalVotes: totalVotes,
              ),
            ),
            if (result.candidates.isEmpty)
              Text(
                'No votes recorded for this position yet.',
                style: appText.bodySmall,
              ),
          ],
        ),
      ),
    );
  }

  /// Splits the ballot-ordered groups into renderable sections. A run of
  /// consecutive scoped groups sharing one [ElectionResult.positionKey] becomes
  /// a single [_ScopedMasterCard]; everything else is a plain card.
  List<Widget> _masterCardSections(List<ElectionResult> results) {
    final widgets = <Widget>[];
    var i = 0;
    while (i < results.length) {
      final current = results[i];
      if (!current.isScoped) {
        widgets.add(_buildResultCard(current));
        i++;
        continue;
      }

      final run = <ElectionResult>[];
      while (i < results.length &&
          results[i].isScoped &&
          results[i].positionKey == current.positionKey) {
        run.add(results[i]);
        i++;
      }
      widgets.add(_ScopedMasterCard(groups: run));
    }
    return widgets;
  }

  /// Collapses consecutive same-position groups into one run per seat (a
  /// global seat is a single-group run), carrying the tier for the winners
  /// section headers.
  List<_PositionRun> _positionRuns(List<ElectionResult> results) {
    final runs = <_PositionRun>[];
    var i = 0;
    while (i < results.length) {
      final current = results[i];
      if (!current.isScoped) {
        runs.add(_PositionRun(
          tier: current.tier,
          label: current.positionLabel ?? current.positionKey,
          groups: [current],
        ));
        i++;
        continue;
      }

      final run = <ElectionResult>[];
      while (i < results.length &&
          results[i].isScoped &&
          results[i].positionKey == current.positionKey) {
        run.add(results[i]);
        i++;
      }
      runs.add(_PositionRun(
        tier: run.first.tier,
        label: run.first.baseLabel,
        groups: run,
      ));
    }
    return runs;
  }

  /// Winners-first sections: a tier header (National / Provincial) whenever
  /// the tier changes, then one compact winner card per seat.
  List<Widget> _winnersSections(List<_PositionRun> runs) {
    final widgets = <Widget>[];
    String? prevTier;
    for (final run in runs) {
      if (run.tier != prevTier) {
        widgets.add(_TierSectionHeader(
          label: run.tier == 'national'
              ? 'National Winners'
              : run.tier == 'provincial'
                  ? 'Provincial Winners'
                  : 'Winners',
        ));
        prevTier = run.tier;
      }
      widgets.add(_PositionWinnersCard(run: run));
    }
    return widgets;
  }
}

/// One contested seat: a global position is a single-group run, a scoped
/// position spans one group per electorate.
class _PositionRun {
  final String? tier;
  final String label;
  final List<ElectionResult> groups;

  const _PositionRun({
    required this.tier,
    required this.label,
    required this.groups,
  });
}

/// Small uppercase divider between the National and Provincial winner cards.
class _TierSectionHeader extends StatelessWidget {
  final String label;

  const _TierSectionHeader({required this.label});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(
        top: AppSpacing.sm,
        bottom: AppSpacing.sm,
      ),
      child: Text(
        label.toUpperCase(),
        style: AppTextStyles.of(context).labelLarge.copyWith(
              color: Theme.of(context).colorScheme.primary,
              letterSpacing: 0.6,
            ),
      ),
    );
  }
}

/// Compact "who won this seat" card: trophy + winner name (with party and
/// vote count), one block per electorate for a scoped seat. Ties are listed
/// with an amber chip; seats nobody has won yet say so calmly.
class _PositionWinnersCard extends StatelessWidget {
  final _PositionRun run;

  const _PositionWinnersCard({required this.run});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    final perElectorate = run.groups.length > 1;

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: AppCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(
                  Icons.emoji_events_outlined,
                  size: 18,
                  color: AppColors.successGreen,
                ),
                AppSpacing.hSm,
                Expanded(
                  child: Text(run.label, style: appText.titleMedium),
                ),
              ],
            ),
            AppSpacing.vSm,
            for (var g = 0; g < run.groups.length; g++) ...[
              if (g > 0) AppSpacing.vSm,
              if (perElectorate)
                Text(
                  run.groups[g].scopeLabel ?? '',
                  style: appText.labelLarge.copyWith(color: scheme.primary),
                ),
              if (perElectorate) AppSpacing.vXs,
              ..._winnerRows(run.groups[g], appText),
            ],
          ],
        ),
      ),
    );
  }

  List<Widget> _winnerRows(ElectionResult group, AppTextStyles appText) {
    final winners = group.candidates.where((c) => c.isElected).toList();
    final ties = group.candidates.where((c) => c.isTied).toList();

    if (winners.isEmpty && ties.isEmpty) {
      return [
        Text('Awaiting certification', style: appText.bodySmall),
      ];
    }

    return [
      for (final winner in winners)
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.xs),
          child: Row(
            children: [
              Expanded(
                child: Text(winner.name, style: appText.titleSmall),
              ),
              if (winner.partyName != null &&
                  winner.partyName!.isNotEmpty &&
                  winner.partyName != '—') ...[
                _ResultChip(
                  label: winner.partyName!,
                  bg: AppColors.tagBlueBg,
                  fg: AppColors.tagBlueText,
                ),
                AppSpacing.hSm,
              ],
              Text(
                '${winner.votes} votes',
                style: appText.labelSmall,
              ),
            ],
          ),
        ),
      for (final tied in ties)
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.xs),
          child: Row(
            children: [
              Expanded(
                child: Text(tied.name, style: appText.titleSmall),
              ),
              const _ResultChip(
                label: 'TIE',
                bg: AppColors.resultTieBg,
                fg: AppColors.resultTieFg,
              ),
            ],
          ),
        ),
    ];
  }
}

/// Show/hide switch for the detailed per-candidate tallies.
class _FullResultsToggle extends StatelessWidget {
  final bool expanded;
  final int positionCount;
  final VoidCallback onToggle;

  const _FullResultsToggle({
    required this.expanded,
    required this.positionCount,
    required this.onToggle,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Center(
      child: TextButton.icon(
        onPressed: onToggle,
        icon: Icon(
          expanded ? Icons.expand_less : Icons.expand_more,
          size: 18,
        ),
        label: Text(
          expanded
              ? 'Hide detailed results'
              : 'View full results ($positionCount positions)',
          style: appText.labelLarge,
        ),
      ),
    );
  }
}

/// Shown after the polls close but before any admin has run Finalize
/// Winners: no numbers, just a calm explanation and a refresh.
class _AwaitingFinalization extends StatelessWidget {
  const _AwaitingFinalization();

  @override
  Widget build(BuildContext context) {
    return EmptyState(
      message: 'Results are being finalized.',
      subMessage:
          'The official winners will appear here once election officials finalize '
          'the results. Pull down to refresh.',
      icon: Icons.hourglass_top_outlined,
    );
  }
}

/// A scoped seat (e.g. Year Level Representative) contested once per
/// electorate. Renders one titled card with a labelled sub-section per
/// college·year so a 20-electorate race stays readable and ordered.
class _ScopedMasterCard extends StatelessWidget {
  final List<ElectionResult> groups;

  const _ScopedMasterCard({required this.groups});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final base = groups.isEmpty ? '' : groups.first.baseLabel;

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: AppCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(base, style: appText.titleMedium),
                ),
                _ResultChip(
                  label: '${groups.length} areas',
                  bg: AppColors.tagBlueBg,
                  fg: AppColors.tagBlueText,
                ),
              ],
            ),
            AppSpacing.vSm,
            for (var g = 0; g < groups.length; g++) ...[
              if (g > 0) AppSpacing.vSm,
              _ScopedGroupBlock(group: groups[g]),
            ],
          ],
        ),
      ),
    );
  }
}

/// One electorate's tally inside a scoped master card: a scope label header
/// followed by the same candidate rows a plain card shows.
class _ScopedGroupBlock extends StatelessWidget {
  final ElectionResult group;

  const _ScopedGroupBlock({required this.group});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final maxVotes = group.candidates.isNotEmpty
        ? group.candidates.map((c) => c.votes).reduce((a, b) => a > b ? a : b)
        : 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          group.scopeLabel ?? '',
          style: appText.labelLarge.copyWith(
            color: Theme.of(context).colorScheme.primary,
          ),
        ),
        AppSpacing.vXs,
        ...group.candidates.map(
          (candidate) => _CandidateTallyRow(
            candidate: candidate,
            maxVotes: maxVotes,
            totalVotes: group.candidates.fold<int>(
              0,
              (sum, c) => sum + c.votes,
            ),
          ),
        ),
        if (group.candidates.isEmpty)
          Text(
            'No votes recorded yet.',
            style: appText.bodySmall,
          ),
      ],
    );
  }
}

/// One candidate's line inside a results card: name, verdict chip, vote count,
/// share-of-total, and a progress bar scaled against the group's top scorer.
/// Shared by plain position cards and each electorate block of a scoped seat.
class _CandidateTallyRow extends StatelessWidget {
  final ElectionCandidateResult candidate;
  final int maxVotes;
  final int totalVotes;

  const _CandidateTallyRow({
    required this.candidate,
    required this.maxVotes,
    required this.totalVotes,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;

    final share = totalVotes > 0
        ? (candidate.votes / totalVotes) * 100
        : 0.0;

    // Before finalize nothing is elected/tied, so the current top scorer is
    // marked LEADING — a provisional "currently ahead", never an official
    // WINNER. Once the backend certifies, WINNER/TIE take over.
    final isLeading = !candidate.isElected &&
        !candidate.isTied &&
        candidate.votes > 0 &&
        candidate.votes == maxVotes;

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(
                child: Text(candidate.name, style: appText.titleSmall),
              ),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (candidate.isTied) ...[
                    const _ResultChip(
                      label: 'TIE',
                      bg: AppColors.resultTieBg,
                      fg: AppColors.resultTieFg,
                    ),
                    AppSpacing.hXs,
                  ] else if (candidate.isElected) ...[
                    const _ResultChip(
                      label: 'WINNER',
                      bg: AppColors.resultWinnerBg,
                      fg: AppColors.resultWinnerFg,
                    ),
                    AppSpacing.hXs,
                  ] else if (isLeading) ...[
                    const _ResultChip(
                      label: 'LEADING',
                      bg: AppColors.resultLeadingBg,
                      fg: AppColors.resultLeadingFg,
                    ),
                    AppSpacing.hXs,
                  ],
                  Text(
                    '${candidate.votes} votes · ${share.toStringAsFixed(1)}%',
                    style: appText.labelSmall,
                  ),
                ],
              ),
            ],
          ),
          AppSpacing.vXs,
          // LinearProgressIndicator takes a borderRadius directly: wrapping it
          // in a ClipRRect forced an extra clip layer on every row of a screen
          // that is nothing but these bars.
          LinearProgressIndicator(
            value: maxVotes == 0 ? 0 : candidate.votes / maxVotes,
            minHeight: AppMetrics.barThickness,
            borderRadius: BorderRadius.circular(AppMetrics.barRadius),
            backgroundColor: context.appBorder,
            color: candidate.isElected
                ? AppColors.successGreen
                : candidate.isTied
                    ? AppColors.resultTieFg
                    : isLeading
                        ? AppColors.resultLeadingFg
                        : scheme.primary,
          ),
        ],
      ),
    );
  }
}

/// Compact colored tag used for WINNER / TIE verdicts on each candidate.
/// Colors come from the verdict tokens in [AppColors] (self-contained pairs
/// that read on both themes).
class _ResultChip extends StatelessWidget {
  final String label;
  final Color bg;
  final Color fg;

  const _ResultChip({required this.label, required this.bg, required this.fg});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: AppMetrics.tagPadding,
      decoration: BoxDecoration(
        color: bg,
        borderRadius: AppRadius.smAll,
      ),
      child: Text(
        label,
        style: AppTextStyles.of(context).tag.copyWith(color: fg),
      ),
    );
  }
}

/// Surfaces the latest published announcement above the tallies once voting
/// closes. After an admin finalizes results the system publishes "Official
/// Election Results", so winners reach students here automatically.
class _AnnouncementBanner extends ConsumerWidget {
  const _AnnouncementBanner();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final announcementsAsync = ref.watch(announcementsProvider);
    final appText = AppTextStyles.of(context);

    return announcementsAsync.when(
      data: (announcements) {
        if (announcements.isEmpty) {
          return const SizedBox.shrink();
        }
        final latest = announcements.first;
        return Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.md),
          child: AppCard(
            padding: const EdgeInsets.all(AppSpacing.md),
            borderColor: AppColors.successGreen,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(
                      Icons.campaign,
                      size: 18,
                      color: AppColors.successGreen,
                    ),
                    AppSpacing.hSm,
                    Expanded(
                      child: Text(
                        latest.title,
                        style: appText.titleSmall,
                      ),
                    ),
                  ],
                ),
                if (latest.body.isNotEmpty) ...[
                  AppSpacing.vSm,
                  Text(
                    latest.body,
                    style: appText.labelSmall,
                  ),
                ],
                if (latest.authorName?.isNotEmpty ?? false) ...[
                  AppSpacing.vSm,
                  Row(
                    children: [
                      CachedAvatar(
                        imageUrl: latest.authorAvatarUrl,
                        radius: AppMetrics.avatarSm,
                        initials: latest.authorName,
                      ),
                      AppSpacing.hSm,
                      Flexible(
                        child: Text(
                          'Posted by ${latest.authorName}',
                          style: appText.labelSmall,
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        );
      },
      loading: () => const SizedBox.shrink(),
      error: (err, stack) => const SizedBox.shrink(),
    );
  }
}

class _NotYetPublished extends StatelessWidget {
  final VoidCallback onRefresh;

  const _NotYetPublished({required this.onRefresh});

  @override
  Widget build(BuildContext context) {
    return EmptyState(
      // This widget only renders while the phase is not voting_closed, so a
      // "being tallied" branch is unreachable in practice; keep the copy
      // generic for both pre-voting and the tally window.
      message: 'Results will be published after the polls close.',
      // Lets a student who opened the screen before polls closed refresh in
      // place instead of having to kill and relaunch the app.
      actionLabel: 'Refresh',
      onAction: onRefresh,
      icon: Icons.lock_clock_outlined,
    );
  }
}

class _ReceiptVerifier extends StatelessWidget {
  final TextEditingController controller;
  final VoidCallback onVerify;

  const _ReceiptVerifier({required this.controller, required this.onVerify});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Verify My Vote',
            style: appText.titleMedium,
          ),
          AppSpacing.vXs,
          Text(
            'Paste your digital receipt token to confirm your vote was counted. This never reveals who you voted for.',
            style: appText.labelSmall,
          ),
          AppSpacing.vMd,
          AppTextField(
            controller: controller,
            label: 'Receipt token',
            hint: 'Paste your receipt here',
            autocorrect: false,
            textInputAction: TextInputAction.done,
          ),
          AppSpacing.vSm,
          AppButton.primary(
            label: 'Verify Token',
            icon: Icons.verified_outlined,
            onPressed: onVerify,
          ),
        ],
      ),
    );
  }
}

class _VerificationResult extends ConsumerWidget {
  final String token;

  const _VerificationResult({required this.token});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final verifyAsync = ref.watch(verifyReceiptProvider(token));
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;

    return verifyAsync.when(
      data: (counted) => Padding(
        padding: const EdgeInsets.only(top: AppSpacing.sm),
        child: Row(
          children: [
            Icon(
              counted ? Icons.check_circle : Icons.cancel,
              color: counted ? AppColors.successGreen : scheme.error,
            ),
            AppSpacing.hSm,
            Expanded(
              child: Text(
                counted
                    ? 'Your vote was counted in the ledger.'
                    : 'No matching vote was found for that receipt token.',
                style: appText.titleSmall,
              ),
            ),
          ],
        ),
      ),
      loading: () => const Padding(
        padding: EdgeInsets.all(AppSpacing.sm),
        child: LinearProgressIndicator(),
      ),
      error: (err, stack) => Padding(
        padding: const EdgeInsets.only(top: AppSpacing.sm),
        child: Text(
          apiErrorMessage(err, fallback: 'Verification failed.'),
          style: appText.labelSmall.copyWith(color: scheme.error),
        ),
      ),
    );
  }
}
