import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/models/registration_model.dart';
import 'package:omnivote/data/repositories/registration_repository.dart';
import 'package:omnivote/features/dashboard/providers/dashboard_provider.dart';

/// Regression test for the audit 2026-09-13 blocker: the dashboard provider
/// used `Registration` without importing `registration_model.dart`, which made
/// the whole dashboard uncanny-compilable. This test compiles the provider and
/// asserts it resolves the repository payload end-to-end.
class _FakeRegistrationRepository implements RegistrationRepository {
  @override
  Future<Registration> getMyRegistration() async {
    // Mirrors the snake_case /api/registration/me payload, including numeric
    // strings for the turnout counts produced by the cast aggregation.
    return Registration.fromJson({
      'registration_date': '2026-09-01T00:00:00.000000Z',
      'eligibility_status': 'eligible',
      'turnout': {
        'registered_students': '120',
        'total_students': '150',
        'actual_ballots_cast': '95',
      },
    });
  }
}

void main() {
  test('registrationDataProvider resolves the registration type', () async {
    final container = ProviderContainer(
      overrides: [
        registrationRepositoryProvider
            .overrideWithValue(_FakeRegistrationRepository()),
      ],
    );
    addTearDown(container.dispose);

    final registration = await container.read(registrationDataProvider.future);

    expect(registration.eligibilityStatus, 'eligible');
    expect(registration.turnout.registeredStudents, 120);
    expect(registration.turnout.totalStudents, 150);
    expect(registration.turnout.actualBallotsCast, 95);
  });
}