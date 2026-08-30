import 'package:flutter/material.dart';
import '../constants/app_colors.dart';
import '../constants/app_text_styles.dart';
import '../../data/models/candidate_model.dart';

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
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 16),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: AppColors.borderGray),
      ),
      color: AppColors.surfaceWhite,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                CircleAvatar(
                  radius: 30,
                  backgroundColor: AppColors.backgroundGray,
                  backgroundImage: candidate.photoUrl.isNotEmpty 
                      ? NetworkImage(candidate.photoUrl) 
                      : null,
                  child: candidate.photoUrl.isEmpty 
                      ? const Icon(Icons.person, size: 30, color: AppColors.textSecondary)
                      : null,
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
                          color: AppColors.tagBlueBg,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(
                          candidate.position.label.toUpperCase(),
                          style: AppTextStyles.tag,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        candidate.name,
                        style: AppTextStyles.cardTitle.copyWith(fontSize: 18),
                      ),
                      Text(
                        candidate.gradeLine,
                        style: AppTextStyles.secondary,
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
              style: AppTextStyles.slogan,
            ),
            const SizedBox(height: 16),
            Text(
              'KEY PLATFORM POINTS',
              style: AppTextStyles.tag.copyWith(
                color: AppColors.textSecondary,
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
                      const Text('• ', style: TextStyle(color: AppColors.textSecondary)),
                      Expanded(
                        child: Text(
                          point,
                          style: AppTextStyles.body,
                        ),
                      ),
                    ],
                  ),
                )),
            if (!isReadOnly) ...[
              const Divider(height: 32, color: AppColors.borderGray),
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
