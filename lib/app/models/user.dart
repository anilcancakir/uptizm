import 'dart:convert';

import 'package:magic/magic.dart';

import 'team.dart';

/// User model.
///
/// Example Eloquent model demonstrating the Magic ORM pattern.
///
/// ## Usage with Typed Accessors
///
/// ```dart
/// final user = await User.find(1);
/// Log.debug(user?.name); // Uses typed accessor
/// user?.name = 'Updated';
/// await user?.save();
/// ```
///
/// ## Usage with Convenient get/set
///
/// ```dart
/// final user = await User.find(1);
/// Log.debug(user?.get<String>('name', defaultValue: 'Unknown'));
/// user?.set('name', 'Updated');
/// await user?.save();
/// ```
class User extends Model
    with HasTimestamps, InteractsWithPersistence, Authenticatable {
  /// The table associated with the model.
  @override
  String get table => 'users';

  /// The API resource for remote operations.
  @override
  String get resource => 'users';

  /// The attributes that are mass assignable.
  @override
  List<String> get fillable => [
    'name',
    'email',
    'phone',
    'timezone',
    'language',
    'born_at',
  ];

  /// The attributes that should be cast.
  @override
  Map<String, String> get casts => {'born_at': 'datetime', 'settings': 'json'};

  @override
  bool get incrementing => false;

  // ---------------------------------------------------------------------------
  // Typed Accessors
  // ---------------------------------------------------------------------------

  /// Get the user's ID.
  @override
  String? get id => getAttribute('id')?.toString();

  /// Get the user's name.
  String? get name => getAttribute('name') as String?;

  /// Set the user's name.
  set name(String? value) => setAttribute('name', value);

  /// Get the user's email.
  String? get email => getAttribute('email') as String?;

  /// Set the user's email.
  set email(String? value) => setAttribute('email', value);

  /// Get the user's birth date.
  Carbon? get bornAt => getAttribute('born_at') as Carbon?;

  /// Set the user's birth date.
  set bornAt(dynamic value) => setAttribute('born_at', value);

  /// Get the user's settings.
  Map<String, dynamic>? get settings =>
      getAttribute('settings') as Map<String, dynamic>?;

  /// Set the user's settings.
  set settings(Map<String, dynamic>? value) => setAttribute('settings', value);

  /// Get the user's current team.
  Team? get currentTeam {
    final data = getAttribute('current_team');
    if (data is Map<String, dynamic>) {
      return Team.fromMap(data);
    }
    return null;
  }

  /// Get all teams the user belongs to.
  List<Team> get allTeams {
    final data = getAttribute('all_teams');
    if (data is List) {
      return data.map((e) => Team.fromMap(e as Map<String, dynamic>)).toList();
    }
    return [];
  }

  /// Get the user's membership details (pivot).
  /// This is available when fetching team members.
  /// Get the user's role in the current team context.
  String? get teamRole => getAttribute('role') as String?;

  /// Get the user's profile photo URL.
  String? get profilePhotoUrl => getAttribute('profile_photo_url') as String?;

  /// Get the user's phone.
  String? get phone => getAttribute('phone') as String?;

  /// Set the user's phone.
  set phone(String? value) => setAttribute('phone', value);

  /// Get the user's timezone.
  String? get timezone => getAttribute('timezone') as String?;

  /// Set the user's timezone.
  set timezone(String? value) => setAttribute('timezone', value);

  /// Get the user's language.
  String? get language => getAttribute('language') as String?;

  /// Set the user's language.
  set language(String? value) => setAttribute('language', value);

  // ---------------------------------------------------------------------------
  // Static Helpers
  // ---------------------------------------------------------------------------

  /// Find a user by ID.
  ///
  /// ```dart
  /// final user = await User.find(1);
  /// ```
  static Future<User?> find(dynamic id) =>
      InteractsWithPersistence.findById<User>(id, User.new);

  /// Get all users.
  ///
  /// ```dart
  /// final users = await User.all();
  /// ```
  static Future<List<User>> all() =>
      InteractsWithPersistence.allModels<User>(User.new);

  /// Get the currently authenticated user.
  ///
  /// ```dart
  /// final user = User.current;
  /// ```
  static User get current => Auth.user<User>() ?? User();

  // ---------------------------------------------------------------------------
  // Flutter-Familiar Factory Methods
  // ---------------------------------------------------------------------------

  /// Create a User from a Map.
  ///
  /// ```dart
  /// final user = User.fromMap({'id': 1, 'name': 'John', 'email': 'john@test.com'});
  /// ```
  static User fromMap(Map<String, dynamic> map) {
    return User()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  /// Create a User from a JSON string.
  ///
  /// ```dart
  /// final user = User.fromJson('{"id":1,"name":"John"}');
  /// ```
  static User fromJson(String json) {
    final map = jsonDecode(json) as Map<String, dynamic>;
    return User.fromMap(map);
  }
}
