import 'package:magic/magic.dart';

/// Team member roles mirroring backend TeamRole enum.
///
/// ```dart
/// // Check permissions
/// if (TeamRole.canManageMembers(user.teamRole)) {
///   showManageButton();
/// }
///
/// // Get assignable roles for invite dialog
/// TeamRole.assignable.map((r) => SelectOption(value: r, label: TeamRole.label(r)))
/// ```
abstract class TeamRole {
  /// Owner - Full access, can delete team
  static const String owner = 'owner';

  /// Admin - Can manage members, cannot delete team
  static const String admin = 'admin';

  /// Editor - Can edit content, cannot manage members
  static const String editor = 'editor';

  /// Member - View only
  static const String member = 'member';

  /// All roles in order of permissions (highest first)
  static const List<String> all = [owner, admin, editor, member];

  /// Roles that can be assigned via invite (excludes owner)
  static const List<String> assignable = [admin, editor, member];

  /// Check if role can manage team members
  static bool canManageMembers(String? role) => role == owner || role == admin;

  /// Check if role can edit team content
  static bool canEdit(String? role) =>
      role == owner || role == admin || role == editor;

  /// Check if role is owner
  static bool isOwner(String? role) => role == owner;

  /// Get display label for role
  static String label(String role) {
    switch (role) {
      case owner:
        return trans('teams.roles.owner');
      case admin:
        return trans('teams.roles.admin');
      case editor:
        return trans('teams.roles.editor');
      case member:
        return trans('teams.roles.member');
      default:
        return role;
    }
  }

  /// Get SelectOption list for form selects
  static List<SelectOption<String>> get selectOptions =>
      assignable.map((r) => SelectOption(value: r, label: label(r))).toList();
}
