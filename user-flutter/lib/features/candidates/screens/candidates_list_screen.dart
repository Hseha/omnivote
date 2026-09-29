import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/debouncer.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/app_chip.dart';
import '../../../core/widgets/app_text_field.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_skeleton.dart';
import '../../../core/widgets/section_header.dart';
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
  ConsumerState<CandidatesListScreen> createState() =>
      _CandidatesListScreenState();
}

class _CandidatesListScreenState extends ConsumerState<CandidatesListScreen> {
  final TextEditingController _searchController = TextEditingController();
  final Debouncer _searchDebounce = Debouncer(
    delay: const Duration(milliseconds: 400),
  );

  @override
  void dispose() {
    _searchDebounce.dispose();
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String value) {
    _searchDebounce.run(() {
      if (!mounted) return;
      ref
          .read(candidatesFilterProvider.notifier)
          .update((s) => s.copyWith(search: value));
    });
  }

  void _onTierChanged(PositionTier newTier) {
    ref.read(candidatesFilterProvider.notifier).update(
          (s) => s.copyWith(
            tier: newTier,
            clearDepartment: true,
            clearParty: true,
          ),
        );
  }

  void _onTierLabel(String label) {
    _onTierChanged(
      label == 'National' ? PositionTier.national : PositionTier.provincial,
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
    final studentDepartment = ref.watch(
      authProvider.select((state) => state.student?.department?.trim()),
    );
    final isProvincial = filter.tier == PositionTier.provincial;
    final hasOwnDepartment = isProvincial &&
        studentDepartment != null &&
        studentDepartment.isNotEmpty;
    final effectiveDepartment =
        hasOwnDepartment ? studentDepartment : filter.department;
    final isSearching = filter.search.trim().isNotEmpty;

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Candidates'),
      body: CustomScrollView(
        slivers: [
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.md,
              AppSpacing.lg,
              AppSpacing.md,
              0,
            ),
            // Filter chrome. `SliverList.list` rather than a wrapping `Column`
            // so each child keeps the same full-width, tight cross-axis
            // constraints the old outer `ListView` gave it.
            sliver: SliverList.list(
              children: [
                // Tier Toggle
                AppChipRow(
                  items: const ['National', 'Provincial'],
                  selected: filter.tier == PositionTier.national
                      ? 'National'
                      : 'Provincial',
                  onSelect: _onTierLabel,
                ),
                AppSpacing.vLg,

                // Department step
                const SectionHeader(title: 'Department'),
                AppSpacing.vSm,
                if (hasOwnDepartment)
                  _lockedDepartment(context, studentDepartment)
                else
                  departmentsAsync.when(
                    data: (departments) => AppChipRow(
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
                    loading: () => LoadingSkeleton.lines(count: 1),
                    error: (err, _) => ErrorState(
                      message: apiErrorMessage(
                        err,
                        fallback: 'Could not load departments.',
                      ),
                      onRetry: () =>
                          ref.invalidate(departmentsProvider),
                    ),
                  ),
                AppSpacing.vLg,

                // Party step
                const SectionHeader(title: 'Party'),
                AppSpacing.vSm,
                partiesAsync.when(
                  data: (parties) => AppChipRow(
                    items: parties,
                    selected: filter.party,
                    onSelect: (party) {
                      ref
                          .read(candidatesFilterProvider.notifier)
                          .update((s) => s.copyWith(party: party));
                    },
                  ),
                  loading: () => LoadingSkeleton.lines(count: 1),
                  error: (err, _) => ErrorState(
                    message: apiErrorMessage(
                      err,
                      fallback: 'Could not load parties.',
                    ),
                    onRetry: () => ref.invalidate(partiesProvider),
                  ),
                ),
                AppSpacing.vLg,

                // Search
                AppTextField(
                  controller: _searchController,
                  onChanged: _onSearchChanged,
                  hint: 'Search candidates by name or slogan...',
                  prefixIcon: const Icon(Icons.search),
                  textInputAction: TextInputAction.search,
                ),
                AppSpacing.vLg,

                // Header
                Text(
                  '${filter.tier == PositionTier.national ? 'National' : 'Provincial'}'
                  '${effectiveDepartment != null ? ' · $effectiveDepartment' : ''}'
                  '${filter.party != null ? ' · ${filter.party}' : ''}',
                  style: appText.headlineSmall,
                ),
                AppSpacing.vMd,
              ],
            ),
          ),

          // Ballot-form rows. The form is its own sliver list, so only the rows
          // on screen are built instead of every position header, candidate row
          // and spacer up front.
          positionsAsync.when(
            data: (positions) {
              final tierPositions =
                  positions.where((p) => p.tier == filter.tier).toList();
              if (tierPositions.isEmpty) {
                return const SliverToBoxAdapter(
                  child: EmptyState(
                    message: 'No positions available',
                    subMessage:
                        'Positions for this tier have not been set up yet.',
                  ),
                );
              }
              return candidatesAsync.when(
                data: (candidates) => _BallotForm(
                  positions: tierPositions,
                  candidates: candidates,
                  isSearching: isSearching,
                  showEmptySlots: filter.party != null && !isSearching,
                  emptySlotLabel: filter.party != null
                      ? 'No candidate from ${filter.party}'
                      : 'No candidates yet',
                  onCandidateTap: (candidate) {
                    context.push('/candidate-profile', extra: candidate);
                  },
                ),
                loading: () => SliverToBoxAdapter(
                  child: Padding(
                    padding: AppSpacing.screenPaddingHorizontal,
                    child: Column(
                      children: [
                        LoadingSkeleton.row(),
                        AppSpacing.vSm,
                        LoadingSkeleton.row(),
                        AppSpacing.vSm,
                        LoadingSkeleton.row(),
                      ],
                    ),
                  ),
                ),
                error: (err, _) => SliverToBoxAdapter(
                  child: ErrorState(
                    message: apiErrorMessage(
                      err,
                      fallback: 'Could not load candidates.',
                    ),
                    onRetry: () =>
                        ref.invalidate(filteredCandidatesProvider),
                  ),
                ),
              );
            },
            loading: () => SliverToBoxAdapter(
              child: Padding(
                padding: AppSpacing.screenPaddingHorizontal,
                child: Column(
                  children: [
                    LoadingSkeleton.row(),
                    AppSpacing.vSm,
                    LoadingSkeleton.row(),
                    AppSpacing.vSm,
                    LoadingSkeleton.row(),
                  ],
                ),
              ),
            ),
            error: (err, _) => SliverToBoxAdapter(
              child: ErrorState(
                message: apiErrorMessage(
                  err,
                  fallback: 'Could not load positions.',
                ),
                onRetry: () => ref.invalidate(positionsProvider),
              ),
            ),
          ),
          const SliverToBoxAdapter(child: AppSpacing.vLg),
        ],
      ),
    );
  }

  /// Provincial slate is pinned to the student's own department — shown as a
  /// locked pill instead of an interactive chip row.
  Widget _lockedDepartment(BuildContext context, String department) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      height: AppMetrics.minTapTarget,
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
      decoration: BoxDecoration(
        color: scheme.primary.withValues(alpha: 0.1),
        borderRadius: AppRadius.xlAll,
        border: Border.all(color: scheme.primary),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.lock_outline,
            size: 16,
            color: scheme.primary,
          ),
          AppSpacing.hSm,
          Flexible(
            child: Text(
              department,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTextStyles.of(context).titleSmall.copyWith(
                    color: scheme.primary,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Groups matching candidates under their position, in tier rank order.
///
/// Returns a **sliver** list of the flattened form lines, so the scroll view
/// only builds the positions and candidates currently on screen. The previous
/// implementation flattened everything into one `Column` inside the screen's
/// outer `ListView`, which built a widget for every candidate in the tier (and
/// laid them all out) on every rebuild — including every keystroke of the
/// search box, before the 400 ms debounce even fired.
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

  /// Flattens the form into one entry per line. Cheap value objects only; the
  /// widgets for them are built on demand by [SliverList.builder].
  List<_BallotLine> _lines() {
    final byPosition = <String, List<Candidate>>{};
    for (final candidate in candidates) {
      byPosition.putIfAbsent(candidate.position.id, () => []).add(candidate);
    }

    final lines = <_BallotLine>[];
    for (final position in positions) {
      lines.add(_HeaderLine(position));
      lines.add(const _GapLine(AppSpacing.sm));

      final slotCandidates = byPosition[position.id] ?? const <Candidate>[];
      if (slotCandidates.isEmpty) {
        if (showEmptySlots || !isSearching) {
          lines.add(_EmptySlotLine(emptySlotLabel));
        }
      } else {
        for (final candidate in slotCandidates) {
          lines.add(_CandidateLine(candidate));
        }
      }
      lines.add(const _GapLine(AppSpacing.md));
    }
    return lines;
  }

  @override
  Widget build(BuildContext context) {
    final lines = _lines();

    return SliverPadding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      sliver: SliverList.builder(
        itemCount: lines.length,
        itemBuilder: (context, index) {
          final line = lines[index];
          return switch (line) {
            _HeaderLine(:final position) => _PositionHeader(position: position),
            _CandidateLine(:final candidate) => _BallotRow(
                // Keyed by candidate so a filtered list can never leave a
                // recycled row pointing at the previous candidate.
                key: ValueKey(candidate.candidateRef),
                candidate: candidate,
                onTap: () => onCandidateTap(candidate),
              ),
            _EmptySlotLine(:final message) => _EmptySlot(message: message),
            _GapLine(:final height) => SizedBox(height: height),
          };
        },
      ),
    );
  }
}

/// One line of the ballot form. A sealed hierarchy so the item builder is
/// exhaustive without an `else` fallback.
sealed class _BallotLine {
  const _BallotLine();
}

class _HeaderLine extends _BallotLine {
  final Position position;
  const _HeaderLine(this.position);
}

class _CandidateLine extends _BallotLine {
  final Candidate candidate;
  const _CandidateLine(this.candidate);
}

class _EmptySlotLine extends _BallotLine {
  final String message;
  const _EmptySlotLine(this.message);
}

class _GapLine extends _BallotLine {
  final double height;
  const _GapLine(this.height);
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
          width: AppSpacing.xs,
          height: AppSpacing.md,
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.primary,
            borderRadius: AppRadius.smAll,
          ),
        ),
        AppSpacing.hSm,
        Expanded(
          child: Text(
            position.label,
            style: appText.titleMedium,
          ),
        ),
        if (position.seatCount > 1)
          Text(
            '${position.seatCount} seats',
            style: appText.labelSmall,
          ),
      ],
    );
  }
}

/// Compact ballot row: avatar, name, party/grade line, tap → profile.
class _BallotRow extends StatelessWidget {
  final Candidate candidate;
  final VoidCallback onTap;

  const _BallotRow({
    super.key,
    required this.candidate,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final subBits = <String>[
      if (candidate.party?.trim().isNotEmpty ?? false) candidate.party!.trim(),
      if (candidate.gradeLine.trim().isNotEmpty) candidate.gradeLine.trim(),
    ];

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: AppCard(
        onTap: onTap,
        padding: const EdgeInsets.symmetric(
          horizontal: AppMetrics.rowPaddingH,
          vertical: AppMetrics.rowPaddingV,
        ),
        child: Row(
          children: [
            CachedAvatar(
              imageUrl:
                  candidate.photoUrl.isNotEmpty ? candidate.photoUrl : null,
              radius: AppMetrics.avatarMd,
              initials: candidate.name,
            ),
            AppSpacing.hMd,
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    candidate.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: appText.titleSmall,
                  ),
                  if (subBits.isNotEmpty) ...[
                    AppSpacing.vXs,
                    Text(
                      subBits.join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: appText.labelSmall,
                    ),
                  ],
                ],
              ),
            ),
            Icon(
              Icons.chevron_right,
              size: 20,
              color: Theme.of(context).colorScheme.primary,
              semanticLabel: 'View profile',
            ),
          ],
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
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: AppCard(
        color: context.appTagBg,
        padding: const EdgeInsets.symmetric(
          horizontal: AppMetrics.rowPaddingH,
          vertical: AppMetrics.rowPaddingV,
        ),
        child: Row(
          children: [
            Icon(
              Icons.remove_circle_outline,
              size: 18,
              color: context.appTextSecondary,
            ),
            AppSpacing.hSm,
            Expanded(
              child: Text(
                message,
                style: AppTextStyles.of(context).labelSmall.copyWith(
                      fontStyle: FontStyle.italic,
                    ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
