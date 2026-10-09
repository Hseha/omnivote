import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_shape.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/error_message.dart';
import '../../../core/utils/safe_json.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_text_field.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_skeleton.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidacy_application_model.dart';
import '../../../data/models/position_model.dart';
import '../../auth/providers/auth_provider.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../providers/candidacy_provider.dart';

/// Apply for, edit, or withdraw a candidacy (docs/03_APP_FLOW.md step 6).
///
/// The screen adapts to the caller's situation:
///   - no application       -> apply form (POST /api/candidate/apply)
///   - pending / approved   -> overview card with Edit + Withdraw
///   - rejected / withdrawn -> apply again (both are re-eligible)
///
/// Edits go to PUT /api/candidate/apply and withdrawals to
/// POST /api/candidate/withdraw; both are allowed until polls close and the
/// position is locked server-side once the application is approved.
class CandidacyApplyScreen extends ConsumerStatefulWidget {
  const CandidacyApplyScreen({super.key});

  @override
  ConsumerState<CandidacyApplyScreen> createState() =>
      _CandidacyApplyScreenState();
}

class _CandidacyApplyScreenState extends ConsumerState<CandidacyApplyScreen> {
  final _formKey = GlobalKey<FormState>();
  String? _positionId;
  final _partyController = TextEditingController();
  final _sloganController = TextEditingController();
  final _platformController = TextEditingController();
  bool _certify = false;
  String? _applicationStatus;
  CandidacyApplication? _application;
  bool _editing = false;

  Uint8List? _photoBytes;
  String? _photoName;
  String? _photoError;

  @override
  void dispose() {
    _partyController.dispose();
    _sloganController.dispose();
    _platformController.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    _loadApplication();
  }

  /// Loads the authoritative application (GET /api/candidacy/me) and prefills
  /// the form when one exists so edits start from the current campaign.
  Future<void> _loadApplication() async {
    final application =
        await ref.read(candidacyProvider.notifier).myApplication();
    if (!mounted) return;
    setState(() {
      _application = application;
      if (application != null) {
        _applicationStatus = application.status;
        _positionId = application.positionId.isEmpty
            ? null
            : application.positionId;
        _partyController.text = application.partyName ?? '';
        _sloganController.text = application.slogan ?? '';
        _platformController.text = application.platformStatement;
      }
    });
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || !_certify) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Please complete the form and certify your application.',
          ),
          backgroundColor: AppColors.errorRed,
        ),
      );
      return;
    }

    final notifier = ref.read(candidacyProvider.notifier);
    final wasEditing = _editing;
    final success = wasEditing
        ? await notifier.update(
            positionId: _positionId!,
            slogan: _sloganController.text,
            platformStatement: _platformController.text.trim(),
            partyName: _partyController.text.trim().isEmpty
                ? null
                : _partyController.text.trim(),
            photoBytes: _photoBytes,
            photoName: _photoName,
          )
        : await notifier.submit(
            positionId: _positionId!,
            slogan: _sloganController.text,
            platformStatement: _platformController.text.trim(),
            partyName: _partyController.text.trim().isEmpty
                ? null
                : _partyController.text.trim(),
            photoBytes: _photoBytes,
            photoName: _photoName,
          );

    if (success && mounted) {
      setState(() => _editing = false);
      await _loadApplication();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            wasEditing
                ? 'Changes saved.'
                : 'Application submitted for review.',
          ),
          backgroundColor: wasEditing ? null : AppColors.successGreen,
        ),
      );
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            ref.read(candidacyProvider).errorMessage ?? 'Request failed.',
          ),
          backgroundColor: AppColors.errorRed,
        ),
      );
    }
  }

  Future<void> _confirmWithdraw() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Withdraw your application?'),
        content: const Text(
          'You will stop appearing as a candidate. You can re-apply any '
          'time before voting closes.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('Keep running'),
          ),
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: Text(
              'Withdraw',
              style: TextStyle(
                color: Theme.of(dialogContext).colorScheme.error,
              ),
            ),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    final success = await ref.read(candidacyProvider.notifier).withdraw();
    if (success && mounted) {
      setState(() => _editing = false);
      await _loadApplication();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Application withdrawn.')),
      );
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Could not withdraw the application right now.'),
          backgroundColor: AppColors.errorRed,
        ),
      );
    }
  }

  /// Re-enter the apply flow (used by rejected / withdrawn status views).
  void _applyAgain() {
    setState(() {
      _editing = false;
      _applicationStatus = null;
      _application = null;
    });
  }

  Future<void> _pickPhoto() async {
    final picker = ImagePicker();
    final picked = await picker.pickImage(
      source: ImageSource.gallery,
      maxWidth: 1080,
      imageQuality: 85,
    );
    if (picked == null || !mounted) return;

    // Mirror the backend rule (CandidateApplicationRequest): jpg/jpeg/png,
    // up to 5 MB. Downscale + quality above already keep real photos small.
    final ext = _extensionOf(picked.name);
    if (ext == null) {
      setState(() => _photoError = 'Choose a .jpg, .jpeg or .png image.');
      return;
    }
    if (await picked.length() > 5 * 1024 * 1024) {
      setState(() => _photoError = 'Photo must be 5 MB or smaller.');
      return;
    }

    final bytes = await picked.readAsBytes();
    if (!mounted) return;
    setState(() {
      _photoBytes = bytes;
      _photoName = picked.name;
      _photoError = null;
    });
  }

  static String? _extensionOf(String name) {
    final lower = name.toLowerCase();
    if (lower.endsWith('.jpg') || lower.endsWith('.jpeg')) return 'jpeg';
    if (lower.endsWith('.png')) return 'png';
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final positionsAsync = ref.watch(positionsProvider);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Apply for Candidacy'),
      body: positionsAsync.when(
        data: (positions) {
          final status = _applicationStatus;
          if (status != null && !_editing) {
            switch (status) {
              case 'pending':
              case 'approved':
                return _OverviewView(
                  status: status,
                  positionLabel: _labelOf(positions, _positionId),
                  application: _application,
                  onEdit: () => setState(() => _editing = true),
                  onWithdraw: _confirmWithdraw,
                );
              case 'rejected':
              case 'withdrawn':
                return _StatusView(
                  status: status,
                  actionLabel: 'Apply Again',
                  onAction: _applyAgain,
                );
              default:
                return _buildForm(positions);
            }
          }
          return _buildForm(positions);
        },
        loading: () => ListView(
          padding: AppSpacing.screenPadding,
          children: [
            LoadingSkeleton.lines(count: 4),
            AppSpacing.vMd,
            LoadingSkeleton.card(height: 48),
          ],
        ),
        error: (err, stack) => ErrorState(
          message: apiErrorMessage(err, fallback: 'Could not load positions.'),
          onRetry: () => ref.invalidate(positionsProvider),
        ),
      ),
    );
  }

  /// The apply / edit form. Editing cancels back to the overview; applying
  /// keeps the "Cancel and go back to Dashboard" escape hatch.
  Widget _buildForm(List<Position> positions) {
    final student = ref.watch(authProvider.select((state) => state.student));
    final positionLocked = _application?.status == 'approved';

    return ListView(
      padding: AppSpacing.screenPadding,
      children: [
        if (_editing) ...[
          Text(
            'Editing your campaign',
            style: AppTextStyles.of(context).titleMedium,
          ),
          AppSpacing.vSm,
          Text(
            positionLocked
                ? 'Your position is locked because your application is '
                    'already approved; other details remain editable.'
                : 'Keep your details up to date before voting closes.',
            style: AppTextStyles.of(context).bodySmall,
          ),
          AppSpacing.vMd,
        ],
        Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _readOnlyField(context, 'Full Name', student?.name ?? '—'),
              _readOnlyField(context, 'Email', student?.email ?? '—'),
              _readOnlyField(context, 'Student ID', student?.studentId ?? '—'),
              AppSpacing.vMd,
              DropdownButtonFormField<String>(
                initialValue: _positionId,
                decoration: const InputDecoration(
                  labelText: 'Position Running For *',
                ),
                items: positions
                    .map(
                      (p) => DropdownMenuItem<String>(
                        value: p.id,
                        child: Text('${p.label} (${_tierLabel(p)})'),
                      ),
                    )
                    .toList(),
                onChanged: positionLocked
                    ? null
                    : (v) => setState(() => _positionId = v),
                validator: (v) => v == null ? 'Select a position' : null,
              ),
              if (positionLocked) ...[
                AppSpacing.vSm,
                Text(
                  'Position locked after approval.',
                  style: AppTextStyles.of(context).labelSmall.copyWith(
                        color: Theme.of(context).colorScheme.primary,
                      ),
                ),
              ],
              AppSpacing.vMd,
              AppTextField(
                controller: _partyController,
                label: 'Party / Platform Name',
              ),
              AppTextField.fieldGap,
              AppTextField(
                controller: _sloganController,
                label: 'Campaign Slogan',
              ),
              AppTextField.fieldGap,
              _photoSection(),
              AppTextField.fieldGap,
              AppTextField(
                controller: _platformController,
                maxLines: 6,
                maxLength: 5000,
                alignLabelWithHint: true,
                label: 'Campaign Platform / Statement *',
                validator: (v) => (v == null || v.trim().isEmpty)
                    ? 'Campaign platform is required'
                    : null,
              ),
              AppSpacing.vSm,
              CheckboxListTile(
                value: _certify,
                contentPadding: EdgeInsets.zero,
                onChanged: (v) => setState(() => _certify = v ?? false),
                title: Text(
                  'I certify that everything on this application is true and that I meet the eligibility requirements.',
                  style: AppTextStyles.of(context).bodySmall,
                ),
                controlAffinity: ListTileControlAffinity.leading,
              ),
              AppSpacing.vLg,
              Consumer(
                builder: (context, ref, child) {
                  final isSubmitting = ref.watch(
                    candidacyProvider.select((s) => s.isSubmitting),
                  );
                  return AppButton.primary(
                    label: _editing ? 'Save Changes' : 'Submit Application',
                    onPressed: isSubmitting ? null : _submit,
                    isLoading: isSubmitting,
                  );
                },
              ),
              AppSpacing.vMd,
              if (_editing)
                AppButton.text(
                  label: 'Cancel editing',
                  onPressed: () => setState(() => _editing = false),
                )
              else
                AppButton.text(
                  label: 'Cancel and go back to Dashboard',
                  onPressed: () => context.go('/dashboard'),
                ),
            ],
          ),
        ),
      ],
    );
  }

  static String? _labelOf(List<Position> positions, String? id) {
    if (id == null) return null;
    for (final p in positions) {
      if (p.id == id) return '${p.label} (${_tierLabel(p)})';
    }
    return null;
  }

  static String _tierLabel(Position p) =>
      p.tier == PositionTier.provincial ? 'Provincial' : 'National';

  Widget _photoSection() {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    final hasPhoto = _photoBytes != null;
    final existingPhotoUrl = safeHttpImageUrl(_application?.photoUrl);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Candidate Photo (${_editing ? 'tap to replace' : 'optional'})',
          style: appText.labelSmall.copyWith(
            fontWeight: FontWeight.w600,
          ),
        ),
        AppSpacing.vSm,
        Row(
          children: [
            ClipRRect(
              borderRadius: AppRadius.smAll,
              child: SizedBox(
                width: AppMetrics.avatarLg * 2,
                height: AppMetrics.avatarLg * 2,
                child: hasPhoto
                    ? Image.memory(_photoBytes!, fit: BoxFit.cover)
                    : existingPhotoUrl != null
                        ? Image.network(existingPhotoUrl, fit: BoxFit.cover)
                        : Container(
                            color: context.appSurface,
                            child: Icon(
                              Icons.person,
                              size: AppMetrics.avatarLg,
                              color: context.appTextSecondary,
                            ),
                          ),
              ),
            ),
            AppSpacing.hSm,
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  InkWell(
                    onTap: _pickPhoto,
                    borderRadius: AppRadius.smAll,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        vertical: AppSpacing.sm,
                      ),
                      child: Text(
                        hasPhoto ? 'Change photo' : 'Choose from gallery',
                        style: appText.titleSmall.copyWith(
                          color: scheme.primary,
                        ),
                      ),
                    ),
                  ),
                  if (hasPhoto) ...[
                    Text(
                      _photoName ?? 'photo',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: appText.bodySmall,
                    ),
                    InkWell(
                      onTap: () => setState(() {
                        _photoBytes = null;
                        _photoName = null;
                        _photoError = null;
                      }),
                      borderRadius: AppRadius.smAll,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          vertical: AppSpacing.sm,
                        ),
                        child: Text(
                          'Remove photo',
                          style: appText.bodyMedium.copyWith(
                            color: scheme.error,
                          ),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
        if (_photoError != null) ...[
          AppSpacing.vSm,
          Text(
            _photoError!,
            style: appText.labelSmall.copyWith(color: scheme.error),
          ),
        ],
      ],
    );
  }

  Widget _readOnlyField(BuildContext context, String label, String value) {
    final appText = AppTextStyles.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: appText.labelSmall.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
          AppSpacing.vXs,
          Text(
            value,
            style: appText.titleMedium,
          ),
        ],
      ),
    );
  }
}

class _StatusView extends StatelessWidget {
  final String status;
  final String? actionLabel;
  final VoidCallback? onAction;

  const _StatusView({
    required this.status,
    this.actionLabel,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    final color = status == 'approved'
        ? AppColors.successGreen
        : status == 'rejected'
            ? scheme.error
            : scheme.primary;
    final icon = status == 'approved'
        ? Icons.check_circle
        : status == 'rejected'
            ? Icons.cancel
            : status == 'withdrawn'
                ? Icons.person_off_outlined
                : Icons.hourglass_top;

    final message = switch (status) {
      'rejected' => 'Your application was not approved by the committee. '
          'You can submit a new application below.',
      'withdrawn' => 'You have withdrawn from running. If you change your '
          'mind, you can re-apply any time before voting closes.',
      _ => 'If approved, you will appear on the Candidates list once the '
          'election committee publishes approvals.',
    };

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 72, color: color),
            AppSpacing.vMd,
            Text(
              'Application status: ${status.toUpperCase()}',
              style: appText.headlineMedium,
            ),
            AppSpacing.vSm,
            Text(
              message,
              textAlign: TextAlign.center,
              style: appText.bodySmall,
            ),
            AppSpacing.vLg,
            AppButton.primary(
              label: actionLabel ?? 'Back to Dashboard',
              onPressed: onAction ?? () => context.go('/dashboard'),
            ),
          ],
        ),
      ),
    );
  }
}

/// Manage an active (pending or approved) application: summary card plus
/// edit / withdraw actions.
class _OverviewView extends StatelessWidget {
  final String status;
  final String? positionLabel;
  final CandidacyApplication? application;
  final VoidCallback onEdit;
  final VoidCallback onWithdraw;

  const _OverviewView({
    required this.status,
    required this.positionLabel,
    required this.application,
    required this.onEdit,
    required this.onWithdraw,
  });

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    final approved = status == 'approved';
    final color = approved ? AppColors.successGreen : scheme.primary;
    final icon = approved ? Icons.check_circle : Icons.hourglass_top;
    final badge = approved
        ? 'APPROVED'
        : 'PENDING REVIEW';
    final message = approved
        ? 'You are running for office. Your position is locked, but you can '
            'still update your slogan, party, platform and photo.'
        : 'Your application is under review. You can edit the details or '
            'withdraw at any time before voting closes.';

    return ListView(
      padding: AppSpacing.screenPadding,
      children: [
        Container(
          padding: const EdgeInsets.all(AppSpacing.lg),
          decoration: BoxDecoration(
            color: context.appSurface,
            borderRadius: AppRadius.mdAll,
            border: Border.all(color: color.withValues(alpha: 0.4)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Icon(icon, color: color, size: 28),
                  AppSpacing.hSm,
                  Expanded(
                    child: Text(
                      badge,
                      style: appText.titleMedium.copyWith(
                        color: color,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 1.1,
                      ),
                    ),
                  ),
                ],
              ),
              AppSpacing.vMd,
              Text(message, style: appText.bodySmall),
            ],
          ),
        ),
        AppSpacing.vLg,
        ..._summaryRows(context, positionLabel),
        AppSpacing.vLg,
        AppButton.primary(
          label: 'Edit Campaign',
          onPressed: onEdit,
          icon: Icons.edit_outlined,
        ),
        AppSpacing.vMd,
        TextButton(
          onPressed: onWithdraw,
          style: TextButton.styleFrom(
            foregroundColor: scheme.error,
            padding: const EdgeInsets.symmetric(vertical: 12),
            shape: RoundedRectangleBorder(
              borderRadius: AppRadius.smAll,
            ),
          ),
          child: const Text('Withdraw application'),
        ),
        AppSpacing.vMd,
        AppButton.text(
          label: 'Back to Dashboard',
          onPressed: () => context.go('/dashboard'),
        ),
      ],
    );
  }

  List<Widget> _summaryRows(BuildContext context, String? positionLabel) {
    bool hasText(String? value) => value != null && value.isNotEmpty;

    final app = application;
    final rows = <(String, String)>[
      if (positionLabel != null) ('Position', positionLabel),
      if (hasText(app?.partyName)) ('Party', app?.partyName ?? ''),
      if (hasText(app?.slogan)) ('Slogan', app?.slogan ?? ''),
      if (hasText(app?.platformStatement)) ('Platform', app?.platformStatement ?? ''),
    ];
    return [
      for (final row in rows) _readOnlyRow(context, row.$1, row.$2),
    ];
  }

  Widget _readOnlyRow(BuildContext context, String label, String value) {
    final appText = AppTextStyles.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: appText.labelSmall.copyWith(fontWeight: FontWeight.w600),
          ),
          AppSpacing.vXs,
          Text(value, style: appText.titleMedium),
        ],
      ),
    );
  }
}
