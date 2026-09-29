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
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_text_field.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_skeleton.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/position_model.dart';
import '../../auth/providers/auth_provider.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../providers/candidacy_provider.dart';

/// Apply for Candidacy form (docs/03_APP_FLOW.md step 6). Submits to
/// POST /api/candidate/apply (registration phase only) and reflects the
/// pending-review status returned by GET /api/candidacy/me.
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
    _loadStatus();
  }

  Future<void> _loadStatus() async {
    final status =
        await ref.read(candidacyProvider.notifier).applicationStatus();
    if (mounted && status != null && status != 'none') {
      setState(() => _applicationStatus = status);
    }
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

    final success = await ref.read(candidacyProvider.notifier).submit(
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
      setState(() => _applicationStatus = 'pending');
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Application submitted for review.')),
      );
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            ref.read(candidacyProvider).errorMessage ?? 'Application failed.',
          ),
          backgroundColor: AppColors.errorRed,
        ),
      );
    }
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
    final student = ref.watch(authProvider.select((state) => state.student));
    final positionsAsync = ref.watch(positionsProvider);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Apply for Candidacy'),
      body: positionsAsync.when(
        data: (positions) {
          if (_applicationStatus != null) {
            return _StatusView(status: _applicationStatus!);
          }
          return ListView(
            padding: AppSpacing.screenPadding,
            children: [
              Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _readOnlyField(context, 'Full Name', student?.name ?? '—'),
                    _readOnlyField(context, 'Email', student?.email ?? '—'),
                    _readOnlyField(
                        context, 'Student ID', student?.studentId ?? '—'),
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
                      onChanged: (v) => setState(() => _positionId = v),
                      validator: (v) => v == null ? 'Select a position' : null,
                    ),
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
                          label: 'Submit Application',
                          onPressed: isSubmitting ? null : _submit,
                          isLoading: isSubmitting,
                        );
                      },
                    ),
                    AppSpacing.vMd,
                    AppButton.text(
                      label: 'Cancel and go back to Dashboard',
                      onPressed: () => context.go('/dashboard'),
                    ),
                  ],
                ),
              ),
            ],
          );
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

  static String _tierLabel(Position p) =>
      p.tier == PositionTier.provincial ? 'Provincial' : 'National';

  Widget _photoSection() {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;
    final hasPhoto = _photoBytes != null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Candidate Photo (optional)',
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

  const _StatusView({required this.status});

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
            : Icons.hourglass_top;

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
              'If approved, you will appear on the Candidates list once the election committee publishes approvals.',
              textAlign: TextAlign.center,
              style: appText.bodySmall,
            ),
            AppSpacing.vLg,
            AppButton.primary(
              label: 'Back to Dashboard',
              onPressed: () => context.go('/dashboard'),
            ),
          ],
        ),
      ),
    );
  }
}
