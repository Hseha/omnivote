import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/app_text_field.dart';
import '../../../core/widgets/top_bar.dart';
import '../providers/auth_provider.dart';

/// Self-service password recovery for a student who cannot sign in.
///
/// SECURITY (assessment M-3). The previous backoff design let anyone who knew a
/// student's handle lock that student out of the election, and the only way back
/// in was an administrator. The backend now answers a backed-off account with
/// the same `401 Invalid credentials` as an unknown one, and recovers the account
/// with a registrar-issued activation code instead of a temporary password.
///
/// There is deliberately no email field: students sign in with a name-derived
/// handle and have no mailbox, so a brokered reset link would have nowhere to
/// go. The code is read off the slip the registrar handed out, is single-use,
/// and is burned the moment it succeeds — so the student is returned to /login
/// to sign in with the password they just chose.
class RecoverPasswordScreen extends ConsumerStatefulWidget {
  const RecoverPasswordScreen({super.key});

  @override
  ConsumerState<RecoverPasswordScreen> createState() =>
      _RecoverPasswordScreenState();
}

class _RecoverPasswordScreenState extends ConsumerState<RecoverPasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _studentIdController = TextEditingController();
  final _codeController = TextEditingController();
  final _newController = TextEditingController();
  final _confirmController = TextEditingController();

  @override
  void dispose() {
    _studentIdController.dispose();
    _codeController.dispose();
    _newController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  /// Mirrors the server-side password policy so the student is told what is
  /// wrong before a round trip. The server re-validates regardless.
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

    final success = await ref.read(authProvider.notifier).resetPasswordWithCode(
          studentId: _studentIdController.text.trim(),
          // The backend upper-cases and trims, but normalising here keeps the
          // field consistent with what the student reads off the slip.
          code: _codeController.text.trim().toUpperCase(),
          newPassword: _newController.text,
        );

    if (!mounted) return;

    if (!success) {
      final error = ref.read(authProvider).errorMessage ??
          'Could not reset your password';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(error),
          backgroundColor: Theme.of(context).colorScheme.error,
        ),
      );
      return;
    }

    // The response is intentionally identical for a valid and an invalid code,
    // so the honest message is "if that was right, sign in and try" rather than
    // a false confirmation. Signing in is what actually proves the reset.
    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Password updated'),
        content: const Text(
          'If that student ID and code were valid, your password has been reset. '
          'Sign in with your new password.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: const Text('OK'),
          ),
        ],
      ),
    );

    if (mounted) context.go('/login');
  }

  @override
  Widget build(BuildContext context) {
    final isLoading = ref.watch(
      authProvider.select((state) => state.isLoading),
    );
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Recover password'),
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
                      Icons.vpn_key_outlined,
                      size: 48,
                      color: scheme.primary,
                    ),
                    AppSpacing.vMd,
                    Text(
                      'Use your activation code',
                      style: appText.headlineSmall,
                      textAlign: TextAlign.center,
                    ),
                    AppSpacing.vSm,
                    Text(
                      'Enter the student ID and one-time code from the slip '
                      'the registrar gave you, then choose a new password.',
                      style: appText.bodySmall,
                      textAlign: TextAlign.center,
                    ),
                    AppSpacing.vLg,
                    AppTextField(
                      controller: _studentIdController,
                      textInputAction: TextInputAction.next,
                      autocorrect: false,
                      label: 'Student ID',
                      prefixIcon: const Icon(Icons.badge_outlined),
                      validator: (value) =>
                          (value == null || value.trim().isEmpty)
                              ? 'Please enter your student ID'
                              : null,
                    ),
                    AppTextField.fieldGap,
                    AppTextField(
                      controller: _codeController,
                      textCapitalization: TextCapitalization.characters,
                      autocorrect: false,
                      obscure: true,
                      toggleLabel: 'code',
                      label: 'Activation code',
                      prefixIcon: const Icon(
                        Icons.confirmation_number_outlined,
                      ),
                      validator: (value) =>
                          (value == null || value.trim().isEmpty)
                              ? 'Please enter your activation code'
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
                      label: 'Reset password',
                      onPressed: isLoading ? null : _submit,
                      isLoading: isLoading,
                    ),
                    AppSpacing.vSm,
                    AppButton.text(
                      label: 'Back to sign in',
                      onPressed:
                          isLoading ? null : () => context.go('/login'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
