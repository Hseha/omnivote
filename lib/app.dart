import 'package:flutter/material.dart';
import 'core/routes/app_router.dart';
import 'core/theme/app_theme.dart';

class OmniVoteApp extends StatelessWidget {
  const OmniVoteApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'OmniVote',
      debugShowCheckedModeBanner: false,
      routerConfig: AppRouter.router,
      // For now, using default theme. We can implement AppTheme later.
      theme: ThemeData(
        useMaterial3: true,
        primaryColor: const Color(0xFF2F5EFF),
      ),
    );
  }
}
