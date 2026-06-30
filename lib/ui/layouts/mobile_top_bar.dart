import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../app/mocks/teams.dart';
import '../components/notification_center/index.dart';

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
          className: 'bg-surface/80 border-b border-color-border',
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
class _MobileTeamSwitcher extends StatefulWidget {
  const _MobileTeamSwitcher();

  @override
  State<_MobileTeamSwitcher> createState() => _MobileTeamSwitcherState();
}

class _MobileTeamSwitcherState extends State<_MobileTeamSwitcher> {
  /// The active team; seeded to the first fixture, like the React source.
  Team _team = teams.first;

  @override
  Widget build(BuildContext context) {
    return WPopover(
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
            _teamAvatar(_team),
            const SizedBox(width: 8),
            Flexible(
              child: WText(
                _team.name,
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
                    _teamAvatar(t, small: true),
                    Expanded(child: WText(t.name, className: 'truncate')),
                    if (t.id == _team.id)
                      WIcon(Icons.check, className: 'text-[16px] text-primary'),
                  ],
                ),
              ),
            WDiv(className: 'my-1 border-t border-color-border-subtle'),
            _menuRow(trans('uptizm.team_menu.settings'), close),
            _menuRow(trans('uptizm.team_menu.members'), close),
            _menuRow(trans('uptizm.team_menu.channels'), close),
            _menuRow(trans('uptizm.team_menu.create'), close),
          ],
        ),
      ),
    );
  }

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
  Widget _teamAvatar(Team team, {bool small = false}) {
    return WDiv(
      backgroundColor: team.color,
      className: small
          ? 'w-5 h-5 rounded shrink-0 flex items-center justify-center'
          : 'w-7 h-7 rounded-md shrink-0 flex items-center justify-center',
      child: WText(
        team.initial,
        className: small
            ? 'text-[10px] font-bold text-white'
            : 'text-xs font-bold text-white',
      ),
    );
  }
}

/// The notification bell in the mobile top bar (right). Opens the
/// [NotificationCenter] panel; the badge reflects the seed unread count.
class _MobileBell extends StatelessWidget {
  const _MobileBell();

  @override
  Widget build(BuildContext context) {
    final int unread = kSampleNotifications.where((n) => !n.read).length;

    return WPopover(
      alignment: PopoverAlignment.bottomRight,
      offset: const Offset(0, 6),
      maxHeight: 480,
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
      contentBuilder: (context, close) => SingleChildScrollView(
        child: NotificationCenter(
          onClose: close,
          onItemTap: (item) => MagicRoute.to(item.to),
          onSettings: () => MagicRoute.to('/settings'),
        ),
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
    return WPopover(
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
          currentUser.initials,
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
                currentUser.name,
                className: 'truncate text-sm font-medium text-fg',
              ),
              WText(
                currentUser.email,
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
            onTap: close,
            child: WDiv(
              className: 'px-3 py-2 text-sm text-fg hover:bg-surface-container',
              child: WText(trans('uptizm.account.sign_out')),
            ),
          ),
        ],
      ),
    );
  }
}
