import 'package:magic/magic.dart';

/// A team member's access level.
enum TeamRole {
  /// Full control, including billing and team deletion. Cannot be removed.
  owner,

  /// Can manage members, channels, and settings but not billing/deletion.
  admin,

  /// Standard access to monitors, incidents, and status pages.
  member;

  /// Localized label shown in role badges and selects.
  String get label => switch (this) {
    TeamRole.owner => trans('uptizm.enums.team_role.owner'),
    TeamRole.admin => trans('uptizm.enums.team_role.admin'),
    TeamRole.member => trans('uptizm.enums.team_role.member'),
  };
}
