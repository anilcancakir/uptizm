import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart'
    show NotificationDropdown, Notify;
import 'package:magic_starter/magic_starter.dart'
    show MagicStarterConfig, MagicStarterTeamController;

import '../../app/models/team.dart';
import '../../app/models/user.dart';
import 'shell_account.dart';
import '../components/notification_center/index.dart';
import '../components/push_prompt/index.dart';
import 'shell_control_semantics.dart';

/// Computes uppercase avatar initials from a display [name].
///
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
///   [_NotificationBell] (the package's [NotificationDropdown], carrying
///   uptizm's own status dot per row).
/// - The 5 primary nav items (Dashboard / Monitors / Incidents / Status page /
///   Settings), each highlighted when its path matches the current route.
/// - An account menu pinned to the bottom (avatar + name + email) opening a
///   popover with Settings + Sign out.
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
        // 4. The admission that this device cannot be paged, directly above
        //    the account menu. Here rather than beside the bell: the bell is
        //    about notifications that ARRIVED, and this is about the ones that
        //    will not, so it belongs with the account's own quiet controls
        //    rather than decorating the unread badge. It renders nothing at all
        //    when push is reachable, and owns its own margins, so the column
        //    below closes up rather than keeping an empty slot.
        const PushOffNotice(),
        // 5. Account menu pinned to the bottom, above the home edge.
        const _AccountMenu(),
      ],
    );
  }

  Widget _buildNavItem(_SidebarNavItem item) {
    final active = _isActive(item.path);

    // The active fill is applied as a plain, conditional class computed here,
    // NOT via an `active:` variant: Wind's alias expander only expands a WHOLE
    // unprefixed token, so a state-prefixed alias like `active:bg-surface-container`
    // never resolves to a color. py-2 matches the design lab's compact row.
    final String className = active
        ? 'px-3 py-2 rounded-md flex items-center gap-3 '
              'text-sm font-medium bg-surface-container text-fg'
        : 'px-3 py-2 rounded-md flex items-center gap-3 '
              'text-sm font-medium text-fg-muted '
              'hover:bg-surface-container hover:text-fg';

    return WAnchor(
      onTap: () => MagicRoute.to(item.path),
      child: WDiv(
        className: className,
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
/// A popover trigger showing the active team ([User.current.currentTeam]:
/// avatar + name + an unfold chevron); the popover lists every team from
/// [User.current.allTeams] with a checkmark on the active one, then the
/// team-management destinations. Selecting a team calls
/// [MagicStarterTeamController.switchTeam], which persists the switch on the
/// backend and calls `Auth.restore()`; the switcher rebuilds via
/// `Auth.stateNotifier` to reflect the new active team.
class _TeamSwitcher extends StatelessWidget {
  const _TeamSwitcher();

  @override
  Widget build(BuildContext context) {
    // Rebuilds on login/logout/restore/switch: `switchTeam` calls
    // `Auth.restore()`, which bumps `Auth.stateNotifier`, so the active team
    // + team list here reflect a switch without a manual reload.
    return AnimatedBuilder(
      animation: authStateNotifier('Sidebar'),
      builder: (context, _) {
        final User user = currentUserSafe('Sidebar');
        final Team? activeTeam = user.currentTeam;
        final List<Team> allTeams = user.allTeams;

        return ShellControlSemantics(
          label: trans('uptizm.a11y.team_switcher'),
          child: WPopover(
              alignment: PopoverAlignment.bottomLeft,
              offset: const Offset(0, 6),
              maxHeight: 480,
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
                  _teamAvatar(
                    activeTeam,
                    sizeClass: 'w-7 h-7 rounded-md',
                    text: 'text-xs',
                  ),
                  Expanded(
                    child: WText(
                      activeTeam?.name ?? '',
                      className: 'truncate text-sm font-semibold text-fg',
                    ),
                  ),
                  WIcon(Icons.unfold_more, className: 'text-[16px] text-fg-muted'),
                ],
              ),
              // WPopover only constrains content to maxHeight (it does not scroll),
              // so the body is wrapped in a scroll view: it sizes to content when
              // short and scrolls when the team + management list exceeds the
              // popover height.
              contentBuilder: (context, close) => SingleChildScrollView(
                child: WDiv(
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
                    for (final t in allTeams)
                      WAnchor(
                        onTap: () {
                          MagicStarterTeamController.instance.switchTeam(t.id);
                          close();
                        },
                        child: WDiv(
                          className: '''
                            flex items-center gap-2 px-3 py-2 text-sm text-fg
                            hover:bg-surface-container
                          ''',
                          children: [
                            _teamAvatar(
                              t,
                              sizeClass: 'w-5 h-5 rounded',
                              text: 'text-[10px]',
                            ),
                            Expanded(child: WText(t.name ?? '', className: 'truncate')),
                            if (t.id == activeTeam?.id)
                              WIcon(Icons.check, className: 'text-[16px] text-primary'),
                          ],
                        ),
                      ),
                    WDiv(className: 'my-1 border-t border-color-border-subtle'),
                    // Team-management destinations. Settings, members (folded into
                    // settings) and create are owned by the magic_starter team
                    // routes; channels/escalation/on-call/billing are uptizm-domain
                    // routes. Each row closes the popover and navigates.
                    // ONE list for both shells: see `teamMenuDestinations`.
                    // These used to be two literals, and the mobile one was
                    // three rows short.
                    for (final TeamMenuDestination row in teamMenuDestinations)
                      teamMenuRow(trans(row.labelKey), row.route, close),
                  ],
                ),
              ),
            ),
        );
      },
    );
  }

  /// The team avatar square: brand-tinted background carrying the team's
  /// leading initial. Real teams have no per-tenant brand color, so this uses
  /// a semantic token (`bg-primary-container`/`text-fg`) instead of the design
  /// lab's arbitrary inline tint.
  Widget _teamAvatar(
    Team? team, {
    required String sizeClass,
    required String text,
  }) {
    return WDiv(
      className: '''
        $sizeClass shrink-0 flex items-center justify-center
        bg-primary-container
      ''',
      child: WText(
        teamInitial(team?.name),
        className: '$text font-bold text-fg',
      ),
    );
  }
}

/// **The notification bell** in the sidebar top row.
///
/// The bell, its unread badge and the feed panel all come from
/// `magic_notifications` now; what stays uptizm's is the leading status dot
/// (supplied through the `notifications.icon` slot family in
/// [AppServiceProvider.registerNotificationSurface]) and where a tapped row
/// goes. The feed itself is fed by `Notify`'s polling / socket, started and
/// stopped on login and logout in `AppServiceProvider`.
class _NotificationBell extends StatelessWidget {
  const _NotificationBell();

  @override
  Widget build(BuildContext context) {
    return ShellControlSemantics(
      label: trans('uptizm.a11y.notifications'),
      child: NotificationDropdown(
        notificationStream: notificationsStream('Sidebar'),
        onMarkAsRead: (id) => Notify.markAsRead(id),
        onMarkAllAsRead: () => Notify.markAllAsRead(),
        onNotificationTap: (notification) =>
            MagicRoute.to(notificationRouteFor(notification)),
        onViewAll: () => MagicRoute.to(MagicStarterConfig.notificationsRoute()),
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
    // Rebuilds on login/logout/restore: a profile-update also calls
    // `Auth.restore()`, so a name/email change reflects here without reload.
    return AnimatedBuilder(
      animation: authStateNotifier('Sidebar'),
      builder: (context, _) {
        final User user = currentUserSafe('Sidebar');

        return WDiv(
          className: 'p-3 border-t border-color-border',
          child: ShellControlSemantics(
            label: trans('uptizm.a11y.account_menu'),
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
                        userInitials(user.name),
                        className: 'text-xs font-semibold text-fg',
                      ),
                    ),
                    Expanded(
                      child: WDiv(
                        className: 'flex flex-col min-w-0',
                        children: [
                          WText(
                            user.name ?? '',
                            className: 'truncate text-sm font-medium text-fg',
                          ),
                          WText(
                            user.email ?? '',
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
                      onTap: () {
                        close();
                        handleLogout();
                      },
                      child: WDiv(
                        className: 'px-3 py-2 text-sm text-fg hover:bg-surface-container',
                        child: WText(trans('uptizm.account.sign_out')),
                      ),
                    ),
                  ],
                ),
              ),
          ),
        );
      },
    );
  }

}
