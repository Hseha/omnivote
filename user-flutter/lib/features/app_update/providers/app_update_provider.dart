import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../../core/utils/semver.dart';
import '../../../data/models/app_release.dart';
import '../../../data/repositories/release_repository.dart';
import 'app_update_storage.dart';

/// How often a background release check may hit the GitHub API. GitHub's
/// unauthenticated rate limit is per-IP and school networks often share one
/// public IP, so the check is deliberately not per-launch.
const Duration kAppUpdateCheckCooldown = Duration(minutes: 30);

class AppUpdateState {
  /// Installed version (from `package_info_plus`), or null when it could not
  /// be read (e.g. a pure unit test with no platform channels) — in which case
  /// no update is ever surfaced.
  final SemVer? installed;

  /// Newest published release found, if one was fetched.
  final AppRelease? latest;

  final bool checking;

  /// True when [latest] is newer than [installed].
  final bool available;

  /// Banner hidden for this session.
  final bool dismissed;

  /// Version that should prompt the one-time "update available" dialog, or
  /// null once shown (or when the student is already up to date).
  final String? dialogVersion;

  const AppUpdateState({
    this.installed,
    this.latest,
    this.checking = false,
    this.available = false,
    this.dismissed = false,
    this.dialogVersion,
  });

  AppUpdateState copyWith({
    Object? installed = _unset,
    Object? latest = _unset,
    bool? checking,
    bool? available,
    bool? dismissed,
    Object? dialogVersion = _unset,
  }) {
    return AppUpdateState(
      installed: identical(installed, _unset) ? this.installed : installed as SemVer?,
      latest: identical(latest, _unset) ? this.latest : latest as AppRelease?,
      checking: checking ?? this.checking,
      available: available ?? this.available,
      dismissed: dismissed ?? this.dismissed,
      dialogVersion: identical(dialogVersion, _unset)
          ? this.dialogVersion
          : dialogVersion as String?,
    );
  }
}

const Object _unset = Object();

class AppUpdateNotifier extends StateNotifier<AppUpdateState> {
  final ReleaseRepository _repository;
  DateTime? _lastChecked;

  AppUpdateNotifier(this._repository) : super(const AppUpdateState());

  /// Reads the installed version once. Runs before the first check so
  /// [AppUpdateState.available] has a baseline to compare against.
  Future<void> init() async {
    SemVer? installed;
    try {
      final info = await PackageInfo.fromPlatform();
      installed = SemVer.tryParse('${info.version}+${info.buildNumber}') ??
          SemVer.tryParse(info.version);
    } catch (_) {
      installed = null;
    }
    state = state.copyWith(installed: installed);
  }

  /// Fetches the newest release and recomputes availability. Background calls
  /// are gated by a cooldown; [force] (the "Check for updates" button) bypasses
  /// it. Any failure (offline, GitHub unreachable, rate-limited) is silent.
  Future<void> check({bool force = false}) async {
    if (state.checking) return;

    final now = DateTime.now();
    final last = _lastChecked;
    if (!force && last != null && now.difference(last) < kAppUpdateCheckCooldown) {
      return;
    }
    _lastChecked = now;

    state = state.copyWith(checking: true);
    try {
      final latest = await _repository.fetchLatestRelease();
      state = state.copyWith(checking: false, latest: latest);
      await _settle();
    } catch (_) {
      state = state.copyWith(checking: false);
    }
  }

  Future<void> _settle() async {
    final latest = state.latest;
    final installed = state.installed;
    final available = latest != null &&
        installed != null &&
        latest.version.compareTo(installed) > 0;

    if (!available) {
      state = state.copyWith(available: false);
      return;
    }

    // Dialog once per version: only arm it when this version was never nudged.
    var dialogVersion = state.dialogVersion;
    if (dialogVersion == null) {
      final seen = await AppUpdateStorage.loadSeenVersion();
      if (seen != latest.version.toString()) {
        dialogVersion = latest.version.toString();
      }
    }
    state = state.copyWith(available: true, dialogVersion: dialogVersion);
  }

  /// Hides the banner for the rest of this session.
  void dismissBanner() => state = state.copyWith(dismissed: true);

  /// Records that the dialog was shown for the pending version and disarms it.
  Future<void> markDialogShown() async {
    final version = state.dialogVersion;
    if (version != null) {
      await AppUpdateStorage.saveSeenVersion(version);
    }
    state = state.copyWith(dialogVersion: null);
  }
}

final appUpdateProvider =
    StateNotifierProvider<AppUpdateNotifier, AppUpdateState>((ref) {
  final notifier = AppUpdateNotifier(ref.read(releaseRepositoryProvider));
  notifier.init().then((_) => notifier.check());
  return notifier;
});