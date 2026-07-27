import 'package:flutter/widgets.dart' show Locale;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/team_role.dart' show TeamRole;
import 'package:uptizm/app/support/escalation_support.dart'
    show
        EscalationTargetType,
        escalationDelayLabel,
        escalationTargetOptions;
import 'package:uptizm/app/support/team_types.dart'
    show TeamMember, TeamResponder;
import 'package:uptizm/app/mocks/teams_data.dart';

/// Feeds the escalation prose so [trans] returns real copy instead of the raw
/// dot-separated key. The rung labels are translated now, so without a loader
/// these assertions would be comparing key tokens.
class _EscalationLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.teams.escalation_target_on_call': 'On-call rotation',
      'uptizm.teams.escalation_delay_immediate': 'Immediately',
      'uptizm.teams.escalation_delay_after': 'After :n min',
    };
  }
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_EscalationLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  // ---------------------------------------------------------------------------
  // escalationDelayLabel
  // ---------------------------------------------------------------------------

  group('escalationDelayLabel', () {
    test('0 minutes reads as Immediately', () {
      expect(escalationDelayLabel(0), 'Immediately');
    });

    test('a non-zero delay contains the minute count and the "min" suffix', () {
      final String label = escalationDelayLabel(5);
      expect(label, contains('5'));
      expect(label, contains('min'));
    });
  });

  // ---------------------------------------------------------------------------
  // escalationTargetOptions
  // ---------------------------------------------------------------------------

  group('escalationTargetOptions', () {
    const List<TeamResponder> roster = [
      TeamResponder(id: 'u1', name: 'Real Person'),
      TeamResponder(id: 'u2', name: 'Second Person'),
    ];

    test('always offers the on-call rotation first', () {
      final options = escalationTargetOptions(roster);

      expect(options.first.type, EscalationTargetType.onCall);
      expect(options.first.label, 'On-call rotation');
    });

    test('offers exactly the responders it was handed', () {
      // Regression: the roster came from the `teamMembers` fixture, so a rung
      // could target a person who does not exist and the ladder would page
      // nobody during an outage. The roster is now passed in from the team's
      // real members, and nothing else may leak into the picker.
      final options = escalationTargetOptions(roster);
      final userOptions = options
          .where((o) => o.type == EscalationTargetType.user)
          .toList();

      expect(userOptions.map((o) => o.userId), ['u1', 'u2']);
      expect(userOptions.map((o) => o.label), ['Real Person', 'Second Person']);
    });

    test('an empty roster offers the rotation alone', () {
      final options = escalationTargetOptions(const []);

      expect(options, hasLength(1));
      expect(options.single.type, EscalationTargetType.onCall);
    });
  });

  // ---------------------------------------------------------------------------
  // Fixture integrity
  // ---------------------------------------------------------------------------

  group('fixture integrity', () {
    test('teamMembers is non-empty', () {
      expect(teamMembers, isNotEmpty);
    });

    test('exactly one teamMember is the owner', () {
      final int ownerCount = teamMembers
          .where((TeamMember member) => member.role == TeamRole.owner)
          .length;
      expect(ownerCount, 1);
    });

    test('exactly one teamMember is the signed-in user', () {
      final int selfCount = teamMembers
          .where((TeamMember member) => member.isSelf)
          .length;
      expect(selfCount, 1);
    });

    test('pendingInvitations is non-empty', () {
      expect(pendingInvitations, isNotEmpty);
    });

    // No on-call fixture assertions: the rotation fixtures are gone, and the
    // on-call screen reads the real `api/v1/on-call/*` surface (covered by
    // `test/app/controllers/on_call_controller_test.dart` and
    // `test/resources/views/on_call_schedule_view_test.dart`).

    test('invoices is non-empty', () {
      expect(invoices, isNotEmpty);
    });

    test('billingUsage is non-empty', () {
      expect(billingUsage, isNotEmpty);
    });
  });
}
