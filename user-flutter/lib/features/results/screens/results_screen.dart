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
import '../../dashboard/providers/announcements_provider.dart';
import '../../dashboard/providers/election_status_provider.dart';
import '../providers/results_provider.dart';

/// Election Results: per-position tallies once `voting_closed`, plus an
/// anonymous receipt-token verification box. Before the polls close this
/// screen only shows turnout/status, never per-candidate numbers.
class ResultsScreen extends ConsumerStatefulWidget {
  const ResultsScreen({super.key});

  @override
  ConsumerState<ResultsScreen> createState() => _ResultsScreenState();
}

class _ResultsScreenState extends ConsumerState<ResultsScreen> {
  final TextEditingController _receiptController = TextEditingController();
  String? _verifyToken;

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
          _ReceiptVerifier(
            controller: _receiptController,
            onVerify: () {
              final token = _receiptController.text.trim();
              if (token.isNotEmpty) setState(() => _verifyToken = token);
            },
          ),
          if (_verifyToken != null) _VerificationResult(token: _verifyToken!),
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
                      'Tallies appear here once voting closes and results are finalized.',
                  icon: Icons.bar_chart_outlined,
                );
              }
              return Column(
                children: [
                  const _AnnouncementBanner(),
                  for (final result in results) _buildResultCard(result),
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
            error: (err, stack) => ErrorState(
              message: apiErrorMessage(
                err,
                fallback:
                    'Results are not available yet or the API is unreachable.',
              ),
              onRetry: () => refreshAll(),
            ),
          ),
          AppSpacing.vMd,
          _ReceiptVerifier(
            controller: _receiptController,
            onVerify: () {
              final token = _receiptController.text.trim();
              if (token.isNotEmpty) setState(() => _verifyToken = token);
            },
          ),
          if (_verifyToken != null) _VerificationResult(token: _verifyToken!),
        ],
      ),
    );
  }

  Widget _buildResultCard(ElectionResult result) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    final maxVotes = result.candidates.isNotEmpty
        ? result.candidates.map((c) => c.votes).reduce((a, b) => a > b ? a : b)
        : 0;

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
              (candidate) => Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Expanded(
                          child: Text(
                            candidate.name,
                            style: appText.titleSmall,
                          ),
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
                            ],
                            Text(
                              '${candidate.votes} votes',
                              style: appText.labelSmall,
                            ),
                          ],
                        ),
                      ],
                    ),
                    AppSpacing.vXs,
                    // LinearProgressIndicator takes a borderRadius directly:
                    // wrapping it in a ClipRRect forced an extra clip layer on
                    // every row of a screen that is nothing but these bars.
                    LinearProgressIndicator(
                      value: maxVotes == 0 ? 0 : candidate.votes / maxVotes,
                      minHeight: AppMetrics.barThickness,
                      borderRadius: BorderRadius.circular(
                        AppMetrics.barRadius,
                      ),
                      backgroundColor: context.appBorder,
                      color: candidate.isElected
                          ? AppColors.successGreen
                          : candidate.isTied
                              ? AppColors.resultTieFg
                              : scheme.primary,
                    ),
                  ],
                ),
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
