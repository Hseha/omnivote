import 'package:flutter/material.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../data/models/registration_model.dart';

class TurnoutProgress extends StatelessWidget {
  final Turnout turnout;

  const TurnoutProgress({
    super.key,
    required this.turnout,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Election Turnout',
              style: AppTextStyles.cardTitle,
            ),
            const SizedBox(height: 24),
            _buildProgressBar(
              title: 'Registered vs Total Students',
              current: turnout.registeredStudents,
              total: turnout.totalStudents,
              color: AppColors.primaryBlue,
            ),
            const SizedBox(height: 24),
            _buildProgressBar(
              title: 'Actual Ballots Cast To Date',
              current: turnout.actualBallotsCast,
              total: turnout.registeredStudents,
              color: AppColors.successGreen,
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildProgressBar({
    required String title,
    required int current,
    required int total,
    required Color color,
  }) {
    final double percentage = total > 0 ? (current / total) * 100 : 0;
    final double progress = total > 0 ? (current / total) : 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Expanded(
              child: Text(
                title,
                style: AppTextStyles.body.copyWith(fontWeight: FontWeight.w600),
              ),
            ),
            Text(
              '$current/$total (${percentage.toStringAsFixed(1)}%)',
              style: AppTextStyles.secondary.copyWith(
                fontWeight: FontWeight.bold,
                color: AppColors.textPrimary,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        ClipRRect(
          borderRadius: BorderRadius.circular(10),
          child: LinearProgressIndicator(
            value: progress,
            backgroundColor: AppColors.borderGray,
            valueColor: AlwaysStoppedAnimation<Color>(color),
            minHeight: 8,
          ),
        ),
      ],
    );
  }
}
