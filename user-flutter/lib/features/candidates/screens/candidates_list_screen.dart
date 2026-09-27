import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/debouncer.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/position_model.dart';
import '../../auth/providers/auth_provider.dart';
import '../providers/candidates_provider.dart';

/// Candidates as one ballot-style form: every position of the chosen tier in
/// rank order (President → Property Custodian; Governor → Provincial
/// Custodian), each with its candidates listed underneath. Picking a party
/// narrows each slot to that party's candidate; an empty slot shows a
/// placeholder so the form always reads top-to-bottom.
class CandidatesListScreen extends ConsumerStatefulWidget {
  const CandidatesListScreen({super.key});

  @override
  ConsumerState<CandidatesListScreen> createState() => _CandidatesListScreenState();
}

class _CandidatesListScreenState extends ConsumerState<CandidatesListScreen> {
  final TextEditingController _searchController = TextEditingController();
  final Debouncer _searchDebounce =
      Debouncer(delay: const Duration(milliseconds: 400));

  @override
  void dispose() {
    _searchDebounce.dispose();
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String value) {
    _searchDebounce.run(() {
      if (!mounted) return;
      ref.read(candidatesFilterProvider.notifier).update(
        (s) => s.copyWith(search: value),
      );
    });
  }

  void _onTierChanged(PositionTier newTier) {
    ref.read(candidatesFilterProvider.notifier).update(
      (s) => s.copyWith(tier: newTier, clearDepartment: true, clearParty: true),
    );
  }

  Widget _buildChips({
    required List<String> items,
    required String? selected,
    required void Function(String) onSelect,
  }) {
    if (items.isEmpty) {
      return const SizedBox.shrink();
    }
    return SizedBox(
      height: 44,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: items.length,
        separatorBuilder: (_, _) => const SizedBox(width: 8),
        itemBuilder: (context, index) {
          final item = items[index];
          final isSelected = selected == item;
          return ChoiceChip(
            label: Text(item),
            selected: isSelected,
            onSelected: (_) => onSelect(item),
            selectedColor: AppColors.primaryBlue.withValues(alpha: 0.1),
            labelStyle: TextStyle(
              color:
                  isSelected ? AppColors.primaryBlue : context.appTextSecondary,
              fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
              fontSize: 14,
            ),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(20),
              side: BorderSide(
                color: isSelected ? AppColors.primaryBlue : context.appBorder,
              ),
            ),
            showCheckmark: false,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final filter = ref.watch(candidatesFilterProvider);
    final departmentsAsync = ref.watch(departmentsProvider);
    final partiesAsync = ref.watch(partiesProvider);
    final positionsAsync = ref.watch(positionsProvider);
    final candidatesAsync = ref.watch(filteredCandidatesProvider);
    final appText = AppTextStyles.of(context);

    // Provincial races are departmental: the student is pinned to their own
    // department (when known), and only the party filter stays selectable.
    final studentDepartment =
        ref.watch(authProvider).student?.department?.trim();
    final isProvincial = filter.tier == PositionTier.provincial;
    final hasOwnDepartment =
        isProvincial && studentDepartment != null && studentDepartment.isNotEmpty;
    final effectiveDepartment =
        hasOwnDepartment ? studentDepartment : filter.department;
    final isSearching = filter.search.trim().isNotEmpty;

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Candidates'),
      body: ListView(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
        children: [
          // Tier Toggle
          Center(
            child: SegmentedButton<PositionTier>(
              segments: const [
                ButtonSegment(
                  value: PositionTier.national,
                  label: Text('National'),
                ),
                ButtonSegment(
                  value: PositionTier.provincial,
                  label: Text('Provincial'),
                ),
              ],
              selected: {filter.tier},
              onSelectionChanged: (newSelection) =>
                  _onTierChanged(newSelection.first),
              style: SegmentedButton.styleFrom(
                selectedBackgroundColor: AppColors.primaryBlue,
                selectedForegroundColor: Colors.white,
                side: BorderSide(color: context.appBorder),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(8),
                ),
              ),
            ),
          ),
          const SizedBox(height: 24),

          // Department step
          Text(
            'Department',
            style: appText.cardTitle,
          ),
          const SizedBox(height: 8),
          if (hasOwnDepartment)
            _lockedDepartment(studentDepartment)
          else
            departmentsAsync.when(
              data: (departments) => _buildChips(
                items: departments,
                selected: filter.department,
                onSelect: (dept) {
                  ref.read(candidatesFilterProvider.notifier).update(
                    (s) => s.copyWith(
                      department: dept,
                      clearParty: true,
                    ),
                  );
                },
              ),
              loading: () => const Center(child: LoadingIndicator()),
              error: (err, _) => Text(
                apiErrorMessage(err, fallback: 'Could not load departments.'),
                style: appText.secondary,
              ),
            ),
          const SizedBox(height: 24),

          // Party step
          Text(
            'Party',
            style: appText.cardTitle,
          ),
          const SizedBox(height: 8),
          partiesAsync.when(
            data: (parties) => _buildChips(
              items: parties,
              selected: filter.party,
              onSelect: (party) {
                ref.read(candidatesFilterProvider.notifier).update(
                  (s) => s.copyWith(party: party),
                );
              },
            ),
            loading: () => const Center(child: LoadingIndicator()),
            error: (err, _) => Text(
              apiErrorMessage(err, fallback: 'Could not load parties.'),
              style: appText.secondary,
            ),
          ),
          const SizedBox(height: 24),

          // Search
          TextField(
            controller: _searchController,
            onChanged: _onSearchChanged,
            decoration: const InputDecoration(
              hintText: 'Search candidates by name or slogan...',
              prefixIcon: Icon(Icons.search),
            ),
          ),
          const SizedBox(height: 24),

          // Header
          Text(
            '${filter.tier == PositionTier.national ? 'National' : 'Provincial'}'
            '${effectiveDepartment != null ? ' · $effectiveDepartment' : ''}'
            '${filter.party != null ? ' · ${filter.party}' : ''}',
            style: appText.pageTitle,
          ),
          const SizedBox(height: 16),

          // Ballot-form list grouped by position, top position first.
          positionsAsync.when(
            data: (positions) {
              final tierPositions =
                  positions.where((p) => p.tier == filter.tier).toList();
              if (tierPositions.isEmpty) {
                return const EmptyState(
                  message: 'No positions available',
                  subMessage: 'Positions for this tier have not been set up yet.',
                );
              }
              return candidatesAsync.when(
                data: (candidates) => _BallotForm(
                  positions: tierPositions,
                  candidates: candidates,
                  isSearching: isSearching,
                  showEmptySlots: filter.party != null && !isSearching,
                  emptySlotLabel:
                      filter.party != null ? 'No candidate from ${filter.party}' : 'No candidates yet',
                  onCandidateTap: (candidate) {
                    context.push('/candidate-profile', extra: candidate);
                  },
                ),
                loading: () => const Center(child: LoadingIndicator()),
                error: (err, _) => Center(
                  child: Text(
                    apiErrorMessage(err, fallback: 'Could not load candidates.'),
                  ),
                ),
              );
            },
            loading: () => const Center(child: LoadingIndicator()),
            error: (err, _) => Center(
              child: Text(
                apiErrorMessage(err, fallback: 'Could not load positions.'),
              ),
            ),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }

  /// Provincial slate is pinned to the student's own department — shown as a
  /// locked pill instead of an interactive chip row.
  Widget _lockedDepartment(String department) {
    return Container(
      height: 44,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: AppColors.primaryBlue.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.primaryBlue),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(
            Icons.lock_outline,
            size: 16,
            color: AppColors.primaryBlue,
          ),
          const SizedBox(width: 8),
          Flexible(
            child: Text(
              department,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: AppColors.primaryBlue,
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Groups matching candidates under their position, in tier rank order.
class _BallotForm extends StatelessWidget {
  final List<Position> positions;
  final List<Candidate> candidates;
  final bool isSearching;
  final bool showEmptySlots;
  final String emptySlotLabel;
  final void Function(Candidate) onCandidateTap;

  const _BallotForm({
    required this.positions,
    required this.candidates,
    required this.isSearching,
    required this.showEmptySlots,
    required this.emptySlotLabel,
    required this.onCandidateTap,
  });

  @override
  Widget build(BuildContext context) {
    final byPosition = <String, List<Candidate>>{};
    for (final candidate in candidates) {
      byPosition.putIfAbsent(candidate.position.id, () => []).add(candidate);
    }

    final children = <Widget>[];
    for (final position in positions) {
      children.add(_PositionHeader(position: position));
      children.add(const SizedBox(height: 8));

      final slotCandidates = byPosition[position.id] ?? const <Candidate>[];
      if (slotCandidates.isEmpty) {
        if (showEmptySlots || !isSearching) {
          children.add(_EmptySlot(message: emptySlotLabel));
        }
      } else {
        for (final candidate in slotCandidates) {
          children.add(
            _BallotRow(
              candidate: candidate,
              onTap: () => onCandidateTap(candidate),
            ),
          );
        }
      }
      children.add(const SizedBox(height: 20));
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: children,
    );
  }
}

/// Section heading for one position, e.g. "Senator · 12 seats".
class _PositionHeader extends StatelessWidget {
  final Position position;

  const _PositionHeader({required this.position});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Row(
      children: [
        Container(
          width: 4,
          height: 16,
          decoration: BoxDecoration(
            color: AppColors.primaryBlue,
            borderRadius: BorderRadius.circular(2),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            position.label,
            style: appText.cardTitle.copyWith(fontSize: 16),
          ),
        ),
        if (position.seatCount > 1)
          Text(
            '${position.seatCount} seats',
            style: TextStyle(
              fontSize: 12,
              color: context.appTextSecondary,
              fontWeight: FontWeight.w600,
            ),
          ),
      ],
    );
  }
}

/// Compact ballot row: avatar, name, party/grade line, tap → profile.
class _BallotRow extends StatelessWidget {
  final Candidate candidate;
  final VoidCallback onTap;

  const _BallotRow({required this.candidate, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final subBits = <String>[
      if (candidate.party?.trim().isNotEmpty ?? false) candidate.party!.trim(),
      if (candidate.gradeLine.trim().isNotEmpty) candidate.gradeLine.trim(),
    ];

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: context.appBorder),
      ),
      color: context.appSurface,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(
            children: [
              CachedAvatar(
                imageUrl: candidate.photoUrl.isNotEmpty
                    ? candidate.photoUrl
                    : null,
                radius: 22,
                initials: candidate.name,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      candidate.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        fontSize: 15,
                        color: context.appTextPrimary,
                      ),
                    ),
                    if (subBits.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        subBits.join(' · '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 13,
                          color: context.appTextSecondary,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const Icon(
                Icons.chevron_right,
                size: 20,
                color: AppColors.primaryBlue,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Placeholder shown when a party adopts no candidate for a position, so the
/// ballot form still reads President → lowest without gaps.
class _EmptySlot extends StatelessWidget {
  final String message;

  const _EmptySlot({required this.message});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: context.appTagBg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: context.appBorder),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.remove_circle_outline,
            size: 18,
            color: AppColors.textSecondary,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: TextStyle(
                fontSize: 13,
                color: context.appTextSecondary,
                fontStyle: FontStyle.italic,
              ),
            ),
          ),
        ],
      ),
    );
  }
}