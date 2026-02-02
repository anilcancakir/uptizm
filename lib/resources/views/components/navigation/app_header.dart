import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../../app/controllers/auth_controller.dart';
import '../notification_dropdown.dart';
import '../search_autocomplete.dart';
import '../theme_toggle_button.dart';
import 'user_profile_card.dart';

/// App Header
///
/// Top header bar with responsive elements.
/// - Mobile: Menu toggle + title + icon-only button
/// - Desktop: Title + search + full button
/// Supports light/dark mode with new Uptizm design system.
class AppHeader extends StatelessWidget {
  final bool showMenuButton;
  final bool showSearch;
  final VoidCallback? onMenuPressed;

  const AppHeader({
    super.key,
    this.showMenuButton = false,
    this.showSearch = true,
    this.onMenuPressed,
  });

  // Mock notifications for demo
  static final List<NotificationItem> _mockNotifications = [
    NotificationItem(
      id: '1',
      title: 'Monitor Down',
      message: 'api.example.com is not responding',
      createdAt: DateTime.now().subtract(const Duration(minutes: 5)),
      type: NotificationType.error,
      actionPath: '/monitors/1',
    ),
    NotificationItem(
      id: '2',
      title: 'Monitor Recovered',
      message: 'web.example.com is back online',
      createdAt: DateTime.now().subtract(const Duration(hours: 1)),
      type: NotificationType.success,
      isRead: true,
    ),
    NotificationItem(
      id: '3',
      title: 'SSL Certificate Expiring',
      message: 'Certificate for app.example.com expires in 7 days',
      createdAt: DateTime.now().subtract(const Duration(days: 1)),
      type: NotificationType.warning,
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        w-full px-2 md:px-6 py-2 md:py-4 
        bg-white/80 dark:bg-gray-900/80 
        border-b border-gray-200 dark:border-gray-700 
        flex items-center
      ''',
      children: [
        WDiv(
          className: 'flex items-center gap-4 flex-1 min-w-0',
          children: [
            if (showMenuButton)
              WAnchor(
                onTap: onMenuPressed ?? () {},
                child: WDiv(
                  className: '''
                    p-2 rounded-lg 
                    hover:bg-gray-100 dark:hover:bg-gray-800 duration-150
                  ''',
                  child: WIcon(
                    Icons.menu,
                    className: 'text-2xl text-gray-500 dark:text-gray-400',
                  ),
                ),
              ),

            // Search Autocomplete
            if (showSearch) const SearchAutocomplete(),
          ],
        ),

        // Right side: Theme Toggle + Notifications + Actions
        WDiv(
          className: 'flex justify-end items-center gap-1',
          children: [
            // Theme Toggle Button
            const ThemeToggleButton(),

            // Notifications Dropdown
            NotificationDropdown(
              notifications: _mockNotifications,
              onMarkAllRead: () {
                // TODO: Mark all notifications as read
              },
              onNotificationTap: (notification) {
                if (notification.actionPath != null) {
                  MagicRoute.to(notification.actionPath!);
                }
              },
              onViewAll: () {
                MagicRoute.to('/notifications');
              },
            ),

            // User Profile
            UserProfileCard(
              onlyAvatar: true,
              showLogout: true,
              onLogout: () {
                AuthController.instance.doLogout();
              },
            ),
          ],
        ),
      ],
    );
  }
}
