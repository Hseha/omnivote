import 'dart:async';

/// Coalesces rapidly-fired actions (e.g. search keystrokes) into a single
/// invocation after [delay] of quiet. Cancellable via [cancel] and dispose.
///
/// Extracted so the debounce behaviour is unit-testable (audit §3 #3).
class Debouncer {
  final Duration delay;
  Timer? _timer;

  Debouncer({required this.delay});

  /// Schedules [action], replacing any pending scheduled invocation.
  void run(void Function() action) {
    _timer?.cancel();
    _timer = Timer(delay, action);
  }

  /// Cancels any pending invocation.
  void cancel() {
    _timer?.cancel();
    _timer = null;
  }

  /// Cancels pending work; call from State.dispose.
  void dispose() => cancel();
}