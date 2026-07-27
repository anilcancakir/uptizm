import 'dart:convert';

import 'package:magic/magic.dart';

/// On-call schedule model.
///
/// Represents a team-scoped on-call rotation ring plus its temporary
/// overrides. Extends the Magic ORM [Model] with [HasTimestamps] and
/// [InteractsWithPersistence] to provide ORM-native persistence and automatic
/// timestamp tracking, the same way [User] and [Team] already do.
///
/// ## Wire path
///
/// The REST resource is the nested segment `on-call/schedules`, so the static
/// [find]/[all] helpers route through `GET /on-call/schedules` and
/// `GET /on-call/schedules/:id`. The network drivers build the request URL as
/// `'/' + resource`, so `'on-call/schedules'` resolves to the exact path
/// [OnCallController] already drives raw via [Http.get]. The existing
/// `Http.fake` stubs (keyed on `on-call/schedules`) therefore continue to
/// resolve for both the ORM and the raw controller.
///
/// ## Sub-resources
///
/// The eager-loaded `rotations` and `overrides` rings travel on both the index
/// and the detail payload and are stored as raw attributes by [fromMap]. They
/// are exposed as RAW row lists, not typed accessors: rotations and overrides
/// are sub-resources (created/deleted via
/// `POST/DELETE /on-call/schedules/:id/{rotations|overrides}`), deliberately
/// kept out of the ORM. [OnCallController] decodes the rows into
/// `OnCallRotationSlot`/`OnCallOverrideWindow` value objects, so the typed
/// boundary lives there rather than on the schedule's own scalar surface.
///
/// ## Usage
///
/// ```dart
/// final schedules = await OnCallSchedule.all();
/// final schedule = await OnCallSchedule.find('sched-1');
/// Log.debug(schedule?.name);
/// ```
class OnCallSchedule extends Model
    with HasTimestamps, InteractsWithPersistence {
  /// The table associated with the model.
  @override
  String get table => 'on_call_schedules';

  /// The API resource for remote operations.
  ///
  /// The nested segment `on-call/schedules` resolves to the wire paths
  /// `GET /on-call/schedules` (index) and `GET /on-call/schedules/:id`
  /// (show) because the network drivers build the request URL as
  /// `'/' + resource`. This matches [OnCallController]'s raw
  /// `Http.get('/on-call/schedules')` path exactly, so the existing
  /// `Http.fake` stubs continue to resolve.
  @override
  String get resource => 'on-call/schedules';

  /// Whether the primary key is auto-incrementing.
  ///
  /// Set to false because the backend issues UUID strings as primary keys.
  @override
  bool get incrementing => false;

  /// The attributes that are mass assignable.
  ///
  /// Mirrors the backend `StoreOnCallScheduleRequest` /
  /// `UpdateOnCallScheduleRequest` rules: `name` (required) and `timezone`
  /// (nullable). The owning `team_id` is resolved server-side from the
  /// authenticated user's current team and is deliberately NOT
  /// client-writable, so it never enters the fillable surface.
  @override
  List<String> get fillable => [
    'name',
    'timezone',
  ];

  /// The attributes that should be cast.
  ///
  /// Both fillable fields are plain strings; the `created_at`/`updated_at`
  /// timestamps are parsed to [Carbon] by the [HasTimestamps] mixin's
  /// `createdAt`/`updatedAt` getters, so no explicit casts are needed here.
  @override
  Map<String, String> get casts => {};

  // ---------------------------------------------------------------------------
  // Typed Accessors
  // ---------------------------------------------------------------------------

  /// The schedule's primary key (UUID string).
  @override
  String get id => getAttribute('id')?.toString() ?? '';

  /// The owning team's id (tenant boundary; server-resolved on write).
  String? get teamId => getAttribute('team_id')?.toString();

  /// The schedule's display name.
  String? get name => getAttribute('name') as String?;

  /// Set the schedule's display name.
  set name(String? value) => setAttribute('name', value);

  /// The schedule's IANA timezone (e.g. `UTC`, `America/New_York`).
  String? get timezone => getAttribute('timezone') as String?;

  /// Set the schedule's timezone.
  set timezone(String? value) => setAttribute('timezone', value);

  /// The eager-loaded rotation rows, as raw maps.
  ///
  /// Each row carries `id` (rotation row id), `user_id`, `user_name`,
  /// `position` and `shift_hours`, which [OnCallController] decodes into
  /// [OnCallRotationSlot]s. Returns an empty list when the wire omits the
  /// `rotations` array (a `store`/`update` response, whose relations were
  /// never loaded). Kept as raw maps because rotations are a sub-resource the
  /// ORM does not model directly.
  List<Map<String, dynamic>> get rotations => _rawRows('rotations');

  /// The eager-loaded override rows, as raw maps.
  ///
  /// Each row carries `id`, `user_id`, `user_name`, `starts_at` and `ends_at`,
  /// which [OnCallController] decodes into [OnCallOverrideWindow]s. Empty when
  /// the wire omits the `overrides` array, on the same terms as [rotations].
  List<Map<String, dynamic>> get overrides => _rawRows('overrides');

  /// The raw sub-resource rows stored under [key] by [fromMap], or an empty
  /// list when the attribute is absent or not a list.
  List<Map<String, dynamic>> _rawRows(String key) {
    final Object? raw = getAttribute(key);
    if (raw is! List) return const [];
    return raw.whereType<Map<String, dynamic>>().toList();
  }

  // ---------------------------------------------------------------------------
  // Static Helpers
  // ---------------------------------------------------------------------------

  /// Find a schedule by ID.
  ///
  /// Routes through `GET /on-call/schedules/:id` (the same wire path the
  /// controller drives raw), so the existing fake stubs resolve unchanged.
  ///
  /// Returns `null` if no schedule with the given [id] exists.
  ///
  /// ```dart
  /// final schedule = await OnCallSchedule.find('sched-1');
  /// ```
  static Future<OnCallSchedule?> find(dynamic id) =>
      InteractsWithPersistence.findById<OnCallSchedule>(
        id,
        OnCallSchedule.new,
      );

  /// List the current team's schedules.
  ///
  /// Routes through `GET /on-call/schedules` (the same wire path the
  /// controller drives raw), so the existing fake stubs resolve unchanged.
  ///
  /// ```dart
  /// final schedules = await OnCallSchedule.all();
  /// ```
  static Future<List<OnCallSchedule>> all() =>
      InteractsWithPersistence.allModels<OnCallSchedule>(OnCallSchedule.new);

  // ---------------------------------------------------------------------------
  // Flutter-Familiar Factory Methods
  // ---------------------------------------------------------------------------

  /// Create an [OnCallSchedule] from a [Map].
  ///
  /// Uses [setRawAttributes] to hydrate the model directly from a raw
  /// `OnCallScheduleResource` payload, bypassing mass-assignment protection.
  /// The nested `rotations`/`overrides` rings ride through as raw attributes
  /// for the controller migration to read. The [exists] flag is set based on
  /// whether the map contains an `id` key.
  ///
  /// ```dart
  /// final schedule = OnCallSchedule.fromMap({
  ///   'id': 'sched-1',
  ///   'name': 'Primary',
  ///   'timezone': 'UTC',
  /// });
  /// ```
  static OnCallSchedule fromMap(Map<String, dynamic> map) {
    return OnCallSchedule()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  /// Create an [OnCallSchedule] from a JSON string.
  ///
  /// Decodes [json] and delegates to [fromMap].
  ///
  /// ```dart
  /// final schedule = OnCallSchedule.fromJson('{"id":"sched-1","name":"Primary"}');
  /// ```
  static OnCallSchedule fromJson(String json) {
    final map = jsonDecode(json) as Map<String, dynamic>;
    return OnCallSchedule.fromMap(map);
  }
}
