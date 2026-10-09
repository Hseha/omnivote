import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/notifications/providers/notifications_provider.dart';

/// App-bar bell showing the live unread count. Tapping opens the notification
/// center (`/notifications`). Only mounted for signed-in students (the [TopBar]
/// gates it), so it never fires an authenticated request on a logged-out screen.
class NotificationBell extends ConsumerWidget {
  const NotificationBell({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // `.select` so only the number matters: a title/body change in the feed does
    // not rebuild every screen's app bar.
    final unread = ref.watch(notificationsProvider.select((s) => s.unread));

    return IconButton(
      tooltip: 'Notifications',
      onPressed: () => context.push('/notifications'),
      icon: Badge(
        isLabelVisible: unread > 0,
        label: Text(unread > 99 ? '99+' : '$unread'),
        child: const Icon(Icons.notifications_outlined),
      ),
    );
  }
}
