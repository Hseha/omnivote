import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../providers/auth_provider.dart';

class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _navigateToNext();
  }

  Future<void> _navigateToNext() async {
    // Start auth check while splash is showing
    final authNotifier = ref.read(authProvider.notifier);
    
    // Minimum splash duration for the "Restaurant Door" experience
    final startTime = DateTime.now();
    
    await authNotifier.checkAuth();
    
    final elapsed = DateTime.now().difference(startTime);
    if (elapsed < const Duration(seconds: 2)) {
      await Future.delayed(const Duration(seconds: 2) - elapsed);
    }
    
    if (mounted) {
      final isAuthenticated = ref.read(authProvider).isAuthenticated;
      debugPrint('Auth check complete. isAuthenticated: $isAuthenticated');
      if (isAuthenticated) {
        debugPrint('Navigating to Dashboard');
        context.go('/dashboard');
      } else {
        debugPrint('Navigating to Login');
        context.go('/login');
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: AppColors.primaryBlue,
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              Icons.how_to_vote,
              size: 80,
              color: Colors.white,
            ),
            SizedBox(height: 24),
            Text(
              'OMNIVOTE',
              style: TextStyle(
                fontSize: 32,
                fontWeight: FontWeight.bold,
                color: Colors.white,
                letterSpacing: 4,
              ),
            ),
            SizedBox(height: 8),
            Text(
              'Student Voting Portal',
              style: TextStyle(
                fontSize: 16,
                color: Colors.white70,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
