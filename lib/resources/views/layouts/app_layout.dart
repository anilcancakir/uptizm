import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';

import '../../../app/controllers/team_controller.dart';
import '../components/navigation/app_header.dart';
import '../components/navigation/app_sidebar.dart';
import '../components/navigation/navigation_list.dart';
import '../components/navigation/team_selector.dart';

/// App Layout
///
/// Main layout wrapper for authenticated pages.
/// - Web (≥768px): Fixed sidebar on left
/// - Mobile (<768px): Drawer sidebar + Bottom navigation bar
/// Supports light/dark mode with new Uptizm design system.
class AppLayout extends StatefulWidget {
  final Widget child;
  final String? title;

  static final ValueNotifier<int> refreshNotifier = ValueNotifier(0);

  const AppLayout({super.key, required this.child, this.title});

  @override
  State<AppLayout> createState() => _AppLayoutState();
}

class _AppLayoutState extends State<AppLayout> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();

  @override
  void initState() {
    super.initState();
    AppLayout.refreshNotifier.addListener(_refresh);

    // Start notification polling when app layout mounts (user is authenticated)
    // Note: startPolling() already calls fetchNotifications() immediately
    // Wrapped in try-catch for test environments where Magic may not be initialized
    try {
      Notify.startPolling();
    } catch (_) {
      // Silently fail in test environments
    }
  }

  @override
  void dispose() {
    AppLayout.refreshNotifier.removeListener(_refresh);
    super.dispose();
  }

  void _refresh() {
    if (mounted) setState(() {});
  }

  void _openDrawer() {
    _scaffoldKey.currentState?.openDrawer();
  }

  String _getCurrentPath(BuildContext context) {
    try {
      return GoRouterState.of(context).uri.path;
    } catch (_) {
      return '/';
    }
  }

  @override
  Widget build(BuildContext context) {
    final currentPath = _getCurrentPath(context);

    return LayoutBuilder(
      builder: (context, constraints) {
        final isDesktop = wScreenIs(context, 'lg');

        return Scaffold(
          key: _scaffoldKey,
          backgroundColor: wColor(
            context,
            'gray',
            shade: 50,
            darkColorName: 'gray',
            darkShade: 950,
          ),
          // Drawer for mobile - uses AppSidebar
          drawer: isDesktop ? null : _buildDrawer(context, currentPath),
          body: SafeArea(
            // Keep bottom: false because bottomNavigationBar handles its own safe area
            bottom: false,
            child: WDiv(
              className: 'flex flex-row w-full h-full',
              children: [
                // Sidebar - only on desktop/web
                if (isDesktop) AppSidebar(currentPath: currentPath),

                // Main content area
                WDiv(
                  className: 'flex-1 flex flex-col h-full',
                  children: [
                    // Header
                    AppHeader(
                      showMenuButton: !isDesktop,
                      showSearch: isDesktop,
                      onMenuPressed: _openDrawer,
                    ),

                    // Page content
                    WDiv(className: 'flex-1', child: widget.child),
                  ],
                ),
              ],
            ),
          ),
          // Bottom navigation - only on mobile
          bottomNavigationBar: isDesktop
              ? null
              : _buildBottomNav(context, currentPath),
        );
      },
    );
  }

  Widget _buildDrawer(BuildContext context, String currentPath) {
    return Drawer(
      backgroundColor: wColor(
        context,
        'white',
        darkColorName: 'gray',
        darkShade: 900,
      ),
      child: SafeArea(
        child: WDiv(
          className: 'flex flex-col h-full',
          children: [
            // Close button header
            WDiv(
              className: '''
                px-4 py-3 w-full
                border-b border-gray-200 dark:border-gray-700
                flex flex-row items-center justify-between
              ''',
              children: [
                WText(
                  trans('app.name'),
                  className: 'text-lg font-bold text-primary',
                ),
                WAnchor(
                  onTap: () => Navigator.of(context).pop(),
                  child: WDiv(
                    className: '''
                      p-2 rounded-lg 
                      hover:bg-gray-100 dark:hover:bg-gray-800
                    ''',
                    child: WIcon(
                      Icons.close,
                      className: 'text-2xl text-gray-500 dark:text-gray-400',
                    ),
                  ),
                ),
              ],
            ),

            // Team/Organization Selector
            WDiv(
              className: '''
                px-4 py-3 w-full
                border-b border-gray-200 dark:border-gray-700
              ''',
              child: TeamSelector(
                onTeamSelect: (team) {
                  Navigator.of(context).pop();
                  TeamController.instance.switchTeam(team);
                },
                onTeamSettings: () {
                  Navigator.of(context).pop();
                  MagicRoute.to('/teams/settings');
                },
                onCreateTeam: () {
                  Navigator.of(context).pop();
                  MagicRoute.to('/teams/create');
                },
              ),
            ),

            // Navigation List
            WDiv(
              className: 'flex-1',
              child: NavigationList(
                currentPath: currentPath,
                onItemTap: () => Navigator.of(context).pop(),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildBottomNav(BuildContext context, String currentPath) {
    final bottomPadding = MediaQuery.of(context).viewPadding.bottom;

    return WDiv(
      className:
          'bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700',
      children: [
        WDiv(
          className: 'flex flex-row justify-between px-4',
          children: bottomNavItems
              .map(
                (item) => _buildNavItem(
                  context,
                  icon: item.icon,
                  activeIcon: item.activeIcon ?? item.icon,
                  label: trans(item.labelKey),
                  path: item.path,
                  isActive: item.path == '/'
                      ? currentPath == '/'
                      : currentPath.startsWith(item.path),
                ),
              )
              .toList(),
        ),
        // Safe area padding for home indicator
        SizedBox(height: bottomPadding),
      ],
    );
  }

  Widget _buildNavItem(
    BuildContext context, {
    required IconData icon,
    required IconData activeIcon,
    required String label,
    required String path,
    required bool isActive,
  }) {
    return WAnchor(
      onTap: () => MagicRoute.to(path),
      child: WDiv(
        className: 'py-2 flex flex-col items-center gap-1',
        children: [
          WIcon(
            isActive ? activeIcon : icon,
            states: isActive ? {'active'} : {},
            className: 'text-2xl text-gray-400 active:text-primary',
          ),
          WText(
            label,
            states: isActive ? {'active'} : {},
            className: 'text-xs text-gray-400 active:text-primary',
          ),
        ],
      ),
    );
  }
}
