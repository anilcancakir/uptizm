import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../app/mocks/teams.dart';
import '../components/notification_center/index.dart';

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
    labelKey: 'uptizm.nav.dashboard',
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
    labelKey: 'uptizm.nav.status_page',
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
/// - A top row with the [_TeamSwitcher] (team avatar + name + popover) and the
///   [_NotificationBell] (bell + unread badge + the [NotificationCenter] panel).
/// - The 5 primary nav items (Dashboard / Monitors / Incidents / Status page /
///   Settings), each highlighted when its path matches the current route.
/// - An account menu pinned to the bottom (avatar + name + email) opening a
///   popover with Settings + Sign out.
///
/// All colors are semantic Wind tokens; every color carries its `dark:` pair.
/// The one exception is the per-team avatar tint ([Team.color]), which is
/// content data passed inline to `WDiv.backgroundColor`, the direct analogue of
/// the React source's `style={{ background: team.color }}`.
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
        w-56 h-full flex flex-col shrink-0
        border-r border-color-border bg-surface
      ''',
      children: [
        // 2. Team switcher + notification bell.
        WDiv(
          className: 'flex items-center gap-1 p-3',
          children: [
            const Expanded(child: _TeamSwitcher()),
            const _NotificationBell(),
          ],
        ),
        // 3. Primary navigation fills the remaining height.
        Expanded(
          child: WDiv(
            className: 'flex flex-col gap-1 px-3',
            children: [for (final item in _navItems) _buildNavItem(item)],
          ),
        ),
        // 4. Account menu pinned to the bottom, above the home edge.
        const _AccountMenu(),
      ],
    );
  }

  Widget _buildNavItem(_SidebarNavItem item) {
    final active = _isActive(item.path);

    // Active item picks up the muted surface + strong foreground; inactive
    // stays muted with a hover surface. py-2 matches the design lab's compact
    // row height (no fixed h-11).
    return WAnchor(
      onTap: () => MagicRoute.to(item.path),
      child: WDiv(
        states: {if (active) 'active'},
        className: '''
          px-3 py-2 rounded-md flex items-center gap-3
          text-sm font-medium
          text-fg-muted hover:bg-surface-container hover:text-fg
          active:bg-surface-container active:text-fg
        ''',
        children: [
          WIcon(item.icon, className: 'text-[18px]'),
          Expanded(child: WText(trans(item.labelKey), className: 'truncate')),
        ],
      ),
    );
  }
}

/// **The team switcher** in the sidebar top row.
///
/// A popover trigger showing the active team (colored avatar + name + an
/// unfold chevron); the popover lists every team with a checkmark on the
/// active one, then the team-management destinations. Selecting a team updates
/// the local active team (mirrors the design lab's `useState(teams[0])`).
class _TeamSwitcher extends StatefulWidget {
  const _TeamSwitcher();

  @override
  State<_TeamSwitcher> createState() => _TeamSwitcherState();
}

class _TeamSwitcherState extends State<_TeamSwitcher> {
  /// The active team; seeded to the first fixture, like the React source.
  Team _team = teams.first;

  @override
  Widget build(BuildContext context) {
    return WPopover(
      alignment: PopoverAlignment.bottomLeft,
      offset: const Offset(0, 6),
      className: '''
        w-64 max-w-full overflow-hidden rounded-lg py-1
        bg-surface border border-color-border shadow-xl
      ''',
      triggerBuilder: (context, isOpen, isHovering) => WDiv(
        className: '''
          flex w-full min-w-0 items-center gap-2 rounded-md px-2 py-2
          hover:bg-surface-container
        ''',
        children: [
          _teamAvatar(_team, sizeClass: 'w-7 h-7 rounded-md', text: 'text-xs'),
          Expanded(
            child: WText(
              _team.name,
              className: 'truncate text-sm font-semibold text-fg',
            ),
          ),
          WIcon(Icons.unfold_more, className: 'text-[16px] text-fg-muted'),
        ],
      ),
      contentBuilder: (context, close) => WDiv(
        className: 'flex flex-col',
        children: [
          // Section heading.
          WText(
            trans('uptizm.team_menu.heading'),
            className: '''
              px-3 py-1.5 text-xs font-medium uppercase tracking-wide
              text-fg-muted
            ''',
          ),
          // Team list with a checkmark on the active team.
          for (final t in teams)
            WAnchor(
              onTap: () {
                setState(() => _team = t);
                close();
              },
              child: WDiv(
                className: '''
                  flex items-center gap-2 px-3 py-2 text-sm text-fg
                  hover:bg-surface-container
                ''',
                children: [
                  _teamAvatar(t, sizeClass: 'w-5 h-5 rounded', text: 'text-[10px]'),
                  Expanded(child: WText(t.name, className: 'truncate')),
                  if (t.id == _team.id)
                    WIcon(Icons.check, className: 'text-[16px] text-primary'),
                ],
              ),
            ),
          WDiv(className: 'my-1 border-t border-color-border-subtle'),
          // Team-management destinations. These screens are not built in this
          // vertical, so the rows close the popover without navigating (the
          // design lab routes to /teams/* pages that do not exist here yet).
          _menuRow(trans('uptizm.team_menu.settings'), close),
          _menuRow(trans('uptizm.team_menu.members'), close),
          _menuRow(trans('uptizm.team_menu.channels'), close),
          _menuRow(trans('uptizm.team_menu.escalation'), close),
          _menuRow(trans('uptizm.team_menu.on_call'), close),
          _menuRow(trans('uptizm.team_menu.billing'), close),
          _menuRow(trans('uptizm.team_menu.create'), close),
        ],
      ),
    );
  }

  /// A plain text row inside the team popover that just closes it (mock).
  Widget _menuRow(String label, VoidCallback close) {
    return WAnchor(
      onTap: close,
      child: WDiv(
        className: 'px-3 py-2 text-sm text-fg hover:bg-surface-container',
        child: WText(label, className: 'truncate'),
      ),
    );
  }

  /// The colored team avatar square. [Team.color] is content data, applied via
  /// the inline `backgroundColor` (no semantic token fits an arbitrary tint).
  Widget _teamAvatar(
    Team team, {
    required String sizeClass,
    required String text,
  }) {
    return WDiv(
      backgroundColor: team.color,
      className: '$sizeClass shrink-0 flex items-center justify-center',
      child: WText(
        team.initial,
        className: '$text font-bold text-white',
      ),
    );
  }
}

/// **The notification bell** in the sidebar top row.
///
/// A bell trigger carrying an unread badge that opens the [NotificationCenter]
/// panel in a popover. The badge reflects the seed unread count (the panel
/// owns its own read-state once open).
class _NotificationBell extends StatelessWidget {
  const _NotificationBell();

  @override
  Widget build(BuildContext context) {
    final int unread =
        kSampleNotifications.where((n) => !n.read).length;

    return WPopover(
      alignment: PopoverAlignment.bottomRight,
      offset: const Offset(0, 6),
      className: 'w-80 max-w-full rounded-lg shadow-xl',
      triggerBuilder: (context, isOpen, isHovering) => WDiv(
        className: '''
          w-9 h-9 shrink-0 rounded-md flex items-center justify-center
          text-fg-muted hover:bg-surface-container hover:text-fg
        ''',
        child: Stack(
          clipBehavior: Clip.none,
          alignment: Alignment.center,
          children: [
            WIcon(Icons.notifications_none, className: 'text-[18px]'),
            if (unread > 0)
              Positioned(
                top: -4,
                right: -4,
                child: WDiv(
                  className: '''
                    min-w-[16px] h-4 px-1 rounded-full bg-down
                    flex items-center justify-center
                  ''',
                  child: WText(
                    '$unread',
                    className: 'text-[10px] font-semibold text-white',
                  ),
                ),
              ),
          ],
        ),
      ),
      contentBuilder: (context, close) => NotificationCenter(
        onClose: close,
        onItemTap: (item) => MagicRoute.to(item.to),
        onSettings: () => MagicRoute.to('/settings'),
      ),
    );
  }
}

/// **The bottom account menu.**
///
/// Shows the signed-in user (avatar initials + name + email) and opens a
/// popover with Settings + Sign out. Mirrors the design lab's account dropdown.
class _AccountMenu extends StatelessWidget {
  const _AccountMenu();

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'p-3 border-t border-color-border',
      child: WPopover(
        alignment: PopoverAlignment.topLeft,
        offset: const Offset(0, 6),
        className: '''
          w-56 max-w-full overflow-hidden rounded-lg py-1
          bg-surface border border-color-border shadow-xl
        ''',
        triggerBuilder: (context, isOpen, isHovering) => WDiv(
          className: '''
            flex w-full min-w-0 items-center gap-2 rounded-md px-2 py-2
            hover:bg-surface-container
          ''',
          children: [
            WDiv(
              className: '''
                w-8 h-8 rounded-full bg-surface-container shrink-0
                flex items-center justify-center
              ''',
              child: WText(
                currentUser.initials,
                className: 'text-xs font-semibold text-fg',
              ),
            ),
            Expanded(
              child: WDiv(
                className: 'flex flex-col min-w-0',
                children: [
                  WText(
                    currentUser.name,
                    className: 'truncate text-sm font-medium text-fg',
                  ),
                  WText(
                    currentUser.email,
                    className: 'truncate text-xs text-fg-muted',
                  ),
                ],
              ),
            ),
            WIcon(
              Icons.expand_more,
              className: 'text-[16px] text-fg-muted shrink-0',
            ),
          ],
        ),
        contentBuilder: (context, close) => WDiv(
          className: 'flex flex-col',
          children: [
            WAnchor(
              onTap: () {
                close();
                MagicRoute.to('/settings');
              },
              child: WDiv(
                className: 'px-3 py-2 text-sm text-fg hover:bg-surface-container',
                child: WText(trans('uptizm.nav.settings')),
              ),
            ),
            WDiv(className: 'my-1 border-t border-color-border-subtle'),
            WAnchor(
              onTap: close,
              child: WDiv(
                className: 'px-3 py-2 text-sm text-fg hover:bg-surface-container',
                child: WText(trans('uptizm.account.sign_out')),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
