import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../data/models/student_model.dart';

class RegistrationDetailsCard extends StatelessWidget {
  final Student student;
  final DateTime registrationDate;
  final String eligibilityStatus;

  const RegistrationDetailsCard({
    super.key,
    required this.student,
    required this.registrationDate,
    required this.eligibilityStatus,
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
              'My Registration Details',
              style: AppTextStyles.cardTitle,
            ),
            const SizedBox(height: 20),
            _buildDetailRow('Student ID', student.studentId),
            _buildDetailRow('Full Name', student.name),
            _buildDetailRow('Grade', student.gradeLevel),
            _buildDetailRow('Homeroom', student.homeroom),
            _buildDetailRow(
              'Registration Date',
              DateFormat('MMM d, yyyy').format(registrationDate),
            ),
            _buildDetailRow(
              'Eligibility Status',
              eligibilityStatus,
              valueColor: AppColors.successGreen,
              isBadge: true,
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDetailRow(
    String label,
    String value, {
    Color? valueColor,
    bool isBadge = false,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12.0),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: AppTextStyles.secondary,
          ),
          if (isBadge)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(
                color: (valueColor ?? AppColors.textPrimary).withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(4),
              ),
              child: Text(
                value,
                style: AppTextStyles.body.copyWith(
                  color: valueColor ?? AppColors.textPrimary,
                  fontWeight: FontWeight.bold,
                  fontSize: 12,
                ),
              ),
            )
          else
            Text(
              value,
              style: AppTextStyles.body.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
        ],
      ),
    );
  }
}
