import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:magic/magic.dart';
import 'package:sentry_flutter/sentry_flutter.dart';

import '../models/user.dart';

/// Tells Sentry which operator, and which team, an error happened to.
///
/// It mirrors the backend's `SetSentryContext` field for field, and that
/// symmetry is the point: a single user action usually fails on both halves, so
/// the two user cards have to agree before anyone can line them up. An id, an
/// email and a team id; no name, no plan, no locale.
///
/// ## Kept in sync by the auth state, not by each call site
///
/// [install] subscribes to magic's `Auth.stateNotifier`, which fires on login,
/// on logout, on a session restore at boot and on a team switch. Doing it there
/// rather than at each of those four call sites is what keeps a future fifth
/// one correct for free, and it is also what guarantees the LOGOUT case: a
/// scope that keeps the previous user attached would file the next visitor's
/// errors under the person who signed out, which is worse than having no user
/// at all.
class SentryUserContext {
  /// Start following the auth state, and apply whatever it says right now.
  ///
  /// The immediate call matters: `Auth.restore()` runs during boot, so by the
  /// time this is installed there is often already a signed-in user, and
  /// waiting for the next change would leave the whole first session
  /// anonymous.
  static void install() {
    apply();
    Auth.stateNotifier.addListener(apply);
  }

  /// Push the current auth state into Sentry's scope.
  ///
  /// A no-op in practice when the SDK has no DSN, which is every debug run.
  static void apply() {
    final User? user = Auth.check() ? Auth.user<User>() : null;
    final SentryUser? reported = userFor(user);
    final String? teamId = teamIdFor(user);

    Sentry.configureScope((scope) {
      // Explicitly null on sign-out. See the class docblock: a stale user is
      // worse than none.
      scope.setUser(reported);

      if (teamId == null) {
        scope.removeTag('team_id');

        return;
      }

      scope.setTag('team_id', teamId);
    });
  }

  /// The Sentry user for [user], or null when there is nobody to report.
  ///
  /// Null for an ABSENT user and equally for the empty one magic hands back:
  /// `User.current` answers `Auth.user<User>() ?? User()`, so a signed-out app
  /// produces a User whose `id` is the empty string. Reporting that would
  /// attach a phantom actor with a blank id to every anonymous error.
  ///
  /// The email is omitted rather than sent as null when the account has none,
  /// which guest authentication produces for real.
  @visibleForTesting
  static SentryUser? userFor(User? user) {
    if (user == null || user.id.isEmpty) {
      return null;
    }

    final String? email = user.email;

    return SentryUser(
      id: user.id,
      email: (email != null && email.isNotEmpty) ? email : null,
    );
  }

  /// The current team's id for [user], or null when there is not one.
  @visibleForTesting
  static String? teamIdFor(User? user) {
    if (user == null || user.id.isEmpty) {
      return null;
    }

    final String? teamId = user.currentTeam?.id;

    return (teamId != null && teamId.isNotEmpty) ? teamId : null;
  }
}
