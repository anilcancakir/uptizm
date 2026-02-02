import 'dart:convert';

import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Team Model
///
/// Represents a team/workspace in the application.
///
/// ## Usage
///
/// ```dart
/// // Create a new team
/// final team = Team()..name = 'My Team';
/// await team.save();
///
/// // Find a team
/// final team = await Team.find(1);
/// ```
class Team extends Model with HasTimestamps, InteractsWithPersistence {
  @override
  String get table => 'teams';

  @override
  String get resource => 'teams';

  @override
  List<String> get fillable => ['name'];

  // ---------------------------------------------------------------------------
  // Typed Accessors
  // ---------------------------------------------------------------------------

  /// Get the team name.
  String? get name => getAttribute('name') as String?;

  /// Set the team name.
  set name(String? value) => setAttribute('name', value);

  /// Get the profile photo URL.
  String? get profilePhotoUrl => getAttribute('profile_photo_url') as String?;

  /// Set the profile photo URL.
  set profilePhotoUrl(String? value) =>
      setAttribute('profile_photo_url', value);

  /// Check if this is a personal team.
  bool get isPersonalTeam => getAttribute('personal_team') == true;

  /// Get the owner ID.
  int? get ownerId => getAttribute('owner_id') as int?;

  /// Current user's role in this team (from API response).
  String? get userRole => getAttribute('user_role') as String?;

  /// Check if current user can manage members (owner or admin).
  bool get canManageMembers => userRole == 'owner' || userRole == 'admin';

  /// Check if current user can edit team content (owner, admin, or editor).
  bool get canEdit =>
      userRole == 'owner' || userRole == 'admin' || userRole == 'editor';

  /// Check if current user is the owner.
  bool get isOwner => userRole == 'owner';

  // ---------------------------------------------------------------------------
  // Static Helpers
  // ---------------------------------------------------------------------------

  /// Find a team by ID.
  static Future<Team?> find(dynamic id) =>
      InteractsWithPersistence.findById<Team>(id, Team.new);

  /// Get all teams.
  static Future<List<Team>> all() =>
      InteractsWithPersistence.allModels<Team>(Team.new);

  // ---------------------------------------------------------------------------
  // Factory Methods
  // ---------------------------------------------------------------------------

  /// Create a Team from a Map.
  static Team fromMap(Map<String, dynamic> map) {
    return Team()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  /// Create a Team from JSON.
  static Team fromJson(String json) {
    final map = jsonDecode(json) as Map<String, dynamic>;
    return Team.fromMap(map);
  }
}
