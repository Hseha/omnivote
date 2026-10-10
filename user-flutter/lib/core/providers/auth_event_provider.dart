import 'package:flutter_riverpod/flutter_riverpod.dart';

/// A provider to signal authentication events like 401 Unauthorized.
/// This helps break circular dependencies between AuthNotifier and ApiClient.
final authEventProvider = StateProvider<AuthEvent?>((ref) => null);

/// Why an auth event fired.
///
/// `vote` means the 401 arrived during a ballot submit: the vote screen
/// keeps the selections held in memory and shows a re-login prompt instead
/// of silently logging the student out.
class AuthEvent {
  final String reason;

  const AuthEvent.unauthorized([this.reason = 'session']);
  const AuthEvent.logout() : reason = 'session';

  bool get isVoteReauth => reason == 'vote';

  @override
  bool operator ==(Object other) =>
      other is AuthEvent && other.reason == reason;

  @override
  int get hashCode => reason.hashCode;
}
