import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/team.dart';

void main() {
  group('Team', () {
    test('table is teams', () {
      final team = Team();
      expect(team.table, 'teams');
    });

    test('resource is teams', () {
      final team = Team();
      expect(team.resource, 'teams');
    });

    test('fillable includes name', () {
      final team = Team();
      expect(team.fillable, contains('name'));
    });

    group('typed accessors', () {
      test('name getter and setter', () {
        final team = Team()..name = 'Test Team';
        expect(team.name, 'Test Team');
      });

      test('profilePhotoUrl', () {
        final team = Team.fromMap({'profile_photo_url': 'https://img.com/a.jpg'});
        expect(team.profilePhotoUrl, 'https://img.com/a.jpg');
      });

      test('isPersonalTeam true', () {
        final team = Team.fromMap({'personal_team': true});
        expect(team.isPersonalTeam, true);
      });

      test('isPersonalTeam false when missing', () {
        final team = Team.fromMap({});
        expect(team.isPersonalTeam, false);
      });

      test('ownerId', () {
        final team = Team.fromMap({'owner_id': 42});
        expect(team.ownerId, 42);
      });

      test('userRole', () {
        final team = Team.fromMap({'user_role': 'admin'});
        expect(team.userRole, 'admin');
      });
    });

    group('computed properties', () {
      test('canManageMembers true for owner', () {
        final team = Team.fromMap({'user_role': 'owner'});
        expect(team.canManageMembers, true);
      });

      test('canManageMembers true for admin', () {
        final team = Team.fromMap({'user_role': 'admin'});
        expect(team.canManageMembers, true);
      });

      test('canManageMembers false for editor', () {
        final team = Team.fromMap({'user_role': 'editor'});
        expect(team.canManageMembers, false);
      });

      test('canManageMembers false for member', () {
        final team = Team.fromMap({'user_role': 'member'});
        expect(team.canManageMembers, false);
      });

      test('canEdit true for owner, admin, editor', () {
        expect(Team.fromMap({'user_role': 'owner'}).canEdit, true);
        expect(Team.fromMap({'user_role': 'admin'}).canEdit, true);
        expect(Team.fromMap({'user_role': 'editor'}).canEdit, true);
      });

      test('canEdit false for member', () {
        expect(Team.fromMap({'user_role': 'member'}).canEdit, false);
      });

      test('isOwner true only for owner', () {
        expect(Team.fromMap({'user_role': 'owner'}).isOwner, true);
        expect(Team.fromMap({'user_role': 'admin'}).isOwner, false);
      });
    });

    group('fromMap', () {
      test('with full data sets exists true', () {
        final team = Team.fromMap({
          'id': 1,
          'name': 'My Team',
          'owner_id': 5,
          'personal_team': false,
          'user_role': 'owner',
          'profile_photo_url': 'https://img.com/team.jpg',
        });

        expect(team.id, 1);
        expect(team.name, 'My Team');
        expect(team.ownerId, 5);
        expect(team.isPersonalTeam, false);
        expect(team.userRole, 'owner');
        expect(team.profilePhotoUrl, 'https://img.com/team.jpg');
        expect(team.exists, true);
      });

      test('with minimal data', () {
        final team = Team.fromMap({'name': 'Minimal'});
        expect(team.name, 'Minimal');
        expect(team.ownerId, null);
        expect(team.userRole, null);
        expect(team.exists, false);
      });

      test('null handling for optional fields', () {
        final team = Team.fromMap({
          'id': 1,
          'name': null,
          'owner_id': null,
          'profile_photo_url': null,
          'user_role': null,
        });

        expect(team.name, null);
        expect(team.ownerId, null);
        expect(team.profilePhotoUrl, null);
        expect(team.userRole, null);
      });
    });
  });
}
