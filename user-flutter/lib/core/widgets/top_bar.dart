import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../features/auth/providers/auth_provider.dart';
import '../constants/app_colors.dart';
import '../constants/app_text_styles.dart';
import 'cached_avatar.dart';
import 'status_badge.dart';

class TopBar extends ConsumerWidget implements PreferredSizeWidget {
  final String title;

  const TopBar({
    super.key,
    required this.title,
  });

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final student = ref.watch(authProvider).student;

    return AppBar(
      backgroundColor: AppColors.surfaceWhite,
      surfaceTintColor: AppColors.surfaceWhite,
      elevation: 0,
      title: Text(title, style: AppTextStyles.pageTitle.copyWith(fontSize: 18)),
      centerTitle: false,
      actions: [
        const StatusBadge(),
        const SizedBox(width: 12),
        const _LiveClock(),
        const SizedBox(width: 12),
        if (student != null) ...[
          GestureDetector(
            onTap: () {
              // Navigate to profile
            },
            child: CachedAvatar(
              imageUrl: student.avatarUrl,
              radius: 18,
            ),
          ),
          const SizedBox(width: 16),
        ],
      ],
      bottom: PreferredSize(
        preferredSize: const Size.fromHeight(1),
        child: Container(
          color: AppColors.borderGray,
          height: 1,
        ),
      ),
    );
  }
}

class _LiveClock extends StatefulWidget {
  const _LiveClock();

  @override
  State<_LiveClock> createState() => _LiveClockState();
}

class _LiveClockState extends State<_LiveClock> {
  late DateTime _now;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _now = DateTime.now();
    // Tick every second; the timer is cancelled in dispose() so setState can
    // never fire after the widget is unmounted (audit §3 #1: leaked stream).
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) {
        setState(() {
          _now = DateTime.now();
        });
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Format: HH:MM:SS
    final timeStr = "${_now.hour.toString().padLeft(2, '0')}:${_now.minute.toString().padLeft(2, '0')}:${_now.second.toString().padLeft(2, '0')}";
    
    return Text(
      timeStr,
      style: const TextStyle(
        fontSize: 13,
        color: AppColors.textSecondary,
        fontFamily: 'monospace',
        fontWeight: FontWeight.w500,
      ),
    );
  }
}
