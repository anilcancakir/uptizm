/// A team member's access level.
enum TeamRole {
  /// Full control, including billing and team deletion. Cannot be removed.
  owner,

  /// Can manage members, channels, and settings but not billing/deletion.
  admin,

  /// Standard access to monitors, incidents, and status pages.
  member;

  /// Human-readable label shown in role badges and selects.
  String get label => switch (this) {
    TeamRole.owner => 'Owner',
    TeamRole.admin => 'Admin',
    TeamRole.member => 'Member',
  };
}
