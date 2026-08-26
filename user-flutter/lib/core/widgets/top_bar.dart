import 'package:flutter/material.dart';
import 'status_badge.dart';

class TopBar extends StatelessWidget {
  const TopBar({super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Row(
        children: [
          const Expanded(
            child: Text(
              'Candidates',
              style: TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: Color(0xFF0F172A),
              ),
            ),
          ),
          const StatusBadge(),
          const SizedBox(width: 12),
          const Text(
            '16:03:47', // Mock clock
            style: TextStyle(
              fontSize: 14,
              color: Color(0xFF64748B),
              fontFamily: 'monospace',
            ),
          ),
          const SizedBox(width: 12),
          const CircleAvatar(
            radius: 16,
            backgroundColor: Color(0xFFE2E8F0),
            child: Icon(Icons.person, size: 20, color: Color(0xFF64748B)),
          ),
        ],
      ),
    );
  }
}
