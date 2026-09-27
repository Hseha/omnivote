import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/constants/app_text_styles.dart';
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

  bool _obscureCode = true;
  bool _obscurePassword = true;

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
        SnackBar(content: Text(error), backgroundColor: AppColors.errorRed),
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
    final isLoading = ref.watch(authProvider).isLoading;
    final appText = AppTextStyles.of(context);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: AppBar(
        title: const Text('Recover password'),
        backgroundColor: Colors.transparent,
        elevation: 0,
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24.0),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 400),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(32.0),
                child: Form(
                  key: _formKey,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Icon(
                        Icons.vpn_key_outlined,
                        size: 48,
                        color: AppColors.primaryBlue,
                      ),
                      const SizedBox(height: 16),
                      Text(
                        'Use your activation code',
                        style: appText.pageTitle,
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Enter the student ID and one-time code from the slip '
                        'the registrar gave you, then choose a new password.',
                        style: appText.secondary,
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 32),
                      TextFormField(
                        controller: _studentIdController,
                        textInputAction: TextInputAction.next,
                        autocorrect: false,
                        decoration: const InputDecoration(
                          labelText: 'Student ID',
                          prefixIcon: Icon(Icons.badge_outlined),
                        ),
                        validator: (value) =>
                            (value == null || value.trim().isEmpty)
                                ? 'Please enter your student ID'
                                : null,
                      ),
                      const SizedBox(height: 20),
                      TextFormField(
                        controller: _codeController,
                        textCapitalization: TextCapitalization.characters,
                        autocorrect: false,
                        obscureText: _obscureCode,
                        decoration: InputDecoration(
                          labelText: 'Activation code',
                          prefixIcon: const Icon(Icons.confirmation_number_outlined),
                          suffixIcon: IconButton(
                            icon: Icon(_obscureCode
                                ? Icons.visibility_off_outlined
                                : Icons.visibility_outlined),
                            tooltip: _obscureCode ? 'Show code' : 'Hide code',
                            onPressed: () =>
                                setState(() => _obscureCode = !_obscureCode),
                          ),
                        ),
                        validator: (value) =>
                            (value == null || value.trim().isEmpty)
                                ? 'Please enter your activation code'
                                : null,
                      ),
                      const SizedBox(height: 20),
                      TextFormField(
                        controller: _newController,
                        obscureText: _obscurePassword,
                        decoration: InputDecoration(
                          labelText: 'New password',
                          prefixIcon: const Icon(Icons.lock_outline),
                          suffixIcon: IconButton(
                            icon: Icon(_obscurePassword
                                ? Icons.visibility_off_outlined
                                : Icons.visibility_outlined),
                            tooltip:
                                _obscurePassword ? 'Show password' : 'Hide password',
                            onPressed: () => setState(
                                () => _obscurePassword = !_obscurePassword),
                          ),
                        ),
                        validator: _validateNewPassword,
                      ),
                      const SizedBox(height: 20),
                      TextFormField(
                        controller: _confirmController,
                        obscureText: _obscurePassword,
                        decoration: const InputDecoration(
                          labelText: 'Confirm new password',
                          prefixIcon: Icon(Icons.lock_outline),
                        ),
                        validator: (value) => value != _newController.text
                            ? 'Passwords do not match'
                            : null,
                      ),
                      const SizedBox(height: 32),
                      ElevatedButton(
                        onPressed: isLoading ? null : _submit,
                        child: isLoading
                            ? const SizedBox(
                                height: 20,
                                width: 20,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: Colors.white,
                                ),
                              )
                            : const Text('Reset password'),
                      ),
                      const SizedBox(height: 12),
                      TextButton(
                        onPressed:
                            isLoading ? null : () => context.go('/login'),
                        child: const Text('Back to sign in'),
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
