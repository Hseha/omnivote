import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/branding_model.dart';
import '../../../data/services/theme_mode.dart';
import '../providers/branding_provider.dart';

/// User-facing Settings (reachable from the avatar menu).
///
/// Holds the app's appearance preference (Light / Dark / System theme). The
/// choice is persisted via [ThemeModeStorage] and seeded at launch in
/// `main()`, so it survives restarts.
class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final themeMode = ref.watch(themeModeProvider);
    final branding =
        ref.watch(brandingProvider).valueOrNull ?? const Branding();

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Settings'),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            'Appearance',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 12),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Theme',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Match the system, or force the app to light or dark.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: 16),
                  SegmentedButton<ThemeMode>(
                    segments: const [
                      ButtonSegment(
                        value: ThemeMode.light,
                        icon: Icon(Icons.light_mode_outlined),
                        label: Text('Light'),
                      ),
                      ButtonSegment(
                        value: ThemeMode.system,
                        icon: Icon(Icons.brightness_auto_outlined),
                        label: Text('System'),
                      ),
                      ButtonSegment(
                        value: ThemeMode.dark,
                        icon: Icon(Icons.dark_mode_outlined),
                        label: Text('Dark'),
                      ),
                    ],
                    selected: {themeMode},
                    onSelectionChanged: (selection) {
                      final mode = selection.first;
                      ref.read(themeModeProvider.notifier).state = mode;
                      ThemeModeStorage.save(mode);
                    },
                    style: SegmentedButton.styleFrom(
                      selectedBackgroundColor: AppColors.primaryBlue,
                      selectedForegroundColor: Colors.white,
                      side: BorderSide(color: context.appBorder),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Card(
            child: ListTile(
              leading: const Icon(
                Icons.help_outline,
                color: AppColors.primaryBlue,
              ),
              title: const Text('Help & FAQ'),
              subtitle: const Text('Eligibility, voting and account help'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push('/faq'),
            ),
          ),
          const SizedBox(height: 12),
          Card(
            child: ListTile(
              leading: const Icon(
                Icons.account_circle_outlined,
                color: AppColors.primaryBlue,
              ),
              title: const Text('My Profile'),
              subtitle: const Text('Your details, status and log out'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push('/profile'),
            ),
          ),
          const SizedBox(height: 12),
          Card(
            child: ListTile(
              leading: Icon(
                Icons.info_outline,
                color: branding.primaryColor,
              ),
              title: const Text('About'),
              subtitle: Text(
                branding.siteName.isEmpty
                    ? 'OmniVote'
                    : '${branding.siteName} — student portal for school elections',
              ),
              onTap: () {
                showAboutDialog(
                  context: context,
                  applicationName: branding.siteName,
                  applicationVersion: '1.0.0',
                  applicationIcon: Icon(
                    Icons.how_to_vote,
                    size: 40,
                    color: branding.primaryColor,
                  ),
                  children: [
                    const SizedBox(height: 8),
                    Text(
                      branding.headerText.isEmpty
                          ? 'Secure digital voting for student government elections.'
                          : branding.headerText,
                    ),
                    if (branding.footerText.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Text(
                        branding.footerText,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}