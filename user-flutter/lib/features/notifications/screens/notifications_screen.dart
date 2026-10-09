import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/constants/app_text_styles.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/utils/relative_time.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/error_state.dart';
import '../../../core/widgets/loading_indicator.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/models/notification_model.dart';
import '../providers/notifications_provider.dart';

/// In-app notification center, reached from the app-bar bell.
///
/// Every row is scoped to the signed-in student. Tapping a row marks it read
/// and, when the server attached a `link`, deep-links into the relevant screen.
class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(notificationsProvider);
    final notifier = ref.read(notificationsProvider.notifier);

    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: TopBar(
        title: 'Notifications',
        actions: [
          if (state.unread > 0)
            TextButton(
              onPressed: notifier.markAllRead,
              child: const Text('Mark all read'),
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => notifier.refresh(),
        child: _body(context, ref, state),
      ),
    );
  }

  Widget _body(
    BuildContext context,
    WidgetRef ref,
    NotificationsState state,
  ) {
    if (state.items.isEmpty) {
      if (state.isLoading) {
        return const LoadingIndicator();
      }
      if (state.error != null) {
        return _scrollable(
          ErrorState(
            message: state.error!,
            onRetry: () =>
                ref.read(notificationsProvider.notifier).refresh(),
          ),
        );
      }
      return _scrollable(
        const EmptyState(
          icon: Icons.notifications_none,
          message: 'No notifications yet',
          subMessage:
              'Election updates, results and account messages will appear here.',
        ),
      );
    }

    return ListView.separated(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: AppSpacing.screenPadding,
      itemCount: state.items.length,
      separatorBuilder: (_, _) => AppSpacing.vSm,
      itemBuilder: (context, index) {
        final notification = state.items[index];
        return _NotificationTile(
          notification: notification,
          onTap: () {
            ref
                .read(notificationsProvider.notifier)
                .markRead(notification.id);
            _openLink(context, notification.link);
          },
        );
      },
    );
  }

  /// A `RefreshIndicator` needs a scrollable child even when the content is a
  /// centered placeholder.
  Widget _scrollable(Widget child) {
    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: constraints.maxHeight),
          child: child,
        ),
      ),
    );
  }

  /// Shell branches keep the bottom navigation, so they are entered with `go`;
  /// everything else is a pushed screen.
  static const _shellRoutes = {
    '/dashboard',
    '/vote-now',
    '/candidates',
    '/ballot',
    '/results',
  };

  void _openLink(BuildContext context, String? link) {
    if (link == null || link.isEmpty) return;
    if (_shellRoutes.contains(link)) {
      context.go(link);
    } else {
      context.push(link);
    }
  }
}

class _NotificationTile extends StatelessWidget {
  final AppNotification notification;
  final VoidCallback onTap;

  const _NotificationTile({required this.notification, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final appText = AppTextStyles.of(context);

    return AppCard(
      padding: EdgeInsets.zero,
      child: ListTile(
        onTap: onTap,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.xs,
        ),
        leading: CircleAvatar(
          backgroundColor: _tint(scheme).withValues(alpha: 0.14),
          child: Icon(_icon(), color: _tint(scheme), size: 20),
        ),
        title: Text(
          notification.title,
          style: appText.cardTitle.copyWith(
            fontWeight: notification.read ? FontWeight.w600 : FontWeight.bold,
          ),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (notification.body.isNotEmpty) ...[
              AppSpacing.vXs,
              Text(notification.body, style: appText.bodySmall),
            ],
            AppSpacing.vXs,
            Text(
              relativeTimeFromIso(notification.createdAt),
              style: appText.labelSmall,
            ),
          ],
        ),
        trailing: notification.read
            ? null
            : Container(
                width: AppSpacing.sm,
                height: AppSpacing.sm,
                decoration: BoxDecoration(
                  color: scheme.primary,
                  shape: BoxShape.circle,
                ),
              ),
      ),
    );
  }

  IconData _icon() {
    switch (notification.type) {
      case 'success':
        return Icons.check_circle_outline;
      case 'warning':
        return Icons.warning_amber_outlined;
      case 'danger':
        return Icons.error_outline;
      default:
        return Icons.info_outline;
    }
  }

  Color _tint(ColorScheme scheme) {
    switch (notification.type) {
      case 'success':
        return const Color(0xFF2E7D32);
      case 'warning':
        return const Color(0xFFB26A00);
      case 'danger':
        return scheme.error;
      default:
        return scheme.primary;
    }
  }
}
