import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/election_result_model.dart';
import '../../../data/models/election_status_model.dart';
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

    return Scaffold(
      backgroundColor: AppColors.backgroundGray,
      appBar: const TopBar(title: 'Election Results'),
      body: statusAsync.when(
        data: (status) {
          if (status.phase != ElectionPhase.votingClosed) {
            return _NotYetPublished(status: status);
          }
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              resultsAsync.when(
                data: (results) => Column(
                  children: [
                    for (final result in results) _buildResultCard(result),
                  ],
                ),
                loading: () => const LoadingIndicator(),
                error: (err, stack) => Center(
                  child: Text(
                    'Results are not available yet or the API is unreachable.\n$err',
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
          );
        },
        loading: () => const LoadingIndicator(),
        error: (err, stack) => Center(child: Text('Error: $err')),
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
        side: const BorderSide(color: AppColors.borderGray),
      ),
      color: AppColors.surfaceWhite,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              result.positionLabel ?? result.positionKey,
              style: const TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 18,
                color: AppColors.textPrimary,
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
                          Text('${candidate.votes} votes',
                              style: const TextStyle(
                                  color: AppColors.textSecondary, fontSize: 13)),
                        ],
                      ),
                      const SizedBox(height: 6),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(3),
                        child: LinearProgressIndicator(
                          value: maxVotes == 0 ? 0 : candidate.votes / maxVotes,
                          minHeight: 8,
                          backgroundColor: AppColors.backgroundGray,
                          color: AppColors.primaryBlue,
                        ),
                      ),
                    ],
                  ),
                )),
            if (result.candidates.isEmpty)
              const Text('No votes recorded for this position yet.',
                  style: TextStyle(color: AppColors.textSecondary)),
          ],
        ),
      ),
    );
  }
}

class _NotYetPublished extends StatelessWidget {
  final ElectionStatus status;

  const _NotYetPublished({required this.status});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.lock_clock, size: 64, color: AppColors.textSecondary),
            const SizedBox(height: 16),
            Text(
              status.isVotingClosed
                  ? 'Results are being tallied. Check back soon.'
                  : 'Results will be published after the polls close.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppColors.textSecondary, fontSize: 16),
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
        side: const BorderSide(color: AppColors.borderGray),
      ),
      color: AppColors.surfaceWhite,
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
            const Text(
              'Paste your digital receipt token to confirm your vote was counted. This never reveals who you voted for.',
              style: TextStyle(color: AppColors.textSecondary, fontSize: 13),
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
        child: Text('Verification failed: $err',
            style: const TextStyle(color: AppColors.errorRed)),
      ),
    );
  }
}
