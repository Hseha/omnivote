import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/widgets/loading_indicator.dart';
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
    final status = await ref.read(candidacyProvider.notifier).applicationStatus();
    if (mounted && status != null && status != 'none') {
      setState(() => _applicationStatus = status);
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || !_certify) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please complete the form and certify your application.'),
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

  @override
  Widget build(BuildContext context) {
    final student = ref.watch(authProvider).student;
    final positionsAsync = ref.watch(positionsProvider);

    return Scaffold(
      backgroundColor: AppColors.backgroundGray,
      appBar: const TopBar(title: 'Apply for Candidacy'),
      body: positionsAsync.when(
        data: (positions) {
          if (_applicationStatus != null) {
            return _StatusView(status: _applicationStatus!);
          }
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _readOnlyField('Full Name', student?.name ?? '—'),
                    _readOnlyField('Email', student?.email ?? '—'),
                    _readOnlyField('Student ID', student?.studentId ?? '—'),
                    const SizedBox(height: 16),
                    DropdownButtonFormField<String>(
                      initialValue: _positionId,
                      decoration: const InputDecoration(
                        labelText: 'Position Running For *',
                        border: OutlineInputBorder(),
                      ),
                      items: positions
                          .map((p) => DropdownMenuItem<String>(
                                value: p.id,
                                child: Text('${p.label} (${_tierLabel(p)})'),
                              ))
                          .toList(),
                      onChanged: (v) => setState(() => _positionId = v),
                      validator: (v) =>
                          v == null ? 'Select a position' : null,
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _partyController,
                      decoration: const InputDecoration(
                        labelText: 'Party / Platform Name',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _sloganController,
                      decoration: const InputDecoration(
                        labelText: 'Campaign Slogan',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _platformController,
                      maxLines: 6,
                      maxLength: 5000,
                      decoration: const InputDecoration(
                        labelText: 'Campaign Platform / Statement *',
                        alignLabelWithHint: true,
                        border: OutlineInputBorder(),
                      ),
                      validator: (v) => (v == null || v.trim().isEmpty)
                          ? 'Campaign platform is required'
                          : null,
                    ),
                    const SizedBox(height: 8),
                    CheckboxListTile(
                      value: _certify,
                      contentPadding: EdgeInsets.zero,
                      onChanged: (v) => setState(() => _certify = v ?? false),
                      title: const Text(
                        'I certify that everything on this application is true and that I meet the eligibility requirements.',
                        style: TextStyle(fontSize: 13),
                      ),
                      controlAffinity: ListTileControlAffinity.leading,
                    ),
                    const SizedBox(height: 24),
                    Consumer(
                      builder: (context, ref, child) {
                        final isSubmitting = ref.watch(candidacyProvider).isSubmitting;
                        return ElevatedButton(
                          onPressed: isSubmitting ? null : _submit,
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppColors.primaryBlue,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                          ),
                          child: isSubmitting
                              ? const SizedBox(
                                  height: 20,
                                  width: 20,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    color: Colors.white,
                                  ),
                                )
                              : const Text('Submit Application'),
                        );
                      },
                    ),
                    const SizedBox(height: 16),
                    TextButton(
                      onPressed: () => context.go('/dashboard'),
                      child: const Text('Cancel and go back to Dashboard'),
                    ),
                  ],
                ),
              ),
            ],
          );
        },
        loading: () => const LoadingIndicator(),
        error: (err, stack) => Center(child: Text('Error: $err')),
      ),
    );
  }

  static String _tierLabel(Position p) =>
      p.tier == PositionTier.provincial ? 'Provincial' : 'School';

  Widget _readOnlyField(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label,
              style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary)),
          const SizedBox(height: 4),
          Text(value,
              style: const TextStyle(
                  fontSize: 16, fontWeight: FontWeight.w600)),
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
    final color = status == 'approved'
        ? const Color(0xFF16A34A)
        : status == 'rejected'
            ? AppColors.errorRed
            : AppColors.primaryBlue;
    final icon = status == 'approved'
        ? Icons.check_circle
        : status == 'rejected'
            ? Icons.cancel
            : Icons.hourglass_top;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 72, color: color),
            const SizedBox(height: 16),
            Text(
              'Application status: ${status.toUpperCase()}',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            const Text(
              'If approved, you will appear on the Candidates list once the election committee publishes approvals.',
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.textSecondary),
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: () => context.go('/dashboard'),
              child: const Text('Back to Dashboard'),
            ),
          ],
        ),
      ),
    );
  }
}
