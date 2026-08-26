import 'package:flutter/material.dart';
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

  // Design tokens based on 07_DESIGN_SYSTEM.md
  static const Color primaryBlue = Color(0xFF2F5EFF);
  static const Color navyDark = Color(0xFF0F172A);
  static const Color textPrimary = Color(0xFF0F172A);
  static const Color textSecondary = Color(0xFF64748B);
  static const Color borderGray = Color(0xFFE2E8F0);
  static const Color tagBlueBg = Color(0xFFE0E7FF);
  static const Color tagBlueText = Color(0xFF3730A3);

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 16),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: borderGray),
      ),
      color: Colors.white,
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
                  backgroundImage: NetworkImage(candidate.photoUrl),
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
                          color: tagBlueBg,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(
                          candidate.position.label.toUpperCase(),
                          style: const TextStyle(
                            color: tagBlueText,
                            fontWeight: FontWeight.bold,
                            fontSize: 10,
                            letterSpacing: 0.5,
                          ),
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        candidate.name,
                        style: const TextStyle(
                          color: textPrimary,
                          fontWeight: FontWeight.bold,
                          fontSize: 18,
                        ),
                      ),
                      Text(
                        candidate.gradeLine,
                        style: const TextStyle(
                          color: textSecondary,
                          fontSize: 14,
                        ),
                      ),
                    ],
                  ),
                ),
                if (isSelectable)
                  Checkbox(
                    value: isSelected,
                    onChanged: onSelected,
                    activeColor: primaryBlue,
                  ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              '"${candidate.slogan}"',
              style: const TextStyle(
                color: textSecondary,
                fontStyle: FontStyle.italic,
                fontSize: 14,
              ),
            ),
            const SizedBox(height: 16),
            const Text(
              'KEY PLATFORM POINTS',
              style: TextStyle(
                color: textSecondary,
                fontSize: 11,
                fontWeight: FontWeight.w900,
                letterSpacing: 0.5,
              ),
            ),
            const SizedBox(height: 8),
            ...candidate.platformPoints.take(3).map((point) => Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('• ', style: TextStyle(color: textSecondary)),
                      Expanded(
                        child: Text(
                          point,
                          style: const TextStyle(
                            color: textPrimary,
                            fontSize: 14,
                          ),
                        ),
                      ),
                    ],
                  ),
                )),
            if (!isReadOnly) ...[
              const Divider(height: 32, color: borderGray),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  TextButton(
                    onPressed: onViewProfile,
                    child: const Text(
                      'View Profile',
                      style: TextStyle(
                        color: primaryBlue,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                  if (!isSelectable)
                    ElevatedButton(
                      onPressed: onVote,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: primaryBlue,
                        foregroundColor: Colors.white,
                        elevation: 0,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8),
                        ),
                        padding: const EdgeInsets.symmetric(
                          horizontal: 24, 
                          vertical: 12
                        ),
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
