import 'package:flutter/material.dart';

import '../constants/app_text_styles.dart';
import '../theme/app_shape.dart';
import '../theme/app_spacing.dart';

/// The single filter-chip primitive (department / party / tier filters).
///
/// Selected chips read the theme primary (runtime branding accent); idle
/// chips read token text/border. Fixed 44-pt row height keeps the minimum
/// tap target without each screen re-specifying it.
class AppChip extends StatelessWidget {
  final String label;
  final bool selected;
  final ValueChanged<String> onSelect;

  const AppChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final appText = AppTextStyles.of(context);
    return ChoiceChip(
      label: Text(label),
      selected: selected,
      onSelected: (_) => onSelect(label),
      showCheckmark: false,
      selectedColor: scheme.primary.withValues(alpha: 0.12),
      backgroundColor: Colors.transparent,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm + AppSpacing.xs,
        vertical: AppSpacing.sm,
      ),
      labelStyle: appText.titleSmall.copyWith(
        color: selected ? scheme.primary : null,
      ),
      shape: StadiumBorder(
        side: BorderSide(
          color: selected
              ? scheme.primary
              : Theme.of(context).dividerColor,
        ),
      ),
    );
  }
}

/// Horizontal chip row with standard gaps, replacing per-screen
/// `ListView.separated` chip scaffolding.
class AppChipRow extends StatelessWidget {
  final List<String> items;
  final String? selected;
  final ValueChanged<String> onSelect;

  const AppChipRow({
    super.key,
    required this.items,
    required this.selected,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) return const SizedBox.shrink();
    return SizedBox(
      height: AppMetrics.minTapTarget,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: items.length,
        separatorBuilder: (_, _) => AppSpacing.hSm,
        itemBuilder: (context, index) {
          final item = items[index];
          return AppChip(
            label: item,
            selected: selected == item,
            onSelect: onSelect,
          );
        },
      ),
    );
  }
}
