import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/utils/error_message.dart';
import '../../../data/models/notification_model.dart';
import '../../../data/repositories/notification_repository.dart';
import '../../auth/providers/auth_provider.dart';

/// How often the feed is refreshed while the app is in the foreground.
const Duration kNotificationsPollInterval = Duration(seconds: 60);

/// Emits a tick every [kNotificationsPollInterval] while the app is visible,
/// mirroring `electionStatusTickerProvider`: the timer pauses while the app is
/// backgrounded and fires immediately on resume, so a student who left the app
/// overnight sees a fresh unread badge the moment they return rather than
/// billing background requests all night.
final notificationTickerProvider = StreamProvider<int>((ref) {
  final controller = StreamController<int>();
  Timer? timer;
  var tick = 0;

  void emit() {
    if (!controller.isClosed) controller.add(++tick);
  }

  void start() {
    timer ??= Timer.periodic(kNotificationsPollInterval, (_) => emit());
  }

  void stop() {
    timer?.cancel();
    timer = null;
  }

  start();

  AppLifecycleListener? lifecycle;
  try {
    lifecycle = AppLifecycleListener(
      onStateChange: (state) {
        if (state == AppLifecycleState.resumed) {
          start();
          emit();
        } else {
          stop();
        }
      },
    );
  } catch (_) {
    // No widget binding (a pure unit test): keep ticking; ref.onDispose still
    // cancels the timer.
  }

  ref.onDispose(() {
    lifecycle?.dispose();
    stop();
    controller.close();
  });

  return controller.stream;
});

class NotificationsState {
  final List<AppNotification> items;
  final int unread;
  final bool isLoading;
  final String? error;

  const NotificationsState({
    this.items = const [],
    this.unread = 0,
    this.isLoading = false,
    this.error,
  });

  NotificationsState copyWith({
    List<AppNotification>? items,
    int? unread,
    bool? isLoading,
    Object? error = _unset,
  }) {
    return NotificationsState(
      items: items ?? this.items,
      unread: unread ?? this.unread,
      isLoading: isLoading ?? this.isLoading,
      error: identical(error, _unset) ? this.error : error as String?,
    );
  }
}

const Object _unset = Object();

class NotificationsNotifier extends StateNotifier<NotificationsState> {
  final NotificationRepository _repository;

  NotificationsNotifier(this._repository)
      : super(const NotificationsState(isLoading: true));

  /// Loads the feed. When [silent] (the poll tick) a failure keeps the last
  /// good list and badge instead of replacing them with an error — a transient
  /// outage should not blank a populated notification center.
  Future<void> refresh({bool silent = false}) async {
    if (!silent) {
      state = state.copyWith(isLoading: true, error: null);
    }

    try {
      final feed = await _repository.getFeed();
      state = NotificationsState(items: feed.items, unread: feed.unread);
    } catch (e) {
      if (silent) return;
      state = state.copyWith(
        isLoading: false,
        error: apiErrorMessage(e, fallback: 'Could not load notifications'),
      );
    }
  }

  /// Optimistically marks one notification read, then persists. A failed write
  /// is left optimistic and reconciled by the next refresh so the tap always
  /// feels instant.
  Future<void> markRead(int id) async {
    var unread = state.unread;
    var changed = false;
    final items = state.items.map((n) {
      if (n.id == id && !n.read) {
        changed = true;
        unread = unread > 0 ? unread - 1 : 0;
        return n.copyWith(read: true);
      }
      return n;
    }).toList();

    if (!changed) return;
    state = state.copyWith(items: items, unread: unread);

    try {
      await _repository.markRead(id: id);
    } catch (_) {
      // Reconcile on the next poll.
    }
  }

  /// Optimistically marks every notification read.
  Future<void> markAllRead() async {
    if (state.unread == 0 && state.items.every((n) => n.read)) return;

    state = state.copyWith(
      items: state.items.map((n) => n.copyWith(read: true)).toList(),
      unread: 0,
    );

    try {
      await _repository.markRead();
    } catch (_) {
      // Reconcile on the next poll.
    }
  }

  /// Drops all state on logout so one account's feed/unread badge never bleeds
  /// into the next session.
  void clear() {
    state = const NotificationsState();
  }
}

final notificationsProvider =
    StateNotifierProvider<NotificationsNotifier, NotificationsState>((ref) {
  final notifier =
      NotificationsNotifier(ref.read(notificationRepositoryProvider));

  // Re-fetch on each foreground poll tick.
  ref.listen(notificationTickerProvider, (_, _) {
    notifier.refresh(silent: true);
  });

  // Clear when the session ends (logout / disabled account).
  ref.listen(authProvider.select((state) => state.student), (previous, next) {
    if (next == null) notifier.clear();
  });

  notifier.refresh();

  return notifier;
});
