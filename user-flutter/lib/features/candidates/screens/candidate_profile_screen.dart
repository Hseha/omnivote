import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/safe_json.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/cached_avatar.dart';
import '../../../core/widgets/section_header.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidate_model.dart';
import '../widgets/platform_points_list.dart';

class CandidateProfileScreen extends StatelessWidget {
  final Candidate candidate;

  const CandidateProfileScreen({
    super.key,
    required this.candidate,
  });

  @override
  Widget build(BuildContext context) {
    final String firstName = candidate.name.split(' ').first;
    final appText = AppTextStyles.of(context);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Candidate Profile'),
      body: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Hero Area
            Container(
              color: context.appSurface,
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Column(
                children: [
                  CachedAvatar(
                    imageUrl: candidate.photoUrl,
                    radius: AppMetrics.avatarXl,
                  ),
                  AppSpacing.vMd,
                  // Position Tag
                  Container(
                    padding: AppMetrics.tagPadding,
                    decoration: BoxDecoration(
                      color: context.appTagBg,
                      borderRadius: AppRadius.smAll,
                    ),
                    child: Text(
                      candidate.position.label.toUpperCase(),
                      style: appText.tag.copyWith(letterSpacing: 0.8),
                    ),
                  ),
                  AppSpacing.vSm,
                  Text(
                    candidate.name,
                    textAlign: TextAlign.center,
                    style: appText.titleLarge,
                  ),
                  if (candidate.gradeLine.trim().isNotEmpty) ...[
                    AppSpacing.vXs,
                    Text(
                      candidate.gradeLine,
                      style: appText.subtitle,
                    ),
                  ],
                  if (candidate.slogan.trim().isNotEmpty) ...[
                    AppSpacing.vMd,
                    Text(
                      '"${candidate.slogan}"',
                      textAlign: TextAlign.center,
                      style: appText.subtitle.copyWith(
                        fontStyle: FontStyle.italic,
                      ),
                    ),
                  ],
                  AppSpacing.vXl,
                  Row(
                    children: [
                      Expanded(
                        child: AppButton.primary(
                          label: 'Vote for $firstName',
                          onPressed: () {
                            // Route to the guided Vote Now flow, preselected at
                            // this candidate's position (audit §2 #2).
                            context.go(
                              '/vote-now',
                              extra: candidate.position.id,
                            );
                          },
                        ),
                      ),
                      AppSpacing.hSm,
                      Expanded(
                        child: AppButton.secondary(
                          label: 'Back to Candidates',
                          onPressed: () => context.pop(),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),

            Padding(
              padding: AppSpacing.screenPadding,
              child: Column(
                children: [
                  // 1. Campaign Platform
                  if (candidate.platformPoints.isNotEmpty)
                    _buildSectionCard(
                      context,
                      title: 'Campaign Platform',
                      child: PlatformPointsList(
                        points: candidate.platformPoints,
                        isNumbered: true,
                      ),
                    ),

                  // 2. Campaign Video
                  if (candidate.videoUrl != null &&
                      candidate.videoUrl!.trim().isNotEmpty)
                    _buildSectionCard(
                      context,
                      title: 'Campaign Video',
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Container(
                            height: 200,
                            width: double.infinity,
                            decoration: BoxDecoration(
                              // Media backdrop: near-black in both themes so
                              // thumbnails and the play glyph read the same.
                              color: AppColors.navyDark,
                              borderRadius: AppRadius.smAll,
                              image: safeHttpImageUrl(candidate.photoUrl) !=
                                      null
                                  ? DecorationImage(
                                      image: CachedNetworkImageProvider(
                                        candidate.photoUrl,
                                      ),
                                      fit: BoxFit.cover,
                                      colorFilter: ColorFilter.mode(
                                        Colors.black.withValues(alpha: 0.4),
                                        BlendMode.darken,
                                      ),
                                    )
                                  : null,
                            ),
                            child: const Center(
                              child: Icon(
                                Icons.play_circle_fill,
                                color: Colors.white,
                                size: 64,
                                semanticLabel: 'Play campaign video',
                              ),
                            ),
                          ),
                          AppSpacing.vSm,
                          Text(
                            "Listen to ${candidate.name}'s 2-minute pitch to voters",
                            style: appText.bodySmall,
                          ),
                        ],
                      ),
                    ),

                  // 3. Qualifications & Experience
                  if (candidate.qualifications.isNotEmpty)
                    _buildSectionCard(
                      context,
                      title: 'Qualifications & Experience',
                      child: PlatformPointsList(
                        points: candidate.qualifications,
                        isNumbered: false,
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSectionCard(BuildContext context,
      {required String title, required Widget child}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: AppCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SectionHeader(title: title),
            AppSpacing.vMd,
            child,
          ],
        ),
      ),
    );
  }
}