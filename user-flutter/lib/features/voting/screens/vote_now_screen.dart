import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_chip.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_skeleton.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/models/student_model.dart';
import '../../../data/repositories/candidate_repository.dart';
import '../../../data/repositories/vote_repository.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../../dashboard/providers/election_status_provider.dart';
import '../../auth/providers/auth_provider.dart';
import '../electorate_scope.dart';

/// Fetches every approved candidate in a tier, for the grouped national
/// checklist. One paged request instead of one per position, so switching the
/// party filter does not fan out into nine round-trips.
final voteTierCandidatesProvider = FutureProvider.family<List<Candidate>,
    ({PositionTier tier, String? party})>((ref, key) async {
  return await ref
      .watch(candidateRepositoryProvider)
      .getAllCandidatesForTier(tier: key.tier.name, party: key.party);
});

/// "Vote Now" guided flow: two tabs, National and Provincial, each showing its
/// whole tier as one continuous ballot sheet. Lets the student select 1 (or N for
/// multi-seat positions, e.g. 12 Senators), persists the draft, and hands off to
/// "My Ballot" to submit.
class VoteNowScreen extends ConsumerStatefulWidget {
  /// When set (from a CandidateCard / profile "Vote" action), the guided flow
  /// starts at this position instead of the first one.
  final String? initialPositionId;

  const VoteNowScreen({super.key, this.initialPositionId});

  @override
  ConsumerState<VoteNowScreen> createState() => _VoteNowScreenState();
}

class _VoteNowScreenState extends ConsumerState<VoteNowScreen> {
  final Map<String, List<String>> _selections = {};
  bool _saving = false;

  /// Party view filter, per tier. Null shows every party. Each tab keeps its
  /// own so a party chosen for the national council never silently hides a
  /// provincial slate, and switching tabs does not reset the other one.
  String? _nationalParty;
  String? _provincialParty;

  /// Which tier's sheet is on screen. Replaces the old linear step index: the
  /// flow is two tabs, not eight screens.
  PositionTier _tier = PositionTier.national;

  String? get _activeParty =>
      _tier == PositionTier.provincial ? _provincialParty : _nationalParty;
  bool get _isProvincialStep => _tier == PositionTier.provincial;

  /// The signed-in student, used to decide which seats and nominees are on
  /// their ballot. Null during the frame before auth resolves; the helpers
  /// treat that as "unknown voter", which for a scoped seat means showing
  /// nothing rather than showing everything.
  Student? get _voter => ref.read(authProvider).student;

  /// Guards the one-time deep-link tab switch so it can't fight the user if
  /// they change tabs while the requested position is still loading.
  bool _preselected = false;

  @override
  Widget build(BuildContext context) {
    final positionsAsync = ref.watch(positionsProvider);
    // Phase, loading and error are read as three primitives derived from the
    // status provider, so this screen rebuilds only when the phase actually
    // changes — not twice every 30 s tick (loading → data), and not when the
    // status payload changes in some other field. The 30 s poll re-runs the
    // provider, which puts it back in AsyncLoading while it refetches — that
    // state is NOT a "refresh" (isRefreshing stays false), so
    // `skipLoadingOnRefresh` does not suppress it and mapping it to a spinner
    // would blank the whole ballot every cycle, wiping the voter's in-progress
    // selections. Only a null value with no error means "nothing to show yet".
    final phase = ref.watch(
      electionStatusProvider.select((state) => state.valueOrNull?.phase),
    );
    final statusLoading = ref.watch(
      electionStatusProvider.select((state) => state.isLoading),
    );
    final statusError = ref.watch(
      electionStatusProvider.select((state) => state.error),
    );
    // Only `hasVoted` decides which of the three bodies below is shown.
    final hasVoted = ref.watch(
      authProvider.select((state) => state.student?.hasVoted ?? false),
    );

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Vote Now'),
      body: phase == null
          ? _statusPlaceholder(statusLoading, statusError)
          : _buildForPhase(context, phase, positionsAsync, hasVoted),
    );
  }

  /// The "no phase yet" states: a skeleton preview while the first check is in
  /// flight, an error with retry when it could not be read, and (in the
  /// impossible data-with-no-value case) nothing — matching the old async
  /// `when` precedence.
  Widget _statusPlaceholder(bool loading, Object? error) {
    if (loading) {
      return ListView(
        padding: AppSpacing.screenPadding,
        children: [
          LoadingSkeleton.row(),
          AppSpacing.vSm,
          LoadingSkeleton.row(),
          AppSpacing.vSm,
          LoadingSkeleton.row(),
        ],
      );
    }
    if (error != null) {
      return ErrorState(
        message:
            apiErrorMessage(error, fallback: 'Could not check election status.'),
        onRetry: () => ref.read(electionStatusEpochProvider.notifier).state++,
      );
    }
    return const SizedBox.shrink();
  }

  Widget _buildForPhase(
    BuildContext context,
    ElectionPhase phase,
    AsyncValue<List<Position>> positionsAsync,
    bool hasVoted,
  ) {
    if (hasVoted) {
      return _AlreadyVoted(onViewBallot: () => context.go('/ballot'));
    }
    if (phase != ElectionPhase.votingOpen) {
      return _PhaseBanner(
        message: phase == ElectionPhase.votingClosed
            ? 'Polls are closed. You can review your ballot and results.'
            : 'Voting is not open yet - you can browse candidates but not cast a ballot.',
        onGoToBallot: phase == ElectionPhase.votingClosed
            ? () => context.go('/ballot')
            : null,
      );
    }

    return positionsAsync.when(
      data: (positions) => _buildFlow(positions),
      loading: () => ListView(
        padding: AppSpacing.screenPadding,
        children: [
          LoadingSkeleton.row(),
          AppSpacing.vSm,
          LoadingSkeleton.row(),
          AppSpacing.vSm,
          LoadingSkeleton.row(),
        ],
      ),
      error: (err, stack) => ErrorState(
        message: apiErrorMessage(err, fallback: 'Could not load positions.'),
        onRetry: () => ref.invalidate(positionsProvider),
      ),
    );
  }

  Widget _buildFlow(List<Position> positions) {
    final national =
        positions.where((p) => p.tier == PositionTier.national).toList();
    final provincial =
        positions.where((p) => p.tier == PositionTier.provincial).toList();

    if (national.isEmpty && provincial.isEmpty) {
      return const EmptyState(
        message: 'No active positions.',
        subMessage: 'Positions have not been set up for this election yet.',
        icon: Icons.ballot_outlined,
      );
    }

    // A deep link from a candidate card asks for one specific seat. Open the
    // tab that owns it, otherwise a student tapping "Vote" on a provincial
    // candidate would land on the national sheet, where that race is not
    // shown and the tap looks like it did nothing.
    final requestedId = widget.initialPositionId;
    if (!_preselected && requestedId != null) {
      _preselected = true;
      Position? requested;
      for (final p in positions) {
        if (p.id == requestedId || p.slug == requestedId) {
          requested = p;
          break;
        }
      }
      if (requested != null && requested.tier != _tier) {
        final target = requested.tier;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) setState(() => _tier = target);
        });
      }
    }

    return _buildTabView(national: national, provincial: provincial);
  }

  /// Every position the voter is entitled to fill, national and provincial
  /// together. Drives the "n of m filled" counter and the list of omissions
  /// offered when they try to review early.
  List<Position> _requiredPositions(
    List<Position> national,
    List<Position> provincial,
  ) =>
      [...national, ...provincial];

  /// Last successful position lists, kept so the header counter and the review
  /// warning can read them without re-plumbing the loading state.
  List<Position> _nationalCache = const [];
  List<Position> _provincialCache = const [];

  Widget _buildTabView({
    required List<Position> national,
    required List<Position> provincial,
  }) {
    // Stashed during build so _missingPositions/_filledCount can read them.
    // Positions load once and then only change when the admin edits the
    // election, so this does not fight the user across rebuilds.
    _nationalCache = national;
    _provincialCache = provincial;

    final partiesAsync = ref.watch(partiesProvider);
    final activeParty = _activeParty;
    final tierPositions = _isProvincialStep ? provincial : national;

    final body = _buildBallotSheet(
      tierPositions: tierPositions,
      activeParty: activeParty,
      candidatesAsync: ref.watch(
        voteTierCandidatesProvider((tier: _tier, party: activeParty)),
      ),
    );

    final required = _requiredPositions(national, provincial);
    final filled = required
        .where((p) => (_selections[p.slug] ?? const <String>[]).isNotEmpty)
        .length;
    final missing = required
        .where((p) => (_selections[p.slug] ?? const <String>[]).isEmpty)
        .toList();

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.md,
            AppSpacing.md,
            AppSpacing.md,
            0,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // National and Provincial are two views of ONE ballot, not two
              // halves of it. Progress therefore counts filled positions across
              // both, so a full national slate with an empty provincial tab
              // never reads as "done".
              LinearProgressIndicator(
                value: required.isEmpty ? 0 : filled / required.length,
                backgroundColor: context.appBorder,
                color: Theme.of(context).colorScheme.primary,
                minHeight: AppMetrics.barThickness,
                borderRadius: BorderRadius.circular(AppMetrics.barRadius),
              ),
              AppSpacing.vSm,
              Text(
                '$filled of ${required.length} positions filled',
                style: AppTextStyles.of(context).labelSmall.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
              ),
              AppSpacing.vSm,
              _buildTierSwitch(),
              AppSpacing.vSm,
              Text(
                _isProvincialStep
                    ? 'Your college, all provincial positions'
                    : 'All colleges, all national positions',
                style: AppTextStyles.of(context).titleMedium,
              ),
              AppSpacing.vSm,
              _buildPartyFilter(partiesAsync, activeParty),
            ],
          ),
        ),
        Expanded(child: body),
        SafeArea(
          top: false,
          child: Padding(
            padding: AppSpacing.screenPadding,
            child: AppButton.primary(
              label: missing.isEmpty
                  ? 'Review My Ballot'
                  : 'Review My Ballot (${missing.length} unfilled)',
              onPressed: _saving ? null : () => _reviewBallot(missing: missing),
              isLoading: _saving,
            ),
          ),
        ),
      ],
    );
  }

  /// The National / Provincial switch. Same single-select chip row the
  /// Candidates screen uses so the two places feel identical.
  Widget _buildTierSwitch() {
    final hasNational = _nationalCache.isNotEmpty;
    final hasProvincial = _provincialCache.isNotEmpty;

    return AppChipRow(
      items: [
        if (hasNational) 'National',
        if (hasProvincial) 'Provincial',
      ],
      selected:
          _tier == PositionTier.national ? 'National' : 'Provincial',
      onSelect: (label) {
        // Tab switches are locked while the draft is saving, mirroring the
        // old disabled segmented control.
        if (_saving) return;
        setState(
          () => _tier = label == 'National'
              ? PositionTier.national
              : PositionTier.provincial,
        );
      },
    );
  }

  /// Hands off to the review step. A student is allowed to review with seats
  /// left blank -- a single skipped race is rarely deliberate and hard-blocking
  /// is the kind of thing that generates support tickets -- but only after
  /// being told exactly which seats are empty, so the gap is never silent.
  Future<void> _reviewBallot({required List<Position> missing}) async {
    if (missing.isNotEmpty) {
      final proceed = await showDialog<bool>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: const Text('Some positions are unfilled'),
          content: Text(
            'You have not picked anyone for:\n\n'
            '${missing.map((p) => '  - ${p.label}').join('\n')}\n\n'
            'Those positions will be left empty. Continue to review?',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(false),
              child: const Text('Go Back'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(dialogContext).pop(true),
              child: const Text('Continue'),
            ),
          ],
        ),
      );
      if (proceed != true) return;
    }

    await _saveDraftAndContinue();
  }

  /// Every position in the step inside one continuous sheet, grouped by
  /// position so each tick belongs unambiguously to its seat.
  ///
  /// Shared by both tiers. A provincial step holds a single position, but it
  /// still renders as a sheet rather than a stack of detached cards, so the
  /// whole ballot reads as one continuous form from the national council down
  /// to the last provincial race — the way a paper ballot does.
  Widget _buildBallotSheet({
    required List<Position> tierPositions,
    required String? activeParty,
    required AsyncValue<List<Candidate>> candidatesAsync,
  }) {
    return candidatesAsync.when(
      data: (all) {
        // The year-level representative seat sits in the national tier but is
        // contested by year, so a 1st-year student must not see the 2nd-year
        // nominee. The scope comes from /api/positions, not from each candidate's
        // embedded position, which omits it. Provincial seats are likewise
        // restricted to the voter's own college.
        final byPosition = <String, List<Candidate>>{};
        final votablePositions = <Position>[
          for (final p in tierPositions)
            if (ElectorateScope.voterMayVote(p, _voter)) p,
        ];

        for (final p in votablePositions) {
          final eligible = <Candidate>[];
          for (final c in all.where((c) => c.position.id == p.id)) {
            if (ElectorateScope.candidateIsOnBallot(
              position: p,
              candidate: c,
              voter: _voter,
            )) {
              eligible.add(c);
            }
          }
          byPosition[p.id] = eligible;
        }

        // Every position gets a section even when it has no candidate under the
        // active filter, so the form always reads top-to-bottom and the student
        // can see which seats are unfilled rather than wondering where one went.
        final withContent = votablePositions
            .where((p) => (byPosition[p.id] ?? const <Candidate>[]).isNotEmpty)
            .toList();
        final withoutContent = votablePositions
            .where((p) => (byPosition[p.id] ?? const <Candidate>[]).isEmpty)
            .toList();

        if (withContent.isEmpty) {
          // Two very different empties. If the API returned nominees for these
          // seats and they were all filtered out, the voter simply is not in
          // this electorate — offering "show all parties" there would be
          // useless, since no party would help. Only a genuinely party-shaped
          // gap gets the filter escape hatch.
          final hadNominees = all.any(
            (c) => votablePositions.any((p) => p.id == c.position.id),
          );
          if (hadNominees && !_isWithinElectorate(votablePositions, all)) {
            return _OutOfElectorate(
              position: tierPositions.first,
              voter: _voter,
            );
          }

          return _EmptyForParty(
            position: tierPositions.first,
            party: activeParty,
            isProvincial: _isProvincialStep,
            isGrouped: tierPositions.length > 1,
            onShowAll: activeParty == null
                ? null
                : () => setState(() {
                      if (_isProvincialStep) {
                        _provincialParty = null;
                      } else {
                        _nationalParty = null;
                      }
                    }),
          );
        }

        final filled = withContent
            .where((p) => (_selections[p.slug] ?? const <String>[]).isNotEmpty)
            .length;

        // One continuous surface, the way a paper ballot reads top to bottom:
        // a single bordered sheet with every position inside it, rather than a
        // stack of detached cards that each look like their own mini-screen.
        return ListView(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.md,
            AppSpacing.sm,
            AppSpacing.md,
            AppSpacing.md,
          ),
          children: [
            Container(
              decoration: BoxDecoration(
                color: context.appSurface,
                borderRadius: AppRadius.mdAll,
                border: Border.all(color: context.appBorder),
              ),
              clipBehavior: Clip.antiAlias,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _sheetMasthead(
                    party: activeParty,
                    filled: filled,
                    total: withContent.length,
                  ),
                  for (var i = 0; i < withContent.length; i++) ...[
                    _sheetSectionHeader(withContent[i]),
                    for (var c = 0;
                        c < byPosition[withContent[i].id]!.length;
                        c++)
                      _ballotRow(
                        candidate: byPosition[withContent[i].id]![c],
                        position: withContent[i],
                        currentRefs: _selections[withContent[i].slug] ??
                            const <String>[],
                        isFirstRow: c == 0,
                      ),
                  ],
                  if (withoutContent.isNotEmpty)
                    _sheetEmptyPositions(withoutContent, activeParty),
                ],
              ),
            ),
          ],
        );
      },
      loading: () => ListView(
        padding: AppSpacing.screenPadding,
        children: [
          LoadingSkeleton.row(),
          AppSpacing.vSm,
          LoadingSkeleton.row(),
          AppSpacing.vSm,
          LoadingSkeleton.row(),
        ],
      ),
      error: (err, stack) => ErrorState(
        message: apiErrorMessage(
          err,
          fallback: _isProvincialStep
              ? 'Could not load provincial candidates.'
              : 'Could not load national candidates.',
        ),
        onRetry: () => ref.invalidate(
          voteTierCandidatesProvider((tier: _tier, party: activeParty)),
        ),
      ),
    );
  }

  /// Whether at least one nominee in [all] belongs on this voter's ballot.
  ///
  /// Distinguishes "your party fielded nobody" (a filter problem, fixable with
  /// the party chips) from "your college/year has no nominee" (an electorate
  /// problem the party chips cannot fix).
  bool _isWithinElectorate(List<Position> positions, List<Candidate> all) {
    for (final p in positions) {
      for (final c in all.where((c) => c.position.id == p.id)) {
        if (ElectorateScope.candidateIsOnBallot(
          position: p,
          candidate: c,
          voter: _voter,
        )) {
          return true;
        }
      }
    }

    return false;
  }

  /// Ballot header band: which party this sheet lists and how far along the
  /// voter is, so the sheet is self-describing without the step header above.
  Widget _sheetMasthead({
    required String? party,
    required int filled,
    required int total,
  }) {
    final complete = filled == total;
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Container(
      width: double.infinity,
      color: scheme.primary.withValues(alpha: 0.06),
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppMetrics.rowPaddingV,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                Icons.how_to_vote,
                size: 18,
                color: scheme.primary,
              ),
              AppSpacing.hSm,
              Expanded(
                child: Text(
                  party ?? 'All parties',
                  style: appText.titleSmall.copyWith(
                    color: scheme.primary,
                  ),
                ),
              ),
            ],
          ),
          AppSpacing.vXs,
          Row(
            children: [
              Expanded(
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(AppMetrics.barRadius),
                  child: LinearProgressIndicator(
                    value: total == 0 ? 0 : filled / total,
                    minHeight: AppMetrics.barThickness,
                    backgroundColor: context.appBorder,
                    color: complete ? AppColors.successGreen : scheme.primary,
                  ),
                ),
              ),
              AppSpacing.hSm,
              Text(
                '$filled/$total',
                style: appText.labelSmall.copyWith(
                  fontWeight: FontWeight.bold,
                  color: complete ? AppColors.successGreen : null,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Position band inside the sheet. A tinted strip plus a rule underneath
  /// separates each race the way a ruled ballot form does.
  Widget _sheetSectionHeader(Position position) {
    final picked = (_selections[position.slug] ?? const <String>[]).length;
    final done = picked > 0;
    final appText = AppTextStyles.of(context);

    return Container(
      width: double.infinity,
      color: context.appBackground,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppMetrics.rowPaddingV,
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              position.label,
              style: appText.titleSmall,
            ),
          ),
          if (done)
            const Icon(
              Icons.check_circle,
              size: 16,
              color: AppColors.successGreen,
              semanticLabel: 'Position filled',
            )
          else if (position.seatCount > 1)
            Text(
              '${position.seatCount} seats',
              style: appText.labelSmall.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
        ],
      ),
    );
  }

  /// One candidate line inside the sheet. The tick sits on the left like a
  /// real ballot box; selected rows tint so a half-finished sheet is readable
  /// at a glance while scrolling back up.
  Widget _ballotRow({
    required Candidate candidate,
    required Position position,
    required List<String> currentRefs,
    required bool isFirstRow,
  }) {
    final isSelected = currentRefs.contains(candidate.candidateRef);
    final isMulti = position.seatCount > 1;
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!isFirstRow)
          Divider(height: 1, thickness: 1, color: context.appBorder),
        InkWell(
          onTap: () =>
              _toggleCandidate(candidate.candidateRef, position, isSelected),
          child: Container(
            color: isSelected
                ? scheme.primary.withValues(alpha: 0.08)
                : Colors.transparent,
            padding: const EdgeInsets.symmetric(
              horizontal: AppMetrics.rowPaddingH,
              vertical: AppSpacing.sm,
            ),
            child: Row(
              children: [
                if (isMulti)
                  SizedBox(
                    width: 24,
                    height: 24,
                    child: Checkbox(
                      value: isSelected,
                      activeColor: scheme.primary,
                      materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      onChanged: (_) => _toggleCandidate(
                        candidate.candidateRef,
                        position,
                        isSelected,
                      ),
                    ),
                  )
                else
                  Icon(
                    isSelected
                        ? Icons.radio_button_checked
                        : Icons.radio_button_unchecked,
                    size: 22,
                    color: isSelected ? scheme.primary : context.appBorder,
                  ),
                AppSpacing.hSm,
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        candidate.name,
                        style: appText.titleSmall.copyWith(
                          fontWeight: isSelected
                              ? FontWeight.bold
                              : FontWeight.w600,
                        ),
                      ),
                      if (candidate.party?.trim().isNotEmpty ?? false)
                        Text(
                          candidate.party!.trim(),
                          style: appText.labelSmall.copyWith(
                            color: scheme.primary,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  /// Positions the active party fielded nobody for. Kept inside the sheet so
  /// the race list stays complete top-to-bottom instead of a position silently
  /// disappearing when a party filter is on.
  Widget _sheetEmptyPositions(List<Position> positions, String? party) {
    final appText = AppTextStyles.of(context);
    return Container(
      width: double.infinity,
      color: context.appBackground,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppMetrics.rowPaddingV,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'NO CANDIDATE FROM ${(party ?? 'ANY PARTY').toUpperCase()} FOR',
            style: appText.tag.copyWith(
              color: appText.bodySmall.color,
              letterSpacing: 0.6,
            ),
          ),
          AppSpacing.vXs,
          Wrap(
            spacing: AppSpacing.xs,
            runSpacing: AppSpacing.xs,
            children: [
              for (final position in positions)
                Container(
                  padding: AppMetrics.tagPadding,
                  decoration: BoxDecoration(
                    borderRadius: AppRadius.smAll,
                    border: Border.all(color: context.appBorder),
                  ),
                  child: Text(
                    position.label,
                    style: appText.labelSmall,
                  ),
                ),
              if (party != null)
                InkWell(
                  // Reset the filter for whichever tier this sheet belongs to.
                  // Hardcoding the national party here would silently do
                  // nothing on a provincial step.
                  onTap: () => setState(() {
                    if (_isProvincialStep) {
                      _provincialParty = null;
                    } else {
                      _nationalParty = null;
                    }
                  }),
                  borderRadius: AppRadius.smAll,
                  child: Padding(
                    padding: AppMetrics.tagPadding,
                    child: Text(
                      'Show all parties',
                      style: appText.labelSmall.copyWith(
                        color: Theme.of(context).colorScheme.primary,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }

  /// Party chips for the current tab. "All parties" is first and is the
  /// default, so a student can browse the whole slate before committing to a
  /// view. Purely a view filter: it never restricts what may be selected, which
  /// is why a pick made under ASLE is still there after switching to SVEA.
  Widget _buildPartyFilter(
    AsyncValue<List<String>> partiesAsync,
    String? activeParty,
  ) {
    return partiesAsync.maybeWhen(
      data: (parties) {
        if (parties.isEmpty) return const SizedBox.shrink();
        void setParty(String? value) => setState(() {
              if (_isProvincialStep) {
                _provincialParty = value;
              } else {
                _nationalParty = value;
              }
            });
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Party',
              style: AppTextStyles.of(context).labelSmall.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
            ),
            AppSpacing.vSm,
            AppChipRow(
              items: ['All parties', ...parties],
              selected: activeParty ?? 'All parties',
              onSelect: (label) =>
                  setParty(label == 'All parties' ? null : label),
            ),
          ],
        );
      },
      orElse: () => const SizedBox.shrink(),
    );
  }

  void _toggleCandidate(
    String candidateId,
    Position position,
    bool wasSelected,
  ) {
    setState(() {
      final existing = List<String>.from(
        _selections[position.slug] ?? const [],
      );
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
        SnackBar(
          content: const Text('Could not save your ballot draft. Try again.'),
          backgroundColor: Theme.of(context).colorScheme.error,
        ),
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }
}

/// Shown when the filtered list comes back empty. A provincial race is already
/// pinned to the student's own college, so "no candidate" there usually means
/// that party fielded nobody for this seat in their college rather than that
/// the filter is wrong — worth saying so instead of showing a bare empty list.
class _EmptyForParty extends StatelessWidget {
  final Position position;
  final String? party;
  final bool isProvincial;
  final bool isGrouped;
  final VoidCallback? onShowAll;

  const _EmptyForParty({
    required this.position,
    required this.party,
    required this.isProvincial,
    this.isGrouped = false,
    required this.onShowAll,
  });

  @override
  Widget build(BuildContext context) {
    return EmptyState(
      message: isGrouped
          ? '${party == null ? 'No candidates' : 'No ${party!} candidates'} for any national position'
          : 'No ${party ?? ''} candidate for ${position.label}'
              .replaceAll('  ', ' '),
      subMessage: isProvincial
          ? 'Provincial races are decided within your own college, so this '
              'party may simply have fielded no one here.'
          : null,
      icon: Icons.how_to_vote_outlined,
      actionLabel: onShowAll != null ? 'Show all parties' : null,
      onAction: onShowAll,
    );
  }
}

class _OutOfElectorate extends StatelessWidget {
  final Position position;
  final Student? voter;

  const _OutOfElectorate({required this.position, required this.voter});

  @override
  Widget build(BuildContext context) {
    final reason = switch (position.scopeType) {
      'year_level' =>
        'This seat is decided by year level, and no nominee is filed for your year yet.',
      'department' =>
        'Provincial races are decided within your own college, and no nominee is filed for it yet.',
      'course' => 'No nominee is filed for your course yet.',
      _ => 'You are not part of the electorate for this seat.',
    };

    return EmptyState(
      message: 'You cannot vote in ${position.label}',
      subMessage: reason,
      icon: Icons.lock_outline,
    );
  }
}

class _AlreadyVoted extends StatelessWidget {
  final VoidCallback onViewBallot;

  const _AlreadyVoted({required this.onViewBallot});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.verified,
              size: 72,
              color: Theme.of(context).colorScheme.primary,
            ),
            AppSpacing.vMd,
            Text(
              'You have already cast your ballot',
              textAlign: TextAlign.center,
              style: appText.headlineMedium,
            ),
            AppSpacing.vSm,
            Text(
              'Each voter casts one ballot. Review your submission and receipt from the My Ballot tab.',
              textAlign: TextAlign.center,
              style: appText.bodySmall,
            ),
            AppSpacing.vLg,
            AppButton.primary(
              label: 'View My Ballot',
              icon: Icons.ballot,
              onPressed: onViewBallot,
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
    return EmptyState(
      message: message,
      icon: Icons.schedule_outlined,
      actionLabel: onGoToBallot != null ? 'Review My Ballot' : null,
      onAction: onGoToBallot,
    );
  }
}
