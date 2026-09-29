import 'package:flutter/material.dart';

import '../theme/app_shape.dart';

/// The single button primitive: primary / secondary / text variants with
/// built-in loading and disabled states.
///
/// - [AppButton.primary]: full-width filled CTA (vote, submit, log in).
/// - [AppButton.secondary]: tonal button for adjacent alternatives.
/// - [AppButton.text]: inline text action.
/// - [isLoading]: swaps the label for a spinner and disables the button, so
///   every form gets "disabled while a request is in flight" for free.
/// - Buttons keep a 48-pt minimum height ([AppMetrics.minTapTarget]).
class AppButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool isLoading;
  final bool expands;
  final _Variant _variant;

  const AppButton.primary({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.isLoading = false,
    this.expands = true,
  }) : _variant = _Variant.primary;

  const AppButton.secondary({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.isLoading = false,
    this.expands = true,
  }) : _variant = _Variant.secondary;

  const AppButton.text({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.isLoading = false,
    this.expands = false,
  }) : _variant = _Variant.text;

  bool get _disabled => onPressed == null || isLoading;

  Widget _label(BuildContext context) {
    if (!isLoading) return Text(label);
    // Spinner + label (not spinner alone) so the in-flight action stays
    // named — e.g. "Logging in…" rather than a bare spinner.
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox.square(
          dimension: 20,
          child: CircularProgressIndicator(
            strokeWidth: 2.5,
            color: _variant == _Variant.primary
                ? Colors.white
                : Theme.of(context).colorScheme.primary,
          ),
        ),
        const SizedBox(width: 12),
        Text(label),
      ],
    );
  }

  ButtonStyle _style(BuildContext context) {
    final shape = RoundedRectangleBorder(borderRadius: AppRadius.smAll);
    const padding = EdgeInsets.symmetric(vertical: 12);
    switch (_variant) {
      case _Variant.primary:
        return FilledButton.styleFrom(
          minimumSize: const Size(64, 48),
          shape: shape,
          padding: padding,
        );
      case _Variant.secondary:
        return FilledButton.styleFrom(
          minimumSize: const Size(64, 48),
          shape: shape,
          padding: padding,
        );
      case _Variant.text:
        return TextButton.styleFrom(shape: shape, padding: padding);
    }
  }

  @override
  Widget build(BuildContext context) {
    final VoidCallback? action = _disabled ? null : onPressed;
    final Widget button;
    switch (_variant) {
      case _Variant.primary:
        button = icon != null
            ? FilledButton.icon(
                onPressed: action,
                icon: _ButtonIcon(icon: icon!, loading: isLoading),
                label: _label(context),
                style: _style(context),
              )
            : FilledButton(
                onPressed: action,
                style: _style(context),
                child: _label(context),
              );
      case _Variant.secondary:
        button = icon != null
            ? FilledButton.tonalIcon(
                onPressed: action,
                icon: _ButtonIcon(icon: icon!, loading: isLoading),
                label: _label(context),
                style: _style(context),
              )
            : FilledButton.tonal(
                onPressed: action,
                style: _style(context),
                child: _label(context),
              );
      case _Variant.text:
        button = TextButton(
          onPressed: action,
          style: _style(context),
          child: _label(context),
        );
    }
    if (!expands) return button;
    return SizedBox(width: double.infinity, child: button);
  }
}

enum _Variant { primary, secondary, text }

/// Hides the icon while loading so it never crowds the spinner.
class _ButtonIcon extends StatelessWidget {
  final IconData icon;
  final bool loading;

  const _ButtonIcon({required this.icon, required this.loading});

  @override
  Widget build(BuildContext context) {
    if (loading) return const SizedBox.shrink();
    return Icon(icon);
  }
}
