import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:fluttersdk_magic_notifications/fluttersdk_magic_notifications.dart';

/// Notification model
class NotificationItem {
  final String id;
  final String title;
  final String message;
  final DateTime createdAt;
  final bool isRead;
  final NotificationType type;
  final String? actionPath;

  const NotificationItem({
    required this.id,
    required this.title,
    required this.message,
    required this.createdAt,
    this.isRead = false,
    this.type = NotificationType.info,
    this.actionPath,
  });
}

enum NotificationType { info, success, warning, error }

/// Notification Dropdown
///
/// Dropdown panel for displaying notifications.
/// Uses WPopover for the overlay mechanics.
class NotificationDropdown extends StatelessWidget {
  final List<NotificationItem> notifications;
  final VoidCallback? onMarkAllRead;
  final void Function(NotificationItem)? onNotificationTap;
  final VoidCallback? onViewAll;

  const NotificationDropdown({
    super.key,
    this.notifications = const [],
    this.onMarkAllRead,
    this.onNotificationTap,
    this.onViewAll,
  });

  int get _unreadCount => notifications.where((n) => !n.isRead).length;

  @override
  Widget build(BuildContext context) {
    return WPopover(
      alignment: PopoverAlignment.bottomRight,
      className: '''
        w-80 
        bg-white dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
        rounded-xl shadow-xl
      ''',
      maxHeight: 400,
      triggerBuilder: _buildTrigger,
      contentBuilder: _buildContent,
    );
  }

  Widget _buildTrigger(BuildContext context, bool isOpen, bool isHovering) {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        WDiv(
          states: {if (isOpen) 'active', if (isHovering) 'hover'},
          className: '''
            p-2 rounded-lg duration-150
            bg-transparent hover:bg-gray-100 dark:hover:bg-gray-800
            active:bg-gray-100 dark:active:bg-gray-800
          ''',
          child: WIcon(
            Icons.notifications_outlined,
            className: 'text-2xl text-gray-500 dark:text-gray-400',
          ),
        ),
        if (_unreadCount > 0)
          Positioned(
            top: 4,
            right: 4,
            child: WDiv(
              className: '''
                min-w-[14px] h-[14px] px-1 rounded-full 
                bg-red-500 
                flex items-center justify-center
                animate-bounce duration-500
              ''',
              child: WText(
                _unreadCount > 9 ? '9+' : _unreadCount.toString(),
                className: 'text-[9px] font-bold text-white',
              ),
            ),
          ),
      ],
    );
  }

  Widget _buildContent(BuildContext context, VoidCallback close) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _buildHeader(context),
        Flexible(child: _buildNotificationsList(context, close)),
        if (onViewAll != null) _buildFooter(context, close),
      ],
    );
  }

  Widget _buildHeader(BuildContext context) {
    return WDiv(
      className: '''
        px-4 py-3 w-full
        border-b border-gray-200 dark:border-gray-700
        flex flex-row items-center justify-between
      ''',
      children: [
        WText(
          trans('notifications.title'),
          className: 'text-base font-semibold text-gray-900 dark:text-white',
        ),
        if (_unreadCount > 0 && onMarkAllRead != null)
          WAnchor(
            onTap: onMarkAllRead,
            child: WText(
              trans('notifications.mark_all_read'),
              className: 'text-xs text-primary hover:text-green-600',
            ),
          ),
      ],
    );
  }

  Widget _buildNotificationsList(BuildContext context, VoidCallback close) {
    if (notifications.isEmpty) {
      return WDiv(
        className: 'py-12 flex flex-col items-center justify-center gap-3',
        children: [
          WIcon(
            Icons.notifications_off_outlined,
            className: 'text-4xl text-gray-300 dark:text-gray-600',
          ),
          WText(
            trans('notifications.empty'),
            className: 'text-sm text-gray-500 dark:text-gray-400',
          ),
        ],
      );
    }

    return SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: notifications
            .map((n) => _buildNotificationItem(context, n, close))
            .toList(),
      ),
    );
  }

  Widget _buildNotificationItem(
    BuildContext context,
    NotificationItem notification,
    VoidCallback close,
  ) {
    final IconData icon = switch (notification.type) {
      NotificationType.success => Icons.check_circle,
      NotificationType.warning => Icons.warning,
      NotificationType.error => Icons.error,
      NotificationType.info => Icons.info,
    };

    final String iconColor = switch (notification.type) {
      NotificationType.success => 'text-green-500',
      NotificationType.warning => 'text-yellow-500',
      NotificationType.error => 'text-red-500',
      NotificationType.info => 'text-blue-500',
    };

    // Follow activity_item.dart pattern exactly
    return WAnchor(
      onTap: () {
        onNotificationTap?.call(notification);
        close();
      },
      child: WDiv(
        className: '''
          flex flex-row items-start gap-3 px-4 py-3 w-full
          border-b border-gray-100 dark:border-gray-700
          hover:bg-gray-50 dark:hover:bg-gray-700
          ${notification.isRead ? '' : 'bg-primary/5 dark:bg-primary/10'}
        ''',
        children: [
          // Icon container
          WDiv(
            className: '''
              w-8 h-8 rounded-full
              bg-gray-100 dark:bg-gray-700
              flex items-center justify-center
            ''',
            child: WIcon(icon, className: 'text-lg $iconColor'),
          ),

          // Content - same as activity_item
          Expanded(
            child: WDiv(
              className: 'flex flex-col min-w-0',
              children: [
                WText(
                  notification.title,
                  className: '''
                    text-sm text-gray-900 dark:text-white truncate
                    ${notification.isRead ? '' : 'font-semibold'}
                  ''',
                ),
                const SizedBox(height: 2),
                WText(
                  notification.message,
                  className: 'text-xs text-gray-500 dark:text-gray-400',
                ),
                const SizedBox(height: 2),
                WText(
                  _formatTime(notification.createdAt),
                  className: 'text-xs text-gray-400 dark:text-gray-500',
                ),
              ],
            ),
          ),

          // Unread indicator
          if (!notification.isRead)
            WDiv(
              className: 'w-2 h-2 rounded-full bg-primary mt-2',
              child: const SizedBox.shrink(),
            ),
        ],
      ),
    );
  }

  Widget _buildFooter(BuildContext context, VoidCallback close) {
    return WAnchor(
      onTap: () {
        onViewAll?.call();
        close();
      },
      child: WDiv(
        className: '''
          px-4 py-3 w-full
          border-t border-gray-200 dark:border-gray-700
          hover:bg-gray-50 dark:hover:bg-gray-700
          flex items-center justify-center
        ''',
        child: WText(
          trans('notifications.view_all'),
          className: 'text-sm font-medium text-primary',
        ),
      ),
    );
  }

  String _formatTime(DateTime dateTime) {
    final now = DateTime.now();
    final difference = now.difference(dateTime);

    if (difference.inMinutes < 1) {
      return trans('time.just_now');
    } else if (difference.inHours < 1) {
      return '${difference.inMinutes}m ago';
    } else if (difference.inDays < 1) {
      return '${difference.inHours}h ago';
    } else if (difference.inDays < 7) {
      return '${difference.inDays}d ago';
    } else {
      return '${dateTime.day}/${dateTime.month}/${dateTime.year}';
    }
  }
}

/// Notification Dropdown with Stream
///
/// Connects to real notification stream from NotificationManager.
/// Handles loading, error, and data states via StreamBuilder.
class NotificationDropdownWithStream extends StatelessWidget {
  final Stream<List<DatabaseNotification>> notificationStream;
  final Future<void> Function(String id)? onMarkAsRead;
  final Future<void> Function()? onMarkAllAsRead;
  final void Function(String path)? onNavigate;
  final VoidCallback? onViewAll;

  const NotificationDropdownWithStream({
    super.key,
    required this.notificationStream,
    this.onMarkAsRead,
    this.onMarkAllAsRead,
    this.onNavigate,
    this.onViewAll,
  });

  @override
  Widget build(BuildContext context) {
    return StreamBuilder<List<DatabaseNotification>>(
      stream: notificationStream,
      builder: (context, snapshot) {
        // Loading state
        if (snapshot.connectionState == ConnectionState.waiting &&
            !snapshot.hasData) {
          return _buildLoadingDropdown();
        }

        // Error state
        if (snapshot.hasError) {
          return _buildErrorDropdown();
        }

        // Data state (may be empty list)
        final notifications = snapshot.data ?? [];
        final notificationItems = notifications
            .map(
              (n) => NotificationItem(
                id: n.id,
                title: n.title,
                message: n.body,
                createdAt: n.createdAt,
                isRead: n.isRead,
                type: _mapNotificationType(n.type),
                actionPath: n.actionUrl,
              ),
            )
            .toList();

        return NotificationDropdown(
          notifications: notificationItems,
          onMarkAllRead: () async {
            await onMarkAllAsRead?.call();
          },
          onNotificationTap: (item) async {
            await onMarkAsRead?.call(item.id);
            if (item.actionPath != null && onNavigate != null) {
              onNavigate!(item.actionPath!);
            }
          },
          onViewAll: onViewAll,
        );
      },
    );
  }

  /// Map notification type string to NotificationType enum
  NotificationType _mapNotificationType(String type) {
    switch (type) {
      case 'monitor_down':
        return NotificationType.error;
      case 'monitor_up':
        return NotificationType.success;
      case 'monitor_degraded':
        return NotificationType.warning;
      default:
        return NotificationType.info;
    }
  }

  /// Build loading state dropdown
  Widget _buildLoadingDropdown() {
    return WPopover(
      alignment: PopoverAlignment.bottomRight,
      className: '''
        w-80
        bg-white dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
        rounded-xl shadow-xl
      ''',
      maxHeight: 400,
      triggerBuilder: (context, isOpen, isHovering) =>
          _buildTrigger(context, isOpen, isHovering, unreadCount: 0),
      contentBuilder: (context, close) => _buildLoadingContent(),
    );
  }

  /// Build error state dropdown
  Widget _buildErrorDropdown() {
    return WPopover(
      alignment: PopoverAlignment.bottomRight,
      className: '''
        w-80
        bg-white dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
        rounded-xl shadow-xl
      ''',
      maxHeight: 400,
      triggerBuilder: (context, isOpen, isHovering) =>
          _buildTrigger(context, isOpen, isHovering, unreadCount: 0),
      contentBuilder: (context, close) => _buildErrorContent(),
    );
  }

  /// Build trigger button (bell icon)
  Widget _buildTrigger(
    BuildContext context,
    bool isOpen,
    bool isHovering, {
    required int unreadCount,
  }) {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        WDiv(
          states: {if (isOpen) 'active', if (isHovering) 'hover'},
          className: '''
            p-2 rounded-lg duration-150
            bg-transparent hover:bg-gray-100 dark:hover:bg-gray-800
            active:bg-gray-100 dark:active:bg-gray-800
          ''',
          child: WIcon(
            Icons.notifications_outlined,
            className: 'text-2xl text-gray-500 dark:text-gray-400',
          ),
        ),
        if (unreadCount > 0)
          Positioned(
            top: 4,
            right: 4,
            child: WDiv(
              className: '''
                min-w-[14px] h-[14px] px-1 rounded-full
                bg-red-500
                flex items-center justify-center
                animate-bounce duration-500
              ''',
              child: WText(
                unreadCount > 9 ? '9+' : unreadCount.toString(),
                className: 'text-[9px] font-bold text-white',
              ),
            ),
          ),
      ],
    );
  }

  /// Build loading content
  Widget _buildLoadingContent() {
    return WDiv(
      className: 'py-12 flex items-center justify-center',
      child: const CircularProgressIndicator(),
    );
  }

  /// Build error content
  Widget _buildErrorContent() {
    return WDiv(
      className: 'py-12 flex flex-col items-center justify-center gap-3',
      children: [
        WIcon(Icons.error_outline, className: 'text-4xl text-red-500'),
        WText(
          'Failed to load notifications',
          className: 'text-sm text-gray-600 dark:text-gray-400',
        ),
      ],
    );
  }
}
