import '../../core/utils/semver.dart';

/// A published OmniVote student-app release read from the public GitHub
/// Releases API. `publish-student-app.yml` creates these on demand: stable
/// releases use `vX.Y.Z` tags, device-test prereleases use `vX.Y.Z-test.N`.
class AppRelease {
  final String tagName;
  final SemVer version;
  final String name;
  final bool prerelease;
  final DateTime? publishedAt;
  final String notes;
  final String htmlUrl;

  const AppRelease({
    required this.tagName,
    required this.version,
    required this.name,
    required this.prerelease,
    this.publishedAt,
    required this.notes,
    required this.htmlUrl,
  });

  /// True for the device-test channel (`vX.Y.Z-test.N` tags).
  bool get isTestChannel => tagName.contains('-test.');

  factory AppRelease.fromJson(Map<String, dynamic> json) {
    final tagName = json['tag_name']?.toString() ?? '';
    return AppRelease(
      tagName: tagName,
      version: SemVer.tryParse(tagName) ?? const SemVer(0, 0, 0),
      name: json['name']?.toString() ?? tagName,
      prerelease: json['prerelease'] == true,
      publishedAt: DateTime.tryParse(json['published_at']?.toString() ?? ''),
      notes: json['body']?.toString() ?? '',
      htmlUrl: json['html_url']?.toString() ?? '',
    );
  }
}