import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_tokens.dart';
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
  bool _obscurePassword = true;
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
    final branding = ref.watch(
      brandingProvider.select((state) => state.valueOrNull ?? const Branding()),
    );

    return Scaffold(
      backgroundColor: context.appBackground,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
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
                      tint: branding.primaryColor,
                    ),
                  ),
                  const SizedBox(height: 18),
                  Text(
                    branding.siteName,
                    textAlign: TextAlign.center,
                    style: appText.pageTitle.copyWith(fontSize: 30),
                  ),
                  const SizedBox(height: 10),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 6,
                    ),
                    decoration: BoxDecoration(
                      color: context.appTagBg,
                      borderRadius: BorderRadius.circular(999),
                      border: Border.all(
                        color: branding.primaryColor.withValues(alpha: 0.25),
                      ),
                    ),
                    child: Text('STUDENT PORTAL', style: appText.tag),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'Sign in with your school-issued email or username to '
                    'access your ballot, candidates and results.',
                    textAlign: TextAlign.center,
                    style: appText.secondary,
                  ),
                  const SizedBox(height: 24),

                  // ---- Credentials card ----
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
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
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: Theme.of(context)
                                      .colorScheme
                                      .errorContainer,
                                  borderRadius: BorderRadius.circular(8),
                                  border: Border.all(
                                    color: Theme.of(context)
                                        .colorScheme
                                        .onErrorContainer
                                        .withValues(alpha: 0.4),
                                  ),
                                ),
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Icon(
                                      Icons.error_outline,
                                      size: 20,
                                      color: AppColors.errorRed,
                                    ),
                                    const SizedBox(width: 10),
                                    Expanded(
                                      child: Text(
                                        _errorMessage!,
                                        style: appText.body.copyWith(
                                          color: Theme.of(context)
                                              .colorScheme
                                              .onErrorContainer,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(height: 20),
                            ],
                            TextFormField(
                              controller: _emailController,
                              onChanged: (_) => _clearError(),
                              decoration: const InputDecoration(
                                labelText: 'Email / Username',
                                prefixIcon: Icon(Icons.mail_outline),
                              ),
                              keyboardType: TextInputType.text,
                              textInputAction: TextInputAction.next,
                              autofillHints: const [AutofillHints.username],
                              validator: (value) =>
                                  value == null || value.trim().isEmpty
                                      ? 'Please enter your email or username'
                                      : null,
                            ),
                            const SizedBox(height: 16),
                            TextFormField(
                              controller: _passwordController,
                              onChanged: (_) => _clearError(),
                              obscureText: _obscurePassword,
                              decoration: InputDecoration(
                                labelText: 'Password',
                                prefixIcon: const Icon(Icons.lock_outline),
                                suffixIcon: IconButton(
                                  icon: Icon(
                                    _obscurePassword
                                        ? Icons.visibility_off_outlined
                                        : Icons.visibility_outlined,
                                    color: context.appTextSecondary,
                                  ),
                                  tooltip: _obscurePassword
                                      ? 'Show password'
                                      : 'Hide password',
                                  onPressed: () => setState(
                                    () => _obscurePassword = !_obscurePassword,
                                  ),
                                ),
                              ),
                              textInputAction: TextInputAction.done,
                              autofillHints: const [AutofillHints.password],
                              onFieldSubmitted: (_) {
                                if (!isLoading) _submit();
                              },
                              validator: (value) =>
                                  value == null || value.isEmpty
                                      ? 'Please enter your password'
                                      : null,
                            ),
                            const SizedBox(height: 8),
                            Row(
                              children: [
                                Checkbox(
                                  value: _rememberMe,
                                  onChanged: (value) => setState(
                                    () => _rememberMe = value ?? false,
                                  ),
                                ),
                                GestureDetector(
                                  onTap: () => setState(
                                    () => _rememberMe = !_rememberMe,
                                  ),
                                  child: const Text('Remember me'),
                                ),
                              ],
                            ),
                            const SizedBox(height: 16),
                            SizedBox(
                              height: 52,
                              child: ElevatedButton(
                                onPressed: isLoading ? null : _submit,
                                child: isLoading
                                    ? const Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          SizedBox(
                                            height: 18,
                                            width: 18,
                                            child: CircularProgressIndicator(
                                              strokeWidth: 2,
                                              color: Colors.white,
                                            ),
                                          ),
                                          SizedBox(width: 12),
                                          Text('Logging in...'),
                                        ],
                                      )
                                    : const Text('Log In'),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Align(
                    alignment: Alignment.center,
                    child: TextButton.icon(
                      onPressed: isLoading
                          ? null
                          : () => context.push('/recover-password'),
                      icon: const Icon(Icons.help_outline, size: 18),
                      label: const Text('Forgot your password?'),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    "Don't have access? Student accounts are created by your "
                    'school registrar — contact the administration office '
                    'if you need one.',
                    style: appText.secondary.copyWith(fontSize: 13),
                    textAlign: TextAlign.center,
                  ),
                  if (branding.footerText.isNotEmpty) ...[
                    const SizedBox(height: 24),
                    Text(
                      branding.footerText,
                      style: appText.secondary.copyWith(fontSize: 12),
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
