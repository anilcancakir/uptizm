import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

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

    return WAnchor(
      onTap: () {
        onNotificationTap?.call(notification);
        close();
      },
      child: WDiv(
        states: notification.isRead ? {} : {'unread'},
        className: '''
          px-4 py-3 w-full
          border-b border-gray-100 dark:border-gray-700
          hover:bg-gray-50 dark:hover:bg-gray-700
          unread:bg-primary/5 dark:unread:bg-primary/10
          flex flex-row gap-3
        ''',
        children: [
          WDiv(
            className: '''
              w-8 h-8 rounded-full 
              bg-gray-100 dark:bg-gray-700
              flex items-center justify-center flex-shrink-0
            ''',
            child: WIcon(icon, className: 'text-lg $iconColor'),
          ),
          Expanded(
            child: WDiv(
              className: 'flex flex-col gap-0.5 min-w-0',
              children: [
                WDiv(
                  className: 'flex flex-row items-center gap-2',
                  children: [
                    Expanded(
                      child: WText(
                        notification.title,
                        states: notification.isRead ? {} : {'unread'},
                        className: '''
                          text-sm text-gray-700 dark:text-gray-200 
                          unread:font-semibold unread:text-gray-900 dark:unread:text-white
                          truncate
                        ''',
                      ),
                    ),
                    if (!notification.isRead)
                      WDiv(
                        className:
                            'w-2 h-2 rounded-full bg-primary flex-shrink-0',
                        child: const SizedBox.shrink(),
                      ),
                  ],
                ),
                WText(
                  notification.message,
                  className: 'text-xs text-gray-500 dark:text-gray-400',
                ),
                WText(
                  _formatTime(notification.createdAt),
                  className: 'text-xs text-gray-400 dark:text-gray-500 mt-1',
                ),
              ],
            ),
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
