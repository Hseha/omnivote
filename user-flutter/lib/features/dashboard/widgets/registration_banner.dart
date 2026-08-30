import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';

class RegistrationBanner extends StatelessWidget {
  final DateTime registrationDate;

  const RegistrationBanner({
    super.key,
    required this.registrationDate,
  });

  @override
  Widget build(BuildContext context) {
    final formattedDate = DateFormat('MMM d, yyyy').format(registrationDate);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.successGreen.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(
          color: AppColors.successGreen.withValues(alpha: 0.3),
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(
            Icons.check_circle,
            color: AppColors.successGreen,
            size: 20,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              'You registered on $formattedDate. Your account is active and eligible to cast a ballot in all ongoing student body elections.',
              style: AppTextStyles.body.copyWith(
                color: AppColors.successGreen,
                height: 1.4,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
