import 'dart:convert';

import 'package:magic/magic.dart';

/// Scheduled-maintenance window model.
///
/// Backs the `scheduled-maintenances` API resource
/// (`GET/POST /scheduled-maintenances`, ...): the planned windows the public
/// status page announces, the subscriber announcement mails once, and the
/// paging path holds alerts for. Extends the Magic ORM [Model] with
/// [HasTimestamps] and [InteractsWithPersistence] so a window can be fetched,
/// decoded and persisted like the other uptizm models ([Incident],
/// [StatusPage]).
///
/// ## The two datetime rules this model deliberately does NOT bend
///
/// 1. `starts_at` / `ends_at` are stored and posted as ISO-8601 **strings in
///    UTC**, and carry NO `datetime` cast. [Model.toMap] (which `save()` posts)
///    re-applies every read cast on the way out, so a `datetime` cast here
///    would put a [Carbon] into the outgoing JSON body instead of the string
///    the backend validates. The typed [startsAt] / [endsAt] getters parse on
///    demand instead.
/// 2. The caller converts with `.toUtc().toIso8601String()` before filling.
///    Dart's [DateTime] cannot carry an arbitrary UTC offset
///    (dart-lang/sdk#54993, by design), and the backend's database session is
///    pinned to UTC, so a naive local-time string silently shifts the window by
///    the operator's offset.
///
/// ## Usage
///
/// ```dart
/// final window = ScheduledMaintenance()
///   ..fill({
///     'status_page_id': 'page-1',
///     'title': 'Database upgrade',
///     'starts_at': starts.toUtc().toIso8601String(),
///     'ends_at': ends.toUtc().toIso8601String(),
///     'monitor_ids': ['checkout'],
///   });
/// await window.save(); // POST /scheduled-maintenances
/// ```
class ScheduledMaintenance extends Model
    with HasTimestamps, InteractsWithPersistence {
  /// The table associated with the model.
  @override
  String get table => 'scheduled_maintenances';

  /// The API resource for remote operations (`POST /scheduled-maintenances`).
  @override
  String get resource => 'scheduled-maintenances';

  /// Whether the primary key is auto-incrementing.
  ///
  /// False: the backend issues string UUIDs for maintenance windows.
  @override
  bool get incrementing => false;

  /// The attributes that are mass assignable.
  ///
  /// Mirrors `StoreScheduledMaintenanceRequest::rules()` exactly, `monitor_ids`
  /// included: that key is a PIVOT payload rather than a column, but the ORM
  /// strips any key absent from this list before the request is sent, so
  /// leaving it out would silently drop the window's affected components.
  ///
  /// `announced_at` and `team_id` are deliberately absent, matching the request:
  /// the announce-once guard belongs to the mail job, and the team comes from
  /// the authenticated user.
  @override
  List<String> get fillable => [
    'status_page_id',
    'title',
    'description',
    'suppress_alerts',
    'starts_at',
    'ends_at',
    'monitor_ids',
  ];

  /// The attributes that should be cast.
  ///
  /// `suppress_alerts` only. The three timestamp columns stay raw strings on
  /// purpose (see the class docblock): a `datetime` cast round-trips back
  /// through [Model.toMap] and would post a [Carbon] where the backend expects
  /// an ISO-8601 string.
  @override
  Map<String, dynamic> get casts => {
    'suppress_alerts': 'bool',
  };

  /// No relations: the affected components arrive as the resource's plain
  /// `monitors` list and are read through [monitorIds] / [monitorNames].
  @override
  Map<String, Model Function()> get relations => {};

  // ---------------------------------------------------------------------------
  // Typed accessors
  // ---------------------------------------------------------------------------

  /// The window identifier (string UUID).
  @override
  String get id => getAttribute('id')?.toString() ?? '';

  /// The id of the status page this window is announced on.
  String? get statusPageId => getAttribute('status_page_id')?.toString();

  /// The operator-facing window headline.
  String get title => (getAttribute('title') as String?) ?? '';

  /// The public description shown on the status page, or `null` when the
  /// operator left it blank.
  String? get description => getAttribute('description') as String?;

  /// Whether alerts for the attached monitors are held for the window's
  /// duration. Defaults to `true` on the backend, so an absent value reads as
  /// suppressed.
  bool get suppressAlerts => getAttribute('suppress_alerts') != false;

  /// When the window opens, or `null` when the attribute is absent or
  /// unparseable.
  DateTime? get startsAt => _readDateTime('starts_at');

  /// When the window closes.
  DateTime? get endsAt => _readDateTime('ends_at');

  /// When the subscriber announcement was claimed, or `null` for a window that
  /// has not been announced. Read-only: neither request class accepts it.
  DateTime? get announcedAt => _readDateTime('announced_at');

  /// The ids of the affected monitors, read from the resource's `monitors`
  /// list.
  List<String> get monitorIds => _monitorField('monitor_id');

  /// The names of the affected monitors, in the order the resource emits them.
  List<String> get monitorNames => _monitorField('name');

  // ---------------------------------------------------------------------------
  // Static helpers
  // ---------------------------------------------------------------------------

  /// Find a window by [id] (`GET /scheduled-maintenances/{id}`).
  static Future<ScheduledMaintenance?> find(dynamic id) =>
      InteractsWithPersistence.findById<ScheduledMaintenance>(
        id,
        ScheduledMaintenance.new,
      );

  /// Every window of the current team (`GET /scheduled-maintenances`).
  static Future<List<ScheduledMaintenance>> all() =>
      InteractsWithPersistence.allModels<ScheduledMaintenance>(
        ScheduledMaintenance.new,
      );

  /// Hydrate a window from a raw wire map (a `ScheduledMaintenanceResource`
  /// payload), bypassing mass-assignment protection.
  static ScheduledMaintenance fromMap(Map<String, dynamic> map) {
    return ScheduledMaintenance()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  /// Hydrate a window from a JSON string.
  static ScheduledMaintenance fromJson(String json) =>
      ScheduledMaintenance.fromMap(jsonDecode(json) as Map<String, dynamic>);

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  /// Reads a timestamp attribute as a [DateTime], tolerating the ISO-8601
  /// string the resource emits as well as a raw [DateTime] or [Carbon].
  DateTime? _readDateTime(String key) {
    final Object? value = getAttribute(key);
    if (value is Carbon) return value.toDateTime;
    if (value is DateTime) return value;
    if (value is String) return DateTime.tryParse(value);
    return null;
  }

  /// Projects one field out of the resource's `monitors` list, tolerating an
  /// absent or unexpectedly shaped attribute as an empty list.
  List<String> _monitorField(String key) {
    final Object? raw = getAttribute('monitors');
    if (raw is! List) return const [];
    return raw
        .whereType<Map<String, dynamic>>()
        .map((Map<String, dynamic> monitor) => monitor[key]?.toString())
        .whereType<String>()
        .toList();
  }
}
