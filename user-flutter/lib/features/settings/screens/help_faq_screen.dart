import 'package:flutter/material.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/top_bar.dart';
import '../../dashboard/widgets/eligibility_faq.dart';

/// "Help & FAQ" (docs/03_APP_FLOW.md: reachable from the avatar menu).
///
/// Reuses the [EligibilityFAQ] already shown on the Dashboard, plus static
/// pointers for receipt verification and account support.
class HelpFaqScreen extends StatelessWidget {
  const HelpFaqScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Help & FAQ'),
      body: ListView(
        padding: AppSpacing.screenPadding,
        children: [
          const EligibilityFAQ(),
          AppSpacing.vSm,
          AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
              leading: Icon(Icons.receipt_long, color: scheme.primary),
              title: const Text('Verify My Vote'),
              subtitle: const Text(
                'After submitting your ballot you receive a digital receipt '
                'token. Paste it on the Results tab to confirm your vote was '
                'counted — it never reveals who you voted for.',
              ),
              isThreeLine: true,
            ),
          ),
          AppSpacing.vSm,
          AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
              leading:
                  Icon(Icons.contact_support_outlined, color: scheme.primary),
              title: const Text('Need an account?'),
              subtitle: const Text(
                'Student accounts are created by your school registrar. '
                'Contact the administration office if you need one or have '
                'trouble with your eligibility.',
              ),
              isThreeLine: true,
            ),
          ),
        ],
      ),
    );
  }
}