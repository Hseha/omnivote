import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/brand_logo.dart';
import '../../../data/models/branding_model.dart';
import '../../settings/providers/branding_provider.dart';
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
    Future.microtask(_bootstrap);
  }

  Future<void> _bootstrap() async {
    await ref.read(authProvider.notifier).checkAuth();
    if (!mounted) return;
    final auth = ref.read(authProvider);
    if (!auth.isAuthenticated) {
      context.go('/login');
    } else if (auth.mustChangePassword) {
      context.go('/change-password');
    } else {
      context.go('/dashboard');
    }
  }

  @override
  Widget build(BuildContext context) {
    final branding =
        ref.watch(brandingProvider).valueOrNull ?? const Branding();

    return Scaffold(
      backgroundColor: context.appSurface,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            BrandLogo(logoUrl: branding.logoUrl, size: 84, tint: branding.primaryColor),
            const SizedBox(height: 16),
            Text(
              branding.siteName,
              style: TextStyle(
                fontSize: 28,
                fontWeight: FontWeight.bold,
                color: context.appTextPrimary,
              ),
            ),
            const SizedBox(height: 24),
            CircularProgressIndicator(color: branding.primaryColor),
          ],
        ),
      ),
    );
  }
}