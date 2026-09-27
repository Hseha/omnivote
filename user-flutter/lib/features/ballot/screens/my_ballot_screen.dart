import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/candidate_repository.dart';
import '../../../data/repositories/vote_repository.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../../dashboard/providers/election_status_provider.dart';
import '../../voting/providers/voting_provider.dart';

/// The student's persisted ballot (GET /api/ballot/me).
final myBallotProvider = FutureProvider<Map<String, dynamic>>((ref) async {
  final data = await ref.watch(voteRepositoryProvider).getMyBallot();
  if (data == null) return {'status': 'draft', 'selections': <String, dynamic>{}};
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
final allApprovedCandidatesProvider = FutureProvider<List<Candidate>>((ref) async {
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
    final votingState = ref.watch(votingProvider);
    final savedReceiptAsync = ref.watch(savedReceiptProvider);

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
                : (votingState.receipt?.receiptToken ??
                    savedReceiptAsync.valueOrNull ??
                    '');
            return _SubmittedBallot(receiptToken: token);
          }

          return _DraftBallot(selections: selections);
        },
        loading: () => const LoadingIndicator(),
        error: (err, stack) => Center(
          child: Text(
            apiErrorMessage(err, fallback: 'Could not load your ballot.'),
          ),
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
    final candidatesAsync =
        ref.watch(allApprovedCandidatesProvider);
    final votingState = ref.watch(votingProvider);
    final electionStatus = ref.watch(electionStatusProvider);
    final receipt = votingState.receipt;
    final votingOpen = electionStatus.maybeWhen(
      data: (status) => status.isVotingOpen,
      orElse: () => false,
    );

    if (receipt != null) {
      return _SubmittedBallot(receiptToken: receipt.receiptToken);
    }

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              positionsAsync.when(
                data: (positions) {
                  if (selections.isEmpty) {
                    return const Padding(
                      padding: EdgeInsets.all(24),
                      child: Center(
                        child: Text(
                          'Your ballot is empty. Use Vote Now or the Candidates screen to add selections.',
                          textAlign: TextAlign.center,
                        ),
                      ),
                    );
                  }
                  return candidatesAsync.when(
                    data: (candidates) {
                      final byRef = {
                        for (final c in candidates) c.candidateRef: c,
                      };
                      // Resolve the opaque position slugs/ids once (audit §5 #9:
                      // each row used to re-scan the whole list linearly).
                      final byPositionKey = <String, Position>{};
                      for (final p in positions) {
                        byPositionKey[p.id] = p;
                        if (p.slug.isNotEmpty) byPositionKey[p.slug] = p;
                      }
                      return ListView.builder(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        itemCount: selections.entries.length,
                        itemBuilder: (context, index) {
                          final entry =
                              selections.entries.elementAt(index);
                          return _BallotRow(
                            positionSlug: entry.key,
                            refs: (entry.value is List)
                                ? (entry.value as List)
                                    .map((e) => e.toString())
                                    .toList()
                                : [entry.value.toString()],
                            byPositionKey: byPositionKey,
                            byRef: byRef,
                          );
                        },
                      );
                    },
                    loading: () => const LoadingIndicator(),
                    error: (err, stack) => Center(
                      child: Text(
                        apiErrorMessage(
                          err,
                          fallback: 'Could not load candidates for your ballot.',
                        ),
                      ),
                    ),
                  );
                },
                loading: () => const LoadingIndicator(),
                error: (err, stack) => Center(
                  child: Text(
                    apiErrorMessage(
                      err,
                      fallback: 'Could not load positions for your ballot.',
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
        if (!votingOpen)
          electionStatus.when(
            data: (status) => Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Text(
                status.phaseLabel == null
                    ? 'Ballot submission is unavailable until voting opens.'
                    : 'Ballot submission is unavailable: ${status.phaseLabel}.',
                textAlign: TextAlign.center,
                style: const TextStyle(color: AppColors.errorRed),
              ),
            ),
            loading: () => const SizedBox.shrink(),
            error: (error, stack) => const Padding(
              padding: EdgeInsets.symmetric(horizontal: 16),
              child: Text(
                'Unable to confirm the election phase. Ballot submission is disabled.',
                textAlign: TextAlign.center,
                style: TextStyle(color: AppColors.errorRed),
              ),
            ),
          ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: !votingOpen ||
                        votingState.isSubmitting ||
                        selections.isEmpty
                    ? null
                    : () => _submit(context, ref),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.primaryBlue,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                icon: votingState.isSubmitting
                    ? const SizedBox(
                        height: 18,
                        width: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : const Icon(Icons.how_to_vote),
                label: Text(votingState.isSubmitting ? 'Submitting...' : 'Submit Ballot'),
              ),
            ),
          ),
        ),
      ],
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
        const SnackBar(content: Text('Ballot submitted. Keep your receipt token!')),
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
    required this.positionSlug,
    required this.refs,
    required this.byPositionKey,
    required this.byRef,
  });

  @override
  Widget build(BuildContext context) {
    final position = byPositionKey[positionSlug];
    final label = position?.label ?? positionSlug;

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: context.appBorder),
      ),
      color: context.appSurface,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 15,
                color: context.appTextPrimary,
              ),
            ),
            const SizedBox(height: 8),
            ...refs.map((ref) => Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Row(
                    children: [
                      Icon(Icons.check_circle,
                          size: 16, color: AppColors.successGreen),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          byRef[ref]?.name ?? 'Candidate',
                          style: TextStyle(color: context.appTextSecondary),
                        ),
                      ),
                    ],
                  ),
                )),
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
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.verified, size: 72, color: AppColors.successGreen),
            const SizedBox(height: 16),
            Text(
              'Ballot Submitted',
              style: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.bold,
                color: context.appTextPrimary,
              ),
            ),
            const SizedBox(height: 12),
            Text(
              'Your digital receipt token (use it on the Results tab to verify your vote was counted — it never reveals your choices):',
              textAlign: TextAlign.center,
              style: TextStyle(color: context.appTextSecondary),
            ),
            const SizedBox(height: 16),
            SelectableText(
              receiptToken.isEmpty ? '(receipt pending)' : receiptToken,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: 'monospace',
                fontWeight: FontWeight.bold,
                color: context.appTextPrimary,
              ),
            ),
            const SizedBox(height: 24),
            ElevatedButton.icon(
              onPressed: () => context.go('/results'),
              icon: const Icon(Icons.bar_chart),
              label: const Text('View Results & Verify'),
            ),
          ],
        ),
      ),
    );
  }
}
