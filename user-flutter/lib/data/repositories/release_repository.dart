import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/utils/semver.dart';
import '../models/app_release.dart';
import '../services/release_service.dart';

final releaseRepositoryProvider = Provider<ReleaseRepository>((ref) {
  return ReleaseRepository(ref.read(releaseServiceProvider));
});

class ReleaseRepository {
  final ReleaseService _releaseService;

  ReleaseRepository(this._releaseService);

  /// The newest published release — stable or device-test prerelease, per the
  /// product decision to surface both. Picks the highest X.Y.Z; drafts are
  /// never returned by the public API, and unparseable tags are skipped.
  Future<AppRelease?> fetchLatestRelease() async {
    final response = await _releaseService.fetchReleases();
    final data = response.data;
    final releases = data is List
        ? data
            .whereType<Map>()
            .map((r) => AppRelease.fromJson(Map<String, dynamic>.from(r)))
        : const <AppRelease>[];

    const unparseable = SemVer(0, 0, 0);
    AppRelease? best;
    for (final release in releases) {
      if (release.version == unparseable) continue;
      final sameVersionAsBest =
          best != null && release.version.compareTo(best.version) == 0;
      // On an X.Y.Z tie prefer a stable build over a device-test prerelease
      // (e.g. both v1.2.0 and v1.2.0-test.3 exist): students should be pointed
      // at the real release, not a test build of the same version.
      final preferStableOnTie = sameVersionAsBest &&
          !release.isTestChannel &&
          best!.isTestChannel;
      if (best == null ||
          release.version.compareTo(best.version) > 0 ||
          preferStableOnTie) {
        best = release;
      }
    }
    return best;
  }
}