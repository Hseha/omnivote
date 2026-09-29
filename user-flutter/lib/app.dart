import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/routes/app_router.dart';
import 'core/theme/app_theme.dart';
import 'core/theme/brand_accent.dart';
import 'data/services/theme_mode.dart';
import 'features/auth/providers/auth_provider.dart';

// Entrypoint is lib/main.dart; this file only defines the root widget tree.

class OmniVoteApp extends ConsumerStatefulWidget {
  const OmniVoteApp({super.key});

  @override
  ConsumerState<OmniVoteApp> createState() => _OmniVoteAppState();
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
        // Registrar-provisioned accounts must rotate their temporary password
        // before they can reach the dashboard.
        AppRouter.router.go(
          next.mustChangePassword ? '/change-password' : '/dashboard',
        );
      } else if (isNowAuthenticated &&
          !next.mustChangePassword &&
          (previous?.mustChangePassword ?? false)) {
        // Password rotation finished: advance into the app.
        AppRouter.router.go('/dashboard');
      } else if (!isNowAuthenticated && wasAuthenticated) {
        AppRouter.router.go('/login');
      }
    });

    final themeMode = ref.watch(themeModeProvider);
    // The school branding accent flows into ColorScheme.primary (and the
    // button/input roles derived from it) at runtime. With default branding
    // the accent is the fallback blue, so the themes below are identical to
    // the static ones. Only `.select()`s the color, so unrelated branding
    // payload changes don't rebuild the app.
    final accent = ref.brandAccent();

    return MaterialApp.router(
      title: 'OmniVote',
      debugShowCheckedModeBanner: false,
      routerConfig: AppRouter.router,
      theme: AppTheme.withAccent(AppTheme.lightTheme, accent),
      darkTheme: AppTheme.withAccent(AppTheme.darkTheme, accent),
      themeMode: themeMode,
    );
  }
}
