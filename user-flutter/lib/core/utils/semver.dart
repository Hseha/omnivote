/// Three-part app version used to decide whether a newer release exists.
///
/// Release tags are `vX.Y.Z` (stable) or `vX.Y.Z-test.N` (device-test
/// prereleases) and the installed version from `pubspec.yaml` is `X.Y.Z+N`, so
/// only the major.minor.patch triplet is compared — the `-test.N` channel
/// suffix and the `+N` build number never affect the answer.
class SemVer implements Comparable<SemVer> {
  final int major;
  final int minor;
  final int patch;

  const SemVer(this.major, this.minor, this.patch);

  /// Parses `v1.2.0`, `v1.2.0-test.3` or `1.1.0+2`, ignoring the channel and
  /// build suffixes. Returns null for anything without a clean X.Y.Z core.
  static SemVer? tryParse(String? raw) {
    if (raw == null) return null;
    var value = raw.trim();
    if (value.startsWith('v')) value = value.substring(1);
    final core = value.split('-').first.split('+').first;
    final parts = core.split('.');
    if (parts.length != 3) return null;
    final major = int.tryParse(parts[0]);
    final minor = int.tryParse(parts[1]);
    final patch = int.tryParse(parts[2]);
    if (major == null || minor == null || patch == null) return null;
    return SemVer(major, minor, patch);
  }

  @override
  int compareTo(SemVer other) {
    if (major != other.major) return major.compareTo(other.major);
    if (minor != other.minor) return minor.compareTo(other.minor);
    return patch.compareTo(other.patch);
  }

  @override
  String toString() => '$major.$minor.$patch';

  @override
  bool operator ==(Object other) =>
      other is SemVer &&
      other.major == major &&
      other.minor == minor &&
      other.patch == patch;

  @override
  int get hashCode => Object.hash(major, minor, patch);
}