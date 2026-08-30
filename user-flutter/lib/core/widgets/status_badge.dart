import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../features/dashboard/providers/election_status_provider.dart';
import '../../data/models/election_status_model.dart';

/// Displays the current election phase (Registration / Voting Open /
/// Voting Closed) fed from the API. Falls back to "Unknown" while loading or
/// when the status endpoint is unreachable.
class StatusBadge extends ConsumerWidget {
  const StatusBadge({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final statusAsync = ref.watch(electionStatusProvider);

    final String label;
    final Color bg;
    final Color dot;
    final Color text;

    switch (statusAsync.valueOrNull?.phase ?? ElectionPhase.unknown) {
      case ElectionPhase.registration:
        label = 'Registration';
        bg = const Color(0xFFEFF6FF);
        dot = const Color(0xFF2563EB);
        text = const Color(0xFF1D4ED8);
      case ElectionPhase.votingOpen:
        label = 'Voting Open';
        bg = const Color(0xFFDCFCE7);
        dot = const Color(0xFF16A34A);
        text = const Color(0xFF166534);
      case ElectionPhase.votingClosed:
        label = 'Voting Closed';
        bg = const Color(0xFFF3F4F6);
        dot = const Color(0xFF6B7280);
        text = const Color(0xFF4B5563);
      case ElectionPhase.unknown:
        label = 'Unknown';
        bg = const Color(0xFFF3F4F6);
        dot = const Color(0xFF9CA3AF);
        text = const Color(0xFF6B7280);
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(
              color: dot,
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 6),
          Text(
            label,
            style: TextStyle(
              color: text,
              fontSize: 12,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}
