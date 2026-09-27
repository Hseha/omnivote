import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/loading_indicator.dart';
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
    final statusAsync = ref.watch(electionStatusProvider);
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
      body: statusAsync.when(
        data: (status) {
          if (status.phase != ElectionPhase.votingClosed) {
            // Before the polls close there are no per-candidate numbers, but
            // POST /api/results/verify works at any time, so the receipt
            // verifier is shown now too.
            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _NotYetPublished(onRefresh: refreshAll),
                const SizedBox(height: 16),
                _ReceiptVerifier(
                  controller: _receiptController,
                  onVerify: () {
                    final token = _receiptController.text.trim();
                    if (token.isNotEmpty) setState(() => _verifyToken = token);
                  },
                ),
                if (_verifyToken != null)
                  _VerificationResult(token: _verifyToken!),
              ],
            );
          }
          return RefreshIndicator(
            onRefresh: refreshAll,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              children: [
                resultsAsync.when(
                  data: (results) => Column(
                    children: [
                      const _AnnouncementBanner(),
                      for (final result in results) _buildResultCard(result),
                    ],
                  ),
                  loading: () => const LoadingIndicator(),
                  error: (err, stack) => Center(
                    child: Text(
                      apiErrorMessage(
                        err,
                        fallback: 'Results are not available yet or the API is unreachable.',
                      ),
                      textAlign: TextAlign.center,
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                _ReceiptVerifier(
                  controller: _receiptController,
                  onVerify: () {
                    final token = _receiptController.text.trim();
                    if (token.isNotEmpty) setState(() => _verifyToken = token);
                  },
                ),
                if (_verifyToken != null)
                  _VerificationResult(token: _verifyToken!),
              ],
            ),
          );
        },
        loading: () => const LoadingIndicator(),
        error: (err, stack) => Center(
          child: Text(
            apiErrorMessage(err, fallback: 'Could not check election status.'),
          ),
        ),
      ),
    );
  }

  Widget _buildResultCard(ElectionResult result) {
    final maxVotes = result.candidates.isNotEmpty
        ? result.candidates.map((c) => c.votes).reduce((a, b) => a > b ? a : b)
        : 0;

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 16),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: context.appBorder),
      ),
      color: context.appSurface,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              result.positionLabel ?? result.positionKey,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 18,
                color: context.appTextPrimary,
              ),
            ),
            const SizedBox(height: 12),
            ...result.candidates.map((candidate) => Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              candidate.name,
                              style: const TextStyle(fontWeight: FontWeight.w600),
                            ),
                          ),
                          Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              if (candidate.isTied) ...[
                                const _ResultChip(
                                  label: 'TIE',
                                  bg: Color(0xFFFEF3C7),
                                  fg: Color(0xFFB45309),
                                ),
                                const SizedBox(width: 6),
                              ] else if (candidate.isElected) ...[
                                const _ResultChip(
                                  label: 'WINNER',
                                  bg: Color(0xFFDCFCE7),
                                  fg: Color(0xFF166534),
                                ),
                                const SizedBox(width: 6),
                              ],
                              Text('${candidate.votes} votes',
                                  style: TextStyle(
                                      color: context.appTextSecondary, fontSize: 13)),
                            ],
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(3),
                        child: LinearProgressIndicator(
                          value: maxVotes == 0 ? 0 : candidate.votes / maxVotes,
                          minHeight: 8,
                          backgroundColor: context.appBorder,
                          color: candidate.isElected
                              ? AppColors.successGreen
                              : candidate.isTied
                                  ? const Color(0xFFB45309)
                                  : AppColors.primaryBlue,
                        ),
                      ),
                    ],
                  ),
                )),
            if (result.candidates.isEmpty)
              Text('No votes recorded for this position yet.',
                  style: TextStyle(color: context.appTextSecondary)),
          ],
        ),
      ),
    );
  }
}

/// Compact colored tag used for WINNER / TIE verdicts on each candidate.
class _ResultChip extends StatelessWidget {
  final String label;
  final Color bg;
  final Color fg;

  const _ResultChip({required this.label, required this.bg, required this.fg});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.bold,
          color: fg,
        ),
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

    return announcementsAsync.when(
      data: (announcements) {
        if (announcements.isEmpty) {
          return const SizedBox.shrink();
        }
        final latest = announcements.first;
        return Card(
          elevation: 0,
          margin: const EdgeInsets.only(bottom: 16),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: const BorderSide(color: AppColors.successGreen),
          ),
          color: context.appSurface,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(Icons.campaign, size: 18, color: AppColors.successGreen),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        latest.title,
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 15,
                          color: context.appTextPrimary,
                        ),
                      ),
                    ),
                  ],
                ),
                if (latest.body.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text(
                    latest.body,
                    style: TextStyle(
                      color: context.appTextSecondary,
                      fontSize: 13,
                      height: 1.4,
                    ),
                  ),
                ],
                if (latest.authorName?.isNotEmpty ?? false) ...[
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      CachedAvatar(
                        imageUrl: latest.authorAvatarUrl,
                        radius: 13,
                        initials: latest.authorName,
                      ),
                      const SizedBox(width: 8),
                      Flexible(
                        child: Text(
                          'Posted by ${latest.authorName}',
                          style: TextStyle(
                            color: context.appTextSecondary,
                            fontSize: 12,
                            fontWeight: FontWeight.w500,
                          ),
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
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.lock_clock, size: 64, color: context.appTextSecondary),
            const SizedBox(height: 16),
            Text(
              // This widget only renders while the phase is not voting_closed,
              // so the "being tallied" branch below is unreachable in practice;
              // keep the copy generic for both pre-voting and the tally window.
              'Results will be published after the polls close.',
              textAlign: TextAlign.center,
              style: TextStyle(color: context.appTextSecondary, fontSize: 16),
            ),
            const SizedBox(height: 16),
            // Lets a student who opened the screen before polls closed refresh
            // in place instead of having to kill and relaunch the app.
            ElevatedButton.icon(
              onPressed: onRefresh,
              icon: const Icon(Icons.refresh),
              label: const Text('Refresh'),
            ),
          ],
        ),
      ),
    );
  }
}

class _ReceiptVerifier extends StatelessWidget {
  final TextEditingController controller;
  final VoidCallback onVerify;

  const _ReceiptVerifier({required this.controller, required this.onVerify});

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: context.appBorder),
      ),
      color: context.appSurface,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Verify My Vote',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
            ),
            const SizedBox(height: 4),
            Text(
              'Paste your digital receipt token to confirm your vote was counted. This never reveals who you voted for.',
              style: TextStyle(color: context.appTextSecondary, fontSize: 13),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: controller,
              decoration: const InputDecoration(
                labelText: 'Receipt token',
                hintText: 'Paste your receipt here',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            ElevatedButton.icon(
              onPressed: onVerify,
              icon: const Icon(Icons.verified_outlined),
              label: const Text('Verify Token'),
            ),
          ],
        ),
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

    return verifyAsync.when(
      data: (counted) => Padding(
        padding: const EdgeInsets.only(top: 12),
        child: Row(
          children: [
            Icon(
              counted ? Icons.check_circle : Icons.cancel,
              color: counted ? const Color(0xFF16A34A) : AppColors.errorRed,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                counted
                    ? 'Your vote was counted in the ledger.'
                    : 'No matching vote was found for that receipt token.',
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      ),
      loading: () => const Padding(
        padding: EdgeInsets.all(12),
        child: LinearProgressIndicator(),
      ),
      error: (err, stack) => Padding(
        padding: const EdgeInsets.only(top: 12),
        child: Text(
          apiErrorMessage(err, fallback: 'Verification failed.'),
          style: const TextStyle(color: AppColors.errorRed),
        ),
      ),
    );
  }
}
