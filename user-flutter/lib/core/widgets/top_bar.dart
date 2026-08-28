import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../features/auth/providers/auth_provider.dart';
import '../constants/app_colors.dart';
import '../constants/app_text_styles.dart';
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
            child: CircleAvatar(
              radius: 18,
              backgroundColor: AppColors.backgroundGray,
              backgroundImage: student.avatarUrl != null
                  ? NetworkImage(student.avatarUrl!)
                  : null,
              child: student.avatarUrl == null
                  ? const Icon(Icons.person, size: 20, color: AppColors.textSecondary)
                  : null,
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

  @override
  void initState() {
    super.initState();
    _now = DateTime.now();
    // Update every second
    Stream.periodic(const Duration(seconds: 1)).listen((_) {
      if (mounted) {
        setState(() {
          _now = DateTime.now();
        });
      }
    });
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
