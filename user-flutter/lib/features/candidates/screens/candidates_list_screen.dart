import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/utils/debouncer.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/widgets/candidate_card.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/vote_repository.dart';
import '../providers/candidates_provider.dart';

class CandidatesListScreen extends ConsumerStatefulWidget {
  const CandidatesListScreen({super.key});

  @override
  ConsumerState<CandidatesListScreen> createState() => _CandidatesListScreenState();
}

class _CandidatesListScreenState extends ConsumerState<CandidatesListScreen> {
  static const int _senatorLimit = 12;

  /// Holds senator selections as `candidate_ref` values so they match the
  /// draft-ballot / vote-submit contract (the old `candidate.id` values never
  /// resolved in My Ballot or on the server).
  final List<String> _selectedSenatorIds = [];
  final TextEditingController _searchController = TextEditingController();
  final Debouncer _searchDebounce =
      Debouncer(delay: const Duration(milliseconds: 400));
  bool _filterInitialized = false;

  @override
  void dispose() {
    _searchDebounce.dispose();
    _searchController.dispose();
    super.dispose();
  }

  /// Returns the first position matching [test], or null (audit §2 #6:
  /// `firstWhere` on an empty tier used to throw `StateError`).
  Position? _pickFirst(List<Position> positions, bool Function(Position) test) {
    for (final p in positions) {
      if (test(p)) return p;
    }
    return null;
  }

  /// Debounces search input (audit §3 #3) so a request fires only after the
  /// user pauses typing, not on every keystroke.
  void _onSearchChanged(String value) {
    _searchDebounce.run(() {
      if (!mounted) return;
      ref.read(candidatesFilterProvider.notifier).update(
        (s) => s.copyWith(search: value),
      );
    });
  }

  /// Wires the previously-dead "Confirm Selection" action (audit §2 #3):
  /// persists the senator selections to the server draft (PUT /api/ballot/me)
  /// keyed by the position slug with `candidate_ref` values, then hands off to
  /// My Ballot for review/submit.
  Future<void> _confirmSenatorSelection(Position position) async {
    if (_selectedSenatorIds.isEmpty) return;
    try {
      await ref.read(voteRepositoryProvider).saveDraft({
        position.slug: List<String>.from(_selectedSenatorIds),
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Senator selections saved to your draft ballot.'),
        ),
      );
      context.go('/ballot');
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            apiErrorMessage(e, fallback: 'Could not save your selections.'),
          ),
          backgroundColor: AppColors.errorRed,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final positionsAsync = ref.watch(positionsProvider);
    final filter = ref.watch(candidatesFilterProvider);
    final candidatesAsync = ref.watch(filteredCandidatesProvider);

    // Effective position id without mutating state during build: the default
    // is committed once positions resolve (see post-frame init below).
    final loadedPositions = positionsAsync.value ?? const <Position>[];
    final effPositionId = filter.positionId ??
        _pickFirst(loadedPositions, (p) => p.tier == filter.tier)?.id;
    final senatorPosition = effPositionId == 'senator'
        ? _pickFirst(loadedPositions, (p) => p.id == effPositionId)
        : null;

    return Scaffold(
      backgroundColor: AppColors.backgroundGray,
      appBar: const TopBar(title: 'Candidates'),
      body: positionsAsync.when(
        data: (positions) {
          final tierPositions = positions.where((p) => p.tier == filter.tier).toList();

          // Initialize the default positionId once positions resolve (post-frame,
          // audit §2 #7) — never from inside build.
          if (!_filterInitialized && tierPositions.isNotEmpty) {
            _filterInitialized = true;
            if (filter.positionId == null) {
              final firstId = tierPositions.first.id;
              WidgetsBinding.instance.addPostFrameCallback((_) {
                if (mounted) {
                  ref.read(candidatesFilterProvider.notifier).update(
                    (s) => s.copyWith(positionId: firstId),
                  );
                }
              });
            }
          }

          if (tierPositions.isEmpty) {
            return const Center(
              child: EmptyState(
                message: 'No positions available',
                subMessage: 'There are no positions in this tier yet.',
              ),
            );
          }

          final selectedPosition = tierPositions.firstWhere(
            (p) => p.id == (filter.positionId ?? tierPositions.first.id),
            orElse: () => tierPositions.first,
          );

          return Column(
            children: [
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
                  children: [
                    // Tier Toggle
                    Center(
                      child: SegmentedButton<PositionTier>(
                        segments: const [
                          ButtonSegment(
                            value: PositionTier.school,
                            label: Text('School'),
                          ),
                          ButtonSegment(
                            value: PositionTier.provincial,
                            label: Text('Provincial'),
                          ),
                        ],
                        selected: {filter.tier},
                        onSelectionChanged: (newSelection) {
                          final newTier = newSelection.first;
                          final firstPosInTier =
                              _pickFirst(positions, (p) => p.tier == newTier);
                          ref.read(candidatesFilterProvider.notifier).update(
                            (s) => s.copyWith(
                              tier: newTier,
                              positionId: firstPosInTier?.id,
                              clearPositionId: firstPosInTier == null,
                            ),
                          );
                        },
                        style: SegmentedButton.styleFrom(
                          selectedBackgroundColor: AppColors.primaryBlue,
                          selectedForegroundColor: Colors.white,
                          side: const BorderSide(color: AppColors.borderGray),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(8),
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 24),

                    // Position Chips
                    SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: Row(
                        children: tierPositions.map((pos) {
                          final isSelected = filter.positionId == pos.id;
                          return Padding(
                            padding: const EdgeInsets.only(right: 8),
                            child: ChoiceChip(
                              label: Text(pos.label),
                              selected: isSelected,
                              onSelected: (selected) {
                                if (selected) {
                                  ref.read(candidatesFilterProvider.notifier).update(
                                    (s) => s.copyWith(positionId: pos.id),
                                  );
                                }
                              },
                              selectedColor: AppColors.primaryBlue.withValues(alpha: 0.1),
                              labelStyle: TextStyle(
                                color: isSelected ? AppColors.primaryBlue : AppColors.textSecondary,
                                fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                                fontSize: 14,
                              ),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(20),
                                side: BorderSide(
                                  color: isSelected ? AppColors.primaryBlue : AppColors.borderGray,
                                ),
                              ),
                              showCheckmark: false,
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                            ),
                          );
                        }).toList(),
                      ),
                    ),
                    const SizedBox(height: 32),

                    // Header
                    Text(
                      selectedPosition.label,
                      style: AppTextStyles.pageTitle,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      selectedPosition.description,
                      style: AppTextStyles.secondary,
                    ),
                    const SizedBox(height: 24),

                    // Search & Filters (Spec: For President only)
                    if (selectedPosition.id == 'president') ...[
                      TextField(
                        controller: _searchController,
                        onChanged: _onSearchChanged,
                        decoration: InputDecoration(
                          hintText: 'Search candidates by name or slogan...',
                          prefixIcon: const Icon(Icons.search),
                        ),
                      ),
                      const SizedBox(height: 12),
                      Row(
                        children: [
                          Expanded(
                            child: _buildDropdown(
                              value: filter.grade ?? 'All Grades',
                              items: ['All Grades', 'Grade 10', 'Grade 11', 'Grade 12'],
                              onChanged: (val) {
                                ref.read(candidatesFilterProvider.notifier).update(
                                  (s) => s.copyWith(grade: val),
                                );
                              },
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 24),
                    ],

                    // Candidate List
                    candidatesAsync.when(
                      data: (candidates) {
                        if (candidates.isEmpty) {
                          return const EmptyState(
                            message: 'No candidates found',
                            subMessage: 'Try adjusting your filters or search query.',
                          );
                        }
                        final isSenator = selectedPosition.id == 'senator';
                        return ListView.builder(
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          itemCount: candidates.length,
                          itemBuilder: (context, index) {
                            final candidate = candidates[index];
                            return CandidateCard(
                              candidate: candidate,
                              isSelectable: isSenator,
                              isSelected:
                                  _selectedSenatorIds.contains(candidate.candidateRef),
                              onSelected: (selected) {
                                setState(() {
                                  if (selected == true) {
                                    if (_selectedSenatorIds.length < _senatorLimit) {
                                      _selectedSenatorIds.add(candidate.candidateRef);
                                    }
                                  } else {
                                    _selectedSenatorIds.remove(candidate.candidateRef);
                                  }
                                });
                              },
                              onViewProfile: () {
                                context.push('/candidate-profile', extra: candidate);
                              },
                              onVote: () {
                                // Route to the guided Vote Now flow, preselected at
                                // this candidate's position (audit §2 #1).
                                context.go('/vote-now', extra: candidate.position.id);
                              },
                            );
                          },
                        );
                      },
                      loading: () => const Center(child: LoadingIndicator()),
                      error: (err, stack) => Center(
                        child: Text(
                          apiErrorMessage(err, fallback: 'Could not load candidates.'),
                        ),
                      ),
                    ),
                    const SizedBox(height: 100),
                  ],
                ),
              ),
            ],
          );
        },
        loading: () => const LoadingIndicator(),
        error: (err, stack) => Center(
          child: Text(
            apiErrorMessage(err, fallback: 'Could not load positions.'),
          ),
        ),
      ),
      bottomSheet: senatorPosition == null
          ? null
          : _buildSenatorSelectionBar(senatorPosition),
    );
  }

  Widget _buildSenatorSelectionBar(Position position) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: AppColors.borderGray)),
        boxShadow: [
          BoxShadow(
            color: Colors.black12,
            blurRadius: 10,
            offset: Offset(0, -2),
          ),
        ],
      ),
      child: SafeArea(
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${_selectedSenatorIds.length} / 12 selected',
                  style: AppTextStyles.cardTitle,
                ),
                Text(
                  'Select up to 12 candidates',
                  style: AppTextStyles.secondary.copyWith(fontSize: 12),
                ),
              ],
            ),
            ElevatedButton(
              onPressed: _selectedSenatorIds.isNotEmpty
                  ? () => _confirmSenatorSelection(position)
                  : null,
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primaryBlue,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(8),
                ),
                elevation: 0,
              ),
              child: const Text(
                'Confirm Selection',
                style: TextStyle(fontWeight: FontWeight.bold),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDropdown({
    required String value,
    required List<String> items,
    required ValueChanged<String?> onChanged,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: AppColors.borderGray),
        borderRadius: BorderRadius.circular(8),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          value: value,
          isExpanded: true,
          icon: const Icon(Icons.keyboard_arrow_down, color: AppColors.textSecondary),
          style: AppTextStyles.body,
          onChanged: onChanged,
          items: items.map<DropdownMenuItem<String>>((String value) {
            return DropdownMenuItem<String>(
              value: value,
              child: Text(value),
            );
          }).toList(),
        ),
      ),
    );
  }
}
