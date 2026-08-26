import 'package:flutter/material.dart';

// Standalone runner function to launch this screen directly
void main() {
  runApp(
    const MaterialApp(
      debugShowCheckedModeBanner: false,
      home: DashboardScreen(),
    ),
  );
}

// Fallback color styles in case core files don't load
class LocalColors {
  static const Color surfaceWhite = Colors.white;
  static const Color borderGray = Color(0xFFE0E0E0);
  static const Color successGreen = Color(0xFF2E7D32);
  static const Color primaryBlue = Color(0xFF1E88E5);
}

class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Student Portal', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.black87)),
        backgroundColor: LocalColors.surfaceWhite,
        elevation: 0,
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1.0),
          child: Container(color: LocalColors.borderGray, height: 1.0),
        ),
      ),

      body: ListView(
        padding: const EdgeInsets.all(16.0),
        children: [
          // Registration Complete Banner
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: LocalColors.successGreen.withOpacity(0.1),
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: LocalColors.successGreen.withOpacity(0.3)),
            ),
            child: Row(
              children: [
                const Icon(Icons.check_circle, color: LocalColors.successGreen),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'You registered on Aug 20, 2026. Your account is active and eligible to cast a ballot in all ongoing student body elections.',
                    style: TextStyle(color: LocalColors.successGreen, fontSize: 13),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // Temporary placeholder for custom Registration Details Card
          const Card(
            child: Padding(
              padding: EdgeInsets.all(16.0),
              child: Text('Registration Details Card Placeholder'),
            ),
          ),
          const SizedBox(height: 16),

          // Temporary placeholder for custom Turnout Progress Card
          const Card(
            child: Padding(
              padding: EdgeInsets.all(16.0),
              child: Text('Turnout Progress Placeholder'),
            ),
          ),
          const SizedBox(height: 32),

          ElevatedButton.icon(
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Viewing candidates feature triggered!')),
              );
            },
            icon: const Icon(Icons.people),
            label: const Text('View All Candidates'),
            style: ElevatedButton.styleFrom(
              backgroundColor: LocalColors.primaryBlue,
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(vertical: 16),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
          ),

          const SizedBox(height: 32),
          const Text('Eligibility FAQ', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),

          const ExpansionTile(
            title: Text('What are the requirements to vote?', style: TextStyle(fontSize: 14)),
            children: [
              Padding(
                padding: EdgeInsets.all(16),
                child: Text('You must be a currently enrolled student with a valid Student ID.'),
              )
            ],
          ),

          const ExpansionTile(
            title: Text('How to verify a vote was counted?', style: TextStyle(fontSize: 14)),
            children: [
              Padding(
                padding: EdgeInsets.all(16),
                child: Text('After voting, you will receive a receipt token to verify your ballot.'),
              )
            ],
          ),
        ],
      ),
    );
  }
}
