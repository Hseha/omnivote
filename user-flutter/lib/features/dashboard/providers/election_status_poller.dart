import 'dart:async';

import '../../../data/models/election_status_model.dart';

/// Coordinates the periodic `GET /election/status` poll.
///
/// Three things used to happen on every 30 s tick, all of which cost battery,
/// bandwidth and rebuilds on the mid/low-end devices this app targets:
///
///  * **In-flight dedupe** — a tick and a pull-to-refresh (epoch bump) could put
///    two identical requests on the wire at once, and a slow response could
///    outlive its own interval. While a request is in flight every caller now
///    shares its future instead of starting another one.
///  * **Error backoff** — a school Wi-Fi outage used to keep hammering a dead
///    endpoint every 30 s, per open app. Consecutive failures now back off
///    30 s → 60 s → 120 s (capped at [maxBackoff]). A success, or a manual
///    refresh, clears the history immediately — a student's own "Retry" is
///    never delayed.
///  * **Skip work when nothing changed** — while backed off, a tick re-returns
///    the *same* [ElectionStatus] instance. Riverpod compares the resolved value,
///    so no listener is notified and no screen rebuilds.
///
/// The class is deliberately free of Flutter/Riverpod imports so the policy can
/// be unit-tested with a fake clock (see `test/election_status_poller_test.dart`).
class ElectionStatusPoller {
  ElectionStatusPoller({
    DateTime Function()? clock,
    this.baseBackoff = const Duration(seconds: 30),
    this.maxBackoff = const Duration(minutes: 2),
  }) : _clock = clock ?? DateTime.now;

  final DateTime Function() _clock;

  /// Delay before the first retry after a failure. Matches the poll interval,
  /// so a single blip costs exactly one skipped cycle.
  final Duration baseBackoff;

  /// Ceiling for the exponential retry delay, so a long outage is still noticed
  /// — and recovered from — within a couple of minutes of the network returning.
  final Duration maxBackoff;

  int _consecutiveFailures = 0;
  DateTime? _retryNotBefore;
  int? _lastEpoch;
  ElectionStatus? _lastStatus;
  Future<ElectionStatus>? _inFlight;

  /// Failures since the last success. Exposed for tests and diagnostics.
  int get consecutiveFailures => _consecutiveFailures;

  /// The phase to keep rendering while a poll is skipped: the last one that was
  /// successfully parsed, or `unknown` (which disables vote actions) when
  /// nothing has ever loaded.
  ElectionStatus get cachedStatus =>
      _lastStatus ?? const ElectionStatus(phase: ElectionPhase.unknown);

  /// Whether this evaluation should reuse [cachedStatus] instead of calling the
  /// API.
  ///
  /// [epoch] is the manual-refresh counter: a change means the operator (or the
  /// student's pull-to-refresh) asked for a fresh read, which always clears the
  /// backoff so the request goes out.
  bool shouldSkip({required int epoch}) {
    if (_consumeEpoch(epoch)) return false;
    final notBefore = _retryNotBefore;
    if (notBefore == null) return false;
    return _clock().isBefore(notBefore);
  }

  /// Runs [fetch] unless the request would be skipped or is already running.
  ///
  /// [fetch] returns `null` when the status could not be read (network failure
  /// or malformed payload), which is what feeds the backoff.
  Future<ElectionStatus> poll({
    required int epoch,
    required Future<ElectionStatus?> Function() fetch,
  }) {
    if (shouldSkip(epoch: epoch)) {
      return Future<ElectionStatus>.value(cachedStatus);
    }
    final running = _inFlight;
    if (running != null) return running;
    final future = _run(fetch);
    _inFlight = future;
    return future;
  }

  /// Forgets the failure history (used when the API base URL changes, so a new
  /// backend is not judged by the old one's failures).
  void reset() {
    _consecutiveFailures = 0;
    _retryNotBefore = null;
    _lastStatus = null;
    _lastEpoch = null;
  }

  /// Returns true when [epoch] differs from the last one seen, i.e. this is an
  /// explicit refresh that should bypass any backoff.
  bool _consumeEpoch(int epoch) {
    if (_lastEpoch == epoch) return false;
    _lastEpoch = epoch;
    _consecutiveFailures = 0;
    _retryNotBefore = null;
    return true;
  }

  Future<ElectionStatus> _run(Future<ElectionStatus?> Function() fetch) async {
    try {
      final status = await fetch();
      if (status == null) return _recordFailure();
      // A clean read ends the outage: the next failure starts the backoff over.
      _consecutiveFailures = 0;
      _retryNotBefore = null;
      _lastStatus = status;
      return status;
    } catch (_) {
      return _recordFailure();
    } finally {
      _inFlight = null;
    }
  }

  ElectionStatus _recordFailure() {
    _consecutiveFailures++;
    var delay = baseBackoff * (1 << (_consecutiveFailures - 1));
    if (delay > maxBackoff) delay = maxBackoff;
    _retryNotBefore = _clock().add(delay);
    return cachedStatus;
  }
}
