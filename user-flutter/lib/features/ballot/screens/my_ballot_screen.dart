import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/candidate_repository.dart';
import '../../../data/repositories/vote_repository.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../../voting/providers/voting_provider.dart';

/// The student's persisted ballot (GET /api/ballot/me).
final myBallotProvider = FutureProvider<Map<String, dynamic>>((ref) async {
  final data = await ref.watch(voteRepositoryProvider).getMyBallot();
  if (data == null) return {'status': 'draft', 'selections': <String, dynamic>{}};
  return data;
});

/// Every approved candidate (all positions) so draft rows can resolve the
/// opaque `candidate_ref` values back to display names.
final allApprovedCandidatesProvider = FutureProvider<List<Candidate>>((ref) async {
  return await ref.watch(candidateRepositoryProvider).getCandidates();
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

    return Scaffold(
      backgroundColor: AppColors.backgroundGray,
      appBar: const TopBar(title: 'My Ballot'),
      body: ballotAsync.when(
        data: (ballot) {
          final status = (ballot['status'] ?? 'draft').toString();
          final selections =
              (ballot['selections'] as Map?)?.cast<String, dynamic>() ?? {};

          if (status == 'submitted') {
            return _SubmittedBallot(
              receiptToken:
                  (ballot['receipt_token'] ?? votingState.receipt?.receiptToken ?? '')
                      .toString(),
            );
          }

          return _DraftBallot(selections: selections);
        },
        loading: () => const LoadingIndicator(),
        error: (err, stack) => Center(child: Text('Error loading ballot: $err')),
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
    final receipt = votingState.receipt;

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
                      return Column(
                        children: selections.entries
                            .map((entry) => _BallotRow(
                                  positionSlug: entry.key,
                                  refs:
                                      (entry.value is List)
                                          ? (entry.value as List)
                                              .map((e) => e.toString())
                                              .toList()
                                          : [entry.value.toString()],
                                  positions: positions,
                                  byRef: byRef,
                                ))
                            .toList(),
                      );
                    },
                    loading: () => const LoadingIndicator(),
                    error: (err, stack) => Center(
                      child: Text('Error loading candidates: $err'),
                    ),
                  );
                },
                loading: () => const LoadingIndicator(),
                error: (err, stack) => const Center(
                  child: Text('Error loading positions.'),
                ),
              ),
            ],
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: votingState.isSubmitting || selections.isEmpty
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
        const SnackBar(content: Text('Ballot submission failed. Please retry.')),
      );
    }
  }
}

class _BallotRow extends StatelessWidget {
  final String positionSlug;
  final List<String> refs;
  final List<Position> positions;
  final Map<String, Candidate> byRef;

  const _BallotRow({
    required this.positionSlug,
    required this.refs,
    required this.positions,
    required this.byRef,
  });

  @override
  Widget build(BuildContext context) {
    Position? position;
    for (final p in positions) {
      if (p.id == positionSlug || p.slug == positionSlug) {
        position = p;
        break;
      }
    }
    final label = position?.label ?? positionSlug;

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: AppColors.borderGray),
      ),
      color: AppColors.surfaceWhite,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: const TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 15,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 8),
            ...refs.map((ref) => Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Row(
                    children: [
                      const Icon(Icons.check_circle,
                          size: 16, color: Color(0xFF16A34A)),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          byRef[ref]?.name ?? 'Candidate',
                          style: const TextStyle(color: AppColors.textSecondary),
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
            const Icon(Icons.verified, size: 72, color: Color(0xFF16A34A)),
            const SizedBox(height: 16),
            const Text(
              'Ballot Submitted',
              style: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.bold,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 12),
            const Text(
              'Your digital receipt token (use it on the Results tab to verify your vote was counted — it never reveals your choices):',
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.textSecondary),
            ),
            const SizedBox(height: 16),
            SelectableText(
              receiptToken.isEmpty ? '(receipt pending)' : receiptToken,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontFamily: 'monospace',
                fontWeight: FontWeight.bold,
                color: AppColors.textPrimary,
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
