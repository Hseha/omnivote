import 'package:flutter/material.dart';
import '../../../core/constants/app_text_styles.dart';

class EligibilityFAQCard extends StatelessWidget {
  const EligibilityFAQCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Padding(
          padding: EdgeInsets.symmetric(horizontal: 4.0),
          child: Text(
            'Eligibility FAQ',
            style: AppTextStyles.cardTitle,
          ),
        ),
        const SizedBox(height: 12),
        _buildExpansionTile(
          'What are the requirements to vote?',
          'You must be a currently enrolled student with a valid Student ID. Your account must be in "Eligible Voter" status, which is automatically verified upon successful registration.',
        ),
        _buildExpansionTile(
          'What if I missed the registration window?',
          'Voter registration typically closes 48 hours before the election starts. If you missed it, please contact the Election Committee via the Help tab, though exceptions are rare.',
        ),
        _buildExpansionTile(
          'How to verify a vote was counted?',
          'After submitting your ballot in the "My Ballot" tab, you will receive a digital receipt token. You can input this anonymous token on the Results tab to verify your vote was recorded without revealing your selections.',
        ),
      ],
    );
  }

  Widget _buildExpansionTile(String title, String content) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ExpansionTile(
        shape: const RoundedRectangleBorder(side: BorderSide.none),
        collapsedShape: const RoundedRectangleBorder(side: BorderSide.none),
        title: Text(
          title,
          style: AppTextStyles.body.copyWith(fontWeight: FontWeight.w600),
        ),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
            child: Text(
              content,
              style: AppTextStyles.secondary.copyWith(height: 1.5),
            ),
          ),
        ],
      ),
    );
  }
}
