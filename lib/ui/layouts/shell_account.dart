/// The account and team affordances both app shells render.
///
/// [Sidebar] (desktop) and [MobileTopBar] (mobile) show the same user avatar,
/// the same team switcher and the same team menu, and each carried its own copy
/// of every helper behind them. The copies were identical apart from a log
/// prefix, and a comment in one of them said so, calling it "kept local per file
/// to avoid cross-layout coupling".
///
/// The cost of that arrangement is not hypothetical. The two team menus had
/// already drifted: the desktop one offered seven rows and the mobile one four,
/// silently dropping escalation, on-call and billing. Those screens stayed
/// reachable through Settings, so nobody lost access, but the same menu answered
/// two different questions depending on the width of the window, and a row added
/// to one would have kept missing the other.
///
/// So the helpers live here once and [teamMenuDestinations] is the single list
/// both menus render.
library;

import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../app/models/user.dart';



/// The initials rendered inside a user's avatar circle.
///
/// The FIRST TWO words, not the first and last: "Ada Byron Lovelace" reads as
/// AB. Carried over exactly as both shells had it, because a refactor that
/// quietly improves an initial is a refactor that changed behaviour.
String userInitials(String? name) {
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
String teamInitial(String? name) {
  final String trimmed = name?.trim() ?? '';

  return trimmed.isEmpty ? '?' : trimmed[0].toUpperCase();
}

/// A never-mutated fallback used when the auth guard is unavailable (e.g. a
/// widget test that renders a shell without booting a Magic app / binding the
/// `auth` service). Keeps [AnimatedBuilder] satisfied without reacting to
/// anything.
final ValueNotifier<int> _fallbackAuthNotifier = ValueNotifier<int>(0);

/// Resolves the auth guard's `stateNotifier`, tolerating an unconfigured
/// container. Mirrors `MagicRouter._resolveAuthRefreshListenable`'s try/catch
/// tolerance so a shell degrades to a static (non-reactive) display instead of
/// crashing.
///
/// [shell] names the caller in the log line, which is the only thing the two
/// copies of this ever differed by.
Listenable authStateNotifier(String shell) {
  try {
    return Auth.stateNotifier;
  } catch (e) {
    debugPrint(
      '$shell: auth state notifier unavailable; the shell will not react '
      'to auth-state changes ($e).',
    );

    return _fallbackAuthNotifier;
  }
}

/// Resolves the authenticated [User], tolerating an unconfigured auth container
/// the same way [authStateNotifier] does. Falls back to an empty,
/// unauthenticated [User] so name/email/team reads degrade to blanks instead of
/// crashing.
User currentUserSafe(String shell) {
  try {
    return User.current;
  } catch (e) {
    debugPrint('$shell: authenticated user unavailable; showing an empty user ($e).');

    return User();
  }
}

/// The unread-notification feed, tolerating an uninstalled notifications
/// plugin the same way the two helpers above tolerate an unbound auth guard.
Stream<List<DatabaseNotification>> notificationsStream(String shell) {
  try {
    return Notify.notifications();
  } catch (e) {
    debugPrint('$shell: notification stream unavailable; showing an empty feed ($e).');

    return const Stream.empty();
  }
}

/// One row of the team menu: its label key and the route it opens.
@immutable
class TeamMenuDestination {
  /// The `uptizm.team_menu.*` key for the row's label.
  final String labelKey;

  /// The route the row opens.
  final String route;

  /// Creates a [TeamMenuDestination].
  const TeamMenuDestination(this.labelKey, this.route);
}

/// The team menu, in order, for both shells.
///
/// One list rather than two literals. The two used to be written out
/// separately, and the mobile one was three rows short: escalation, on-call and
/// billing were missing, so the same menu offered a different product depending
/// on the width of the window.
const List<TeamMenuDestination> teamMenuDestinations = <TeamMenuDestination>[
  TeamMenuDestination('uptizm.team_menu.settings', '/teams/settings'),
  TeamMenuDestination('uptizm.team_menu.members', '/teams/settings'),
  TeamMenuDestination('uptizm.team_menu.channels', '/teams/notifications'),
  TeamMenuDestination('uptizm.team_menu.escalation', '/teams/escalation'),
  TeamMenuDestination('uptizm.team_menu.on_call', '/teams/on-call'),
  TeamMenuDestination('uptizm.team_menu.billing', '/teams/billing'),
  TeamMenuDestination('uptizm.team_menu.create', '/teams/create'),
];

/// One tappable row of the team menu.
Widget teamMenuRow(String label, String route, VoidCallback close) {
  return WAnchor(
    onTap: () {
      close();
      MagicRoute.to(route);
    },
    child: WDiv(
      className: 'px-3 py-2 text-sm text-fg hover:bg-surface-container',
      child: WText(label, className: 'truncate'),
    ),
  );
}

/// Signs the operator out through the starter's own logout path.
///
/// The consumer hook wins when one is installed, which is the starter's
/// documented override point; otherwise the starter's auth controller does it.
Future<void> handleLogout() async {
  final customLogout = MagicStarter.manager.onLogout;
  if (customLogout != null) {
    await customLogout();

    return;
  }

  await MagicStarterAuthController.instance.logout();
}
