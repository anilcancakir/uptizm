import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';

import '../components/app_list.dart';

/// NotificationsListView
///
/// Full-page view for listing all notifications with mark as read,
/// delete, pagination, and view all functionality.
/// Uses server-side pagination for efficiency.
class NotificationsListView extends StatefulWidget {
  final Future<void> Function(String id)? onMarkAsRead;
  final Future<void> Function()? onMarkAllAsRead;
  final Future<void> Function(String id)? onDelete;
  final void Function(String path)? onNavigate;
  final int perPage;

  const NotificationsListView({
    super.key,
    this.onMarkAsRead,
    this.onMarkAllAsRead,
    this.onDelete,
    this.onNavigate,
    this.perPage = 15,
  });

  @override
  State<NotificationsListView> createState() => _NotificationsListViewState();
}

class _NotificationsListViewState extends State<NotificationsListView> {
  PaginatedNotifications? _paginatedData;
  bool _isLoading = true;
  bool _hasError = false;
  int _currentPage = 1;

  @override
  void initState() {
    super.initState();
    _loadPage(1);
  }

  Future<void> _loadPage(int page) async {
    setState(() {
      _isLoading = true;
      _hasError = false;
    });

    try {
      final result = await Notify.fetchPaginatedNotifications(
        page: page,
        perPage: widget.perPage,
      );
      setState(() {
        _paginatedData = result;
        _currentPage = page;
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _hasError = true;
        _isLoading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final notifications = _paginatedData?.data ?? [];
    final hasUnread = notifications.any((n) => !n.isRead);
    final totalPages = _paginatedData?.lastPage ?? 1;

    return WDiv(
      className: 'overflow-y-auto flex flex-col gap-6 p-4 lg:p-6',
      scrollPrimary: true,
      children: [
        _buildHeader(context, hasUnread: hasUnread),
        AppList<DatabaseNotification>(
          items: notifications,
          itemBuilder: (context, notification, index) =>
              _buildNotificationItem(notification),
          isLoading: _isLoading,
          hasError: _hasError,
          emptyIcon: Icons.notifications_off_outlined,
          emptyText: trans('notifications.empty'),
          errorText: trans('notifications.load_failed'),
          currentPage: _currentPage,
          totalPages: totalPages,
          isPaginationLoading: _isLoading,
          onPageChange: (page) => _loadPage(page),
        ),
      ],
    );
  }

  Widget _buildHeader(BuildContext context, {required bool hasUnread}) {
    return WDiv(
      className: '''
        w-full
        flex flex-col sm:flex-row items-start sm:items-center justify-between
        gap-4 pb-4 lg:pb-6
        border-b border-gray-200 dark:border-gray-700
      ''',
      children: [
        // Left: Back button + Title
        WDiv(
          className: 'flex flex-row items-center gap-3',
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
            WDiv(
              className: 'flex flex-col gap-1',
              children: [
                WText(
                  trans('notifications.title'),
                  className: 'text-2xl font-bold text-gray-900 dark:text-white',
                ),
                WText(
                  trans('notifications.list_subtitle'),
                  className: 'text-sm text-gray-600 dark:text-gray-400',
                ),
              ],
            ),
          ],
        ),
        // Right: Mark all as read button
        if (widget.onMarkAllAsRead != null && hasUnread)
          WButton(
            onTap: () => widget.onMarkAllAsRead?.call(),
            className: '''
              px-4 py-2 rounded-lg
              bg-primary hover:bg-green-600
              text-white font-medium text-sm
            ''',
            child: WDiv(
              className: 'flex flex-row items-center gap-2',
              children: [
                WIcon(Icons.done_all, className: 'text-lg text-white'),
                WText(trans('notifications.mark_all_read')),
              ],
            ),
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
            'flex flex-row items-start gap-4 px-4 py-4 w-full hover:bg-gray-50 dark:hover:bg-gray-700',
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
          WDiv(
            className: 'flex-1 flex flex-col min-w-0',
            children: [
              WText(
                notification.title,
                className: notification.isRead
                    ? 'text-sm text-gray-900 dark:text-white'
                    : 'text-sm text-gray-900 dark:text-white font-semibold',
              ),
              const WSpacer(className: 'h-0.5'),
              WText(
                notification.body,
                className: 'text-sm text-gray-500 dark:text-gray-400',
              ),
              const WSpacer(className: 'h-0.5'),
              WText(
                _formatTime(notification.createdAt),
                className: 'text-xs text-gray-400 dark:text-gray-500',
              ),
            ],
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

  String _formatTime(DateTime dateTime) {
    final now = DateTime.now();
    final difference = now.difference(dateTime);

    if (difference.inMinutes < 1) {
      return trans('time.just_now');
    } else if (difference.inHours < 1) {
      return trans('time.minutes_ago', {'minutes': difference.inMinutes});
    } else if (difference.inDays < 1) {
      return trans('time.hours_ago', {'hours': difference.inHours});
    } else if (difference.inDays < 7) {
      return trans('time.days_ago', {'days': difference.inDays});
    } else {
      return '${dateTime.day}/${dateTime.month}/${dateTime.year}';
    }
  }
}
