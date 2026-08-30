import 'package:flutter_riverpod/flutter_riverpod.dart';

/// A provider to signal authentication events like 401 Unauthorized.
/// This helps break circular dependencies between AuthNotifier and ApiClient.
final authEventProvider = StateProvider<AuthEvent?>((ref) => null);

enum AuthEvent {
  unauthorized,
  logout,
}
