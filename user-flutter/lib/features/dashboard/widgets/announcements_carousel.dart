import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_skeleton.dart';
import '../../../core/widgets/section_header.dart';
import '../../../data/models/announcement_model.dart';
import '../providers/announcements_provider.dart';

/// Published announcements as a horizontal scrollable row, newest first with
/// the most recent one tagged "Latest". Same [announcementsProvider] data
/// source — presentation only changed.
///
/// States: skeleton cards while loading, an [EmptyState] when there is
/// nothing published, and an [ErrorState] with retry (re-fetches the
/// provider) when the load fails.
///
/// A card is two lines of body tall, which is enough for a notice but not for
/// a long post — the results announcement lists every elected officer. Tapping
/// a card opens the whole announcement in a sheet.
class AnnouncementsCarousel extends ConsumerWidget {
  const AnnouncementsCarousel({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final announcementsAsync = ref.watch(announcementsProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SectionHeader(title: 'Announcements'),
        AppSpacing.vSm,
        announcementsAsync.when(
          data: (announcements) {
            if (announcements.isEmpty) {
              return const AppCard(
                child: EmptyState(
                  message: 'No announcements yet',
                  subMessage:
                      'Check back later — new announcements will appear here.',
                  icon: Icons.campaign_outlined,
                ),
              );
            }
            return SizedBox(
              height: 132,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: announcements.length,
                separatorBuilder: (_, _) => AppSpacing.hSm,
                itemBuilder: (context, index) {
                  final announcement = announcements[index];
                  return SizedBox(
                    width: 260,
                    child: _AnnouncementCard(
                      announcement: announcement,
                      isLatest: index == 0,
                    ),
                  );
                },
              ),
            );
          },
          loading: () => SizedBox(
            height: 132,
            child: Row(
              children: [
                Expanded(child: LoadingSkeleton.card(height: 132)),
                AppSpacing.hSm,
                Expanded(child: LoadingSkeleton.card(height: 132)),
              ],
            ),
          ),
          error: (error, _) => ErrorState(
            message: 'Could not load announcements',
            onRetry: () => ref.invalidate(announcementsProvider),
          ),
        ),
      ],
    );
  }
}

class _AnnouncementCard extends StatelessWidget {
  final Announcement announcement;
  final bool isLatest;

  const _AnnouncementCard({
    required this.announcement,
    required this.isLatest,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return AppCard(
      padding: const EdgeInsets.symmetric(
        horizontal: AppMetrics.rowPaddingH,
        vertical: AppMetrics.rowPaddingV,
      ),
      // The card is a link to the whole announcement: the two lines of body
      // it shows are a preview, not the post.
      onTap: () => _openAnnouncement(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              if (isLatest) ...[
                Container(
                  padding: AppMetrics.tagPadding,
                  decoration: BoxDecoration(
                    color: context.appTagBg,
                    borderRadius: AppRadius.smAll,
                  ),
                  child: Text('Latest', style: appText.tag),
                ),
                AppSpacing.hXs,
              ],
              Expanded(
                child: Text(
                  announcement.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: appText.titleSmall,
                ),
              ),
            ],
          ),
          AppSpacing.vXs,
          Expanded(
            child: Text(
              announcement.body,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: appText.labelSmall,
            ),
          ),
          if (announcement.authorName?.isNotEmpty ?? false) ...[
            AppSpacing.vXs,
            Row(
              children: [
                CachedAvatar(
                  imageUrl: announcement.authorAvatarUrl,
                  radius: 10,
                  initials: announcement.authorName,
                ),
                AppSpacing.hXs,
                Expanded(
                  child: Text(
                    announcement.authorName!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: appText.labelSmall,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
  /// Opens the whole announcement in a sheet. The carousel card only has room
  /// for two lines, and an announcement can be long — the results post lists
  /// every elected officer.
  Future<void> _openAnnouncement(BuildContext context) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (sheetContext) => _AnnouncementSheet(
        announcement: announcement,
      ),
    );
  }
}

class _AnnouncementSheet extends StatelessWidget {

  final Announcement announcement;

  const _AnnouncementSheet({required this.announcement});

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              announcement.title,
              style: appText.titleMedium,
            ),
            AppSpacing.vSm,
            if (announcement.authorName?.isNotEmpty ?? false) ...[
              Row(
                children: [
                  CachedAvatar(
                    imageUrl: announcement.authorAvatarUrl,
                    radius: 14,
                    initials: announcement.authorName,
                  ),
                  AppSpacing.hXs,
                  Expanded(
                    child: Text(
                      announcement.authorName!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: appText.labelSmall,
                    ),
                  ),
                ],
              ),
              AppSpacing.vSm,
            ],
            Flexible(
              child: SingleChildScrollView(
                child: Text(
                  announcement.body,
                  style: appText.bodySmall,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
