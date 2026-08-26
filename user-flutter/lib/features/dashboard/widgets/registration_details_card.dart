import 'package:flutter/material.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';

class RegistrationDetailsCard extends StatelessWidget {
  const RegistrationDetailsCard({super.key});

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
            const Text('My Registration Details', style: AppTextStyles.cardTitle),
            const Divider(height: 32),
            _buildRow('Student ID', '2024-10042'),
            _buildRow('Full Name', 'John Doe'),
            _buildRow('Grade', 'Grade 11'),
            _buildRow('Status', 'Eligible Voter', isStatus: true),
          ],
        ),
      ),
    );
  }

  Widget _buildRow(String label, String value, {bool isStatus = false}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: AppTextStyles.secondary),
          Text(
            value,
            style: AppTextStyles.body.copyWith(
              fontWeight: FontWeight.bold,
              color: isStatus ? AppColors.successGreen : AppColors.textPrimary,
            ),
          ),
        ],
      ),
    );
  }
}
