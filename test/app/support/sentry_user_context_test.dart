import 'package:flutter_test/flutter_test.dart';
import 'package:sentry_flutter/sentry_flutter.dart';
import 'package:uptizm/app/models/team.dart';
import 'package:uptizm/app/models/user.dart';
import 'package:uptizm/app/support/sentry_user_context.dart';

/// Locks what an issue from the client says about WHO hit it.
///
/// It mirrors the backend's `SetSentryContext` on purpose: an id, an email and
/// a team, and nothing else. The same error usually reaches both halves, so
/// their user cards have to agree or the two are impossible to correlate.
///
/// The empty cases are the ones worth pinning. `User.current` never returns
/// null, it returns an EMPTY user, so a naive check reports a phantom actor
/// with a blank id on every anonymous session; and guest authentication mints
/// real users with no email at all.
void main() {
  group('userFor', () {
    test('it reports nobody when there is no session', () {
      expect(SentryUserContext.userFor(null), isNull);
    });

    test('it reports nobody for the empty user the framework hands back', () {
      // `User.current` answers `Auth.user<User>() ?? User()`, so a signed-out
      // app produces a User whose id is the empty string rather than a null.
      expect(SentryUserContext.userFor(User()), isNull);
    });

    test('it carries the id and the email', () {
      final user = User.fromMap({
        'id': 'usr_42',
        'email': 'operator@example.test',
      });

      final SentryUser? reported = SentryUserContext.userFor(user);

      expect(reported, isNotNull);
      expect(reported!.id, 'usr_42');
      expect(reported.email, 'operator@example.test');
    });

    test('it omits an email a guest user does not have', () {
      final user = User.fromMap({'id': 'usr_9'});

      final SentryUser? reported = SentryUserContext.userFor(user);

      expect(reported, isNotNull);
      expect(reported!.id, 'usr_9');
      expect(reported.email, isNull);
    });
  });

  group('teamIdFor', () {
    test('it reports no team when there is no session', () {
      expect(SentryUserContext.teamIdFor(null), isNull);
    });

    test('it carries the current team', () {
      final user = User.fromMap({
        'id': 'usr_42',
        'current_team': {'id': 'team_3', 'name': 'Acme'},
      });

      expect(user.currentTeam, isA<Team>());
      expect(SentryUserContext.teamIdFor(user), 'team_3');
    });

    test('it reports no team for a user between teams', () {
      final user = User.fromMap({'id': 'usr_42'});

      expect(SentryUserContext.teamIdFor(user), isNull);
    });
  });
}
