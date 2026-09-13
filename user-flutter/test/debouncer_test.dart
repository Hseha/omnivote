import 'package:fake_async/fake_async.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/utils/debouncer.dart';

/// Regression test for audit §3 #3: the candidate search used to fire an API
/// request per keystroke; the Debouncer coalesces rapid calls into one.
void main() {
  test('coalesces rapid calls into a single invocation', () {
    fakeAsync((async) {
      final debouncer = Debouncer(delay: const Duration(milliseconds: 400));
      var calls = 0;

      debouncer.run(() => calls++);
      debouncer.run(() => calls++);
      debouncer.run(() => calls++);

      // Nothing fired yet during the quiet window.
      expect(calls, 0);

      async.elapse(const Duration(milliseconds: 350));
      expect(calls, 0);

      async.elapse(const Duration(milliseconds: 50));
      expect(calls, 1);

      debouncer.dispose();
    });
  });

  test('cancel prevents the pending action from running', () {
    fakeAsync((async) {
      final debouncer = Debouncer(delay: const Duration(milliseconds: 400));
      var calls = 0;

      debouncer.run(() => calls++);
      debouncer.cancel();

      async.elapse(const Duration(seconds: 1));
      expect(calls, 0);
    });
  });
}