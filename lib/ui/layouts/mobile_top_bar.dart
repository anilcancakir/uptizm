import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart'
    show MagicStarter, MagicStarterAuthController, MagicStarterTeamController;

import '../../app/models/team.dart';
import '../../app/models/user.dart';
import '../components/notification_center/index.dart';

/// Computes uppercase avatar initials from a display [name].
///
/// Takes the first letter of up to the first two words, falling back to `?`
/// when [name] is null or blank. Mirrors the sidebar's identically named
/// helper (kept local per file to avoid cross-layout coupling).
String _userInitials(String? name) {
  final String trimmed = name?.trim() ?? '';
  if (trimmed.isEmpty) return '?';

  final List<String> words = trimmed.split(RegExp(r'\s+'));
  final String first = words[0][0];
  final String second = words.length > 1 && words[1].isNotEmpty
      ? words[1][0]
      : '';
  return (first + second).toUpperCase();
}

/// The leading initial rendered inside a team's colored avatar square.
String _teamInitial(String? name) {
  final String trimmed = name?.trim() ?? '';
  return trimmed.isEmpty ? '?' : trimmed[0].toUpperCase();
}

/// A never-mutated fallback used when the auth guard is unavailable (e.g. a
/// widget test that renders the shell without booting a Magic app / binding
/// the `auth` service). Keeps [AnimatedBuilder] satisfied without reacting to
/// anything.
final ValueNotifier<int> _fallbackAuthNotifier = ValueNotifier<int>(0);

/// Resolves the auth guard's `stateNotifier`, tolerating an unconfigured
/// container. Mirrors `MagicRouter._resolveAuthRefreshListenable`'s
/// try/catch tolerance (magic/lib/src/routing/magic_router.dart:239) so the
/// shell degrades to a static (non-reactive) display instead of crashing.
Listenable _authStateNotifier() {
  try {
    return Auth.stateNotifier;
  } catch (e) {
    debugPrint(
      'MobileTopBar: auth state notifier unavailable; the shell will not '
      'react to auth-state changes ($e).',
    );
    return _fallbackAuthNotifier;
  }
}

/// Resolves the authenticated [User], tolerating an unconfigured auth
/// container the same way [_authStateNotifier] does (e.g. a widget test that
/// renders the shell without booting a Magic app). Falls back to an empty,
/// unauthenticated [User] so name/email/team reads degrade to blanks instead
/// of crashing.
User _currentUserSafe() {
  try {
    return User.current;
  } catch (e) {
    debugPrint(
      'MobileTopBar: authenticated user unavailable; showing an empty user '
      '($e).',
    );
    return User();
  }
}

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
class _MobileTeamSwitcher extends StatelessWidget {
  const _MobileTeamSwitcher();

  @override
  Widget build(BuildContext context) {
    // Rebuilds on login/logout/restore/switch: `switchTeam` calls
    // `Auth.restore()`, which bumps `Auth.stateNotifier`, so the active team
    // + team list here reflect a switch without a manual reload.
    return AnimatedBuilder(
      animation: _authStateNotifier(),
      builder: (context, _) {
        final User user = _currentUserSafe();
        final Team? activeTeam = user.currentTeam;
        final List<Team> allTeams = user.allTeams;

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
                _menuRow(trans('uptizm.team_menu.settings'), close),
                _menuRow(trans('uptizm.team_menu.members'), close),
                _menuRow(trans('uptizm.team_menu.channels'), close),
                _menuRow(trans('uptizm.team_menu.create'), close),
              ],
            ),
          ),
        );
      },
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
        _teamInitial(team?.name),
        className: small
            ? 'text-[10px] font-bold text-fg'
            : 'text-xs font-bold text-fg',
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
    // Rebuilds on login/logout/restore: a profile-update also calls
    // `Auth.restore()`, so a name/email change reflects here without reload.
    return AnimatedBuilder(
      animation: _authStateNotifier(),
      builder: (context, _) {
        final User user = _currentUserSafe();

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
              _userInitials(user.name),
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
                  _handleLogout();
                },
                child: WDiv(
                  className: 'px-3 py-2 text-sm text-fg hover:bg-surface-container',
                  child: WText(trans('uptizm.account.sign_out')),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  /// Signs the user out via the app's registered [MagicStarter.useLogout]
  /// callback, falling back to the starter's default auth controller logout.
  Future<void> _handleLogout() async {
    final customLogout = MagicStarter.manager.onLogout;
    if (customLogout != null) {
      await customLogout();
      return;
    }

    await MagicStarterAuthController.instance.logout();
  }
}
