import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/theme/theme_pack.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/section_header.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/branding_model.dart';
import '../../../data/services/theme_mode.dart';
import '../../../data/services/theme_pack.dart';
import '../../app_update/providers/app_update_provider.dart';
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
    final themePack = ref.watch(themePackProvider);
    final branding = ref.watch(
      brandingProvider.select((state) => state.valueOrNull ?? const Branding()),
    );
    // Real installed version from package_info_plus (via the app-update
    // provider), used in the About dialog and the App Updates entry.
    final installedVersion = ref.watch(
      appUpdateProvider.select((state) => state.installed?.toString()),
    );
    final scheme = Theme.of(context).colorScheme;
    final appText = AppTextStyles.of(context);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Settings'),
      body: ListView(
        padding: AppSpacing.screenPadding,
        children: [
          const SectionHeader(title: 'Appearance'),
          AppSpacing.vSm,
          AppCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Theme', style: appText.titleMedium),
                AppSpacing.vXs,
                Text(
                  'Match the system, or force the app to light or dark.',
                  style: appText.bodySmall,
                ),
                AppSpacing.vMd,
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
                    // Deliberately kept as a segmented control (not chips):
                    // it is the right affordance for an exclusive mode choice.
                    // Only the selected color follows the runtime brand accent.
                    selectedBackgroundColor: scheme.primary,
                    selectedForegroundColor: Colors.white,
                    side: BorderSide(color: context.appBorder),
                    shape: RoundedRectangleBorder(
                      borderRadius: AppRadius.smAll,
                    ),
                  ),
                ),
                const Divider(height: 32, color: Colors.transparent),
                Text('Theme pack', style: appText.titleMedium),
                AppSpacing.vXs,
                Text(
                  'Pick a palette for the whole app — it applies to both '
                  'light and dark.',
                  style: appText.bodySmall,
                ),
                AppSpacing.vMd,
                SegmentedButton<ThemePack>(
                  segments: [
                    for (final pack in ThemePack.values)
                      ButtonSegment(
                        value: pack,
                        icon: Icon(Icons.circle, size: 12, color: pack.primary),
                        label: Text(pack.label),
                      ),
                  ],
                  selected: {themePack},
                  onSelectionChanged: (selection) {
                    final pack = selection.first;
                    ref.read(themePackProvider.notifier).state = pack;
                    ThemePackStorage.save(pack);
                  },
                  style: SegmentedButton.styleFrom(
                    selectedBackgroundColor: scheme.primary,
                    selectedForegroundColor: Colors.white,
                    side: BorderSide(color: context.appBorder),
                    shape: RoundedRectangleBorder(
                      borderRadius: AppRadius.smAll,
                    ),
                  ),
                ),
                AppSpacing.vXs,
                Text(
                  themePack.description,
                  style: appText.bodySmall,
                ),
              ],
            ),
          ),
          AppSpacing.vMd,
          AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
              leading: Icon(
                Icons.notifications_outlined,
                color: scheme.primary,
              ),
              title: const Text('Notifications'),
              subtitle: const Text('Election updates, results and account messages'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push('/notifications'),
            ),
          ),
          AppSpacing.vSm,
          AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
              leading: Icon(
                Icons.help_outline,
                color: scheme.primary,
              ),
              title: const Text('Help & FAQ'),
              subtitle: const Text('Eligibility, voting and account help'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push('/faq'),
            ),
          ),
          AppSpacing.vSm,
          AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
              leading: Icon(
                Icons.account_circle_outlined,
                color: scheme.primary,
              ),
              title: const Text('My Profile'),
              subtitle: const Text('Your details, status and log out'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push('/profile'),
            ),
          ),
          AppSpacing.vSm,
          AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
              leading: Icon(
                Icons.system_update_alt,
                color: scheme.primary,
              ),
              title: const Text('App Updates'),
              subtitle: Text(
                installedVersion == null
                    ? 'Check for new versions and what\'s new'
                    : 'Installed v$installedVersion — what\'s new',
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push('/app-update'),
            ),
          ),
          AppSpacing.vSm,
          AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
              leading: Icon(Icons.info_outline, color: branding.primaryColor),
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
                  applicationVersion:
                      installedVersion == null ? '—' : 'v$installedVersion',
                  applicationIcon: Icon(
                    Icons.how_to_vote,
                    size: 40,
                    color: branding.primaryColor,
                  ),
                  children: [
                    AppSpacing.vSm,
                    Text(
                      branding.headerText.isEmpty
                          ? 'Secure digital voting for student government elections.'
                          : branding.headerText,
                    ),
                    if (branding.footerText.isNotEmpty) ...[
                      AppSpacing.vSm,
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
