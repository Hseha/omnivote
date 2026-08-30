import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/routes/app_router.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/providers/auth_provider.dart';

// Entrypoint is lib/main.dart; this file only defines the root widget tree.

class OmniVoteApp extends ConsumerStatefulWidget {
  const OmniVoteApp({super.key});

  @override
  ConsumerState<OmniVoteApp> createState() => _OmniVoteAppState();
}

class _OmniVoteAppState extends ConsumerState<OmniVoteApp> {
  @override
  void initState() {
    super.initState();
    // Check authentication status on app launch
    Future.microtask(() => ref.read(authProvider.notifier).checkAuth());
  }

  @override
  Widget build(BuildContext context) {
    // Listen for auth state changes to handle global navigation
    ref.listen<AuthState>(authProvider, (previous, next) {
      final wasAuthenticated = previous?.isAuthenticated ?? false;
      final isNowAuthenticated = next.isAuthenticated;

      if (isNowAuthenticated && !wasAuthenticated) {
        AppRouter.router.go('/dashboard');
      } else if (!isNowAuthenticated && wasAuthenticated) {
        AppRouter.router.go('/login');
      }
    });

    return MaterialApp.router(
      title: 'OmniVote',
      debugShowCheckedModeBanner: false,
      routerConfig: AppRouter.router,
      theme: AppTheme.lightTheme,
    );
  }
}
