import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Immutable snapshot of the app-wide in-flight request count.
class LoadingState {
  final int calls;
  final bool stale;

  const LoadingState({this.calls = 0, this.stale = false});

  bool get isLoading => calls > 0;
}

/// Central loading accounting for the whole student app.
///
/// Every repository method calls [begin]/[end] around its network call, so
/// a single full-screen overlay can show a spinner for *every* request
/// instead of each screen inventing its own. A hang timer flips the state
/// to `stale` after [hangTimeoutSeconds] with no progress: the spinner is
/// replaced by a 'still waiting' message with a dismissal, so a student
/// never sits on a blank white screen and the spinner always ends.
class LoadingController extends StateNotifier<LoadingState> {
  Timer? _hangTimer;

  static const int hangTimeoutSeconds = 60;

  LoadingController() : super(const LoadingState());

  /// Call once for every network request that is about to start.
  void begin([String label = 'request']) {
    if (state.calls == 0) {
      _hangTimer?.cancel();
      _hangTimer = Timer(
        const Duration(seconds: hangTimeoutSeconds),
        () => state = LoadingState(calls: state.calls, stale: true),
      );
    }
    state = LoadingState(calls: state.calls + 1, stale: state.stale);
  }

  /// Call once for every request that has completed.
  void end([String label = 'request']) {
    final next = state.calls - 1;
    if (next <= 0) {
      _hangTimer?.cancel();
      state = const LoadingState();
    } else {
      state = LoadingState(calls: next, stale: state.stale);
    }
  }

  /// Marks the current in-flight request as stuck so the overlay stops the
  /// spinner and shows a retry action instead of a blank white screen.
  void markStale() {
    if (state.calls > 0) {
      state = LoadingState(calls: state.calls, stale: true);
    }
  }

  /// Dismisses the overlay immediately (the in-flight request keeps
  /// running; when it completes the overlay is already idle).
  void forceIdle() {
    _hangTimer?.cancel();
    state = const LoadingState();
  }
}

final StateNotifierProvider<LoadingController, LoadingState>
    loadingServiceProvider =
    StateNotifierProvider<LoadingController, LoadingState>(
  (ref) => LoadingController(),
);
