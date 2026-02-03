import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:fluttersdk_magic_notifications/fluttersdk_magic_notifications.dart';

import '../components/pagination_controls.dart';

/// NotificationsListView
///
/// Full-page view for listing all notifications with mark as read,
/// delete, pagination, and view all functionality.
class NotificationsListView extends StatefulWidget {
  final Stream<List<DatabaseNotification>> notificationStream;
  final Future<void> Function(String id)? onMarkAsRead;
  final Future<void> Function()? onMarkAllAsRead;
  final Future<void> Function(String id)? onDelete;
  final void Function(String path)? onNavigate;

  const NotificationsListView({
    super.key,
    required this.notificationStream,
    this.onMarkAsRead,
    this.onMarkAllAsRead,
    this.onDelete,
    this.onNavigate,
  });

  @override
  State<NotificationsListView> createState() => _NotificationsListViewState();
}

class _NotificationsListViewState extends State<NotificationsListView> {
  static const int _itemsPerPage = 10;
  int _currentPage = 1;

  @override
  Widget build(BuildContext context) {
    return StreamBuilder<List<DatabaseNotification>>(
      stream: widget.notificationStream,
      builder: (context, snapshot) {
        final allNotifications = snapshot.data ?? [];
        final hasUnread = allNotifications.any((n) => !n.isRead);
        final isLoading =
            snapshot.connectionState == ConnectionState.waiting &&
                !snapshot.hasData;
        final hasError = snapshot.hasError;

        // Pagination calculations
        final totalItems = allNotifications.length;
        final totalPages = (totalItems / _itemsPerPage).ceil();
        final startIndex = (_currentPage - 1) * _itemsPerPage;
        final endIndex = (startIndex + _itemsPerPage).clamp(0, totalItems);
        final paginatedNotifications =
            allNotifications.sublist(startIndex, endIndex);

        return SingleChildScrollView(
          child: WDiv(
            className: 'flex flex-col gap-6 p-4 lg:p-6',
            children: [
              _buildHeader(context, hasUnread: hasUnread),
              _buildContent(
                context,
                notifications: paginatedNotifications,
                isLoading: isLoading,
                hasError: hasError,
              ),
              if (!isLoading && !hasError && totalPages > 1)
                _buildPagination(totalPages),
            ],
          ),
        );
      },
    );
  }

  Widget _buildHeader(BuildContext context, {required bool hasUnread}) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl p-5
      ''',
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            WButton(
              onTap: _handleBack,
              className:
                  'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
              child: WIcon(
                Icons.arrow_back,
                className: 'text-xl text-gray-700 dark:text-gray-300',
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: WText(
                trans('notifications.title'),
                className: 'text-xl font-bold text-gray-900 dark:text-white',
              ),
            ),
            if (widget.onMarkAllAsRead != null && hasUnread)
              WButton(
                onTap: () => widget.onMarkAllAsRead?.call(),
                className: '''
                  px-3 py-2 rounded-lg
                  bg-primary/10 dark:bg-primary/20
                  hover:bg-primary/20 dark:hover:bg-primary/30
                ''',
                child: WText(
                  trans('notifications.mark_all_read'),
                  className: 'text-sm font-medium text-primary',
                ),
              ),
          ],
        ),
      ],
    );
  }

  void _handleBack() {
    // Try to go back, if no history go to dashboard
    final canPop =
        MagicRouter.instance.navigatorKey.currentState?.canPop() ?? false;
    if (canPop) {
      MagicRoute.back();
    } else {
      MagicRoute.to('/');
    }
  }

  Widget _buildContent(
    BuildContext context, {
    required List<DatabaseNotification> notifications,
    required bool isLoading,
    required bool hasError,
  }) {
    // Loading state
    if (isLoading) {
      return const Center(child: CircularProgressIndicator());
    }

    // Error state
    if (hasError) {
      return _buildErrorState();
    }

    // Empty state
    if (notifications.isEmpty) {
      return _buildEmptyState();
    }

    return _buildNotificationsList(notifications);
  }

  Widget _buildEmptyState() {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl p-12
        flex flex-col items-center justify-center w-full
      ''',
      children: [
        WIcon(
          Icons.notifications_off_outlined,
          className: 'text-4xl text-gray-400 dark:text-gray-600 mb-2',
        ),
        WText(
          trans('notifications.empty'),
          className: 'text-sm text-gray-600 dark:text-gray-400',
        ),
      ],
    );
  }

  Widget _buildErrorState() {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl p-12
        flex flex-col items-center justify-center w-full
      ''',
      children: [
        WIcon(
          Icons.error_outline,
          className: 'text-4xl text-red-400 dark:text-red-500 mb-2',
        ),
        WText(
          trans('notifications.load_failed'),
          className: 'text-sm text-gray-600 dark:text-gray-400',
        ),
      ],
    );
  }

  Widget _buildNotificationsList(List<DatabaseNotification> notifications) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
      ''',
      child: Column(
        children: notifications.map((n) => _buildNotificationItem(n)).toList(),
      ),
    );
  }

  Widget _buildNotificationItem(DatabaseNotification notification) {
    final IconData icon = switch (notification.type) {
      'monitor_up' => Icons.check_circle_outline,
      'monitor_degraded' => Icons.warning_outlined,
      'monitor_down' => Icons.error_outline,
      _ => Icons.info_outline,
    };

    final String iconColor = switch (notification.type) {
      'monitor_up' => 'text-green-500',
      'monitor_degraded' => 'text-yellow-500',
      'monitor_down' => 'text-red-500',
      _ => 'text-blue-500',
    };

    // Follow activity_item.dart pattern exactly
    return WAnchor(
      onTap: () async {
        await widget.onMarkAsRead?.call(notification.id);
        if (notification.actionUrl != null && widget.onNavigate != null) {
          widget.onNavigate!(notification.actionUrl!);
        }
      },
      child: WDiv(
        className:
            'flex flex-row items-start gap-4 px-4 py-4 w-full border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700',
        children: [
          // Icon container
          WDiv(
            className: '''
              w-10 h-10 rounded-full
              bg-gray-100 dark:bg-gray-700
              flex items-center justify-center
            ''',
            child: WIcon(icon, className: 'text-xl $iconColor'),
          ),

          // Content - same as activity_item
          Expanded(
            child: WDiv(
              className: 'flex flex-col min-w-0',
              children: [
                WText(
                  notification.title,
                  className: notification.isRead
                      ? 'text-sm text-gray-900 dark:text-white'
                      : 'text-sm text-gray-900 dark:text-white font-semibold',
                ),
                const SizedBox(height: 2),
                WText(
                  notification.body,
                  className: 'text-sm text-gray-500 dark:text-gray-400',
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

          // Delete button
          if (widget.onDelete != null)
            WAnchor(
              onTap: () => widget.onDelete?.call(notification.id),
              child: WDiv(
                className: '''
                  p-2 rounded-lg
                  hover:bg-red-50 dark:hover:bg-red-900/20
                ''',
                child: WIcon(
                  Icons.delete_outline,
                  className: 'text-lg text-gray-400 hover:text-red-500',
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildPagination(int totalPages) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl px-4
      ''',
      child: PaginationControls(
        currentPage: _currentPage,
        totalPages: totalPages,
        hasPrevious: _currentPage > 1,
        hasNext: _currentPage < totalPages,
        onPrevious: () {
          if (_currentPage > 1) {
            setState(() => _currentPage--);
          }
        },
        onNext: () {
          if (_currentPage < totalPages) {
            setState(() => _currentPage++);
          }
        },
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
