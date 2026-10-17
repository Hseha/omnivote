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
import '../../../data/models/election_status_model.dart';
import '../../../data/models/position_model.dart';
import '../../../data/repositories/candidacy_repository.dart';
import '../../auth/providers/auth_provider.dart';
import '../../candidates/providers/candidates_provider.dart';
import '../../dashboard/providers/election_status_provider.dart';
import '../../dashboard/widgets/election_countdown.dart';
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
  String? _partyName;
  PositionTier? _tier;
  final _sloganController = TextEditingController();
  final _platformController = TextEditingController();
  bool _certify = false;
  String? _applicationStatus;
  CandidacyApplication? _application;
  bool _editing = false;

  /// Whether the `?edit=1` deep-link intent has been honored. The query
  /// parameter stays in the URL for the screen's whole lifetime, so every
  /// later reload (after a successful save) would re-read it and bounce the
  /// user straight back into the edit form — the "Changes saved, then Save
  /// Changes again" loop. Consuming it once keeps reloads on the overview.
  bool _editIntentConsumed = false;

  Uint8List? _photoBytes;
  String? _photoName;
  String? _photoError;

  @override
  void dispose() {
    _sloganController.dispose();
    _platformController.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    _loadApplication();
  }

  /// `/candidacy?edit=1` (linked from the My Candidacy hub) opens the form
  /// straight in edit mode once an active application is confirmed below.
  bool get _wantsEdit =>
      (GoRouterState.of(context).uri.queryParameters['edit'] ?? '') == '1';

  /// Loads the authoritative application (GET /api/candidacy/me) and prefills
  /// the form when one exists so edits start from the current campaign.
  Future<void> _loadApplication() async {
    final application =
        await ref.read(candidacyProvider.notifier).myApplication();
    if (!mounted) return;
    final active = application != null &&
        (application.status == 'pending' || application.status == 'approved');
    // The ?edit=1 link is honored exactly once per screen instance: after a
    // successful save this reload runs with the same URL, and re-honoring it
    // would flip the form straight back into edit mode (the save loop).
    setState(() {
      // Only read the edit intent when an application exists: _wantsEdit
      // needs a GoRouter above the widget (absent in widget tests), and the
      // short-circuit below guarantees it is never touched otherwise.
      final firstLoadWithApplication =
          !_editIntentConsumed && application != null;
      final openForEdit = firstLoadWithApplication && _wantsEdit;
      _editIntentConsumed = true;
      _application = application;
      if (application != null) {
        _applicationStatus = application.status;
        _positionId = application.positionId.isEmpty
            ? null
            : application.positionId;
        _partyName = (application.partyName ?? '').trim().isEmpty
            ? null
            : application.partyName!.trim();
        _tier = application.position?.tier;
        _sloganController.text = application.slogan ?? '';
        _platformController.text = application.platformStatement;
        _editing = openForEdit && active;
      }
    });
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() ||
        !_certify ||
        _partyName == null ||
        _tier == null ||
        _positionId == null) {
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
            partyName: _partyName,
            photoBytes: _photoBytes,
            photoName: _photoName,
          )
        : await notifier.submit(
            positionId: _positionId!,
            slogan: _sloganController.text,
            platformStatement: _platformController.text.trim(),
            partyName: _partyName,
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
      // A fresh submission lands on the dedicated My Candidacy hub, where the
      // review timeline and what-happens-next live. An edit returns to the
      // dashboard: the edit form must never re-open behind the snackbar (the
      // `?edit=1` query stays in the URL, so the overview path alone cannot
      // be trusted to clear it).
      if (!wasEditing) {
        context.go('/candidacy/status');
      } else {
        context.go('/dashboard');
      }
    } else if (mounted) {
      final failure = ref.read(candidacyProvider);
      if (failure.errorCode == 'seat_taken') {
        // A party already holds this seat. The server's sentence explains it
        // ("the ASLE seat for President is already taken."); counts may have
        // moved, so refresh the availability labels behind the dialog.
        ref.invalidate(candidacyAvailabilityProvider);
        await showDialog<void>(
          context: context,
          builder: (dialogContext) => AlertDialog(
            title: const Text('Seat taken'),
            content: Text(
              failure.errorMessage ??
                  'That seat is already taken by another candidate.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(dialogContext).pop(),
                child: const Text('OK'),
              ),
              TextButton(
                onPressed: () {
                  Navigator.of(dialogContext).pop();
                  _showProblemReportDialog(
                    action: 'submit',
                    errorCode: failure.errorCode ?? 'seat_taken',
                    errorMessage: failure.errorMessage ?? 'Seat taken.',
                  );
                },
                child: const Text('Report problem'),
              ),
            ],
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(failure.errorMessage ?? 'Request failed.'),
            backgroundColor: AppColors.errorRed,
            action: SnackBarAction(
              label: 'Report problem',
              onPressed: () => _showProblemReportDialog(
                action: 'submit',
                errorCode: failure.errorCode ?? 'unknown',
                errorMessage: failure.errorMessage ?? 'Request failed.',
              ),
            ),
          ),
        );
      }
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

  /// Shows a dialog that lets the user report a problem to the admin so the
  /// root cause can be investigated.
  Future<void> _showProblemReportDialog({
    required String action,
    required String errorCode,
    required String errorMessage,
    Map<String, dynamic> extraContext = const {},
  }) async {
    final descriptionController = TextEditingController();

    final submitted = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Report problem to admin'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'What happened: $errorMessage',
                style: AppTextStyles.of(context).bodySmall,
              ),
              AppSpacing.vSm,
              TextField(
                controller: descriptionController,
                decoration: const InputDecoration(
                  labelText: 'Additional details (optional)',
                  hintText: 'What were you doing when the problem occurred?',
                ),
                maxLines: 3,
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('Cancel'),
          ),
          AppButton.primary(
            label: 'Send',
            onPressed: () => Navigator.of(dialogContext).pop(true),
          ),
        ],
      ),
    );

    if (submitted != true || !mounted) return;

    try {
      await ref.read(candidacyRepositoryProvider).submitProblemReport(
            screen: 'CandidacyApplyScreen',
            action: action,
            errorCode: errorCode,
            errorMessage: errorMessage,
            context: {
              ...extraContext,
              'party': _partyName,
              'tier': _tier?.name,
              'position': _positionId,
            },
            description: descriptionController.text.trim(),
          );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Report sent to admin. Thank you.'),
            backgroundColor: AppColors.successGreen,
          ),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Could not send report. Please try again later.'),
            backgroundColor: AppColors.errorRed,
          ),
        );
      }
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
    final positionsAsync = ref.watch(positionsProvider);
    final phase = ref.watch(
      electionStatusProvider.select(
        (state) => state.valueOrNull?.phase ?? ElectionPhase.unknown,
      ),
    );

    // The apply form is only reachable during registration; an existing
    // application may be edited through voting (see _canEdit).
    final canApply = phase == ElectionPhase.registration;
    final canEdit =
        phase == ElectionPhase.registration ||
        phase == ElectionPhase.votingOpen;

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
                  canEdit: canEdit,
                  onEdit: () => setState(() => _editing = true),
                  onWithdraw: _confirmWithdraw,
                );
              case 'rejected':
              case 'withdrawn':
                if (!canApply) {
                  return _PhaseBlockedView(phase: phase);
                }
                return _StatusView(
                  status: status,
                  actionLabel: 'Re-apply',
                  onAction: _applyAgain,
                  rejectionReason: _application?.rejectionReason,
                  reapplyBlockedUntil: _application?.reapplyBlockedUntil,
                );
              default:
                if (!canApply) {
                  return _PhaseBlockedView(phase: phase);
                }
                return _buildForm(positions);
            }
          }
          if (!canApply) {
            return _PhaseBlockedView(phase: phase);
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
    final availabilityAsync = ref.watch(candidacyAvailabilityProvider);
    final availability = availabilityAsync.valueOrNull;

    return ListView(
      padding: AppSpacing.screenPadding,
      children: [
        const _PhaseCountdownBanner(),
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
              _partyField(positionLocked: positionLocked),
              AppTextField.fieldGap,
              _tierField(positionLocked: positionLocked),
              AppTextField.fieldGap,
              _positionField(
                positions: positions,
                availability: availability,
                positionLocked: positionLocked,
              ),
              AppTextField.fieldGap,
              _seatAvailabilityCounter(
                positions: positions,
                availability: availability,
              ),
              if (availabilityAsync.isLoading) ...[
                AppSpacing.vSm,
                Text(
                  'Checking seat availability…',
                  style: AppTextStyles.of(context).labelSmall,
                ),
              ] else if (availabilityAsync.hasError) ...[
                AppSpacing.vSm,
                Text(
                  'Seat availability could not be loaded; the server will '
                  'still confirm your seat on submit.',
                  style: AppTextStyles.of(context).labelSmall,
                ),
                TextButton(
                  onPressed: () => _showProblemReportDialog(
                    action: 'load_availability',
                    errorCode: 'availability_failed',
                    errorMessage:
                        'Seat availability could not be loaded.',
                  ),
                  child: const Text('Report problem'),
                ),
              ],
              if (positionLocked) ...[
                AppSpacing.vSm,
                Text(
                  'Position locked after approval.',
                  style: AppTextStyles.of(context).labelSmall.copyWith(
                        color: Theme.of(context).colorScheme.primary,
                      ),
                ),
              ],
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

  /// The availability key for the current pick: the server reports the
  /// null-party ("No party") bucket under the reserved `_none` key.
  String get _partyKey => _partyName ?? '_none';

  /// Human label for the current pick, used in the "seat taken" wording.
  String get _partyDisplay => _partyName ?? 'No party';

  /// Whether the selected party already fills [position]'s seat, per the
  /// authoritative `party_counts` from `GET /candidacy/availability`.
  ///
  /// Returns false when availability is missing (endpoint failed or not yet
  /// deployed) so the picker stays fully usable and the server still enforces
  /// the cap on submit.
  bool _seatTaken(Position p, Map<int, Map<String, int>>? availability) {
    if (availability == null) return false;
    final id = int.tryParse(p.id);
    if (id == null) return false;
    final counts = availability[id];
    if (counts == null) return false;
    return (counts[_partyKey] ?? 0) >= p.seatCount;
  }

  /// Official party picker, populated from GET /api/parties (ASLE, SVEA).
  ///
  /// The field is required — a candidate must select an official party before
  /// they can choose a tier or position. The "No party" choice is not offered.
  /// A previously saved value that is no longer in the official list is kept
  /// as an option, so editing a legacy campaign never silently drops the party
  /// it was submitted with.
  Widget _partyField({required bool positionLocked}) {
    final partiesAsync = ref.watch(partiesProvider);
    final parties = partiesAsync.valueOrNull ?? const <String>[];
    final legacy = _partyName?.trim();
    final options = <String>{
      ...parties,
      if (legacy != null && legacy.isNotEmpty) legacy,
    }.toList();

    return DropdownButtonFormField<String>(
      initialValue: _partyName,
      isExpanded: true,
      decoration: InputDecoration(
        labelText: 'Party *',
        helperText: partiesAsync.hasError
            ? 'Could not load the party list.'
            : partiesAsync.isLoading
                ? 'Loading parties…'
                : 'Select your official party. Required before choosing a position.',
      ),
      items: options
          .map(
            (p) => DropdownMenuItem<String>(value: p, child: Text(p)),
          )
          .toList(),
      validator: (v) => v == null || v.isEmpty ? 'Select a party' : null,
      onChanged: positionLocked
          ? null
          : (value) => setState(() {
                _partyName = value;
                _tier = null;
                _positionId = null;
              }),
    );
  }

  /// Tier picker: National or Provincial. Enabled only once a party is chosen.
  Widget _tierField({required bool positionLocked}) {
    return DropdownButtonFormField<PositionTier>(
      initialValue: _tier,
      isExpanded: true,
      decoration: const InputDecoration(
        labelText: 'Election tier *',
        helperText: 'Choose National or Provincial.',
      ),
      items: [
        DropdownMenuItem<PositionTier>(
          value: PositionTier.national,
          child: const Text('National'),
        ),
        DropdownMenuItem<PositionTier>(
          value: PositionTier.provincial,
          child: const Text('Provincial'),
        ),
      ],
      validator: (v) => v == null ? 'Select a tier' : null,
      onChanged: (_partyName == null || positionLocked)
          ? null
          : (value) => setState(() {
                _tier = value;
                _positionId = null;
              }),
    );
  }

  /// Position Running For picker, filtered to the selected tier.
  Widget _positionField({
    required List<Position> positions,
    required Map<int, Map<String, int>>? availability,
    required bool positionLocked,
  }) {
    final tierPositions =
        positions.where((p) => p.tier == _tier).toList();

    return DropdownButtonFormField<String>(
      initialValue: _positionId,
      decoration: const InputDecoration(
        labelText: 'Position Running For *',
      ),
      items: tierPositions
          .map(
            (p) => DropdownMenuItem<String>(
              value: p.id,
              enabled: !(_seatTaken(p, availability) &&
                  p.id != _positionId),
              child: Text(
                '${p.label} (${_tierLabel(p)})'
                '${_seatTaken(p, availability) ? ' — $_partyDisplay seat taken' : ''}',
              ),
            ),
          )
          .toList(),
      onChanged: positionLocked
          ? null
          : (v) => setState(() => _positionId = v),
      validator: (v) => v == null ? 'Select a position' : null,
    );
  }

  /// Seat availability counter for the selected position + party.
  Widget _seatAvailabilityCounter({
    required List<Position> positions,
    required Map<int, Map<String, int>>? availability,
  }) {
    if (_positionId == null || _partyName == null || availability == null) {
      return const SizedBox.shrink();
    }

    final id = int.tryParse(_positionId!);
    if (id == null) return const SizedBox.shrink();

    final position = positions.firstWhere(
      (p) => p.id == _positionId,
      orElse: () => Position(
        id: _positionId!,
        label: _positionId!,
        tier: _tier ?? PositionTier.national,
        seatCount: 1,
        description: '',
      ),
    );

    final taken = (availability[id] ?? <String, int>{})[_partyName] ?? 0;
    final remaining = position.seatCount - taken;

    final color = remaining <= 0
        ? Theme.of(context).colorScheme.error
        : null;

    return Text(
      'Seats available: $remaining of ${position.seatCount} for $_partyName'
      '${remaining <= 0 ? ' — seat taken' : ''}',
      style: AppTextStyles.of(context).labelSmall.copyWith(
        color: color,
        fontWeight: remaining <= 0 ? FontWeight.w600 : FontWeight.normal,
      ),
    );
  }

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
  final String? rejectionReason;
  final String? reapplyBlockedUntil;

  const _StatusView({
    required this.status,
    this.actionLabel,
    this.onAction,
    this.rejectionReason,
    this.reapplyBlockedUntil,
  });

  /// True when the 24-hour lockout (set on the 3rd rejection) is still active.
  bool get _isBlocked {
    if (reapplyBlockedUntil == null) return false;
    final until = DateTime.tryParse(reapplyBlockedUntil!);
    return until != null && until.isAfter(DateTime.now());
  }

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

    // Show the exact reapply time when blocked (3-strike lockout).
    final blockedMessage = _isBlocked
        ? 'You have been rejected 3 times. You cannot reapply until '
            '${_formatBlockedUntil()}.'
        : null;

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
            // Committee rejection reason, when supplied.
            if (status == 'rejected' && (rejectionReason ?? '').isNotEmpty) ...[
              AppSpacing.vMd,
              Container(
                padding: const EdgeInsets.all(AppSpacing.md),
                decoration: BoxDecoration(
                  color: scheme.error.withValues(alpha: 0.08),
                  borderRadius: AppRadius.mdAll,
                  border: Border.all(color: scheme.error.withValues(alpha: 0.3)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Reason for rejection',
                      style: appText.labelSmall.copyWith(
                        fontWeight: FontWeight.w700,
                        color: scheme.error,
                      ),
                    ),
                    AppSpacing.vXs,
                    Text(rejectionReason!, style: appText.bodySmall),
                  ],
                ),
              ),
            ],
            // 3-strike lockout warning when the 24h block is active.
            if (blockedMessage != null) ...[
              AppSpacing.vMd,
              Container(
                padding: const EdgeInsets.all(AppSpacing.md),
                decoration: BoxDecoration(
                  color: Colors.orange.withValues(alpha: 0.08),
                  borderRadius: AppRadius.mdAll,
                  border: Border.all(color: Colors.orange.withValues(alpha: 0.3)),
                ),
                child: Row(
                  children: [
                    Icon(Icons.timer_off_outlined, color: Colors.orange, size: 20),
                    AppSpacing.hSm,
                    Expanded(
                      child: Text(blockedMessage, style: appText.bodySmall),
                    ),
                  ],
                ),
              ),
            ],
            AppSpacing.vLg,
            if (_isBlocked)
              // Blocked: show disabled button so the student knows reapply exists.
              AppButton.primary(
                label: 'Re-apply (locked)',
                onPressed: null,
              )
            else
              AppButton.primary(
                label: actionLabel ?? 'Back to Dashboard',
                onPressed: onAction ?? () => context.go('/dashboard'),
              ),
          ],
        ),
      ),
    );
  }

  /// "Oct 16, 2026 03:15 PM" from the ISO lockout timestamp.
  String _formatBlockedUntil() {
    final until = DateTime.tryParse(reapplyBlockedUntil ?? '');
    if (until == null) return '24 hours';
    final local = until.toLocal();
    const months = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];
    final h = local.hour % 12 == 0 ? 12 : local.hour % 12;
    final amPm = local.hour >= 12 ? 'PM' : 'AM';
    final minute = local.minute.toString().padLeft(2, '0');
    return '${months[local.month - 1]} ${local.day}, ${local.year} '
        '$h:$minute $amPm';
  }
}

/// Manage an active (pending or approved) application: summary card plus
/// edit / withdraw actions.
class _OverviewView extends StatelessWidget {
  final String status;
  final String? positionLabel;
  final CandidacyApplication? application;
  final bool canEdit;
  final VoidCallback onEdit;
  final VoidCallback onWithdraw;

  const _OverviewView({
    required this.status,
    required this.positionLabel,
    required this.application,
    required this.canEdit,
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
        if (canEdit) ...[
          AppButton.primary(
            label: 'Edit Campaign',
            onPressed: onEdit,
            icon: Icons.edit_outlined,
          ),
          AppSpacing.vMd,
        ] else
          Text(
            'Editing is closed for the current election phase.',
            style: AppTextStyles.of(context).bodySmall,
          ),
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

/// Shown in place of the apply form when the current election phase does not
/// allow new candidacy applications. Explains the phase and offers a way back.
class _PhaseBlockedView extends StatelessWidget {
  const _PhaseBlockedView({required this.phase});

  final ElectionPhase phase;

  String get _message {
    switch (phase) {
      case ElectionPhase.registrationClosed:
        return 'Registration has closed. You cannot submit a new candidacy application right now. Stay tuned — voting opens soon.';
      case ElectionPhase.votingOpen:
        return 'Registration is closed. New applications are no longer accepted, but you can still edit an existing application.';
      case ElectionPhase.votingClosed:
        return 'Voting has closed. Candidacy applications are no longer accepted.';
      case ElectionPhase.unknown:
      default:
        return 'The election phase is not configured yet, or the status could not be determined. Please try again later.';
    }
  }

  @override
  Widget build(BuildContext context) {
    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;

    return ListView(
      padding: AppSpacing.screenPadding,
      children: [
        Container(
          padding: const EdgeInsets.all(AppSpacing.lg),
          decoration: BoxDecoration(
            color: context.appSurface,
            borderRadius: AppRadius.mdAll,
            border: Border.all(color: scheme.primary.withValues(alpha: 0.3)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.schedule, color: scheme.primary, size: 28),
              AppSpacing.vMd,
              Text(
                'Candidacy applications unavailable',
                style: appText.titleMedium.copyWith(
                  color: scheme.primary,
                  fontWeight: FontWeight.w700,
                ),
              ),
              AppSpacing.vSm,
              Text(_message, style: appText.bodySmall),
            ],
          ),
        ),
        AppSpacing.vLg,
        AppButton.text(
          label: 'Back to Dashboard',
          onPressed: () => context.go('/dashboard'),
        ),
      ],
    );
  }
}

/// A compact, phase-aware countdown banner shown at the top of the apply form.
///
/// Shows a live [ElectionCountdown] to the current phase's end boundary when
/// the target is known, or a short informational line when the phase is
/// closed / unconfigured. Uses the same shared [ElectionCountdown] widget as
/// the dashboard hero card so the two screens stay visually consistent.
class _PhaseCountdownBanner extends ConsumerWidget {
  const _PhaseCountdownBanner();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = ref.watch(electionStatusProvider).valueOrNull;
    if (status == null) return const SizedBox.shrink();

    final appText = AppTextStyles.of(context);
    final scheme = Theme.of(context).colorScheme;

    Widget? countdown;
    String? note;

    switch (status.phase) {
      case ElectionPhase.registration:
        final closesAt = status.registrationClosesAt;
        if (closesAt != null) {
          countdown = ElectionCountdown(
            target: closesAt,
            label: 'Registration closes in',
          );
        } else {
          note = 'Registration is currently open.';
        }
      case ElectionPhase.registrationClosed:
        final opensAt = status.votingOpensAt;
        if (opensAt != null) {
          countdown = ElectionCountdown(
            target: opensAt,
            label: 'Voting opens in',
          );
        } else {
          note = 'Registration has closed. Voting opens soon.';
        }
      case ElectionPhase.votingOpen:
        final closesAt = status.votingClosesAt;
        if (closesAt != null) {
          countdown = ElectionCountdown(
            target: closesAt,
            label: 'Voting closes in',
          );
        } else {
          note = 'Voting is currently open.';
        }
      case ElectionPhase.votingClosed:
        note = 'Voting has closed. Candidacy changes are no longer allowed.';
      case ElectionPhase.unknown:
        note = 'Election status is unavailable. Pull to refresh.';
    }

    if (countdown == null && note == null) return const SizedBox.shrink();

    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: context.appSurface,
        borderRadius: AppRadius.mdAll,
        border: Border.all(color: scheme.primary.withValues(alpha: 0.2)),
      ),
      child: countdown ??
          Text(note!, style: appText.bodySmall),
    );
  }
}
