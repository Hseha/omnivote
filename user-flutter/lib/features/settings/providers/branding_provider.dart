import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/branding_model.dart';
import '../../../data/repositories/branding_repository.dart';

/// Public admin-configured branding, fetched once at startup and cached for
/// the session. Falls back to app defaults when the backend is unreachable,
/// unconfigured, or returns a malformed payload.
final brandingProvider = FutureProvider<Branding>((ref) async {
  return ref.read(brandingRepositoryProvider).getBranding();
});