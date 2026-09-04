import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/candidate_repository.dart';
import '../../../data/repositories/vote_repository.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../../dashboard/providers/election_status_provider.dart';

/// Fetches approved candidates for a single position for the guided flow
/// (isolated from the shared Candidates-list filter provider).
final voteCandidatesProvider =
    FutureProvider.family<List<Candidate>, String>((ref, positionId) async {
  return await ref.watch(candidateRepositoryProvider).getCandidates(
        positionId: positionId,
      );
});

/// "Vote Now" guided flow: steps through every active position (both tiers)
/// in order, lets the student select 1 (or N for multi-seat positions, e.g.
/// 12 Senators), persists the draft, and hands off to "My Ballot" to submit.
class VoteNowScreen extends ConsumerStatefulWidget {
  const VoteNowScreen({super.key});

  @override
  ConsumerState<VoteNowScreen> createState() => _VoteNowScreenState();
}

class _VoteNowScreenState extends ConsumerState<VoteNowScreen> {
  int _positionIndex = 0;
  final Map<String, List<String>> _selections = {};
  bool _saving = false;

  @override
  Widget build(BuildContext context) {
    final positionsAsync = ref.watch(positionsProvider);
    final statusAsync = ref.watch(electionStatusProvider);

    return Scaffold(
      backgroundColor: AppColors.backgroundGray,
      appBar: const TopBar(title: 'Vote Now'),
      body: statusAsync.when(
        data: (status) {
          if (status.phase != ElectionPhase.votingOpen) {
            return _PhaseBanner(
              message: status.isVotingClosed
                  ? 'Polls are closed. You can review your ballot and results.'
                  : 'Voting is not open yet - you can browse candidates but not cast a ballot.',
              onGoToBallot: status.isVotingClosed
                  ? () => context.go('/ballot')
                  : null,
            );
          }
          return positionsAsync.when(
            data: (positions) => _buildFlow(positions),
            loading: () => const LoadingIndicator(),
            error: (err, stack) => Center(child: Text('Error loading positions: $err')),
          );
        },
        loading: () => const LoadingIndicator(),
        error: (err, stack) => Center(child: Text('Error: $err')),
      ),
    );
  }

  Widget _buildFlow(List<Position> positions) {
    final activePositions =
        positions.where((p) => p.tier == PositionTier.school).toList()
          ..addAll(positions.where((p) => p.tier == PositionTier.provincial));
    if (activePositions.isEmpty) {
      return const Center(child: Text('No active positions.'));
    }

    final position =
        activePositions[_positionIndex.clamp(0, activePositions.length - 1)];
    final candidatesAsync = ref.watch(voteCandidatesProvider(position.id));
    final currentRefs = _selections[position.slug] ?? const [];

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              LinearProgressIndicator(
                value: (_positionIndex + 1) / activePositions.length,
                backgroundColor: AppColors.backgroundGray,
                color: AppColors.primaryBlue,
                minHeight: 6,
                borderRadius: BorderRadius.circular(3),
              ),
              const SizedBox(height: 12),
              Text(
                'Step ${_positionIndex + 1} of ${activePositions.length}',
                style: const TextStyle(
                  color: AppColors.textSecondary,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                position.label,
                style: const TextStyle(
                  color: AppColors.textPrimary,
                  fontSize: 22,
                  fontWeight: FontWeight.bold,
                ),
              ),
              Text(
                position.seatCount > 1
                    ? 'Select up to ${position.seatCount} candidates'
                    : 'Select one candidate',
                style: const TextStyle(color: AppColors.textSecondary),
              ),
            ],
          ),
        ),
        Expanded(
          child: candidatesAsync.when(
            data: (candidates) => ListView(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              children: candidates
                  .map((c) => _buildCandidateTile(c, position, currentRefs))
                  .toList(),
            ),
            loading: () => const LoadingIndicator(),
            error: (err, stack) => Center(child: Text('Error: $err')),
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: _positionIndex > 0
                        ? () => setState(() => _positionIndex--)
                        : null,
                    child: const Text('Previous'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: ElevatedButton(
                    onPressed:
                        _saving ? null : () => _nextOrReview(activePositions),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppColors.primaryBlue,
                      foregroundColor: Colors.white,
                    ),
                    child: Text(
                      _positionIndex == activePositions.length - 1
                          ? 'Review My Ballot'
                          : 'Next',
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildCandidateTile(
    Candidate candidate,
    Position position,
    List<String> currentRefs,
  ) {
    final isSelected = currentRefs.contains(candidate.candidateRef);
    final isMulti = position.seatCount > 1;

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: isSelected ? AppColors.primaryBlue : AppColors.borderGray,
          width: isSelected ? 2 : 1,
        ),
      ),
      color: AppColors.surfaceWhite,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () =>
            _toggleCandidate(candidate.candidateRef, position, isSelected),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              CircleAvatar(
                radius: 24,
                backgroundImage: candidate.photoUrl.isNotEmpty
                    ? NetworkImage(candidate.photoUrl)
                    : null,
                child: candidate.photoUrl.isEmpty
                    ? const Icon(Icons.person, color: AppColors.textSecondary)
                    : null,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      candidate.name,
                      style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 16,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    if (candidate.slogan.isNotEmpty)
                      Text(
                        candidate.slogan,
                        style: const TextStyle(color: AppColors.textSecondary),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                  ],
                ),
              ),
              if (isMulti)
                Checkbox(
                  value: isSelected,
                  activeColor: AppColors.primaryBlue,
                  onChanged: (_) =>
                      _toggleCandidate(candidate.candidateRef, position, isSelected),
                )
              else
                Icon(
                  isSelected
                      ? Icons.radio_button_checked
                      : Icons.radio_button_off,
                  color:
                      isSelected ? AppColors.primaryBlue : AppColors.borderGray,
                ),
            ],
          ),
        ),
      ),
    );
  }

  void _toggleCandidate(String candidateId, Position position, bool wasSelected) {
    setState(() {
      final existing =
          List<String>.from(_selections[position.slug] ?? const []);
      if (wasSelected) {
        existing.remove(candidateId);
      } else {
        if (position.seatCount <= 1) {
          existing.clear();
        }
        if (existing.length < position.seatCount) {
          existing.add(candidateId);
        }
      }
      _selections[position.slug] = existing;
    });
  }

  Future<void> _nextOrReview(List<Position> positions) async {
    if (_positionIndex < positions.length - 1) {
      setState(() => _positionIndex++);
      return;
    }
    await _saveDraftAndContinue();
  }

  Future<void> _saveDraftAndContinue() async {
    setState(() => _saving = true);
    try {
      final selections = <String, dynamic>{};
      _selections.forEach(
        (posId, refs) =>
            selections[posId] = refs.length == 1 ? refs.first : refs,
      );
      await ref.read(voteRepositoryProvider).saveDraft(selections);
      if (!mounted) return;
      context.go('/ballot');
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Could not save your ballot draft. Try again.'),
          backgroundColor: AppColors.errorRed,
        ),
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }
}

class _PhaseBanner extends StatelessWidget {
  final String message;
  final VoidCallback? onGoToBallot;

  const _PhaseBanner({required this.message, this.onGoToBallot});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.schedule, size: 64, color: AppColors.textSecondary),
            const SizedBox(height: 16),
            Text(message, textAlign: TextAlign.center),
            if (onGoToBallot != null) ...[
              const SizedBox(height: 16),
              ElevatedButton(
                onPressed: onGoToBallot,
                child: const Text('Review My Ballot'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
