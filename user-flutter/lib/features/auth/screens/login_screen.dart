import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/app_text_field.dart';
import '../../../core/widgets/brand_logo.dart';
import '../../../data/models/branding_model.dart';
import '../../../data/services/login_prefs.dart';
import '../../settings/providers/branding_provider.dart';
import '../providers/auth_provider.dart';

/// Student Portal sign-in.
///
/// Accounts are provisioned exclusively by the registrar import — there is no
/// self-registration path in this app. The previous "Sign Up" link and
/// `/register` route were removed as a security gap (anyone could mint a
/// student account while the registration phase was open).
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _rememberMe = false;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _prefillRemembered();
  }

  /// Fills the form from the stored "Remember me" pair (if any) so a
  /// returning user can sign in with one tap. Never throws: a missing or
  /// unreadable entry simply leaves the form blank.
  Future<void> _prefillRemembered() async {
    final prefs = ref.read(loginPrefsProvider);
    final saved = await prefs.load();
    if (!mounted || saved == null) return;
    setState(() {
      _emailController.text = saved.email;
      _passwordController.text = saved.password;
      _rememberMe = true;
    });
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _errorMessage = null);

    final email = _emailController.text.trim();
    final password = _passwordController.text;
    // Captured before awaiting: navigation can dispose this State as soon as
    // the auth listener fires, and `ref` must not be used after that.
    final prefs = ref.read(loginPrefsProvider);

    final success = await ref
        .read(authProvider.notifier)
        .login(email: email, password: password);

    // Persist (or forget) the credentials for the next launch. Only a
    // successful login ever writes them, and unticking the box clears any
    // previously remembered pair.
    if (success) {
      if (_rememberMe) {
        await prefs.save(email: email, password: password);
      } else {
        await prefs.clear();
      }
    }

    // Navigation after a successful login is handled by the global auth
    // listener in app.dart (single navigation path — audit §2 #11).
    if (!mounted) return;
    if (!success) {
      setState(() {
        final authState = ref.read(authProvider);
        _errorMessage = authState.errorMessage ??
            'Login failed. Please check your email or username and password and try again.';

        // The backend deliberately answers a backed-off account with the same
        // "Invalid credentials" body as an unknown one, and reports the wait in
        // a `Retry-After` header. Appending that hint is what stops a student
        // who is genuinely rate-limited from thinking their password is wrong.
        final hint = backoffHint(authState.retryAfterSeconds);
        if (hint != null) {
          _errorMessage = '$_errorMessage\n$hint';
        }
      });
    }
  }

  void _clearError() {
    if (_errorMessage != null) {
      setState(() => _errorMessage = null);
    }
  }

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isLoading = ref.watch(
      authProvider.select((state) => state.isLoading),
    );
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    final branding = ref.watch(
      brandingProvider.select((state) => state.valueOrNull ?? const Branding()),
    );

    return Scaffold(
      backgroundColor: context.appBackground,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.lg,
              vertical: AppSpacing.xl,
            ),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // ---- Brand header: centered logo, wordmark, portal pill ----
                  Center(
                    child: BrandLogo(
                      logoUrl: branding.logoUrl,
                      size: 72,
                    ),
                  ),
                  AppSpacing.vMd,
                  Text(
                    branding.siteName,
                    textAlign: TextAlign.center,
                    style: appText.displaySmall,
                  ),
                  AppSpacing.vSm,
                  Center(
                    child: Container(
                      padding: AppMetrics.tagPadding,
                      decoration: BoxDecoration(
                        color: context.appTagBg,
                        borderRadius: AppRadius.xlAll,
                        border: Border.all(
                          color: scheme.primary.withValues(alpha: 0.25),
                        ),
                      ),
                      child: Text('STUDENT PORTAL', style: appText.tag),
                    ),
                  ),
                  AppSpacing.vMd,
                  Text(
                    'Sign in with your school-issued email or username to '
                    'access your ballot, candidates and results.',
                    textAlign: TextAlign.center,
                    style: appText.bodySmall,
                  ),
                  AppSpacing.vLg,

                  // ---- Credentials card ----
                  AppCard(
                    child: Form(
                      key: _formKey,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          if (_errorMessage != null) ...[
                            // Inline error banner: friendly and impossible
                            // to miss; cleared as soon as a field is edited.
                            Container(
                              padding: const EdgeInsets.all(
                                AppMetrics.rowPaddingV,
                              ),
                              decoration: BoxDecoration(
                                color: scheme.errorContainer,
                                borderRadius: AppRadius.smAll,
                                border: Border.all(
                                  color: scheme.onErrorContainer
                                      .withValues(alpha: 0.4),
                                ),
                              ),
                              child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Icon(
                                    Icons.error_outline,
                                    size: 20,
                                    color: scheme.error,
                                  ),
                                  AppSpacing.hSm,
                                  Expanded(
                                    child: Text(
                                      _errorMessage!,
                                      style: appText.bodyMedium.copyWith(
                                        color: scheme.onErrorContainer,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            AppSpacing.vMd,
                          ],
                          AppTextField(
                            controller: _emailController,
                            onChanged: (_) => _clearError(),
                            label: 'Email / Username',
                            prefixIcon: const Icon(Icons.mail_outline),
                            keyboardType: TextInputType.text,
                            textInputAction: TextInputAction.next,
                            autofillHints: const [AutofillHints.username],
                            validator: (value) =>
                                value == null || value.trim().isEmpty
                                    ? 'Please enter your email or username'
                                    : null,
                          ),
                          AppTextField.fieldGap,
                          AppTextField(
                            controller: _passwordController,
                            onChanged: (_) => _clearError(),
                            label: 'Password',
                            prefixIcon: const Icon(Icons.lock_outline),
                            obscure: true,
                            textInputAction: TextInputAction.done,
                            autofillHints: const [AutofillHints.password],
                            onSubmitted: (_) {
                              if (!isLoading) _submit();
                            },
                            validator: (value) =>
                                value == null || value.isEmpty
                                    ? 'Please enter your password'
                                    : null,
                          ),
                          AppSpacing.vSm,
                          InkWell(
                            onTap: () => setState(
                              () => _rememberMe = !_rememberMe,
                            ),
                            borderRadius: AppRadius.smAll,
                            child: Padding(
                              padding: const EdgeInsets.symmetric(
                                vertical: AppSpacing.xs,
                              ),
                              child: Row(
                                children: [
                                  Checkbox(
                                    value: _rememberMe,
                                    onChanged: (value) => setState(
                                      () => _rememberMe = value ?? false,
                                    ),
                                  ),
                                  const Text('Remember me'),
                                ],
                              ),
                            ),
                          ),
                          AppSpacing.vMd,
                          AppButton.primary(
                            label:
                                isLoading ? 'Logging in…' : 'Log In',
                            onPressed: isLoading ? null : _submit,
                            isLoading: isLoading,
                          ),
                        ],
                      ),
                    ),
                  ),
                  AppSpacing.vMd,
                  Align(
                    alignment: Alignment.center,
                    child: AppButton.text(
                      label: 'Forgot your password?',
                      icon: Icons.help_outline,
                      onPressed: isLoading
                          ? null
                          : () => context.push('/recover-password'),
                    ),
                  ),
                  AppSpacing.vSm,
                  Text(
                    "Don't have access? Student accounts are created by your "
                    'school registrar — contact the administration office '
                    'if you need one.',
                    style: appText.bodySmall,
                    textAlign: TextAlign.center,
                  ),
                  if (branding.footerText.isNotEmpty) ...[
                    AppSpacing.vLg,
                    Text(
                      branding.footerText,
                      style: appText.labelSmall,
                      textAlign: TextAlign.center,
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
