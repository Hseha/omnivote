import 'package:flutter/material.dart';

class PlatformPointsList extends StatelessWidget {
  final List<String> points;
  final bool isNumbered;

  const PlatformPointsList({
    super.key,
    required this.points,
    this.isNumbered = false,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: points.asMap().entries.map((entry) {
        int idx = entry.key + 1;
        String point = entry.value;
        return Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                isNumbered ? '$idx. ' : '• ',
                style: const TextStyle(
                  color: Color(0xFF64748B),
                  fontWeight: FontWeight.bold,
                ),
              ),
              Expanded(
                child: Text(
                  point,
                  style: const TextStyle(
                    color: Color(0xFF0F172A),
                    fontSize: 14,
                  ),
                ),
              ),
            ],
          ),
        );
      }).toList(),
    );
  }
}
