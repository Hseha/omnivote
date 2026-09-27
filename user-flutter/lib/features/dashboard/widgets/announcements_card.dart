import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../providers/announcements_provider.dart';

/// Every published announcement, newest first. Lives on the dashboard so
/// students see school posts and the auto-generated results announcement
/// without digging into Results.
class AnnouncementsCard extends ConsumerWidget {
  const AnnouncementsCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final announcementsAsync = ref.watch(announcementsProvider);
    final appText = AppTextStyles.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Announcements',
          style: appText.cardTitle,
        ),
        const SizedBox(height: 8),
        announcementsAsync.when(
          data: (announcements) {
            if (announcements.isEmpty) {
              return const SizedBox(
                width: double.infinity,
                child: _AnnouncementTile(
                  icon: Icons.campaign_outlined,
                  title: 'No announcements yet',
                  subtitle: 'School updates will appear here.',
                ),
              );
            }
            return Column(
              children: [
                for (var i = 0; i < announcements.length; i++) ...[
                  if (i > 0) const SizedBox(height: 10),
                  _AnnouncementTile(
                    icon: i == 0 ? Icons.campaign : Icons.campaign_outlined,
                    title: announcements[i].title,
                    subtitle: announcements[i].body,
                    authorName: announcements[i].authorName,
                    authorAvatarUrl: announcements[i].authorAvatarUrl,
                  ),
                ],
              ],
            );
          },
          loading: () => const Center(child: LoadingIndicator()),
          error: (error, _) => SizedBox(
            width: double.infinity,
            child: _AnnouncementTile(
              icon: Icons.cloud_off_outlined,
              title: 'Could not load announcements',
              subtitle: 'Pull to refresh to try again.',
            ),
          ),
        ),
      ],
    );
  }
}

class _AnnouncementTile extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final String? authorName;
  final String? authorAvatarUrl;

  const _AnnouncementTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.authorName,
    this.authorAvatarUrl,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: context.appBorder),
      ),
      color: context.appSurface,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, size: 18, color: AppColors.primaryBlue),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    title,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 15,
                      color: context.appTextPrimary,
                    ),
                  ),
                ),
              ],
            ),
            if (subtitle.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                subtitle,
                style: TextStyle(
                  color: context.appTextSecondary,
                  fontSize: 13,
                  height: 1.4,
                ),
              ),
            ],
            if (authorName?.isNotEmpty ?? false) ...[
              const SizedBox(height: 10),
              Row(
                children: [
                  CachedAvatar(
                    imageUrl: authorAvatarUrl,
                    radius: 13,
                    initials: authorName,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      authorName!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: context.appTextSecondary,
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}