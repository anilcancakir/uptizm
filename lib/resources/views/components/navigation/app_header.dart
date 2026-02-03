import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:fluttersdk_magic_notifications/fluttersdk_magic_notifications.dart';

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

            // Notifications Dropdown with real data
            NotificationDropdownWithStream(
              notificationStream: Notify.notifications(),
              onMarkAsRead: (id) => Notify.markAsRead(id),
              onMarkAllAsRead: () => Notify.markAllAsRead(),
              onNavigate: (path) => MagicRoute.to(path),
              onViewAll: () => MagicRoute.to('/notifications'),
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
