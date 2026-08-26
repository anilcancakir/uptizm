import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

/// A single tab in the mobile [BottomNav].
@immutable
class _BottomNavTab {
  /// Lucide-style icon, taken from Material's icon set.
  final IconData icon;

  /// i18n key under `uptizm.nav.*` resolving the visible label.
  final String labelKey;

  /// Target route path navigated to via [MagicRoute.to].
  final String path;

  const _BottomNavTab({
    required this.icon,
    required this.labelKey,
    required this.path,
  });
}

/// The 4 primary mobile destinations. Settings is deliberately NOT a tab; it
/// lives in the [MobileTopBar] account menu instead (Apple-style 4-tab bar).
const List<_BottomNavTab> _tabs = [
  _BottomNavTab(
    icon: Icons.dashboard_outlined,
    labelKey: 'uptizm.nav.home',
    path: '/',
  ),
  _BottomNavTab(
    icon: Icons.monitor_heart_outlined,
    labelKey: 'uptizm.nav.monitors',
    path: '/monitors',
  ),
  _BottomNavTab(
    icon: Icons.warning_amber_outlined,
    labelKey: 'uptizm.nav.incidents',
    path: '/incidents',
  ),
  _BottomNavTab(
    icon: Icons.public_outlined,
    labelKey: 'uptizm.nav.status',
    path: '/status',
  ),
];

/// **The Mobile Bottom Tab Bar**
///
/// An iOS-style fixed bottom bar shown only below `lg`. Holds the 4 primary
/// destinations (Home / Monitors / Incidents / Status); Settings lives in the
/// top-bar account menu, not here. Ported from the design lab's `BottomNav`:
///
/// - **Glass surface:** a [BackdropFilter] blurs whatever scrolls beneath it,
///   over a high-opacity `bg-surface-glass-90` fallback so it stays legible where
///   the platform cannot blur. (Wind has no backdrop token, so the blur is
///   composed here directly per PORTING.md §4.)
/// - **Safe area:** the home-indicator inset is added below the row via
///   [MediaQuery] padding instead of CSS `env()` (PORTING.md §5).
/// - 44px hit targets, active tab in `text-primary`, inactive in `text-fg-muted`.
@immutable
class BottomNav extends StatelessWidget {
  /// The active route path used to highlight the matching tab.
  final String currentPath;

  /// Creates a [BottomNav] highlighting [currentPath].
  const BottomNav({super.key, required this.currentPath});

  /// Active when the current path equals the tab path (root) or descends it.
  bool _isActive(String path) {
    if (path == '/') return currentPath == '/';
    return currentPath == path || currentPath.startsWith('$path/');
  }

  @override
  Widget build(BuildContext context) {
    // 1. Reserve the home-indicator inset below the tab row (no CSS env()).
    final bottomInset = MediaQuery.of(context).viewPadding.bottom;

    // 2. Glass effect: clip the blur to the bar bounds, then blur the content
    //    painted behind it. The high-opacity surface below carries legibility
    //    when the platform has no real backdrop blur.
    return ClipRect(
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
        child: WDiv(
          className: '''
            bg-surface-glass-90 border-t border-color-border
          ''',
          children: [
            WDiv(
              className: 'flex flex-row',
              children: [
                for (final tab in _tabs) Expanded(child: _buildTab(tab)),
              ],
            ),
            SizedBox(height: bottomInset),
          ],
        ),
      ),
    );
  }

  Widget _buildTab(_BottomNavTab tab) {
    final active = _isActive(tab.path);

    // 44px+ hit target via py-2.5 + icon + label column. Active state recolors
    // both icon and label to the brand primary.
    return WAnchor(
      onTap: () => MagicRoute.to(tab.path),
      child: WDiv(
        states: {if (active) 'active'},
        className: '''
          py-2.5 flex flex-col items-center gap-1
          text-fg-muted active:text-primary
        ''',
        children: [
          WIcon(
            tab.icon,
            states: {if (active) 'active'},
            className: 'text-[22px] text-fg-muted active:text-primary',
          ),
          // Clamped, because this row is four equal cells on a phone with
          // nowhere to reflow. iOS accessibility sizes carry a text scale past
          // 2x, and at that scale "Monitors" wrapped to "Monitor" + "s" and ran
          // into "Inciden" + "ts" beside it, growing the bar over the content
          // and turning the labels into one unreadable run. 1.3 still grows the
          // label for someone who asked for larger text, and "Incidents", the
          // longest of the four, still fits its cell. The icons are fixed-size
          // and carry the destination on their own when the label cannot.
          MediaQuery.withClampedTextScaling(
            maxScaleFactor: 1.3,
            child: WText(
              trans(tab.labelKey),
              states: {if (active) 'active'},
              className:
                  'text-[11px] font-medium text-fg-muted active:text-primary',
            ),
          ),
        ],
      ),
    );
  }
}
