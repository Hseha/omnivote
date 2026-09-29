import 'package:flutter/material.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_spacing.dart';

class PlatformPointsList extends StatelessWidget {
  final List<String> points;
  final bool isNumbered;

  const PlatformPointsList({
    super.key,
    required this.points,
    this.isNumbered = false,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: points.asMap().entries.map((entry) {
        int idx = entry.key + 1;
        String point = entry.value;
        return Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                isNumbered ? '$idx. ' : '• ',
                style: appText.bodySmall.copyWith(
                  fontWeight: FontWeight.bold,
                ),
              ),
              Expanded(
                child: Text(
                  point,
                  style: appText.bodyMedium,
                ),
              ),
            ],
          ),
        );
      }).toList(),
    );
  }
}
