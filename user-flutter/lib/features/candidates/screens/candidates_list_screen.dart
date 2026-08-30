import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/widgets/candidate_card.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/position_model.dart';
import '../providers/candidates_provider.dart';

class CandidatesListScreen extends ConsumerStatefulWidget {
  const CandidatesListScreen({super.key});

  @override
  ConsumerState<CandidatesListScreen> createState() => _CandidatesListScreenState();
}

class _CandidatesListScreenState extends ConsumerState<CandidatesListScreen> {
  final List<String> _selectedSenatorIds = [];
  final TextEditingController _searchController = TextEditingController();

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final positionsAsync = ref.watch(positionsProvider);
    final filter = ref.watch(candidatesFilterProvider);
    final candidatesAsync = ref.watch(filteredCandidatesProvider);

    return Scaffold(
      backgroundColor: AppColors.backgroundGray,
      appBar: const TopBar(title: 'Candidates'),
      body: positionsAsync.when(
        data: (positions) {
          final tierPositions = positions.where((p) => p.tier == filter.tier).toList();
          
          // Ensure a position is selected if none is
          if (filter.positionId == null && tierPositions.isNotEmpty) {
            Future.microtask(() {
              ref.read(candidatesFilterProvider.notifier).update(
                (s) => s.copyWith(positionId: tierPositions.first.id),
              );
            });
          }

          final selectedPosition = positions.firstWhere(
            (p) => p.id == filter.positionId,
            orElse: () => tierPositions.isNotEmpty ? tierPositions.first : positions.first,
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
                          final firstPosInTier = positions.firstWhere((p) => p.tier == newTier);
                          ref.read(candidatesFilterProvider.notifier).update(
                            (s) => s.copyWith(tier: newTier, positionId: firstPosInTier.id),
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
                        onChanged: (value) {
                          ref.read(candidatesFilterProvider.notifier).update(
                            (s) => s.copyWith(search: value),
                          );
                        },
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
                        return Column(
                          children: candidates.map((candidate) {
                            final isSenator = selectedPosition.id == 'senator';
                            return CandidateCard(
                              candidate: candidate,
                              isSelectable: isSenator,
                              isSelected: _selectedSenatorIds.contains(candidate.id),
                              onSelected: (selected) {
                                setState(() {
                                  if (selected == true) {
                                    if (_selectedSenatorIds.length < 12) {
                                      _selectedSenatorIds.add(candidate.id);
                                    }
                                  } else {
                                    _selectedSenatorIds.remove(candidate.id);
                                  }
                                });
                              },
                              onViewProfile: () {
                                context.push('/candidate-profile', extra: candidate);
                              },
                              onVote: () {
                                // Handle direct vote logic or navigation to Vote Now
                              },
                            );
                          }).toList(),
                        );
                      },
                      loading: () => const Center(child: LoadingIndicator()),
                      error: (err, stack) => Center(child: Text('Error: $err')),
                    ),
                    const SizedBox(height: 100),
                  ],
                ),
              ),
            ],
          );
        },
        loading: () => const LoadingIndicator(),
        error: (err, stack) => Center(child: Text('Error: $err')),
      ),
      bottomSheet: ref.watch(candidatesFilterProvider).positionId == 'senator'
          ? _buildSenatorSelectionBar()
          : null,
    );
  }

  Widget _buildSenatorSelectionBar() {
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
                  ? () {
                      // Confirm selection
                    }
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
