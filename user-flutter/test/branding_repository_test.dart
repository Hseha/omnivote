import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/models/branding_model.dart';
import 'package:omnivote/data/models/candidate_model.dart';
import 'package:omnivote/data/repositories/branding_repository.dart';
import 'package:omnivote/data/services/branding_service.dart';

void main() {
  group('Branding', () {
    test('parses the branding envelope with a hex primary color', () {
      final branding = Branding.fromJson({
        'siteName': 'ACME High',
        'logoUrl': 'https://example.edu/logo.png',
        'primaryColor': '#FF8800',
        'secondaryColor': '#111111',
        'headerText': 'Vote ACME',
        'footerText': 'Powered by ACME',
      });

      expect(branding.siteName, 'ACME High');
      expect(branding.logoUrl, 'https://example.edu/logo.png');
      expect(branding.primaryColor.toARGB32(), 0xFFFF8800);
      expect(branding.headerText, 'Vote ACME');
      expect(branding.footerText, 'Powered by ACME');
    });

    test('falls back to defaults on a malformed or empty payload', () {
      final branding = Branding.fromJson({'siteName': '', 'primaryColor': 'nope'});

      expect(branding.siteName, 'OmniVote');
      expect(branding.primaryColor.toARGB32(), 0xFF2F5EFF);
    });
  });

  group('BrandingRepository', () {
    test('returns the parsed branding from the service', () async {
      final repository = BrandingRepository(
        _FakeBrandingService(Response(
          requestOptions: RequestOptions(path: '/branding'),
          data: {
            'branding': {
              'siteName': 'ACME High',
              'primaryColor': '#2563eb',
            },
          },
          statusCode: 200,
        )),
      );

      final branding = await repository.getBranding();

      expect(branding.siteName, 'ACME High');
      expect(branding.primaryColor.toARGB32(), 0xFF2563EB);
    });

    test('falls back to defaults when the request throws', () async {
      final repository = BrandingRepository(
        _FakeBrandingService(
          Response(
            requestOptions: RequestOptions(path: '/branding'),
            statusCode: 500,
          ),
          throwOnGet: true,
        ),
      );

      final branding = await repository.getBranding();

      expect(branding.siteName, 'OmniVote');
    });
  });

  group('Candidate grade_level parsing', () {
    test('picks up the backend grade_level key', () {
      final candidate = Candidate.fromJson({
        'id': 7,
        'candidate_ref': 'ref-7',
        'name': 'Ana',
        'position': {'id': 1, 'label': 'President'},
        'grade_level': '11',
        'slogan': 'Fix',
        'platform_points': [],
      });

      expect(candidate.gradeLine, '11');
    });
  });
}

class _FakeBrandingService extends BrandingService {
  final Response _response;
  final bool throwOnGet;

  _FakeBrandingService(this._response, {this.throwOnGet = false})
      : super(Dio());

  @override
  Future<Response> get() async {
    if (throwOnGet) throw DioException(requestOptions: RequestOptions(path: '/branding'));
    return _response;
  }
}