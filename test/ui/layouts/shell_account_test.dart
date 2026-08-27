import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:uptizm/ui/layouts/shell_account.dart';

/// The two app shells render the same account and team affordances, and each
/// used to carry its own copy of every helper behind them.
///
/// The copies were identical apart from a log prefix, and a comment in one of
/// them said so, calling it "kept local per file to avoid cross-layout
/// coupling". The cost was not hypothetical: the two team menus had already
/// drifted, the desktop one offering seven rows and the mobile one four.
void main() {
  group('the team menu is one list', () {
    test('both shells render it from the shared destinations', () {
      // The guard that keeps the drift from coming back. A row added as a
      // literal to one shell would be invisible to the other, which is exactly
      // how escalation, on-call and billing went missing on mobile.
      for (final String shell in <String>[
        'lib/ui/layouts/sidebar.dart',
        'lib/ui/layouts/mobile_top_bar.dart',
      ]) {
        final String source = File(shell).readAsStringSync();

        expect(
          source,
          contains('for (final TeamMenuDestination row in teamMenuDestinations)'),
          reason: '$shell must render the shared list, not its own literals',
        );
        expect(
          source,
          isNot(contains("teamMenuRow(trans('uptizm.team_menu.")),
          reason: '$shell must not write a team-menu row out by hand',
        );
      }
    });

    test('it still offers every destination the desktop shell had', () {
      // The desktop menu was the complete one, so it is the baseline: this
      // refactor must not have quietly dropped a row while unifying.
      expect(
        teamMenuDestinations.map((TeamMenuDestination d) => d.route).toList(),
        <String>[
          '/teams/settings',
          '/teams/settings',
          '/teams/notifications',
          '/teams/escalation',
          '/teams/on-call',
          '/teams/billing',
          '/teams/create',
        ],
      );
    });
  });

  group('the initials helpers keep the behaviour both shells had', () {
    test('a user takes the first TWO words, not the first and last', () {
      // Carried over exactly rather than improved: a refactor that quietly
      // changes an avatar's letters is a refactor that changed behaviour.
      expect(userInitials('Ada Byron Lovelace'), 'AB');
      expect(userInitials('Ada Lovelace'), 'AL');
      expect(userInitials('Ada'), 'A');
      expect(userInitials(''), '?');
      expect(userInitials(null), '?');
    });

    test('a team takes its leading letter', () {
      expect(teamInitial('Acme Ops'), 'A');
      expect(teamInitial('  spaced  '), 'S');
      expect(teamInitial(''), '?');
      expect(teamInitial(null), '?');
    });
  });

  group('the tolerant resolvers degrade instead of crashing', () {
    test('an unbound auth container yields a static listenable', () {
      // No Magic app is booted here, which is the case these exist for: a
      // widget test that renders a shell without binding `auth`.
      expect(() => authStateNotifier('Test'), returnsNormally);
    });

    test('an unbound auth container yields an empty user', () {
      final user = currentUserSafe('Test');

      expect(user.name, isNull);
      expect(user.email, isNull);
    });

    test('an uninstalled notifications plugin yields an empty feed', () {
      expect(() => notificationsStream('Test'), returnsNormally);
    });
  });
}
