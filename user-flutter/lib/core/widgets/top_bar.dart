import 'package:flutter/foundation.dart';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../features/auth/providers/auth_provider.dart';
import '../constants/app_text_styles.dart';
import '../theme/app_tokens.dart';
import 'cached_avatar.dart';

/// Minimal app bar: just the title/back affordance and the avatar account
/// menu. The live clock and phase badge were removed to keep every screen's
/// top edge clean (phase now lives in the dashboard welcome banner).
class TopBar extends ConsumerWidget implements PreferredSizeWidget {
  final String title;

  const TopBar({
    super.key,
    required this.title,
  });

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  /// Routes each account-menu action. Profile/FAQ/Settings are pushed above
  /// the shell; Candidacy switches to its (pushed) screen; Log Out signs out
  /// and the global auth listener in app.dart redirects to /login.
  void _handleAccountAction(
    BuildContext context,
    WidgetRef ref,
    String value,
  ) {
    switch (value) {
      case 'profile':
        context.push('/profile');
      case 'candidacy':
        context.push('/candidacy');
      case 'faq':
        context.push('/faq');
      case 'settings':
        context.push('/settings');
      // Dev-only: switching the API base URL needs a debug build.
      case 'api-settings':
        if (kDebugMode) context.push('/api-settings');
      case 'logout':
        ref.read(authProvider.notifier).logout();
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final student = ref.watch(authProvider).student;
    final canPop = Navigator.of(context).canPop();
    final appText = AppTextStyles.of(context);

    return AppBar(
      backgroundColor: context.appSurface,
      surfaceTintColor: context.appSurface,
      elevation: 0,
      automaticallyImplyLeading: false,
      leading: canPop
          ? IconButton(
              tooltip: 'Back',
              icon: const Icon(Icons.arrow_back),
              onPressed: () => context.pop(),
            )
          : null,
      title: Text(
        title,
        style: appText.pageTitle.copyWith(fontSize: 18),
      ),
      centerTitle: false,
      actions: [
        if (student != null) ...[
          // The avatar opens the account menu: My Profile, Apply for
          // Candidacy, Help & FAQ, Settings and (debug builds only) the dev
          // API Settings screen, then Log Out.
          PopupMenuButton<String>(
            tooltip: 'Account menu',
            position: PopupMenuPosition.under,
            onSelected: (value) => _handleAccountAction(context, ref, value),
            itemBuilder: (context) => [
              const PopupMenuItem(
                value: 'profile',
                child: _MenuLabel(icon: Icons.account_circle_outlined, label: 'My Profile'),
              ),
              const PopupMenuItem(
                value: 'candidacy',
                child: _MenuLabel(icon: Icons.how_to_reg, label: 'Apply for Candidacy'),
              ),
              const PopupMenuItem(
                value: 'faq',
                child: _MenuLabel(icon: Icons.help_outline, label: 'Help & FAQ'),
              ),
              const PopupMenuItem(
                value: 'settings',
                child: _MenuLabel(icon: Icons.settings_outlined, label: 'Settings'),
              ),
              if (kDebugMode)
                const PopupMenuItem(
                  value: 'api-settings',
                  child: _MenuLabel(icon: Icons.dns_outlined, label: 'API Settings (dev)'),
                ),
              const PopupMenuDivider(),
              const PopupMenuItem(
                value: 'logout',
                child: _MenuLabel(icon: Icons.logout, label: 'Log Out'),
              ),
            ],
            child: CachedAvatar(
              imageUrl: student.avatarUrl,
              radius: 18,
            ),
          ),
          const SizedBox(width: 16),
        ],
      ],
      bottom: PreferredSize(
        preferredSize: const Size.fromHeight(1),
        child: Container(
          color: context.appBorder,
          height: 1,
        ),
      ),
    );
  }
}

class _MenuLabel extends StatelessWidget {
  final IconData icon;
  final String label;

  const _MenuLabel({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, size: 20, color: context.appTextSecondary),
        const SizedBox(width: 12),
        Text(label),
      ],
    );
  }
}