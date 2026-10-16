import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/app_text_field.dart';
import '../../auth/providers/auth_provider.dart';

/// Redeems the SSG President's one-time setup link and sets a password.
///
/// Reached by tapping the "SSG President access" notification, which carries
/// the one-time token as `/ssg-setup?token=...`. The President is already
/// signed in, so the endpoint is authenticated as well as token-bound; the
/// token is the secret, not the session. No password is ever minted server-side
/// or sent by email — the President chooses their own here, and the link is
/// burned on first use.
class SsgSetupScreen extends ConsumerStatefulWidget {
  const SsgSetupScreen({super.key});

  @override
  ConsumerState<SsgSetupScreen> createState() => _SsgSetupScreenState();
}

class _SsgSetupScreenState extends ConsumerState<SsgSetupScreen> {
  final _formKey = GlobalKey<FormState>();
  final _newController = TextEditingController();
  final _confirmController = TextEditingController();

  @override
  void dispose() {
    _newController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  String? _validateNewPassword(String? value) {
    final password = value ?? '';
    if (password.isEmpty) return 'Please enter a new password';
    if (password.length < 8) return 'Use at least 8 characters';
    if (!RegExp(r'[a-z]').hasMatch(password)) {
      return 'Include a lowercase letter';
    }
    if (!RegExp(r'[A-Z]').hasMatch(password)) {
      return 'Include an uppercase letter';
    }
    if (!RegExp(r'[0-9]').hasMatch(password)) {
      return 'Include a number';
    }
    return null;
  }

  void _leave() {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go('/ssg-president');
    }
  }

  Future<void> _submit(String token) async {
    if (!_formKey.currentState!.validate()) return;

    final success = await ref
        .read(authProvider.notifier)
        .setupSsgPassword(token: token, newPassword: _newController.text);

    if (!mounted) return;
    if (success) {
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Your password is set.')));
      _leave();
    } else {
      final error =
          ref.read(authProvider).errorMessage ??
          'Could not set your password. The link may have expired.';
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(error)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final token = GoRouterState.of(context).uri.queryParameters['token'] ?? '';
    final isLoading = ref.watch(authProvider.select((s) => s.isLoading));
    final scheme = Theme.of(context).colorScheme;
    final appText = AppTextStyles.of(context);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: AppBar(
        backgroundColor: context.appSurface,
        surfaceTintColor: context.appSurface,
        elevation: 0,
        title: const Text('Set your password'),
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: AppSpacing.screenPadding,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 400),
            child: AppCard(
              padding: const EdgeInsets.all(AppSpacing.xl),
              child: token.isEmpty
                  ? _missingToken(scheme, appText)
                  : _form(token, isLoading, scheme, appText),
            ),
          ),
        ),
      ),
    );
  }

  Widget _missingToken(ColorScheme scheme, AppTextStyles appText) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Icon(Icons.link_off, size: 48, color: scheme.error),
        AppSpacing.vMd,
        Text(
          'Open the setup link',
          style: appText.headlineSmall,
          textAlign: TextAlign.center,
        ),
        AppSpacing.vSm,
        Text(
          'This screen has no setup link. Open your notifications and tap the '
          '"SSG President access" message to set your password.',
          style: appText.bodySmall,
          textAlign: TextAlign.center,
        ),
        AppSpacing.vLg,
        AppButton.primary(label: 'Done', onPressed: _leave),
      ],
    );
  }

  Widget _form(
    String token,
    bool isLoading,
    ColorScheme scheme,
    AppTextStyles appText,
  ) {
    return Form(
      key: _formKey,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Icon(Icons.verified_user, size: 48, color: scheme.primary),
          AppSpacing.vMd,
          Text(
            'Choose your password',
            style: appText.headlineSmall,
            textAlign: TextAlign.center,
          ),
          AppSpacing.vSm,
          Text(
            'You were granted SSG President access. Set a password for your '
            'account. This setup link works once and then expires.',
            style: appText.bodySmall,
            textAlign: TextAlign.center,
          ),
          AppSpacing.vLg,
          AppTextField(
            controller: _newController,
            obscure: true,
            label: 'New password',
            helper:
                'At least 8 characters, including uppercase, lowercase and '
                'a number.',
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
            validator: (value) =>
                value != _newController.text ? 'Passwords do not match' : null,
          ),
          AppSpacing.vLg,
          AppButton.primary(
            label: 'Set password',
            onPressed: isLoading ? null : () => _submit(token),
            isLoading: isLoading,
          ),
        ],
      ),
    );
  }
}
