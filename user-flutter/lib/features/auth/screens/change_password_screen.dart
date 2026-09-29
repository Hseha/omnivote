import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/app_text_field.dart';
import '../providers/auth_provider.dart';

/// Forced first-login password rotation for registrar-provisioned accounts.
///
/// Reached automatically after login when the API reports
/// `must_change_password: true`; the account holds a random temporary password
/// that the student must replace. Navigation back into the app is blocked until
/// the change succeeds (the global auth listener advances to /dashboard).
class ChangePasswordScreen extends ConsumerStatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  ConsumerState<ChangePasswordScreen> createState() =>
      _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends ConsumerState<ChangePasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _currentController = TextEditingController();
  final _newController = TextEditingController();
  final _confirmController = TextEditingController();

  @override
  void dispose() {
    _currentController.dispose();
    _newController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  String? _validateNewPassword(String? value) {
    final password = value ?? '';
    if (password.isEmpty) return 'Please enter a new password';
    if (password.length < 8) return 'Use at least 8 characters';
    if (!RegExp(r'[A-Za-z]').hasMatch(password)) {
      return 'Include at least one letter';
    }
    if (!RegExp(r'[0-9]').hasMatch(password)) {
      return 'Include at least one number';
    }
    return null;
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final success = await ref.read(authProvider.notifier).changePassword(
          currentPassword: _currentController.text,
          newPassword: _newController.text,
        );

    if (!mounted) return;
    if (!success) {
      final error =
          ref.read(authProvider).errorMessage ?? 'Could not update password';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(error),
          backgroundColor: Theme.of(context).colorScheme.error,
        ),
      );
    }
    // On success the global auth listener in app.dart moves to /dashboard.
  }

  @override
  Widget build(BuildContext context) {
    final isLoading = ref.watch(
      authProvider.select((state) => state.isLoading),
    );
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;

    return PopScope(
      // Forced step: no back button out of it.
      canPop: false,
      child: Scaffold(
        backgroundColor: context.appBackground,
        body: Center(
          child: SingleChildScrollView(
            padding: AppSpacing.screenPadding,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 400),
              child: AppCard(
                padding: const EdgeInsets.all(AppSpacing.xl),
                child: Form(
                  key: _formKey,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Icon(
                        Icons.lock_reset,
                        size: 48,
                        color: scheme.primary,
                      ),
                      AppSpacing.vMd,
                      Text(
                        'Set a new password',
                        style: appText.headlineSmall,
                        textAlign: TextAlign.center,
                      ),
                      AppSpacing.vSm,
                      Text(
                        'Your account was created with a temporary password. '
                        'Choose a new password to continue.',
                        style: appText.bodySmall,
                        textAlign: TextAlign.center,
                      ),
                      AppSpacing.vLg,
                      AppTextField(
                        controller: _currentController,
                        obscure: true,
                        label: 'Temporary password',
                        prefixIcon: const Icon(Icons.lock_outline),
                        validator: (value) => value == null || value.isEmpty
                            ? 'Please enter your temporary password'
                            : null,
                      ),
                      AppTextField.fieldGap,
                      AppTextField(
                        controller: _newController,
                        obscure: true,
                        label: 'New password',
                        prefixIcon: const Icon(Icons.lock_outline),
                        validator: _validateNewPassword,
                      ),
                      AppTextField.fieldGap,
                      AppTextField(
                        controller: _confirmController,
                        obscure: true,
                        textInputAction: TextInputAction.done,
                        label: 'Confirm new password',
                        prefixIcon: const Icon(Icons.lock_outline),
                        validator: (value) => value != _newController.text
                            ? 'Passwords do not match'
                            : null,
                      ),
                      AppSpacing.vLg,
                      AppButton.primary(
                        label: 'Update password',
                        onPressed: isLoading ? null : _submit,
                        isLoading: isLoading,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
