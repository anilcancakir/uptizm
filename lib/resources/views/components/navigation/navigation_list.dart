import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import 'nav_item.dart';

/// Navigation model for menu items
class NavItemData {
  final IconData icon;
  final String labelKey;
  final String path;
  final IconData? activeIcon;

  const NavItemData({
    required this.icon,
    required this.labelKey,
    required this.path,
    this.activeIcon,
  });
}

/// Main navigation items
const List<NavItemData> mainNavItems = [
  NavItemData(
    icon: Icons.dashboard,
    labelKey: 'nav.dashboard',
    path: '/',
    activeIcon: Icons.dashboard,
  ),
  NavItemData(
    icon: Icons.ssid_chart,
    labelKey: 'nav.monitors',
    path: '/monitors',
    activeIcon: Icons.ssid_chart,
  ),
  NavItemData(
    icon: Icons.notifications_outlined,
    labelKey: 'nav.alerts',
    path: '/alerts',
    activeIcon: Icons.notifications,
  ),
  NavItemData(
    icon: Icons.rule_outlined,
    labelKey: 'nav.alert_rules',
    path: '/alert-rules',
    activeIcon: Icons.rule,
  ),
  NavItemData(
    icon: Icons.warning_amber,
    labelKey: 'nav.incidents',
    path: '/incidents',
    activeIcon: Icons.warning_amber,
  ),
  NavItemData(
    icon: Icons.dns,
    labelKey: 'nav.status_pages',
    path: '/status-pages',
    activeIcon: Icons.dns,
  ),
];

/// System navigation items
const List<NavItemData> systemNavItems = [
  NavItemData(
    icon: Icons.people_outline,
    labelKey: 'nav.team_members',
    path: '/teams/members',
    activeIcon: Icons.people,
  ),
  NavItemData(
    icon: Icons.settings,
    labelKey: 'nav.settings',
    path: '/settings',
    activeIcon: Icons.settings,
  ),
];

/// Bottom navigation items (subset for mobile)
const List<NavItemData> bottomNavItems = [
  NavItemData(
    icon: Icons.dashboard_outlined,
    labelKey: 'nav.dashboard',
    path: '/',
    activeIcon: Icons.dashboard,
  ),
  NavItemData(
    icon: Icons.ssid_chart_outlined,
    labelKey: 'nav.monitors',
    path: '/monitors',
    activeIcon: Icons.ssid_chart,
  ),
  NavItemData(
    icon: Icons.warning_amber_outlined,
    labelKey: 'nav.incidents',
    path: '/incidents',
    activeIcon: Icons.warning_amber,
  ),
  NavItemData(
    icon: Icons.settings_outlined,
    labelKey: 'nav.settings',
    path: '/settings',
    activeIcon: Icons.settings,
  ),
];

/// Navigation List
///
/// Renders a list of navigation items with optional section headers.
/// Used in both sidebar and drawer.
class NavigationList extends StatelessWidget {
  final String currentPath;
  final bool compact;
  final VoidCallback? onItemTap;

  const NavigationList({
    super.key,
    this.currentPath = '/',
    this.compact = false,
    this.onItemTap,
  });

  bool _isActive(String path) {
    if (path == '/') return currentPath == '/';
    return currentPath.startsWith(path);
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      states: compact ? {'compact'} : {},
      className: 'flex flex-col py-4 gap-1 compact:gap-2 w-full',
      children: [
        // Main Navigation
        ...mainNavItems.map(
          (item) => NavItem(
            icon: item.icon,
            label: trans(item.labelKey),
            path: item.path,
            isActive: _isActive(item.path),
            compact: compact,
            onTap: onItemTap != null
                ? () {
                    onItemTap!();
                    MagicRoute.to(item.path);
                  }
                : null,
          ),
        ),

        // Divider
        const NavDivider(),

        // System Section Header
        NavSectionHeader(title: trans('nav.system')),

        // System Navigation
        ...systemNavItems.map(
          (item) => NavItem(
            icon: item.icon,
            label: trans(item.labelKey),
            path: item.path,
            isActive: _isActive(item.path),
            compact: compact,
            onTap: onItemTap != null
                ? () {
                    onItemTap!();
                    MagicRoute.to(item.path);
                  }
                : null,
          ),
        ),
      ],
    );
  }
}
