import 'package:flutter/material.dart';
import '../../../core/widgets/candidate_card.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/candidate_model.dart';
import '../../../data/models/position_model.dart';

class CandidatesListScreen extends StatefulWidget {
  final List<Position> positions;
  final List<Candidate> candidates;

  const CandidatesListScreen({
    super.key,
    required this.positions,
    required this.candidates,
  });

  @override
  State<CandidatesListScreen> createState() => _CandidatesListScreenState();
}

class _CandidatesListScreenState extends State<CandidatesListScreen> {
  PositionTier _selectedTier = PositionTier.school;
  late Position _selectedPosition;
  String _searchQuery = '';
  String? _gradeFilter;
  String? _positionFilter; // Although redundant with chips, included per spec
  final List<String> _selectedSenatorIds = [];

  // Design tokens from 07_DESIGN_SYSTEM.md
  static const Color primaryBlue = Color(0xFF2F5EFF);
  static const Color backgroundGray = Color(0xFFF8FAFC);
  static const Color textPrimary = Color(0xFF0F172A);
  static const Color textSecondary = Color(0xFF64748B);
  static const Color borderGray = Color(0xFFE2E8F0);

  @override
  void initState() {
    super.initState();
    _selectedPosition = widget.positions.firstWhere(
      (p) => p.tier == _selectedTier,
      orElse: () => widget.positions.first,
    );
    _positionFilter = _selectedPosition.label;
  }

  List<Position> get _filteredPositions =>
      widget.positions.where((p) => p.tier == _selectedTier).toList();

  List<Candidate> get _filteredCandidates {
    var filtered = widget.candidates
        .where((c) => c.position.id == _selectedPosition.id)
        .toList();

    // Filters (Search and Dropdowns) - primarily for President per spec
    if (_selectedPosition.id == 'president') {
      if (_searchQuery.isNotEmpty) {
        filtered = filtered
            .where((c) =>
                c.name.toLowerCase().contains(_searchQuery.toLowerCase()) ||
                c.slogan.toLowerCase().contains(_searchQuery.toLowerCase()))
            .toList();
      }
      if (_gradeFilter != null && _gradeFilter != 'All Grades') {
        filtered = filtered.where((c) => c.gradeLine.contains(_gradeFilter!)).toList();
      }
      // Position filter is already applied by _selectedPosition, but kept for UI completeness
    }

    return filtered;
  }

  void _onPositionChanged(Position position) {
    setState(() {
      _selectedPosition = position;
      _positionFilter = position.label;
    });
  }

  void _onTierChanged(PositionTier tier) {
    setState(() {
      _selectedTier = tier;
      _selectedPosition = widget.positions.firstWhere(
        (p) => p.tier == tier,
        orElse: () => widget.positions.first,
      );
      _positionFilter = _selectedPosition.label;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: backgroundGray,
      appBar: PreferredSize(
        preferredSize: const Size.fromHeight(kToolbarHeight + 1),
        child: Column(
          children: [
            const SafeArea(child: TopBar()),
            Container(height: 1, color: borderGray),
          ],
        ),
      ),
      body: Column(
        children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
              children: [
                // Tier Toggle
                Center(
                  child: SegmentedButton<PositionTier>(
                    segments: const [
                      ButtonSegment(
                        value: PositionTier.school,
                        label: Text('School'),
                      ),
                      ButtonSegment(
                        value: PositionTier.provincial,
                        label: Text('Provincial'),
                      ),
                    ],
                    selected: {_selectedTier},
                    onSelectionChanged: (newSelection) {
                      _onTierChanged(newSelection.first);
                    },
                    style: SegmentedButton.styleFrom(
                      selectedBackgroundColor: primaryBlue,
                      selectedForegroundColor: Colors.white,
                      side: const BorderSide(color: borderGray),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 24),

                // Position Chips
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: _filteredPositions.map((pos) {
                      final isSelected = _selectedPosition.id == pos.id;
                      return Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          label: Text(pos.label),
                          selected: isSelected,
                          onSelected: (selected) {
                            if (selected) _onPositionChanged(pos);
                          },
                          selectedColor: primaryBlue.withOpacity(0.1),
                          labelStyle: TextStyle(
                            color: isSelected ? primaryBlue : textSecondary,
                            fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                            fontSize: 14,
                          ),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(20),
                            side: BorderSide(
                              color: isSelected ? primaryBlue : borderGray,
                            ),
                          ),
                          showCheckmark: false,
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        ),
                      );
                    }).toList(),
                  ),
                ),
                const SizedBox(height: 32),

                // Header
                Text(
                  _selectedPosition.label,
                  style: const TextStyle(
                    fontSize: 28,
                    fontWeight: FontWeight.bold,
                    color: textPrimary,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  _selectedPosition.description,
                  style: const TextStyle(
                    fontSize: 14,
                    color: textSecondary,
                  ),
                ),
                const SizedBox(height: 24),

                // Search & Filters (Spec: For President only)
                if (_selectedPosition.id == 'president') ...[
                  TextField(
                    onChanged: (value) => setState(() => _searchQuery = value),
                    decoration: InputDecoration(
                      hintText: 'Search candidates by name or slogan...',
                      hintStyle: const TextStyle(color: textSecondary, fontSize: 14),
                      prefixIcon: const Icon(Icons.search, color: textSecondary),
                      filled: true,
                      fillColor: Colors.white,
                      contentPadding: const EdgeInsets.symmetric(vertical: 16),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(8),
                        borderSide: const BorderSide(color: borderGray),
                      ),
                      enabledBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(8),
                        borderSide: const BorderSide(color: borderGray),
                      ),
                      focusedBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(8),
                        borderSide: const BorderSide(color: primaryBlue, width: 2),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: _buildDropdown(
                          value: _positionFilter,
                          items: _filteredPositions.map((p) => p.label).toList(),
                          onChanged: (val) {
                            if (val != null) {
                              final pos = widget.positions.firstWhere((p) => p.label == val);
                              _onPositionChanged(pos);
                            }
                          },
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: _buildDropdown(
                          value: _gradeFilter ?? 'All Grades',
                          items: ['All Grades', 'Grade 10', 'Grade 11', 'Grade 12'],
                          onChanged: (val) => setState(() => _gradeFilter = val),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 24),
                ],

                // Candidate List
                if (_filteredCandidates.isEmpty)
                  const EmptyState(
                    message: 'No candidates found',
                    subMessage: 'Try adjusting your filters or search query.',
                  )
                else
                  ..._filteredCandidates.map((candidate) {
                    final isSenator = _selectedPosition.id == 'senator';
                    return CandidateCard(
                      candidate: candidate,
                      isSelectable: isSenator,
                      isSelected: _selectedSenatorIds.contains(candidate.id),
                      onSelected: (selected) {
                        setState(() {
                          if (selected == true) {
                            if (_selectedSenatorIds.length < 12) {
                              _selectedSenatorIds.add(candidate.id);
                            }
                          } else {
                            _selectedSenatorIds.remove(candidate.id);
                          }
                        });
                      },
                      onViewProfile: () {
                        Navigator.pushNamed(
                          context,
                          '/candidate-profile',
                          arguments: candidate,
                        );
                      },
                      onVote: () {
                        // Handle direct vote logic
                      },
                    );
                  }),
                const SizedBox(height: 100), // Bottom padding
              ],
            ),
          ),
        ],
      ),
      bottomSheet: _selectedPosition.id == 'senator'
          ? Container(
              padding: const EdgeInsets.all(20),
              decoration: const BoxDecoration(
                color: Colors.white,
                border: Border(top: BorderSide(color: borderGray)),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black12,
                    blurRadius: 10,
                    offset: Offset(0, -2),
                  ),
                ],
              ),
              child: SafeArea(
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${_selectedSenatorIds.length} / 12 selected',
                          style: const TextStyle(
                            fontWeight: FontWeight.bold,
                            color: textPrimary,
                            fontSize: 16,
                          ),
                        ),
                        const Text(
                          'Select up to 12 candidates',
                          style: TextStyle(color: textSecondary, fontSize: 12),
                        ),
                      ],
                    ),
                    ElevatedButton(
                      onPressed: _selectedSenatorIds.isNotEmpty
                          ? () {
                              // Confirm selection
                            }
                          : null,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: primaryBlue,
                        foregroundColor: Colors.white,
                        disabledBackgroundColor: borderGray,
                        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8),
                        ),
                        elevation: 0,
                      ),
                      child: const Text(
                        'Confirm Selection',
                        style: TextStyle(fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
              ),
            )
          : null,
    );
  }

  Widget _buildDropdown({
    required String? value,
    required List<String> items,
    required ValueChanged<String?> onChanged,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: borderGray),
        borderRadius: BorderRadius.circular(8),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          value: value,
          isExpanded: true,
          icon: const Icon(Icons.keyboard_arrow_down, color: textSecondary),
          style: const TextStyle(color: textPrimary, fontSize: 14),
          onChanged: onChanged,
          items: items.map<DropdownMenuItem<String>>((String value) {
            return DropdownMenuItem<String>(
              value: value,
              child: Text(value),
            );
          }).toList(),
        ),
      ),
    );
  }
}
