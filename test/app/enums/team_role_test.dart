import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/team_role.dart';

void main() {
  group('TeamRole', () {
    test('owner value is owner', () {
      expect(TeamRole.owner, 'owner');
    });

    test('admin value is admin', () {
      expect(TeamRole.admin, 'admin');
    });

    test('editor value is editor', () {
      expect(TeamRole.editor, 'editor');
    });

    test('member value is member', () {
      expect(TeamRole.member, 'member');
    });

    test('all contains 4 roles in permission order', () {
      expect(TeamRole.all, ['owner', 'admin', 'editor', 'member']);
      expect(TeamRole.all.length, 4);
    });

    test('assignable excludes owner', () {
      expect(TeamRole.assignable, ['admin', 'editor', 'member']);
      expect(TeamRole.assignable.length, 3);
      expect(TeamRole.assignable.contains('owner'), false);
    });

    test('canManageMembers returns true for owner and admin', () {
      expect(TeamRole.canManageMembers('owner'), true);
      expect(TeamRole.canManageMembers('admin'), true);
      expect(TeamRole.canManageMembers('editor'), false);
      expect(TeamRole.canManageMembers('member'), false);
    });

    test('canManageMembers returns false for null', () {
      expect(TeamRole.canManageMembers(null), false);
    });

    test('canEdit returns true for owner, admin, editor', () {
      expect(TeamRole.canEdit('owner'), true);
      expect(TeamRole.canEdit('admin'), true);
      expect(TeamRole.canEdit('editor'), true);
      expect(TeamRole.canEdit('member'), false);
    });

    test('canEdit returns false for null', () {
      expect(TeamRole.canEdit(null), false);
    });

    test('isOwner returns true only for owner', () {
      expect(TeamRole.isOwner('owner'), true);
      expect(TeamRole.isOwner('admin'), false);
      expect(TeamRole.isOwner('editor'), false);
      expect(TeamRole.isOwner('member'), false);
      expect(TeamRole.isOwner(null), false);
    });

    test('selectOptions returns 3 options (assignable roles)', () {
      final options = TeamRole.selectOptions;
      expect(options.length, 3);
      expect(options[0].value, 'admin');
      expect(options[1].value, 'editor');
      expect(options[2].value, 'member');
    });
  });
}
