import 'package:flutter_test/flutter_test.dart';

import 'package:uptizm/app/enums/team_role.dart' show TeamRole;
import 'package:uptizm/app/support/escalation_support.dart'
    show escalationDelayLabel, escalationTargetOptions;
import 'package:uptizm/app/support/team_types.dart' show OnCallShift, TeamMember;
import 'package:uptizm/app/mocks/teams_data.dart';

void main() {
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
    test('is non-empty', () {
      expect(escalationTargetOptions(), isNotEmpty);
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

    test('onCallRotation is non-empty', () {
      expect(onCallRotation, isNotEmpty);
    });

    test('exactly one onCallRotation shift is current', () {
      final int currentCount = onCallRotation
          .where((OnCallShift shift) => shift.current)
          .length;
      expect(currentCount, 1);
    });

    test('invoices is non-empty', () {
      expect(invoices, isNotEmpty);
    });

    test('billingUsage is non-empty', () {
      expect(billingUsage, isNotEmpty);
    });
  });
}
