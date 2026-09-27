import 'package:flutter/material.dart';
import '../constants/app_colors.dart';
import '../constants/app_text_styles.dart';
import '../theme/app_tokens.dart';
import '../../data/models/candidate_model.dart';
import 'cached_avatar.dart';

class CandidateCard extends StatelessWidget {
  final Candidate candidate;
  final VoidCallback? onViewProfile;
  final VoidCallback? onVote;
  final bool isSelectable;
  final bool isSelected;
  final ValueChanged<bool?>? onSelected;
  final bool isReadOnly;

  const CandidateCard({
    super.key,
    required this.candidate,
    this.onViewProfile,
    this.onVote,
    this.isSelectable = false,
    this.isSelected = false,
    this.onSelected,
    this.isReadOnly = false,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 16),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: context.appBorder),
      ),
      color: context.appSurface,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                CachedAvatar(
                  imageUrl: candidate.photoUrl.isNotEmpty
                      ? candidate.photoUrl
                      : null,
                  radius: 30,
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Position Tag
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8, 
                          vertical: 4
                        ),
                        decoration: BoxDecoration(
                          color: context.appTagBg,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(
                          candidate.position.label.toUpperCase(),
                          style: appText.tag,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        candidate.name,
                        style: appText.cardTitle.copyWith(fontSize: 18),
                      ),
                      Text(
                        candidate.gradeLine,
                        style: appText.secondary,
                      ),
                    ],
                  ),
                ),
                if (isSelectable)
                  Checkbox(
                    value: isSelected,
                    onChanged: onSelected,
                    activeColor: AppColors.primaryBlue,
                  ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              '"${candidate.slogan}"',
              style: appText.slogan,
            ),
            const SizedBox(height: 16),
            Text(
              'KEY PLATFORM POINTS',
              style: appText.tag.copyWith(
                color: context.appTextSecondary,
                fontSize: 11,
                letterSpacing: 0.5,
              ),
            ),
            const SizedBox(height: 8),
            ...candidate.platformPoints.take(3).map((point) => Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('• ', style: TextStyle(color: context.appTextSecondary)),
                      Expanded(
                        child: Text(
                          point,
                          style: appText.body,
                        ),
                      ),
                    ],
                  ),
                )),
            if (!isReadOnly) ...[
              Divider(height: 32, color: context.appBorder),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  TextButton(
                    onPressed: onViewProfile,
                    child: const Text(
                      'View Profile',
                      style: TextStyle(
                        color: AppColors.primaryBlue,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                  if (!isSelectable)
                    ElevatedButton(
                      onPressed: onVote,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.primaryBlue,
                        foregroundColor: Colors.white,
                        elevation: 0,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8),
                        ),
                        padding: const EdgeInsets.symmetric(
                          horizontal: 24, 
                          vertical: 12
                        ),
                        minimumSize: Size.zero, // Allow button to shrink
                      ),
                      child: const Text('Vote'),
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
