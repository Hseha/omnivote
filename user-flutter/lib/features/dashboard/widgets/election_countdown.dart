import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_tokens.dart';

/// A live countdown to [target] that re-renders only its own text.
///
/// The 1 s timer lives here, not on the dashboard, so a tick never rebuilds the
/// whole screen — only the digits change. The parent passes a fixed [target];
/// this widget reads no provider, so Riverpod cannot notify it either.
class ElectionCountdown extends ConsumerStatefulWidget {
  const ElectionCountdown({
    super.key,
    required this.target,
    this.label = 'Countdown',
    this.now,
  });

  final DateTime target;
  final String label;

  /// Injectable clock for tests (mirrors `ElectionStatusPoller.clock`); falls
  /// back to the real clock when omitted.
  final DateTime Function()? now;

  @override
  ConsumerState<ElectionCountdown> createState() => _ElectionCountdownState();
}

class _ElectionCountdownState extends ConsumerState<ElectionCountdown> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    // Rebuild only this widget's subtree once a second; nothing else watches it.
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  String _format(Duration d) {
    final days = d.inDays;
    final h = d.inHours.remainder(24);
    final m = d.inMinutes.remainder(60);
    final s = d.inSeconds.remainder(60);
    String two(int v) => v.toString().padLeft(2, '0');
    if (days > 0) return '${days}d ${two(h)}:${two(m)}:${two(s)}';
    return '${two(h)}:${two(m)}:${two(s)}';
  }

  @override
  Widget build(BuildContext context) {
    final remaining = widget.target.difference((widget.now ?? DateTime.now)());
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.schedule, size: 16, color: Theme.of(context).colorScheme.primary),
        const SizedBox(width: 6),
        Text(
          widget.label,
          style: TextStyle(color: context.appTextSecondary, fontSize: 13),
        ),
        const SizedBox(width: 8),
        Text(
          remaining.isNegative ? 'now' : _format(remaining),
          style: TextStyle(
            fontWeight: FontWeight.bold,
            fontSize: 15,
            color: context.appTextPrimary,
          ),
        ),
      ],
    );
  }
}