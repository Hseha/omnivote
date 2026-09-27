import 'package:flutter/material.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/theme/app_tokens.dart';
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
    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'Help & FAQ'),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: const [
          EligibilityFAQ(),
          SizedBox(height: 12),
          Card(
            child: ListTile(
              leading: Icon(Icons.receipt_long, color: AppColors.primaryBlue),
              title: Text('Verify My Vote'),
              subtitle: Text(
                'After submitting your ballot you receive a digital receipt '
                'token. Paste it on the Results tab to confirm your vote was '
                'counted — it never reveals who you voted for.',
              ),
              isThreeLine: true,
            ),
          ),
          SizedBox(height: 12),
          Card(
            child: ListTile(
              leading: Icon(Icons.contact_support_outlined, color: AppColors.primaryBlue),
              title: Text('Need an account?'),
              subtitle: Text(
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