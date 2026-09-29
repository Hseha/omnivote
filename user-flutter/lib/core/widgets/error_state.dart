import 'package:flutter/material.dart';

import '../constants/app_text_styles.dart';
import '../theme/app_spacing.dart';
import '../theme/app_tokens.dart';
import 'app_button.dart';

/// Icon + message + retry action for "something failed" states.
///
/// Every screen's `.when(error:)` / error branch should render this instead
/// of a bare `Text`, so a failed load always offers a way forward. [message]
/// should already be mapped through `apiErrorMessage` by the caller.
class ErrorState extends StatelessWidget {
  final String message;
  final String retryLabel;
  final VoidCallback? onRetry;
  final IconData icon;

  const ErrorState({
    super.key,
    required this.message,
    this.retryLabel = 'Try again',
    this.onRetry,
    this.icon = Icons.cloud_off_outlined,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 48, color: context.appBorderStrong),
            AppSpacing.vMd,
            Text(
              message,
              textAlign: TextAlign.center,
              style: appText.bodyMedium,
            ),
            if (onRetry != null) ...[
              AppSpacing.vMd,
              AppButton.secondary(
                label: retryLabel,
                onPressed: onRetry,
                expands: false,
              ),
            ],
          ],
        ),
      ),
    );
  }
}
