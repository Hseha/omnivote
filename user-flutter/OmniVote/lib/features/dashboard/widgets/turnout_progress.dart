import 'package:flutter/material.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';

class TurnoutProgress extends StatelessWidget {
  const TurnoutProgress({super.key});

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: AppColors.borderGray),
      ),
      child: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Omni High Voter Turnout', style: AppTextStyles.cardTitle),
            const SizedBox(height: 24),
            _buildProgressBar('Registered vs Total Students', 0.85, '850/1000'),
            const SizedBox(height: 20),
            _buildProgressBar('Actual Ballots Cast To Date', 0.42, '357/850'),
          ],
        ),
      ),
    );
  }

  Widget _buildProgressBar(String title, double value, String label) {
    return Column(
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(title, style: AppTextStyles.secondary.copyWith(fontSize: 12)),
            Text(label, style: AppTextStyles.body.copyWith(fontWeight: FontWeight.bold, fontSize: 12)),
          ],
        ),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: value,
            minHeight: 8,
            backgroundColor: AppColors.backgroundGray,
            valueColor: const AlwaysStoppedAnimation<Color>(AppColors.successGreen),
          ),
        ),
      ],
    );
  }
}
