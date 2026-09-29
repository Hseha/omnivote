import 'package:flutter/material.dart';

import '../theme/app_shape.dart';
import '../theme/app_spacing.dart';
import '../theme/app_tokens.dart';

/// Content-shaped loading placeholder: rounded "bones" that pulse while the
/// real content loads, instead of a centered spinner that erases the layout.
///
/// Use one [LoadingSkeleton.lines] / [.card] / [.row] composition inside each
/// screen's `loading:` branch so the loading frame previews the loaded frame.
/// The pulse is a cheap opacity animation on token colors — no image decoding,
/// no extra dependencies.
class LoadingSkeleton extends StatefulWidget {
  final Widget child;

  const LoadingSkeleton({super.key, required this.child});

  /// A full-width card bone.
  factory LoadingSkeleton.card({Key? key, double height = 120}) {
    return LoadingSkeleton(
      key: key,
      child: _Bone(height: height, radius: AppRadius.md),
    );
  }

  /// A list-row bone (avatar dot + two text lines).
  factory LoadingSkeleton.row({Key? key}) {
    return LoadingSkeleton(
      key: key,
      child: const _RowBone(),
    );
  }

  /// [count] text-line bones, e.g. for hero or section bodies.
  factory LoadingSkeleton.lines({Key? key, int count = 3}) {
    return LoadingSkeleton(
      key: key,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var i = 0; i < count; i++) ...[
            if (i > 0) AppSpacing.vSm,
            _Bone(
              height: 14,
              radius: AppRadius.sm / 2,
              widthFactor: i == count - 1 ? 0.6 : 1.0,
            ),
          ],
        ],
      ),
    );
  }

  @override
  State<LoadingSkeleton> createState() => _LoadingSkeletonState();
}

class _LoadingSkeletonState extends State<LoadingSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: Tween<double>(begin: 0.45, end: 1).animate(
        CurvedAnimation(parent: _pulse, curve: Curves.easeInOut),
      ),
      child: widget.child,
    );
  }
}

class _Bone extends StatelessWidget {
  final double height;
  final double radius;
  final double? widthFactor;

  const _Bone({
    required this.height,
    required this.radius,
    this.widthFactor,
  });

  @override
  Widget build(BuildContext context) {
    final bone = Container(
      height: height,
      decoration: BoxDecoration(
        color: context.appBorder,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
    final factor = widthFactor;
    if (factor == null) return bone;
    return FractionallySizedBox(
      alignment: Alignment.centerLeft,
      widthFactor: factor,
      child: bone,
    );
  }
}

class _RowBone extends StatelessWidget {
  const _RowBone();

  @override
  Widget build(BuildContext context) {
    return const Row(
      children: [
        _BoneAvatar(),
        AppSpacing.hMd,
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _Bone(height: 14, radius: 4),
              AppSpacing.vSm,
              _Bone(height: 12, radius: 4, widthFactor: 0.5),
            ],
          ),
        ),
      ],
    );
  }
}

class _BoneAvatar extends StatelessWidget {
  const _BoneAvatar();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: AppMetrics.avatarMd * 2,
      height: AppMetrics.avatarMd * 2,
      decoration: BoxDecoration(
        color: Theme.of(context).dividerColor,
        shape: BoxShape.circle,
      ),
    );
  }
}
