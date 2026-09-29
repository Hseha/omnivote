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
import '../providers/announcements_provider.dart';

/// Published announcements as a horizontal scrollable row, newest first with
/// the most recent one tagged "Latest". Same [announcementsProvider] data
/// source — presentation only changed.
///
/// States: skeleton cards while loading, an [EmptyState] when there is
/// nothing published, and an [ErrorState] with retry (re-fetches the
/// provider) when the load fails.
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
                      title: announcement.title,
                      body: announcement.body,
                      authorName: announcement.authorName,
                      authorAvatarUrl: announcement.authorAvatarUrl,
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
  final String title;
  final String body;
  final String? authorName;
  final String? authorAvatarUrl;
  final bool isLatest;

  const _AnnouncementCard({
    required this.title,
    required this.body,
    required this.authorName,
    required this.authorAvatarUrl,
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
                  title,
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
              body,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: appText.labelSmall,
            ),
          ),
          if (authorName?.isNotEmpty ?? false) ...[
            AppSpacing.vXs,
            Row(
              children: [
                CachedAvatar(
                  imageUrl: authorAvatarUrl,
                  radius: 10,
                  initials: authorName,
                ),
                AppSpacing.hXs,
                Expanded(
                  child: Text(
                    authorName!,
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
}
