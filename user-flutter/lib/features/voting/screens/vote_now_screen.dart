import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/candidate_repository.dart';
import '../../../data/repositories/vote_repository.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../../dashboard/providers/election_status_provider.dart';
import '../../auth/providers/auth_provider.dart';

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
  /// When set (from a CandidateCard / profile "Vote" action), the guided flow
  /// starts at this position instead of the first one.
  final String? initialPositionId;

  const VoteNowScreen({super.key, this.initialPositionId});

  @override
  ConsumerState<VoteNowScreen> createState() => _VoteNowScreenState();
}

class _VoteNowScreenState extends ConsumerState<VoteNowScreen> {
  int _positionIndex = 0;
  final Map<String, List<String>> _selections = {};
  bool _saving = false;

  /// Guards the one-time post-frame preselection so it can't re-fire on every
  /// rebuild while the requested position is still being loaded.
  bool _preselected = false;

  @override
  Widget build(BuildContext context) {
    final positionsAsync = ref.watch(positionsProvider);
    final statusAsync = ref.watch(electionStatusProvider);
    final authState = ref.watch(authProvider);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Vote Now'),
      body: statusAsync.when(
        data: (status) {
          if (authState.student?.hasVoted ?? false) {
            return _AlreadyVoted(
              onViewBallot: () => context.go('/ballot'),
            );
          }
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
            error: (err, stack) => Center(
              child: Text(apiErrorMessage(err, fallback: 'Could not load positions.')),
            ),
          );
        },
        loading: () => const LoadingIndicator(),
        error: (err, stack) => Center(
          child: Text(apiErrorMessage(err, fallback: 'Could not check election status.')),
        ),
      ),
    );
  }

  Widget _buildFlow(List<Position> positions) {
    final activePositions =
        positions.where((p) => p.tier == PositionTier.national).toList()
          ..addAll(positions.where((p) => p.tier == PositionTier.provincial));
    if (activePositions.isEmpty) {
      return const Center(child: Text('No active positions.'));
    }

    // One-time preselection for direct "Vote" actions (post-frame, never during
    // build). Unknown ids are ignored and the flow just starts at position 0.
    final requestedId = widget.initialPositionId;
    if (!_preselected && requestedId != null) {
      _preselected = true;
      final idx = activePositions.indexWhere(
        (p) => p.id == requestedId || p.slug == requestedId,
      );
      if (idx > 0) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) setState(() => _positionIndex = idx);
        });
      }
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
                backgroundColor: context.appBorder,
                color: AppColors.primaryBlue,
                minHeight: 6,
                borderRadius: BorderRadius.circular(3),
              ),
              const SizedBox(height: 12),
              Text(
                'Step ${_positionIndex + 1} of ${activePositions.length}',
                style: TextStyle(
                  color: context.appTextSecondary,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                position.label,
                style: TextStyle(
                  color: context.appTextPrimary,
                  fontSize: 22,
                  fontWeight: FontWeight.bold,
                ),
              ),
              Text(
                position.seatCount > 1
                    ? 'Select up to ${position.seatCount} candidates'
                    : 'Select one candidate',
                style: TextStyle(color: context.appTextSecondary),
              ),
            ],
          ),
        ),
        Expanded(
          child: candidatesAsync.when(
            data: (candidates) => ListView.builder(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              itemCount: candidates.length,
              itemBuilder: (context, index) =>
                  _buildCandidateTile(candidates[index], position, currentRefs),
            ),
            loading: () => const LoadingIndicator(),
            error: (err, stack) => Center(
              child: Text(
                apiErrorMessage(
                  err,
                  fallback: 'Could not load candidates for this position.',
                ),
              ),
            ),
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
          color: isSelected ? AppColors.primaryBlue : context.appBorder,
          width: isSelected ? 2 : 1,
        ),
      ),
      color: context.appSurface,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () =>
            _toggleCandidate(candidate.candidateRef, position, isSelected),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              CachedAvatar(
                imageUrl: candidate.photoUrl.isNotEmpty
                    ? candidate.photoUrl
                    : null,
                radius: 24,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      candidate.name,
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 16,
                        color: context.appTextPrimary,
                      ),
                    ),
                    if (candidate.slogan.isNotEmpty)
                      Text(
                        candidate.slogan,
                        style: TextStyle(color: context.appTextSecondary),
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
                      isSelected ? AppColors.primaryBlue : context.appBorder,
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

class _AlreadyVoted extends StatelessWidget {
  final VoidCallback onViewBallot;

  const _AlreadyVoted({required this.onViewBallot});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.verified,
              size: 72,
              color: Theme.of(context).colorScheme.primary,
            ),
            const SizedBox(height: 16),
            Text(
              'You have already cast your ballot',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.bold,
                color: context.appTextPrimary,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'Each voter casts one ballot. Review your submission and receipt from the My Ballot tab.',
              textAlign: TextAlign.center,
              style: TextStyle(color: context.appTextSecondary),
            ),
            const SizedBox(height: 24),
            ElevatedButton.icon(
              onPressed: onViewBallot,
              icon: const Icon(Icons.ballot),
              label: const Text('View My Ballot'),
            ),
          ],
        ),
      ),
    );
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
            Icon(Icons.schedule, size: 64, color: context.appTextSecondary),
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
