import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/team_invitation.dart';

void main() {
  group('TeamInvitation', () {
    test('table is team_invitations', () {
      final inv = TeamInvitation();
      expect(inv.table, 'team_invitations');
    });

    test('resource is teams_invitations', () {
      final inv = TeamInvitation();
      expect(inv.resource, 'teams_invitations');
    });

    test('fillable includes email, role, team_id', () {
      final inv = TeamInvitation();
      expect(inv.fillable, containsAll(['email', 'role', 'team_id']));
    });

    group('typed accessors', () {
      test('email', () {
        final inv = TeamInvitation.fromMap({'email': 'test@example.com'});
        expect(inv.email, 'test@example.com');
      });

      test('role', () {
        final inv = TeamInvitation.fromMap({'role': 'admin'});
        expect(inv.role, 'admin');
      });

      test('teamId', () {
        final inv = TeamInvitation.fromMap({'team_id': 7});
        expect(inv.teamId, 7);
      });
    });

    group('fromMap', () {
      test('with full data', () {
        final inv = TeamInvitation.fromMap({
          'id': 1,
          'email': 'user@test.com',
          'role': 'member',
          'team_id': 3,
        });

        expect(inv.id, 1);
        expect(inv.email, 'user@test.com');
        expect(inv.role, 'member');
        expect(inv.teamId, 3);
        expect(inv.exists, true);
      });

      test('with minimal data', () {
        final inv = TeamInvitation.fromMap({'email': 'a@b.com'});
        expect(inv.email, 'a@b.com');
        expect(inv.role, null);
        expect(inv.teamId, null);
        expect(inv.exists, false);
      });
    });
  });
}
