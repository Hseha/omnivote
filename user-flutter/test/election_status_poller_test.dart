import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/models/election_status_model.dart';
import 'package:omnivote/features/dashboard/providers/election_status_poller.dart';

/// The poller owns the policy behind the 30 s phase poll: in-flight de-dupe,
/// capped exponential backoff after failures, and an epoch (manual refresh)
/// that always bypasses the backoff. Everything is driven by an injected clock
/// so the timing assertions are deterministic rather than sleep-based.
void main() {
  late DateTime now;

  ElectionStatusPoller poller({
    Duration base = const Duration(seconds: 30),
    Duration max = const Duration(minutes: 2),
  }) =>
      ElectionStatusPoller(
        clock: () => now,
        baseBackoff: base,
        maxBackoff: max,
      );

  Future<ElectionStatus?> succeed() async => const ElectionStatus(
        phase: ElectionPhase.votingOpen,
        phaseLabel: 'Voting Open',
      );

  Future<ElectionStatus?> fail() async => null;

  setUp(() {
    now = DateTime(2026, 9, 28, 8, 0, 0);
  });

  group('success path', () {
    test('returns the parsed status and remembers it as the cached phase',
        () async {
      final subject = poller();

      final status = await subject.poll(epoch: 0, fetch: succeed);

      expect(status.phase, ElectionPhase.votingOpen);
      expect(subject.consecutiveFailures, 0);
      expect(identical(subject.cachedStatus, status), isTrue);
    });
  });

  group('failure backoff', () {
    test('a failed poll yields the last known phase (unknown when there is none)',
        () async {
      final subject = poller();

      final status = await subject.poll(epoch: 0, fetch: fail);

      expect(status.phase, ElectionPhase.unknown);
      expect(subject.consecutiveFailures, 1);
    });

    test('skips the next attempts until the window elapses', () async {
      final subject = poller();
      await subject.poll(epoch: 0, fetch: fail);

      // Immediately after a failure the API is not called again.
      expect(subject.shouldSkip(epoch: 0), isTrue);

      now = now.add(const Duration(seconds: 29));
      expect(subject.shouldSkip(epoch: 0), isTrue,
          reason: 'one poll cycle must be skipped for a single blip');

      now = now.add(const Duration(seconds: 1));
      expect(subject.shouldSkip(epoch: 0), isFalse);
    });

    test('doubles per consecutive failure and stops at maxBackoff', () async {
      final subject = poller();

      final expected = <Duration>[
        const Duration(seconds: 30),
        const Duration(seconds: 60),
        const Duration(minutes: 2),
        const Duration(minutes: 2), // would be 120 s * 2 without the cap
      ];

      for (final delay in expected) {
        now = now.add(delay); // wait out the current window
        await subject.poll(epoch: 0, fetch: fail);
        // Window that was just armed: waits exactly `delay` before retrying.
        expect(subject.shouldSkip(epoch: 0), isTrue);
        now = now.add(delay).subtract(const Duration(milliseconds: 1));
        expect(subject.shouldSkip(epoch: 0), isTrue);
        now = now.add(const Duration(milliseconds: 1));
        expect(subject.shouldSkip(epoch: 0), isFalse);
      }

      expect(subject.consecutiveFailures, 4);
    });

    test('a success clears the failure history', () async {
      final subject = poller();
      await subject.poll(epoch: 0, fetch: fail);
      expect(subject.shouldSkip(epoch: 0), isTrue);

      now = now.add(const Duration(seconds: 30));
      final status = await subject.poll(epoch: 0, fetch: succeed);

      expect(subject.consecutiveFailures, 0);
      expect(subject.shouldSkip(epoch: 0), isFalse);
      expect(identical(subject.cachedStatus, status), isTrue);
    });

    test('a manual refresh (epoch bump) bypasses and clears the backoff',
        () async {
      final subject = poller();
      await subject.poll(epoch: 0, fetch: fail);
      expect(subject.shouldSkip(epoch: 0), isTrue);

      // Pull-to-refresh: the student's own action is never delayed.
      expect(subject.shouldSkip(epoch: 1), isFalse);
      expect(subject.consecutiveFailures, 0);

      final status = await subject.poll(epoch: 1, fetch: succeed);
      expect(status.phase, ElectionPhase.votingOpen);
    });
  });

  group('in-flight de-dupe', () {
    test('concurrent callers share one request', () async {
      final subject = poller();
      var calls = 0;
      final gate = Completer<ElectionStatus?>();

      Future<ElectionStatus?> fetch() {
        calls++;
        return gate.future;
      }

      final first = subject.poll(epoch: 0, fetch: fetch);
      final second = subject.poll(epoch: 0, fetch: fetch);

      expect(calls, 1,
          reason: 'a tick racing a refresh must not double the request');

      gate.complete(const ElectionStatus(phase: ElectionPhase.votingClosed));
      final results = await Future.wait([first, second]);
      expect(calls, 1);
      expect(results.first.phase, ElectionPhase.votingClosed);
      expect(identical(results.first, results.last), isTrue);

      // Once settled, the next poll is a fresh request.
      await subject.poll(epoch: 0, fetch: fetch);
      expect(calls, 2);
    });
  });
}
