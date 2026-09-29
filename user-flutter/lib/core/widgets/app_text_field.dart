import 'package:flutter/material.dart';

import '../theme/app_shape.dart';
import '../theme/app_spacing.dart';

/// The single text-field primitive. Wraps [TextFormField] with the app-wide
/// decoration (filled surface, 8-pt radius, token borders from the theme's
/// `inputDecorationTheme`, accent focus border) plus:
/// - consistent label/hint/helper wiring with inline validator error text
///   (never just a red border — the error string always renders below);
/// - an optional password mode with a visibility toggle that carries a
///   [Semantics] label for screen readers and icon-only-button naming;
/// - standard vertical rhythm via [fieldGap] between stacked fields.
class AppTextField extends StatefulWidget {
  final TextEditingController? controller;
  final String? label;
  final String? hint;
  final String? helper;
  final String? Function(String?)? validator;
  final TextInputType keyboardType;
  final TextInputAction textInputAction;
  final bool obscure;
  final bool enabled;
  final int maxLines;
  final Widget? prefixIcon;
  final Widget? suffixIcon;
  final void Function(String)? onChanged;
  final void Function(String)? onSubmitted;
  final bool autocorrect;
  final int? maxLength;
  final Iterable<String>? autofillHints;
  final TextCapitalization textCapitalization;

  /// Aligns the label with the hint (top) on multiline fields instead of
  /// centering it.
  final bool alignLabelWithHint;

  /// Noun used by the visibility toggle ("password" → "Show password",
  /// "code" → "Show code"). Keeps the toggle's tooltip and Semantics label
  /// accurate on non-password obscured fields like activation codes.
  final String toggleLabel;

  const AppTextField({
    super.key,
    this.controller,
    this.label,
    this.hint,
    this.helper,
    this.validator,
    this.keyboardType = TextInputType.text,
    this.textInputAction = TextInputAction.next,
    this.obscure = false,
    this.enabled = true,
    this.maxLines = 1,
    this.prefixIcon,
    this.suffixIcon,
    this.onChanged,
    this.onSubmitted,
    this.autocorrect = true,
    this.maxLength,
    this.autofillHints,
    this.textCapitalization = TextCapitalization.none,
    this.toggleLabel = 'password',
    this.alignLabelWithHint = false,
  });

  /// Standard gap between stacked fields on a form.
  static const Widget fieldGap = AppSpacing.vMd;

  @override
  State<AppTextField> createState() => _AppTextFieldState();
}

class _AppTextFieldState extends State<AppTextField> {
  bool _revealed = false;

  @override
  Widget build(BuildContext context) {
    final showToggle = widget.obscure && widget.maxLines == 1;
    final hidden = widget.obscure && !_revealed;

    return TextFormField(
      controller: widget.controller,
      validator: widget.validator,
      keyboardType: widget.keyboardType,
      textInputAction: widget.textInputAction,
      textCapitalization: widget.textCapitalization,
      autofillHints: widget.autofillHints,
      obscureText: hidden,
      autocorrect: widget.autocorrect && !hidden,
      enableSuggestions: widget.autocorrect && !hidden,
      enabled: widget.enabled,
      maxLines: widget.maxLines,
      maxLength: widget.maxLength,
      onChanged: widget.onChanged,
      onFieldSubmitted: widget.onSubmitted,
      decoration: InputDecoration(
        labelText: widget.label,
        hintText: widget.hint,
        helperText: widget.helper,
        helperMaxLines: 3,
        errorMaxLines: 3,
        alignLabelWithHint: widget.alignLabelWithHint,
        prefixIcon: widget.prefixIcon,
        suffixIcon: showToggle
            ? Semantics(
                button: true,
                label: _revealed
                    ? 'Hide ${widget.toggleLabel}'
                    : 'Show ${widget.toggleLabel}',
                child: IconButton(
                  tooltip: _revealed
                      ? 'Hide ${widget.toggleLabel}'
                      : 'Show ${widget.toggleLabel}',
                  icon: Icon(
                    _revealed ? Icons.visibility_off : Icons.visibility,
                  ),
                  onPressed: () =>
                      setState(() => _revealed = !_revealed),
                ),
              )
            : widget.suffixIcon,
        border: OutlineInputBorder(borderRadius: AppRadius.smAll),
      ),
    );
  }
}
