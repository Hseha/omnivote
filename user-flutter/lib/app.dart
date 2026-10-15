import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/routes/app_router.dart';
import 'core/theme/app_theme.dart';
import 'core/theme/brand_accent.dart';
import 'data/services/theme_mode.dart';
import 'data/services/theme_pack.dart';
import 'features/auth/providers/auth_provider.dart';
import 'features/ballot/screens/my_ballot_screen.dart';
import 'features/candidacy/providers/candidacy_provider.dart';
import 'features/dashboard/providers/dashboard_provider.dart';
import 'features/voting/providers/voting_provider.dart';

// The app is split across three files rather than the usual single
// `main.dart`:
//
//   lib/main.dart                  - entrypoint; `main()` that Flutter's
//                                    tooling requires, plus the pre-first-frame
//                                    storage reads (API base URL, theme mode).
//   lib/app.dart (this file)       - the root widget tree, `OmniVoteApp`, and
//                                    the Riverpod overrides it is handed.
//   lib/core/routes/app_router.dart - routing table and route-level guards.
//
// `main.dart` is thin on purpose: anything that must be known before the first
// frame is awaited there, while this file stays a plain widget.

class OmniVoteApp extends ConsumerStatefulWidget {
  const OmniVoteApp({super.key});

  @override
  ConsumerState<OmniVoteApp> createState() => _OmniVoteAppState();
}

/// Drops every Riverpod cache that holds account-specific data.
///
/// Device isolation for shared phones: Riverpod's non-autoDispose providers
/// keep their last value for the app's whole lifetime, so without this the
/// next account to sign in would render the previous account's candidacy,
/// ballot, receipt, registration and form state from cache. Election-wide
/// providers (announcements, positions, results, branding, election status)
/// are deliberately left alone — they are the same for every account.
/// Notifications already self-clears on logout (see its provider).
void _resetSessionScopedData(WidgetRef ref) {
  ref.invalidate(myApplicationProvider);
  ref.invalidate(candidacyProvider);
  ref.invalidate(registrationDataProvider);
  ref.invalidate(myBallotProvider);
  ref.invalidate(savedReceiptProvider);
  ref.invalidate(votingProvider);
}

class _OmniVoteAppState extends ConsumerState<OmniVoteApp> {
  @override
  Widget build(BuildContext context) {
    // Listen for auth state changes to handle global navigation.
    // Auth bootstrap happens once, in SplashScreen ('/splash', the app's
    // initial location) — see app_router.dart (audit §2 #11).
    ref.listen<AuthState>(authProvider, (previous, next) {
      final wasAuthenticated = previous?.isAuthenticated ?? false;
      final isNowAuthenticated = next.isAuthenticated;

      if (isNowAuthenticated && !wasAuthenticated) {
        // New session: drop anything cached under a previous account first
        // (defense in depth — the logout path below already clears), then
        // Registrar-provisioned accounts must rotate their temporary password
        // before they can reach the dashboard.
        _resetSessionScopedData(ref);
        AppRouter.router.go(
          next.mustChangePassword ? '/change-password' : '/dashboard',
        );
      } else if (isNowAuthenticated &&
          !next.mustChangePassword &&
          (previous?.mustChangePassword ?? false)) {
        // Password rotation finished: advance into the app.
        AppRouter.router.go('/dashboard');
      } else if (!isNowAuthenticated && wasAuthenticated) {
        // Session ended on this phone: drop every account-scoped cache so
        // the next sign-in can never see the previous account's data.
        _resetSessionScopedData(ref);
        AppRouter.router.go('/login');
      }
    });

    final themeMode = ref.watch(themeModeProvider);
    // The theme pack selects the palette (Classic / Ocean / Sunset); the mode
    // above picks that pack's light or dark variant.
    final pack = ref.watch(themePackProvider);
    // The school branding accent flows into ColorScheme.primary (and the
    // button/input roles derived from it) at runtime, but only when the backend
    // actually configured one — otherwise the active theme pack's own accent is
    // used. Only `.select()`s the color, so unrelated branding payload changes
    // don't rebuild the app.
    final accent = ref.brandAccentOrNull() ?? pack.primary;

    return MaterialApp.router(
      title: 'OmniVote',
      debugShowCheckedModeBanner: false,
      routerConfig: AppRouter.router,
      theme: AppTheme.withAccent(AppTheme.lightFor(pack), accent),
      darkTheme: AppTheme.withAccent(AppTheme.darkFor(pack), accent),
      themeMode: themeMode,
    );
  }
}
