import 'package:flutter/material.dart';
import '../constants/app_text_styles.dart';
import '../theme/app_spacing.dart';
import '../theme/app_tokens.dart';

/// Icon + message + optional action for "there is nothing here" states.
///
/// [icon] and [actionLabel]/[onAction] are optional so every existing call
/// site (`EmptyState(message:, subMessage:)`) renders exactly as before;
/// redesigned screens should always pass an icon and, when the user can do
/// something about the emptiness, an action.
class EmptyState extends StatelessWidget {
  final String message;
  final String? subMessage;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  const EmptyState({
    super.key,
    required this.message,
    this.subMessage,
    this.icon = Icons.search_off,
    this.actionLabel,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              icon,
              size: 64,
              color: context.appBorder,
            ),
            AppSpacing.vMd,
            Text(
              message,
              textAlign: TextAlign.center,
              style: appText.titleMedium.copyWith(fontSize: 18),
            ),
            if (subMessage != null) ...[
              AppSpacing.vSm,
              Text(
                subMessage!,
                textAlign: TextAlign.center,
                style: appText.bodySmall,
              ),
            ],
            if (actionLabel != null && onAction != null) ...[
              AppSpacing.vMd,
              FilledButton.tonal(
                onPressed: onAction,
                child: Text(actionLabel!),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
