import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Application shell layout.
///
/// Desktop (>=lg): fixed sidebar (w-64) on left + content area.
/// Mobile (<lg): content area + bottom tab bar.
///
/// ## Usage
/// ```dart
/// AppShell(child: MonitorShowView())
/// ```
class AppShell extends StatelessWidget {
  const AppShell({super.key, required this.child});

  /// Page content rendered in the main content area.
  final Widget child;

  // -------  Navigation Data  -------

  static List<_NavEntry> _buildSidebarItems() => <_NavEntry>[
    _NavEntry(
      Icons.grid_view_outlined,
      Icons.grid_view_rounded,
      trans('navigation.dashboard'),
      '/',
    ),
    _NavEntry(
      Icons.monitor_heart_outlined,
      Icons.monitor_heart,
      trans('navigation.monitors'),
      '/monitors',
    ),
    _NavEntry(
      Icons.notifications_outlined,
      Icons.notifications,
      trans('navigation.alerts'),
      '/alerts',
    ),
    _NavEntry(
      Icons.rule_outlined,
      Icons.rule,
      trans('navigation.alert_rules'),
      '/alert-rules',
    ),
    _NavEntry(
      Icons.warning_amber_outlined,
      Icons.warning_amber,
      trans('navigation.incidents'),
      '/incidents',
    ),
    _NavEntry(
      Icons.dns_outlined,
      Icons.dns,
      trans('navigation.status_pages'),
      '/status-pages',
    ),
  ];

  static List<_NavEntry> _buildBottomTabs() => <_NavEntry>[
    _NavEntry(
      Icons.grid_view_outlined,
      Icons.grid_view_rounded,
      trans('navigation.dashboard'),
      '/',
    ),
    _NavEntry(
      Icons.monitor_heart_outlined,
      Icons.monitor_heart,
      trans('navigation.monitors'),
      '/monitors',
    ),
    _NavEntry(
      Icons.insert_chart_outlined,
      Icons.insert_chart,
      trans('navigation.status'),
      '/status',
    ),
    _NavEntry(
      Icons.settings_outlined,
      Icons.settings,
      trans('navigation.settings'),
      '/settings',
    ),
  ];

  // -------  Helpers  -------

  String _currentPath(BuildContext context) {
    return GoRouterState.of(context).uri.path;
  }

  bool _isActive(String path, BuildContext context) {
    final current = _currentPath(context);
    if (path == '/') return current == '/';
    return current.startsWith(path);
  }

  @override
  Widget build(BuildContext context) {
    if (context.wIsDesktop) {
      return _buildDesktop(context);
    }
    return _buildMobile(context);
  }

  // -------  Desktop Layout  -------

  Widget _buildDesktop(BuildContext context) {
    return Scaffold(
      backgroundColor: wColor(
        context,
        'white',
        darkColorName: 'gray',
        darkShade: 900,
      ),
      body: WDiv(
        className: 'flex flex-row h-full',
        children: [
          _buildSidebar(context),
          WDiv(className: 'flex-1', child: child),
        ],
      ),
    );
  }

  Widget _buildSidebar(BuildContext context) {
    final sidebarItems = _buildSidebarItems();

    return WDiv(
      className: '''
        w-64 h-full flex flex-col
        bg-white dark:bg-gray-900
        border-r border-gray-200 dark:border-gray-700
      ''',
      children: [
        // Brand header
        WDiv(
          className: '''
            w-full h-16 px-5 flex flex-row items-center
            border-b border-gray-200 dark:border-gray-700
          ''',
          child: WText(
            trans('app.name'),
            className: 'text-xl font-bold text-primary dark:text-primary-400',
          ),
        ),

        // Navigation items
        WDiv(
          className: 'flex-1 flex flex-col gap-1 p-3 overflow-y-auto',
          children: sidebarItems
              .map(
                (item) => _SidebarItem(
                  item: item,
                  isActive: _isActive(item.path, context),
                ),
              )
              .toList(),
        ),
      ],
    );
  }

  // -------  Mobile Layout  -------

  Widget _buildMobile(BuildContext context) {
    return Scaffold(
      backgroundColor: wColor(
        context,
        'white',
        darkColorName: 'gray',
        darkShade: 900,
      ),
      body: SafeArea(child: child),
      bottomNavigationBar: _BottomTabBar(
        tabs: _buildBottomTabs(),
        currentPath: _currentPath(context),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Sidebar Item
// ---------------------------------------------------------------------------

class _SidebarItem extends StatelessWidget {
  const _SidebarItem({required this.item, required this.isActive});

  final _NavEntry item;
  final bool isActive;

  @override
  Widget build(BuildContext context) {
    return WButton(
      onTap: () => MagicRoute.to(item.path),
      states: {if (isActive) 'active'},
      className: '''
        w-full px-3 py-2.5 rounded-lg no-underline
        flex flex-row items-center gap-3
        text-gray-500 dark:text-gray-400
        hover:bg-gray-100 dark:hover:bg-gray-800
        active:text-primary active:bg-primary-50
        active:dark:text-primary-400 active:dark:bg-primary-900/30
      ''',
      child: WDiv(
        className: 'flex flex-row items-center gap-3',
        children: [
          WIcon(
            isActive ? item.activeIcon : item.inactiveIcon,
            className: 'text-[20px]',
          ),
          WText(label, className: 'text-sm font-medium no-underline'),
        ],
      ),
    );
  }

  String get label => item.label;
}

// ---------------------------------------------------------------------------
// Bottom Tab Bar
// ---------------------------------------------------------------------------

class _BottomTabBar extends StatelessWidget {
  const _BottomTabBar({required this.tabs, required this.currentPath});

  final List<_NavEntry> tabs;
  final String currentPath;

  int get _activeIndex {
    for (var i = tabs.length - 1; i >= 0; i--) {
      final path = tabs[i].path;
      if (path == '/' && currentPath == '/') return i;
      if (path != '/' && currentPath.startsWith(path)) return i;
    }
    return 0;
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        border-t border-gray-200 dark:border-gray-700
        bg-white dark:bg-gray-900
      ''',
      child: SafeArea(
        top: false,
        child: WDiv(
          className: 'flex flex-row h-[49px]',
          children: [
            for (var i = 0; i < tabs.length; i++)
              WDiv(
                className: 'flex-1',
                child: _TabItem(tab: tabs[i], isActive: _activeIndex == i),
              ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Tab Item
// ---------------------------------------------------------------------------

class _TabItem extends StatelessWidget {
  const _TabItem({required this.tab, required this.isActive});

  final _NavEntry tab;
  final bool isActive;

  @override
  Widget build(BuildContext context) {
    return WButton(
      onTap: () => MagicRoute.to(tab.path),
      states: {if (isActive) 'active'},
      className: '''
        w-full h-full py-1
        flex flex-col items-center justify-center gap-0.5
      ''',
      child: WDiv(
        className: 'flex flex-col items-center justify-center gap-0.5',
        children: [
          WIcon(
            isActive ? tab.activeIcon : tab.inactiveIcon,
            states: {if (isActive) 'active'},
            className: '''
              text-[22px]
              text-gray-400 dark:text-gray-500
              active:text-primary dark:active:text-primary-400
            ''',
          ),
          WText(
            tab.label,
            states: {if (isActive) 'active'},
            className: '''
              text-[10px]
              text-gray-400 dark:text-gray-500
              active:text-primary dark:active:text-primary-400
            ''',
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Nav Entry
// ---------------------------------------------------------------------------

class _NavEntry {
  const _NavEntry(this.inactiveIcon, this.activeIcon, this.label, this.path);

  final IconData inactiveIcon;
  final IconData activeIcon;
  final String label;
  final String path;
}
