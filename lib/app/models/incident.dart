import 'dart:convert';

import 'package:magic/magic.dart';

import '../enums/ai_confidence.dart' show aiConfidenceFromWire;
import '../enums/incident_impact.dart' show IncidentImpact, impactFromWire;
import '../enums/incident_lifecycle.dart' show IncidentLifecycle, lifecycleFromWire;
import '../enums/incident_severity.dart' show IncidentSeverity, severityFromWire;
import '../enums/signal_source.dart' show SignalSource, signalSourceFromWire;
import '../enums/timeline_actor.dart' show TimelineActor;
import '../support/formatters.dart' show formatDuration, formatRelativeMeta;
import '../support/incident_types.dart'
    show
        AffectedMonitor,
        IncidentAcknowledgement,
        IncidentAi,
        IncidentSummary,
        TimelineEntry;

/// Incident model.
///
/// Backs the `incidents` API resource (`GET/POST /incidents`, ...). Extends
/// the Magic ORM [Model] with [HasTimestamps] and [InteractsWithPersistence]
/// so an incident can be fetched, decoded, persisted, and synced like the
/// other uptizm models ([User], [Team]).
///
/// The typed accessors reproduce the full [IncidentSummary] DTO surface so a
/// later wave can swap the mocks-layer DTO for this ORM model at the call
/// sites under `lib/resources/views/incidents/`. The enum wire-bridge helpers
/// ([lifecycleFromWire], [severityFromWire], [signalSourceFromWire],
/// [impactFromWire], [aiConfidenceFromWire]) are imported from
/// `lib/app/enums/`; the value-objects ([AffectedMonitor], [IncidentAi],
/// [TimelineEntry]) come from `lib/app/support/incident_types.dart` and the
/// formatting helpers ([formatRelativeMeta], [formatDuration]) from
/// `lib/app/support/formatters.dart`. Either way this model's decode path stays
/// byte-for-byte identical to [IncidentSummary.fromMap] (same safe-fallback
/// behaviour, same wire gaps).
///
/// ## Usage
///
/// ```dart
/// final Incident? incident = await Incident.find('checkout-503');
/// Log.debug(incident?.title);           // typed accessor
/// Log.debug(incident?.lifecycle.label); // decoded enum
/// Log.debug(incident?.startedAt);       // "started 14m ago"
/// ```
class Incident extends Model with HasTimestamps, InteractsWithPersistence {
  /// The table associated with the model.
  @override
  String get table => 'incidents';

  /// The API resource for remote operations.
  ///
  /// Maps CRUD to `Http.index('incidents')` -> `GET /incidents`, the same wire
  /// path the [IncidentSummary] DTO and the incident controller already take.
  @override
  String get resource => 'incidents';

  /// Whether the primary key is auto-incrementing.
  ///
  /// Set to false because the backend issues string UUIDs for incidents.
  @override
  bool get incrementing => false;

  /// The attributes that are mass assignable.
  ///
  /// Mirrors `StoreIncidentRequest::rules()`: the create form posts exactly
  /// `monitor_id`, `severity`, `title`, and an optional `message`.
  @override
  List<String> get fillable => [
    'monitor_id',
    'severity',
    'title',
    'message',
  ];

  /// The attributes that should be cast.
  ///
  /// `ai_owned`/`is_public`/`autonomous` decode as `bool`; the five timestamp
  /// columns decode as [Carbon] datetimes; and the four wire-bridge enums
  /// (`severity`/`impact`/`signal_source`/`lifecycle`) decode through the
  /// matching public helper in `lib/app/enums/`, so this model
  /// inherits the exact same safe-fallback semantics as [IncidentSummary].
  @override
  Map<String, dynamic> get casts => {
    'ai_owned': 'bool',
    'is_public': 'bool',
    'autonomous': 'bool',
    'created_at': 'datetime',
    'updated_at': 'datetime',
    'started_at': 'datetime',
    'resolved_at': 'datetime',
    'postmortem_published_at': 'datetime',
  };

  /// No relations this wave: `monitors` and `updates` stay decoded as DTOs
  /// ([AffectedMonitor] / [TimelineEntry]) off the raw pivot lists (Tier-2).
  @override
  Map<String, Model Function()> get relations => {};

  // ---------------------------------------------------------------------------
  // Typed accessors: scalar fields
  // ---------------------------------------------------------------------------

  /// The incident identifier (string UUID).
  @override
  String get id => getAttribute('id')?.toString() ?? '';

  /// Incident headline.
  String get title => (getAttribute('title') as String?) ?? '';

  /// Set the incident headline.
  set title(String? value) => setAttribute('title', value);

  /// Optional first-update or composer message body.
  String? get message => getAttribute('message') as String?;

  /// Set the optional message body.
  set message(String? value) => setAttribute('message', value);

  /// The id of the monitor this incident was opened against.
  String? get monitorId => getAttribute('monitor_id')?.toString();

  /// Set the monitor id this incident was opened against.
  set monitorId(String? value) => setAttribute('monitor_id', value);

  /// The id of the team that owns this incident.
  String? get teamId => getAttribute('team_id')?.toString();

  /// The id of the monitor rendered as the primary affected component.
  String? get primaryMonitorId =>
      getAttribute('primary_monitor_id')?.toString();

  /// The metric key that tripped the threshold, when the signal source is
  /// threshold-based; `null` for anomaly or manual sources.
  String? get triggerMetricKey =>
      getAttribute('trigger_metric_key') as String?;

  /// The id of the team member currently driving the response, or `null` when
  /// the incident is unassigned.
  ///
  /// Decoded from the resource's `assignee` sub-object (`{id, name}`), which the
  /// backend emits from the persisted `assigned_to_user_id`. This is the ONLY
  /// source of the assignment: the detail screen renders and posts against it
  /// rather than keeping a local selection.
  String? get assigneeId => _assigneeField('id');

  /// The display name of the assigned team member, or `null` when unassigned.
  String? get assigneeName => _assigneeField('name');

  /// The stored postmortem body, or `null` when none has been written yet.
  ///
  /// A non-null body with a `null` [postmortemPublishedAt] is an INTERNAL
  /// draft: the public status page does not render it.
  String? get postmortemBody {
    final String body = (getAttribute('postmortem_body') as String?) ?? '';
    return body.trim().isEmpty ? null : body;
  }

  /// When the postmortem was published to the public status page, or `null`
  /// while it is still an internal draft (or absent entirely).
  DateTime? get postmortemPublishedAt => _readDateTime('postmortem_published_at');

  /// Whether the stored postmortem is live on the public status page.
  bool get postmortemIsPublished =>
      postmortemBody != null && postmortemPublishedAt != null;

  // ---------------------------------------------------------------------------
  // Typed accessors: wire-bridge enum fields
  // ---------------------------------------------------------------------------

  /// Operator-side severity tier, decoded from the backend wire value.
  ///
  /// `getAttribute` applies the [severityFromWire] cast for non-null values;
  /// the null branch mirrors [IncidentSummary.fromMap] by delegating to the
  /// same bridge, which returns the safe default.
  IncidentSeverity get severity {
    final Object? value = getAttribute('severity');
    return value is IncidentSeverity
        ? value
        : severityFromWire(value?.toString());
  }

  /// Set the operator-side severity tier, stored as its backend wire string so
  /// a subsequent `toArray()`/`save()` posts a JSON-encodable value (the wire
  /// gap: [IncidentSeverity.warning] serializes to `'warn'`, not `'warning'`).
  set severity(IncidentSeverity? value) => setAttribute(
    'severity',
    switch (value) {
      IncidentSeverity.critical => 'critical',
      IncidentSeverity.warning => 'warn',
      IncidentSeverity.info => 'info',
      null => null,
    },
  );

  /// Customer-facing impact classification, decoded via [impactFromWire].
  IncidentImpact get impact {
    final Object? value = getAttribute('impact');
    return value is IncidentImpact ? value : impactFromWire(value?.toString());
  }

  /// How the incident was first detected, decoded via [signalSourceFromWire].
  SignalSource get signalSource {
    final Object? value = getAttribute('signal_source');
    return value is SignalSource
        ? value
        : signalSourceFromWire(value?.toString());
  }

  /// Current lifecycle stage, decoded via [lifecycleFromWire].
  IncidentLifecycle get lifecycle {
    final Object? value = getAttribute('lifecycle');
    return value is IncidentLifecycle
        ? value
        : lifecycleFromWire(value?.toString());
  }

  // ---------------------------------------------------------------------------
  // Typed accessors: bool flags
  // ---------------------------------------------------------------------------

  /// Whether Uptizm AI owns this incident's response.
  bool get aiOwned => getAttribute('ai_owned') == true;

  /// Whether this incident is visible on the public status page.
  bool get isPublic => getAttribute('is_public') == true;

  /// Whether the latest update was posted autonomously by AI.
  bool get autonomous => getAttribute('autonomous') == true;

  // ---------------------------------------------------------------------------
  // Derived display accessors (reproduce IncidentSummary surface)
  // ---------------------------------------------------------------------------

  /// Relative-time meta line, e.g. `"started 14m ago"` or `"resolved 2h ago"`.
  ///
  /// Reproduces [IncidentSummary.startedAt]: a display string, not a
  /// timestamp. The raw `started_at`/`resolved_at` instants are formatted
  /// through [formatRelativeMeta]. Read the raw instant directly via
  /// `getAttribute('started_at')` (a [Carbon]) when the timestamp is needed.
  String get startedAt {
    final DateTime started = _readDateTime('started_at') ?? DateTime.now();
    return formatRelativeMeta(started, _readDateTime('resolved_at'));
  }

  /// Elapsed duration, e.g. `"14m"` or `"1h 06m"`.
  ///
  /// Reproduces [IncidentSummary.duration]: the elapsed time between
  /// `started_at` and `resolved_at` (or now, when the incident is still open),
  /// formatted through [formatDuration].
  String get duration {
    final DateTime started = _readDateTime('started_at') ?? DateTime.now();
    final DateTime until = _readDateTime('resolved_at') ?? DateTime.now();
    return formatDuration(started, until);
  }

  /// Primary monitor name for the detail header meta line.
  ///
  /// Resolved from the `monitors` pivot list by matching each entry's
  /// `monitor_id` against [primaryMonitorId], falling back to the first
  /// affected monitor, mirroring [IncidentSummary.fromMap].
  String get monitorName {
    final List<Map<String, dynamic>> maps = _monitorMaps;
    if (maps.isEmpty) return '';
    final String? primaryId = primaryMonitorId;
    final Map<String, dynamic> primary = maps.firstWhere(
      (m) => m['monitor_id']?.toString() == primaryId,
      orElse: () => maps.first,
    );
    return (primary['name'] as String?) ?? '';
  }

  /// Number of monitors affected.
  int get affectedCount => _monitorMaps.length;

  /// All monitors affected, with their status transitions, decoded from the
  /// `monitors` pivot list.
  List<AffectedMonitor> get affectedMonitors =>
      _monitorMaps.map(AffectedMonitor.fromMap).toList();

  /// Chronological activity log, decoded from the `updates` list.
  List<TimelineEntry> get timeline =>
      _updateMaps.map(TimelineEntry.fromMap).toList();

  /// The persisted acknowledgement, or `null` while nobody has acknowledged.
  ///
  /// Derived from the timeline and nothing else: the client never authors an
  /// acknowledging identity. `IncidentWriteService.acknowledge` is the write
  /// that moves a `detected` incident to `investigating`, stamping the note's
  /// `author` from the acting backend user, so the acknowledgement is the FIRST
  /// human-authored entry carrying the `investigating` status. The backend
  /// returns `updates` ordered by `display_at` ascending, so "first match" is
  /// the earliest such entry; a later reopen (which also writes
  /// `investigating`) therefore cannot shadow the original acknowledgement.
  ///
  /// The status comparison is against the WIRE token
  /// ([IncidentLifecycle.investigating]'s name) rather than through
  /// [lifecycleFromWire], whose unknown-value fallback would silently answer
  /// `detected` for an unrelated status.
  IncidentAcknowledgement? get acknowledgement {
    for (final TimelineEntry entry in timeline) {
      final String? author = entry.author;
      if (entry.actor != TimelineActor.human || author == null) continue;
      if (entry.status != IncidentLifecycle.investigating.name) continue;

      return IncidentAcknowledgement(by: author, at: entry.time);
    }

    return null;
  }

  /// Attached AI analysis, or `null` when AI analysis is absent.
  ///
  /// Decoded from the `ai` sub-object the `GET /dashboard/ai-inbox` shape
  /// carries; the evidence, suggested-action, and similar-incident lists have
  /// no counterpart in that resource yet and stay empty, mirroring
  /// [IncidentSummary.fromMap].
  IncidentAi? get ai {
    final Object? raw = getAttribute('ai');
    if (raw is! Map) return null;
    return IncidentAi(
      trigger: (raw['trigger'] as String?) ?? '',
      confidence: aiConfidenceFromWire(raw['confidence'] as String?),
      tldr: (raw['tldr'] as String?) ?? '',
      evidenceFor: const [],
      evidenceAgainst: const [],
      suggestedActions: const [],
      similarIncidents: const [],
    );
  }

  // ---------------------------------------------------------------------------
  // Static helpers
  // ---------------------------------------------------------------------------

  /// Find an incident by ID.
  ///
  /// Returns `null` if no incident with the given [id] exists.
  ///
  /// ```dart
  /// final incident = await Incident.find('checkout-503');
  /// ```
  static Future<Incident?> find(dynamic id) =>
      InteractsWithPersistence.findById<Incident>(id, Incident.new);

  /// Get all incidents.
  ///
  /// ```dart
  /// final incidents = await Incident.all();
  /// ```
  static Future<List<Incident>> all() =>
      InteractsWithPersistence.allModels<Incident>(Incident.new);

  // ---------------------------------------------------------------------------
  // Flutter-Familiar Factory Methods
  // ---------------------------------------------------------------------------

  /// Create an [Incident] from a [Map].
  ///
  /// Uses [setRawAttributes] to hydrate the model directly from raw API data,
  /// bypassing mass-assignment protection. The [exists] flag is set based on
  /// whether the map contains an `id` key.
  ///
  /// ```dart
  /// final incident = Incident.fromMap({'id': 'abc', 'title': 'Checkout 503'});
  /// ```
  static Incident fromMap(Map<String, dynamic> map) {
    return Incident()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  /// Create an [Incident] from a JSON string.
  ///
  /// Decodes [json] and delegates to [fromMap].
  ///
  /// ```dart
  /// final incident = Incident.fromJson('{"id":"abc","title":"Checkout 503"}');
  /// ```
  static Incident fromJson(String json) {
    final map = jsonDecode(json) as Map<String, dynamic>;
    return Incident.fromMap(map);
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  /// Reads a timestamp attribute as a [DateTime], tolerating the [Carbon]
  /// produced by the `datetime` cast as well as a raw [DateTime] or ISO string.
  DateTime? _readDateTime(String key) {
    final Object? value = getAttribute(key);
    if (value is Carbon) return value.toDateTime;
    if (value is DateTime) return value;
    if (value is String) return DateTime.tryParse(value);
    return null;
  }

  /// Reads one field off the resource's `assignee` sub-object, tolerating an
  /// absent or non-map value (an unassigned incident, or a payload whose
  /// `assignee` relation was never eager-loaded) as `null`. A blank string
  /// reads as `null` too, so an empty name never renders as an owner.
  String? _assigneeField(String key) {
    final Object? raw = getAttribute('assignee');
    if (raw is! Map) return null;

    final String value = raw[key]?.toString().trim() ?? '';
    return value.isEmpty ? null : value;
  }

  /// The raw `monitors` pivot list as typed maps, or an empty list when the
  /// attribute is absent or shaped unexpectedly.
  List<Map<String, dynamic>> get _monitorMaps =>
      _rawMaps('monitors');

  /// The raw `updates` list as typed maps, or an empty list when the attribute
  /// is absent or shaped unexpectedly.
  List<Map<String, dynamic>> get _updateMaps => _rawMaps('updates');

  /// Coerces a list-valued attribute into a typed list of maps, tolerating a
  /// non-list or null value as an empty list.
  List<Map<String, dynamic>> _rawMaps(String key) {
    final Object? raw = getAttribute(key);
    if (raw is! List) return const [];
    return raw.whereType<Map<String, dynamic>>().toList();
  }
}
