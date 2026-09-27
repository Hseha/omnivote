import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/branding_model.dart';
import '../services/branding_service.dart';

final brandingRepositoryProvider = Provider<BrandingRepository>((ref) {
  return BrandingRepository(ref.read(brandingServiceProvider));
});

class BrandingRepository {
  final BrandingService _service;

  BrandingRepository(this._service);

  /// Never throws: a failed or malformed branding fetch falls back to the
  /// app defaults so pre-login chrome always renders.
  Future<Branding> getBranding({Branding fallback = const Branding()}) async {
    try {
      final response = await _service.get();
      final data = response.data;
      final branding = (data is Map ? data['branding'] : null) as Map?;
      if (branding == null) return fallback;
      return Branding.fromJson(Map<String, dynamic>.from(branding));
    } catch (_) {
      return fallback;
    }
  }
}