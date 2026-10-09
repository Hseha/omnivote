import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/core/utils/semver.dart';
import 'package:omnivote/data/models/app_release.dart';
import 'package:omnivote/data/repositories/release_repository.dart';
import 'package:omnivote/data/services/release_service.dart';

class _FakeReleaseService extends ReleaseService {
  _FakeReleaseService(this._payload) : super(Dio());

  final List<Map<String, dynamic>> _payload;
  int calls = 0;

  @override
  Future<Response> fetchReleases({int perPage = 10}) async {
    calls++;
    return Response(
      data: _payload,
      statusCode: 200,
      requestOptions: RequestOptions(path: '/releases'),
    );
  }
}

Map<String, dynamic> _release({
  required String tag,
  bool prerelease = false,
}) {
  return {
    'tag_name': tag,
    'name': 'OmniVote $tag',
    'prerelease': prerelease,
    'published_at': '2026-10-09T08:00:00Z',
    'body': '# OmniVote student app\n\n## Added\n- something',
    'html_url': 'https://github.com/Hseha/omnivote/releases/tag/$tag',
  };
}

void main() {
  test('picks the highest semantic version including prereleases', () async {
    final service = _FakeReleaseService([
      _release(tag: 'v1.1.0'),
      _release(tag: 'v1.2.0-test.3', prerelease: true),
      _release(tag: 'v1.2.0'),
    ]);

    final latest = await ReleaseRepository(service).fetchLatestRelease();

    expect(latest?.tagName, 'v1.2.0');
    expect(latest?.version, const SemVer(1, 2, 0));
    expect(latest?.isTestChannel, isFalse);
  });

  test('a prerelease wins when it is the only newer build', () async {
    final service = _FakeReleaseService([
      _release(tag: 'v1.1.0'),
      _release(tag: 'v1.2.0-test.1', prerelease: true),
    ]);

    final latest = await ReleaseRepository(service).fetchLatestRelease();

    expect(latest?.tagName, 'v1.2.0-test.1');
    expect(latest?.version, const SemVer(1, 2, 0));
    expect(latest?.isTestChannel, isTrue);
  });

  test('skips unparseable tags instead of failing', () async {
    final service = _FakeReleaseService([
      _release(tag: 'v1.0.0'),
      {'tag_name': 'not-a-semver', 'name': 'garbage', 'prerelease': false},
    ]);

    final latest = await ReleaseRepository(service).fetchLatestRelease();

    expect(latest?.tagName, 'v1.0.0');
  });

  test('returns null for an empty release list', () async {
    final service = _FakeReleaseService(const []);

    final latest = await ReleaseRepository(service).fetchLatestRelease();

    expect(latest, isNull);
  });

  test('parses the release payload into the model', () async {
    final service = _FakeReleaseService([_release(tag: 'v1.3.0')]);
    final latest = await ReleaseRepository(service).fetchLatestRelease();
    final AppRelease release = latest!;

    expect(release.version, const SemVer(1, 3, 0));
    expect(release.notes, contains('## Added'));
    expect(release.htmlUrl, startsWith('https://github.com/'));
    expect(release.publishedAt, isNotNull);
  });
}