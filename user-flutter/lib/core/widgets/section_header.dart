import 'package:flutter/material.dart';

import '../constants/app_text_styles.dart';
import '../theme/app_spacing.dart';

/// The single section-header primitive: 16-pt semibold title, optional
/// secondary description line, optional trailing action (e.g. "See all").
///
/// Replaces the various ad-hoc `Text(cardTitle)` + `SizedBox` heading blocks
/// so every screen's sections share one rhythm.
class SectionHeader extends StatelessWidget {
  final String title;
  final String? description;
  final String? actionLabel;
  final VoidCallback? onAction;

  const SectionHeader({
    super.key,
    required this.title,
    this.description,
    this.actionLabel,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(child: Text(title, style: appText.titleMedium)),
            if (actionLabel != null && onAction != null)
              TextButton(
                onPressed: onAction,
                style: TextButton.styleFrom(
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.sm,
                  ),
                  minimumSize: const Size(44, 44),
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
                child: Text(actionLabel!),
              ),
          ],
        ),
        if (description != null) ...[
          AppSpacing.vXs,
          Text(description!, style: appText.bodySmall),
        ],
      ],
    );
  }
}
