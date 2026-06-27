import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart'
    show DropdownMenu, DropdownMenuItem;

/// A single primary-navigation destination in the desktop [Sidebar].
@immutable
class _SidebarNavItem {
  /// Lucide-style icon, taken from Material's icon set.
  final IconData icon;

  /// i18n key under `uptizm.nav.*` resolving the visible label.
  final String labelKey;

  /// Target route path navigated to via [MagicRoute.to].
  final String path;

  const _SidebarNavItem({
    required this.icon,
    required this.labelKey,
    required this.path,
  });
}

/// The desktop sidebar destinations. Settings IS a sidebar item (unlike the
/// mobile bottom bar, where Settings lives in the top-bar account menu).
const List<_SidebarNavItem> _navItems = [
  _SidebarNavItem(
    icon: Icons.dashboard_outlined,
    labelKey: 'uptizm.nav.home',
    path: '/',
  ),
  _SidebarNavItem(
    icon: Icons.monitor_heart_outlined,
    labelKey: 'uptizm.nav.monitors',
    path: '/monitors',
  ),
  _SidebarNavItem(
    icon: Icons.warning_amber_outlined,
    labelKey: 'uptizm.nav.incidents',
    path: '/incidents',
  ),
  _SidebarNavItem(
    icon: Icons.public_outlined,
    labelKey: 'uptizm.nav.status',
    path: '/status',
  ),
  _SidebarNavItem(
    icon: Icons.settings_outlined,
    labelKey: 'uptizm.nav.settings',
    path: '/settings',
  ),
];

/// **The Desktop Sidebar**
///
/// A persistent left rail shown only at `lg` and wider (the shell hides it on
/// mobile in favor of [MobileTopBar] + `BottomNav`). Ported from the design
/// lab's `Sidebar`:
///
/// - A brand row at the top.
/// - The 5 primary nav items (Home / Monitors / Incidents / Status / Settings),
///   each highlighted when its path matches the current route.
/// - An account menu at the bottom, reusing the magic_starter [DropdownMenu]
///   (Settings + Sign out).
///
/// All colors are semantic Wind tokens; every color carries its `dark:` pair.
@immutable
class Sidebar extends StatelessWidget {
  /// The active route path used to highlight the matching nav item.
  final String currentPath;

  /// Creates a [Sidebar] highlighting [currentPath].
  const Sidebar({super.key, required this.currentPath});

  /// Active when the current path equals the item path (root) or descends it.
  bool _isActive(String path) {
    if (path == '/') return currentPath == '/';
    return currentPath == path || currentPath.startsWith('$path/');
  }

  @override
  Widget build(BuildContext context) {
    // 1. Fixed-width rail with a hairline right border on the page canvas.
    return WDiv(
      className: '''
        w-64 h-full flex flex-col shrink-0
        border-r border-color-border bg-surface
      ''',
      children: [
        _buildBrand(),
        // 2. Primary navigation fills the remaining height.
        Expanded(
          child: WDiv(
            className: 'flex flex-col gap-1 px-3 py-2',
            children: [for (final item in _navItems) _buildNavItem(item)],
          ),
        ),
        // 3. Account menu pinned to the bottom, above the home edge.
        _buildAccountMenu(context),
      ],
    );
  }

  Widget _buildBrand() {
    return WDiv(
      className:
          'flex items-center gap-2 px-5 h-16 border-b border-color-border',
      children: [
        WText(
          trans('app.name'),
          className: 'text-lg font-bold text-fg truncate',
        ),
      ],
    );
  }

  Widget _buildNavItem(_SidebarNavItem item) {
    final active = _isActive(item.path);

    // 44px min hit target: h-11 + flex row, active item picks up the muted
    // surface + strong foreground; inactive stays muted with a hover surface.
    return WAnchor(
      onTap: () => MagicRoute.to(item.path),
      child: WDiv(
        states: {if (active) 'active'},
        className: '''
          h-11 px-3 rounded-md flex items-center gap-3
          text-sm font-medium
          text-fg-muted hover:bg-surface-container hover:text-fg
          active:bg-surface-container active:text-fg
        ''',
        children: [
          WIcon(item.icon, className: 'text-[20px]'),
          Expanded(child: WText(trans(item.labelKey), className: 'truncate')),
        ],
      ),
    );
  }

  Widget _buildAccountMenu(BuildContext context) {
    return WDiv(
      className: 'p-3 border-t border-color-border',
      child: DropdownMenu(
        alignment: PopoverAlignment.topLeft,
        items: [
          DropdownMenuItem(
            label: trans('uptizm.nav.settings'),
            onTap: () => MagicRoute.to('/settings'),
          ),
          DropdownMenuItem(label: trans('auth.logout'), onTap: () {}),
        ],
        child: WDiv(
          className: '''
            h-11 px-2 rounded-md flex items-center gap-2
            hover:bg-surface-container
          ''',
          children: [
            WDiv(
              className: '''
                w-8 h-8 rounded-full bg-surface-container
                flex items-center justify-center shrink-0
              ''',
              child: WIcon(
                Icons.person_outline,
                className: 'text-[18px] text-fg',
              ),
            ),
            Expanded(
              child: WText(
                trans('app.name'),
                className: 'text-sm font-medium text-fg truncate',
              ),
            ),
            WIcon(
              Icons.expand_more,
              className: 'text-[18px] text-fg-muted shrink-0',
            ),
          ],
        ),
      ),
    );
  }
}
