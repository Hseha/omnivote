import 'package:flutter/material.dart';
import '../../../data/models/candidate_model.dart';
import '../widgets/platform_points_list.dart';

class CandidateProfileScreen extends StatelessWidget {
  final Candidate candidate;

  const CandidateProfileScreen({
    super.key,
    required this.candidate,
  });

  // Design tokens from 07_DESIGN_SYSTEM.md
  static const Color primaryBlue = Color(0xFF2F5EFF);
  static const Color navyDark = Color(0xFF0F172A);
  static const Color backgroundGray = Color(0xFFF8FAFC);
  static const Color textPrimary = Color(0xFF0F172A);
  static const Color textSecondary = Color(0xFF64748B);
  static const Color borderGray = Color(0xFFE2E8F0);
  static const Color tagBlueBg = Color(0xFFE0E7FF);
  static const Color tagBlueText = Color(0xFF3730A3);

  @override
  Widget build(BuildContext context) {
    final String firstName = candidate.name.split(' ').first;

    return Scaffold(
      backgroundColor: backgroundGray,
      appBar: AppBar(
        backgroundColor: Colors.white,
        elevation: 0,
        centerTitle: true,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: textPrimary),
          onPressed: () => Navigator.pop(context),
        ),
        title: const Text(
          'Candidate Profile',
          style: TextStyle(
            color: textPrimary,
            fontWeight: FontWeight.bold,
            fontSize: 18,
          ),
        ),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1),
          child: Container(color: borderGray, height: 1),
        ),
      ),
      body: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Hero Area
            Container(
              color: Colors.white,
              padding: const EdgeInsets.all(24),
              child: Column(
                children: [
                  CircleAvatar(
                    radius: 64,
                    backgroundImage: NetworkImage(candidate.photoUrl),
                    backgroundColor: borderGray,
                  ),
                  const SizedBox(height: 20),
                  // Position Tag
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: tagBlueBg,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      candidate.position.label.toUpperCase(),
                      style: const TextStyle(
                        color: tagBlueText,
                        fontWeight: FontWeight.bold,
                        fontSize: 11,
                        letterSpacing: 0.8,
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    candidate.name,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.bold,
                      color: textPrimary,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    candidate.gradeLine,
                    style: const TextStyle(
                      fontSize: 16,
                      color: textSecondary,
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    '"${candidate.slogan}"',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontSize: 16,
                      fontStyle: FontStyle.italic,
                      color: textSecondary,
                    ),
                  ),
                  const SizedBox(height: 32),
                  Row(
                    children: [
                      Expanded(
                        child: ElevatedButton(
                          onPressed: () {
                            // Handle vote action
                          },
                          style: ElevatedButton.styleFrom(
                            backgroundColor: primaryBlue,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 16),
                            elevation: 0,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(8),
                            ),
                          ),
                          child: Text(
                            'Vote for $firstName',
                            style: const TextStyle(fontWeight: FontWeight.bold),
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => Navigator.pop(context),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: primaryBlue,
                            side: const BorderSide(color: primaryBlue),
                            padding: const EdgeInsets.symmetric(vertical: 16),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(8),
                            ),
                          ),
                          child: const Text(
                            'Back to Candidates',
                            style: TextStyle(fontWeight: FontWeight.bold),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),

            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  // 1. Campaign Platform
                  _buildSectionCard(
                    title: 'Campaign Platform',
                    child: PlatformPointsList(
                      points: candidate.platformPoints,
                      isNumbered: true,
                    ),
                  ),

                  // 2. Campaign Video
                  if (candidate.videoUrl != null)
                    _buildSectionCard(
                      title: 'Campaign Video',
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Container(
                            height: 200,
                            width: double.infinity,
                            decoration: BoxDecoration(
                              color: navyDark,
                              borderRadius: BorderRadius.circular(8),
                              image: candidate.photoUrl.isNotEmpty
                                  ? DecorationImage(
                                      image: NetworkImage(candidate.photoUrl),
                                      fit: BoxFit.cover,
                                      colorFilter: ColorFilter.mode(
                                        Colors.black.withOpacity(0.4),
                                        BlendMode.darken,
                                      ),
                                    )
                                  : null,
                            ),
                            child: const Center(
                              child: Icon(
                                Icons.play_circle_fill,
                                color: Colors.white,
                                size: 64,
                              ),
                            ),
                          ),
                          const SizedBox(height: 12),
                          Text(
                            "Listen to ${candidate.name}'s 2-minute pitch to voters",
                            style: const TextStyle(
                              color: textSecondary,
                              fontSize: 14,
                            ),
                          ),
                        ],
                      ),
                    ),

                  // 3. Qualifications & Experience
                  _buildSectionCard(
                    title: 'Qualifications & Experience',
                    child: PlatformPointsList(
                      points: candidate.qualifications,
                      isNumbered: false,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSectionCard({required String title, required Widget child}) {
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 16),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: borderGray),
      ),
      color: Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: const TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: textPrimary,
              ),
            ),
            const SizedBox(height: 16),
            child,
          ],
        ),
      ),
    );
  }
}
