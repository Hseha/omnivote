import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/app_card.dart';
import '../../../data/models/election_status_model.dart';
import '../../../data/models/position_model.dart';
import '../../auth/providers/auth_provider.dart';
import '../../ballot/screens/my_ballot_screen.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../../voting/electorate_scope.dart';
import '../providers/election_status_provider.dart';

/// Ballot progress: how many positions the student has filled out of the ones
/// they're eligible to vote in.
///
/// Reads the count of eligible positions from [positionsProvider] (same source
/// the ballot and vote-now screens use) and the current draft from
/// [myBallotProvider] — nothing is fetched a second time for this tile.
class BallotProgressTile extends ConsumerWidget {
  const BallotProgressTile({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final appText = AppTextStyles.of(context);
    final student = ref.watch(authProvider.select((state) => state.student));
    final phase = ref.watch(
      electionStatusProvider.select(
        (state) => state.valueOrNull?.phase,
      ),
    );
    final positionsAsync = ref.watch(positionsProvider);
    final ballotAsync = ref.watch(myBallotProvider);

    final selections = ballotAsync.valueOrNull?['selections'];
    final selectionsMap = selections is Map
        ? Map<String, dynamic>.from(selections)
        : const <String, dynamic>{};

    // A position counts only if this student would actually get to vote in it
    // (electorate scoping mirrors the server, see ElectorateScope).
    final positions =
        positionsAsync.valueOrNull ?? const <Position>[];
    final eligible = student == null
        ? const <Position>[]
        : positions
            .where((p) => ElectorateScope.voterMayVote(p, student))
            .toList();
    final total = eligible.length;

    // A draft entry is "filled" when the position's selection list is non-empty.
    int filled = 0;
    if (student != null) {
      for (final p in eligible) {
        for (final key in [p.id, p.slug]) {
          if (key.isEmpty) continue;
          final value = selectionsMap[key];
          if (value is List && value.isNotEmpty) {
            filled++;
            break;
          }
        }
      }
    }

    final progress = total == 0 ? 0.0 : filled / total;

    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text('Ballot draft', style: appText.titleMedium),
              const Spacer(),
              if (phase == ElectionPhase.votingClosed)
                Text(
                  'Voting closed',
                  style: appText.bodySmall,
                )
              else
                Text(
                  '$filled of $total positions',
                  style: appText.labelLarge,
                ),
            ],
          ),
          AppSpacing.vSm,
          ClipRRect(
            borderRadius: AppRadius.smAll,
            child: LinearProgressIndicator(
              value: progress,
              minHeight: AppMetrics.barThickness,
              backgroundColor: context.appBorder,
            ),
          ),
          AppSpacing.vSm,
          Semantics(
            label: 'Ballot progress, $filled of $total positions filled',
            child: Text(
              total == 0
                  ? 'Loading positions…'
                  : filled == total
                      ? 'Every position filled — review in My Ballot.'
                      : 'Tap Vote Now to keep building your ballot.',
              style: appText.labelSmall,
            ),
          ),
        ],
      ),
    );
  }
}