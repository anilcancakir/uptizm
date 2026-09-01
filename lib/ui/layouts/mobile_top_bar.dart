import 'dart:ui' show ImageFilter;

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
import 'shell_control_semantics.dart';

/// Computes uppercase avatar initials from a display [name].
///
/// **The Mobile Top Bar**
///
/// A sticky, safe-area-aware glass header shown only below `lg` (the desktop
/// [Sidebar] takes over above it). Ported from the design lab's `MobileTopBar`:
///
/// - **Left:** a team switcher (colored avatar + dynamic team name + a chevron
///   right next to the name) opening a popover to switch team or jump to the
///   team-management destinations.
/// - **Right:** the notification bell (with unread badge) and the account
///   avatar (initials) opening an account popover (Settings + Sign out).
/// - **Glass surface:** a [BackdropFilter] over a high-opacity `bg-surface`
///   fallback, composed directly because Wind has no backdrop token.
/// - **Safe area:** the notch / status-bar inset is added above the bar via
///   [MediaQuery] padding instead of CSS `env()`.
@immutable
class MobileTopBar extends StatelessWidget {
  /// Creates a [MobileTopBar].
  const MobileTopBar({super.key});

  @override
  Widget build(BuildContext context) {
    // 1. Reserve the status-bar / notch inset above the bar (no CSS env()).
    final topInset = MediaQuery.of(context).viewPadding.top;

    // 2. Glass effect: blur whatever scrolls beneath the sticky bar, over a
    //    high-opacity surface fallback for platforms without real blur.
    return ClipRect(
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
        child: WDiv(
          className: 'bg-surface-glass-80 border-b border-color-border',
          children: [
            SizedBox(height: topInset),
            WDiv(
              className: '''
                h-14 px-4 flex flex-row items-center justify-between gap-3
              ''',
              children: [
                // The switcher flexes so its truncating label can shrink,
                // leaving the right-hand controls their full footprint.
                const Flexible(child: _MobileTeamSwitcher()),
                WDiv(
                  className: 'flex flex-row items-center gap-1 shrink-0',
                  children: const [_MobileBell(), _MobileAccountMenu()],
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// The team switcher in the mobile top bar (left). Mirrors the sidebar switcher
/// but with a compact trigger: avatar + name + a chevron directly after the
/// name (not pushed to the far edge).
class _MobileTeamSwitcher extends StatelessWidget {
  const _MobileTeamSwitcher();

  @override
  Widget build(BuildContext context) {
    // Rebuilds on login/logout/restore/switch: `switchTeam` calls
    // `Auth.restore()`, which bumps `Auth.stateNotifier`, so the active team
    // + team list here reflect a switch without a manual reload.
    return AnimatedBuilder(
      animation: authStateNotifier('MobileTopBar'),
      builder: (context, _) {
        final User user = currentUserSafe('MobileTopBar');
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
                className: 'rounded-md py-1 pr-1 hover:bg-surface-container',
                // mainAxisSize.min keeps the chevron tight against the (truncating)
                // name instead of being pushed to the far right of the bar.
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    _teamAvatar(activeTeam),
                    const SizedBox(width: 8),
                    Flexible(
                      child: WText(
                        activeTeam?.name ?? '',
                        className: 'truncate text-sm font-semibold text-fg',
                      ),
                    ),
                    const SizedBox(width: 4),
                    WIcon(Icons.expand_more, className: 'text-[16px] text-fg-muted'),
                  ],
                ),
              ),
              contentBuilder: (context, close) => SingleChildScrollView(
                child: WDiv(
                  className: 'flex flex-col',
                  children: [
                    WText(
                      trans('uptizm.team_menu.heading'),
                      className: '''
                        px-3 py-1.5 text-xs font-medium uppercase tracking-wide
                        text-fg-muted
                      ''',
                    ),
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
                            _teamAvatar(t, small: true),
                            Expanded(child: WText(t.name ?? '', className: 'truncate')),
                            if (t.id == activeTeam?.id)
                              WIcon(Icons.check, className: 'text-[16px] text-primary'),
                          ],
                        ),
                      ),
                    WDiv(className: 'my-1 border-t border-color-border-subtle'),
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
  Widget _teamAvatar(Team? team, {bool small = false}) {
    return WDiv(
      className: small
          ? 'w-5 h-5 rounded shrink-0 flex items-center justify-center bg-primary-container'
          : 'w-7 h-7 rounded-md shrink-0 flex items-center justify-center bg-primary-container',
      child: WText(
        teamInitial(team?.name),
        className: small
            ? 'text-[10px] font-bold text-fg'
            : 'text-xs font-bold text-fg',
      ),
    );
  }
}

/// The notification bell in the mobile top bar (right).
///
/// The same mount as the desktop sidebar's `_NotificationBell`, deliberately:
/// the two are separate classes because the shell swaps whole widget trees at
/// `lg`, and a change made to one of them alone leaves the app rendering two
/// different notification UIs depending on window width. The bell, the badge
/// and the panel come from `magic_notifications`; uptizm supplies the leading
/// status dot through the `notifications.icon` slot family and the route a
/// tapped row opens.
class _MobileBell extends StatelessWidget {
  const _MobileBell();

  @override
  Widget build(BuildContext context) {
    return ShellControlSemantics(
      label: trans('uptizm.a11y.notifications'),
      child: NotificationDropdown(
        notificationStream: notificationsStream('MobileTopBar'),
        onMarkAsRead: (id) => Notify.markAsRead(id),
        onMarkAllAsRead: () => Notify.markAllAsRead(),
        onNotificationTap: (notification) =>
            MagicRoute.to(notificationRouteFor(notification)),
        onViewAll: () => MagicRoute.to(MagicStarterConfig.notificationsRoute()),
      ),
    );
  }
}

/// The account menu in the mobile top bar (right): the user initials avatar
/// opening a popover with the name / email header, Settings, and Sign out.
class _MobileAccountMenu extends StatelessWidget {
  const _MobileAccountMenu();

  @override
  Widget build(BuildContext context) {
    // Rebuilds on login/logout/restore: a profile-update also calls
    // `Auth.restore()`, so a name/email change reflects here without reload.
    return AnimatedBuilder(
      animation: authStateNotifier('MobileTopBar'),
      builder: (context, _) {
        final User user = currentUserSafe('MobileTopBar');

        return ShellControlSemantics(
            label: trans('uptizm.a11y.account_menu'),
            child: WPopover(
              alignment: PopoverAlignment.bottomRight,
              offset: const Offset(0, 6),
              className: '''
                w-56 max-w-full overflow-hidden rounded-lg py-1
                bg-surface border border-color-border shadow-xl
              ''',
              triggerBuilder: (context, isOpen, isHovering) => WDiv(
                className: '''
                  w-9 h-9 shrink-0 rounded-full bg-surface-container
                  flex items-center justify-center hover:bg-surface-container-high
                ''',
                child: WText(
                  userInitials(user.name),
                  className: 'text-xs font-semibold text-fg',
                ),
              ),
              contentBuilder: (context, close) => WDiv(
                className: 'flex flex-col',
                children: [
                  WDiv(
                    className: 'px-3 py-2 flex flex-col',
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
                  WDiv(className: 'my-1 border-t border-color-border-subtle'),
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
          );
      },
    );
  }

}
