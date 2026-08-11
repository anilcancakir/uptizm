import 'dart:convert';

import 'package:magic/magic.dart';

import '../enums/status_key.dart' show StatusKey, statusKeyFromWire;

/// Monitor model.
///
/// Represents a single uptime probe configuration plus its denormalized
/// runtime state. Extends the Magic ORM [Model] with [HasTimestamps] and
/// [InteractsWithPersistence] to provide full ORM persistence (find / all /
/// save / delete) against the `monitors` API resource.
///
/// The accessor surface reproduces the predecessor [MonitorSummary] DTO one
/// for one (computed [status], [responseMs], [uptime], [intervalLabel],
/// [regions], [sloTarget]) so view call-sites migrate to this model without
/// shape changes, while the typed write-surface accessors cover every field
/// the create/edit form posts. The SLO reliability surface is the eight
/// show-only minute accessors ([sloDownMinutes7d] and friends), which replaced
/// the two trailing uptime PERCENTAGES the error-budget card used to convert
/// back into minutes of the full window.
///
/// ## Usage with the ORM
///
/// ```dart
/// final monitor = await Monitor.find('api');
/// Log.debug(monitor?.name);
/// Log.debug(monitor?.status.label);
///
/// final monitors = await Monitor.all();
/// ```
///
/// ## Usage with Typed Accessors
///
/// ```dart
/// final monitor = Monitor()
///   ..name = 'API gateway'
///   ..url = 'https://api.uptizm.com/health'
///   ..checkIntervalSec = 30;
/// await monitor.save();
/// ```
///
/// ## Computed Status Semantics
///
/// The backend exposes two status fields: `status` (admin state:
/// `active` / `paused`) and `last_status` (probe health:
/// `up` / `down` / `degraded` / ...). When the admin `status` is `paused` the
/// monitor is administratively paused and [status] returns
/// [StatusKey.paused] regardless of `last_status`; otherwise [status] decodes
/// `last_status` via [statusKeyFromWire], matching [MonitorSummary.fromMap].
class Monitor extends Model with HasTimestamps, InteractsWithPersistence {
  /// The table associated with the model.
  @override
  String get table => 'monitors';

  /// The API resource for remote operations.
  @override
  String get resource => 'monitors';

  /// Whether the primary key is auto-incrementing.
  ///
  /// Set to false because monitors use string UUIDs as primary keys.
  @override
  bool get incrementing => false;

  /// The attributes that are mass assignable.
  ///
  /// Mirrors the create/edit write surface validated by the backend
  /// `StoreMonitorRequest` / `UpdateMonitorRequest`. Runtime state
  /// (`last_status`, `last_response_ms`, `consecutive_fails`, ...) is server
  /// controlled and therefore intentionally NOT mass-assignable here.
  ///
  /// This list must stay in step with those two FormRequests. A key the form
  /// posts but this list omits is stripped HERE, before the request is ever
  /// sent, so the write fails silently with a 2xx and no validation error to
  /// surface: that is how the monitor form's AI-assist mode and escalation-policy
  /// pin were both lost even though the form collected them correctly.
  @override
  List<String> get fillable => [
    'name',
    'url',
    'type',
    'method',
    'check_interval_sec',
    'timeout_sec',
    'regions',
    'expected_status_code',
    'request_headers',
    'request_body',
    'slo_target',
    'tags',
    'show_on_status_page',
    'only_show_if_degraded',
    'alert_on_down',
    'alert_on_recover',
    'ssl_tracking',
    'ssl_alert_threshold_days',
    'auth_config',
    'ai_mode',
    'escalation_policy_id',

    // Write-only, and create-only in practice: `metrics` is not a monitor
    // column. It is the bulk `metrics[]` the AI create flow submits alongside
    // the monitor so the two land in one transaction, and it has to be listed
    // here for the same reason as everything above it. Omitted, `fill()` strips
    // it before the request leaves and `POST /monitors` answers 201 for a
    // monitor with no metrics, which is precisely the silent 2xx this docblock
    // warns about. The edit form never sets it, so a PUT never carries it.
    'metrics',
  ];

  /// The attributes that should be cast.
  ///
  /// JSON columns (`regions`, `request_headers`, `tags`, `auth_config`) round
  /// trip through the `'json'` cast; numeric and boolean columns are coerced
  /// to their Dart types so typed accessors never hand a caller a raw
  /// `String`; timestamp columns become [Carbon] instances.
  @override
  Map<String, String> get casts => {
    // JSON-encoded columns.
    'regions': 'json',
    'request_headers': 'json',
    'tags': 'json',
    'auth_config': 'json',
    // Float percentages.
    'slo_target': 'double',
    'uptime_24h': 'double',
    // Float minutes: the show-only reliability read-model. The cast is what
    // makes them safe to read as a double, because a whole-number float
    // round-trips through JSON as an int (the backend's `2.0` arrives as `2`)
    // and magic's `'double'` cast coerces that back (`double.tryParse`).
    'slo_down_minutes_7d': 'double',
    'slo_observed_minutes_7d': 'double',
    'slo_gap_minutes_7d': 'double',
    'slo_measured_minutes_7d': 'double',
    'slo_down_minutes_30d': 'double',
    'slo_observed_minutes_30d': 'double',
    'slo_gap_minutes_30d': 'double',
    'slo_measured_minutes_30d': 'double',
    // Booleans.
    'show_on_status_page': 'bool',
    'only_show_if_degraded': 'bool',
    'alert_on_down': 'bool',
    'alert_on_recover': 'bool',
    'ssl_tracking': 'bool',
    'is_group': 'bool',
    // Integers.
    'check_interval_sec': 'int',
    'timeout_sec': 'int',
    'expected_status_code': 'int',
    'last_response_ms': 'int',
    'consecutive_fails': 'int',
    'incident_threshold': 'int',
    'ssl_alert_threshold_days': 'int',
    // Timestamps.
    'created_at': 'datetime',
    'updated_at': 'datetime',
    'last_checked_at': 'datetime',
    'next_check_at': 'datetime',
    'ssl_expires_at': 'datetime',
    'ssl_last_checked_at': 'datetime',
  };

  // ---------------------------------------------------------------------------
  // Typed Accessors: Identity + MonitorSummary Surface
  // ---------------------------------------------------------------------------

  /// Get the monitor's ID.
  @override
  String get id => getAttribute('id')?.toString() ?? '';

  /// Get the monitor's display name.
  String? get name => getAttribute('name') as String?;

  /// Set the monitor's display name.
  set name(String? value) => setAttribute('name', value);

  /// Get the probed URL.
  String? get url => getAttribute('url') as String?;

  /// Set the probed URL.
  set url(String? value) => setAttribute('url', value);

  /// Current health status, computed from the admin + probe states.
  ///
  /// The admin `status` field (`active` / `paused`) wins: when it is `paused`
  /// the monitor is administratively paused and [StatusKey.paused] is returned
  /// regardless of `last_status`. An active monitor with no `last_status` yet
  /// (freshly created, first check still pending) is [StatusKey.pending], NOT
  /// [StatusKey.info]/"Maintenance": a monitor is never in maintenance, so an
  /// absent probe verdict must read as a neutral "Pending", not borrow the
  /// component-maintenance label. Otherwise the probe health (`last_status`) is
  /// decoded via [statusKeyFromWire], which falls back to [StatusKey.info] on an
  /// unrecognized (non-null) wire value so a stale client never crashes.
  StatusKey get status {
    if (getAttribute('status') == 'paused') {
      return StatusKey.paused;
    }
    final String? lastStatus = getAttribute('last_status') as String?;
    if (lastStatus == null) {
      return StatusKey.pending;
    }
    return statusKeyFromWire(lastStatus);
  }

  /// The raw admin status wire value (`active` / `paused`).
  ///
  /// Exposed separately from the computed [status] so callers that need the
  /// raw admin state (for example, the pause/resume toggle) can read it
  /// without going through the [StatusKey] projection.
  String? get adminStatus => getAttribute('status') as String?;

  /// The raw probe health wire value (`up` / `down` / `degraded` / ...).
  String? get lastStatus => getAttribute('last_status') as String?;

  /// Most-recent check response time in milliseconds.
  ///
  /// Returns the `last_response_ms` column, or `null` when the monitor is
  /// paused or the last check produced no timing. Mirrors
  /// [MonitorSummary.responseMs].
  int? get responseMs => getAttribute('last_response_ms') as int?;

  /// Human-formatted trailing uptime string, e.g. `"99.94%"`.
  ///
  /// Defaults to the long-dash placeholder because the current
  /// `MonitorResource` does not emit a rollup uptime field; the value is
  /// populated once a backend uptime-rollup endpoint exists. Mirrors
  /// [MonitorSummary.uptime].
  String get uptime => getAttribute('uptime') as String? ?? '—';

  /// Measured uptime percentage over the trailing 24h, or `null` when the
  /// monitor has no checks in that window yet (the KPI then renders a no-data
  /// placeholder instead of a fabricated figure). Populated by the monitor
  /// show endpoint only.
  double? get uptime24h => getAttribute('uptime_24h') as double?;

  /// Human-readable check interval label, e.g. `"30s"` or `"60s"`.
  ///
  /// Computed from `check_interval_sec` so the wire never carries a
  /// preformatted label. Falls back to the long-dash placeholder when the
  /// interval is absent. Mirrors [MonitorSummary.intervalLabel].
  String get intervalLabel {
    final int? seconds = getAttribute('check_interval_sec') as int?;
    if (seconds == null) return '—';
    return '${seconds}s';
  }

  /// Probe region identifiers, e.g. `['us-east', 'eu-west']`.
  ///
  /// Stored as a JSON array; decoded to a typed [List] of strings. Coerces
  /// each element through `toString()` so a wire integer id never leaks as a
  /// non-string. Defaults to an empty list when absent.
  List<String> get regions {
    final Object? raw = getAttribute('regions');
    if (raw is List) {
      return raw.map((Object? e) => e.toString()).toList();
    }
    return const <String>[];
  }

  /// Set the probe region identifiers.
  set regions(List<String> value) => setAttribute('regions', value);

  /// SLO target as a percentage, e.g. `99.9`. Drives error-budget cards.
  ///
  /// `null` when no SLO is configured for this monitor.
  double? get sloTarget => getAttribute('slo_target') as double?;

  /// Set the SLO target percentage.
  set sloTarget(double? value) => setAttribute('slo_target', value);

  // ---------------------------------------------------------------------------
  // Typed Accessors: SLO Reliability Minutes (show-only)
  // ---------------------------------------------------------------------------

  // Four measurements per error-budget window, all in MINUTES and all
  // populated by `GET /monitors/:id` alone (a list row carries them as null,
  // which is why `MonitorController._measuredUptimeAttributes` names every key
  // below). They replaced the two uptime PERCENTAGES this model used to expose:
  // the card converted a ratio measured over whatever had been checked back
  // into minutes of the full window, so a 15-hour-old monitor with 2 real down
  // minutes reported 112 of them over 30 days.
  //
  // `measured` is the field that separates "never measured" from "measured and
  // fine": a 30-day-old monitor that recorded nothing has a full window of
  // observed time and zero downtime, which reads as a perfect score without it.
  // `gap` is observed time holding no check at all, reported on its own because
  // counting it down invents downtime and counting it up forgives uptizm's own
  // blind spot.

  /// Measured downtime minutes over the trailing 7 days.
  double? get sloDownMinutes7d => getAttribute('slo_down_minutes_7d') as double?;

  /// Elapsed minutes of the trailing 7 days this monitor existed for.
  double? get sloObservedMinutes7d =>
      getAttribute('slo_observed_minutes_7d') as double?;

  /// Observed minutes over the trailing 7 days holding no check at all.
  double? get sloGapMinutes7d => getAttribute('slo_gap_minutes_7d') as double?;

  /// Observed minutes over the trailing 7 days a check was recorded for.
  double? get sloMeasuredMinutes7d =>
      getAttribute('slo_measured_minutes_7d') as double?;

  /// Measured downtime minutes over the trailing 30 days.
  double? get sloDownMinutes30d =>
      getAttribute('slo_down_minutes_30d') as double?;

  /// Elapsed minutes of the trailing 30 days this monitor existed for.
  double? get sloObservedMinutes30d =>
      getAttribute('slo_observed_minutes_30d') as double?;

  /// Observed minutes over the trailing 30 days holding no check at all.
  double? get sloGapMinutes30d => getAttribute('slo_gap_minutes_30d') as double?;

  /// Observed minutes over the trailing 30 days a check was recorded for.
  double? get sloMeasuredMinutes30d =>
      getAttribute('slo_measured_minutes_30d') as double?;

  // ---------------------------------------------------------------------------
  // Typed Accessors: Write Surface (fillable fields)
  // ---------------------------------------------------------------------------

  /// Get the probe type wire value (`http`, `tcp`, ...).
  String? get type => getAttribute('type') as String?;

  /// Set the probe type wire value.
  set type(String? value) => setAttribute('type', value);

  /// Get the HTTP method wire value (`GET`, `POST`, ...).
  String? get method => getAttribute('method') as String?;

  /// Set the HTTP method wire value.
  set method(String? value) => setAttribute('method', value);

  /// Get the check interval in seconds.
  int get checkIntervalSec => getAttribute('check_interval_sec') as int? ?? 0;

  /// Set the check interval in seconds.
  set checkIntervalSec(int value) => setAttribute('check_interval_sec', value);

  /// Get the probe timeout in seconds.
  int get timeoutSec => getAttribute('timeout_sec') as int? ?? 0;

  /// Set the probe timeout in seconds.
  set timeoutSec(int value) => setAttribute('timeout_sec', value);

  /// Get the expected HTTP status code (`null` means any 2xx).
  int? get expectedStatusCode => getAttribute('expected_status_code') as int?;

  /// Set the expected HTTP status code.
  set expectedStatusCode(int? value) =>
      setAttribute('expected_status_code', value);

  /// Get the custom request headers as a JSON map.
  ///
  /// Defaults to an empty map when the wire value is absent or not a JSON
  /// object, so callers can iterate unconditionally.
  Map<String, dynamic> get requestHeaders {
    final Object? raw = getAttribute('request_headers');
    if (raw is Map) {
      return Map<String, dynamic>.from(raw);
    }
    return const <String, dynamic>{};
  }

  /// Set the custom request headers.
  set requestHeaders(Map<String, dynamic> value) =>
      setAttribute('request_headers', value);

  /// Get the raw request body sent on each probe.
  String? get requestBody => getAttribute('request_body') as String?;

  /// Set the raw request body.
  set requestBody(String? value) => setAttribute('request_body', value);

  /// Get the free-form tag list.
  List<String> get tags {
    final Object? raw = getAttribute('tags');
    if (raw is List) {
      return raw.map((Object? e) => e.toString()).toList();
    }
    return const <String>[];
  }

  /// Set the free-form tag list.
  set tags(List<String> value) => setAttribute('tags', value);

  /// Whether this monitor is shown on the public status page.
  bool get showOnStatusPage =>
      getAttribute('show_on_status_page') as bool? ?? false;

  /// Set whether this monitor is shown on the public status page.
  set showOnStatusPage(bool value) =>
      setAttribute('show_on_status_page', value);

  /// Whether this monitor only appears on the status page when degraded.
  bool get onlyShowIfDegraded =>
      getAttribute('only_show_if_degraded') as bool? ?? false;

  /// Set the only-show-if-degraded flag.
  set onlyShowIfDegraded(bool value) =>
      setAttribute('only_show_if_degraded', value);

  /// Whether an alert fires when this monitor goes down.
  bool get alertOnDown => getAttribute('alert_on_down') as bool? ?? false;

  /// Set the alert-on-down flag.
  set alertOnDown(bool value) => setAttribute('alert_on_down', value);

  /// Whether an alert fires when this monitor recovers.
  bool get alertOnRecover => getAttribute('alert_on_recover') as bool? ?? false;

  /// Set the alert-on-recover flag.
  set alertOnRecover(bool value) => setAttribute('alert_on_recover', value);

  /// Whether SSL certificate expiry is tracked for this monitor.
  bool get sslTracking => getAttribute('ssl_tracking') as bool? ?? false;

  /// Set the SSL tracking flag.
  set sslTracking(bool value) => setAttribute('ssl_tracking', value);

  /// Get the SSL alert threshold in days.
  int get sslAlertThresholdDays =>
      getAttribute('ssl_alert_threshold_days') as int? ?? 0;

  /// Set the SSL alert threshold in days.
  set sslAlertThresholdDays(int value) =>
      setAttribute('ssl_alert_threshold_days', value);

  /// Get the auth credential map (redacted on the wire by `MonitorResource`).
  ///
  /// The backend only ever emits the non-secret descriptors
  /// (`type`, `username`, `header`); secret values (`password`, `token`,
  /// `key`) are stripped server-side. Returns `null` when no auth is
  /// configured.
  Map<String, dynamic>? get authConfig {
    final Object? raw = getAttribute('auth_config');
    if (raw is Map) {
      return Map<String, dynamic>.from(raw);
    }
    return null;
  }

  /// Set the auth credential map.
  set authConfig(Map<String, dynamic>? value) =>
      setAttribute('auth_config', value);

  /// Get the pinned escalation-policy id, or `null` when none is pinned.
  ///
  /// Null is meaningful rather than missing: the backend's
  /// `EscalationDispatcher::resolvePolicy()` falls back to the team's default
  /// (earliest-created) policy when a monitor pins none, so the edit form
  /// renders null as "Team default" instead of inventing a selection.
  String? get escalationPolicyId =>
      getAttribute('escalation_policy_id')?.toString();

  /// Set the pinned escalation-policy id (`null` to unpin).
  set escalationPolicyId(String? value) =>
      setAttribute('escalation_policy_id', value);

  /// Get the AI-assist mode token (`off` / `suggest`).
  String get aiMode => getAttribute('ai_mode') as String? ?? 'off';

  /// Set the AI-assist mode token.
  set aiMode(String value) => setAttribute('ai_mode', value);

  // ---------------------------------------------------------------------------
  // Typed Accessors: Runtime State (server-controlled)
  // ---------------------------------------------------------------------------

  /// Get the owning team ID.
  String? get teamId => getAttribute('team_id')?.toString();

  /// Whether this row is a component group with child monitors.
  bool get isGroup => getAttribute('is_group') as bool? ?? false;

  /// Get the parent group ID for a child monitor (`null` at the top level).
  String? get parentId => getAttribute('parent_id')?.toString();

  /// Get the consecutive-failure counter for incident thresholding.
  int get consecutiveFails => getAttribute('consecutive_fails') as int? ?? 0;

  /// Get the number of consecutive fails that opens an incident.
  int get incidentThreshold => getAttribute('incident_threshold') as int? ?? 0;

  /// Get the timestamp of the most recent probe.
  Carbon? get lastCheckedAt => getAttribute('last_checked_at') as Carbon?;

  /// Get the scheduled timestamp of the next probe.
  Carbon? get nextCheckAt => getAttribute('next_check_at') as Carbon?;

  /// Get the SSL certificate expiry timestamp.
  Carbon? get sslExpiresAt => getAttribute('ssl_expires_at') as Carbon?;

  /// Get the timestamp of the most recent SSL check.
  Carbon? get sslLastCheckedAt =>
      getAttribute('ssl_last_checked_at') as Carbon?;

  // ---------------------------------------------------------------------------
  // Static Helpers
  // ---------------------------------------------------------------------------

  /// Find a monitor by ID.
  ///
  /// Returns `null` if no monitor with the given [id] exists (non-2xx
  /// response or empty envelope).
  ///
  /// ```dart
  /// final monitor = await Monitor.find('api');
  /// ```
  static Future<Monitor?> find(dynamic id) =>
      InteractsWithPersistence.findById<Monitor>(id, Monitor.new);

  /// Get all monitors.
  ///
  /// ```dart
  /// final monitors = await Monitor.all();
  /// ```
  static Future<List<Monitor>> all() =>
      InteractsWithPersistence.allModels<Monitor>(Monitor.new);

  // ---------------------------------------------------------------------------
  // Flutter-Familiar Factory Methods
  // ---------------------------------------------------------------------------

  /// Create a [Monitor] from a [Map].
  ///
  /// Uses [setRawAttributes] to hydrate the model directly from raw API data,
  /// bypassing mass-assignment protection. The [exists] flag is set based on
  /// whether the map contains an `id` key, matching the [User] / [Team]
  /// template.
  ///
  /// ```dart
  /// final monitor = Monitor.fromMap(resourcePayload);
  /// ```
  static Monitor fromMap(Map<String, dynamic> map) {
    return Monitor()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  /// Create a [Monitor] from a JSON string.
  ///
  /// Decodes [json] and delegates to [fromMap].
  ///
  /// ```dart
  /// final monitor = Monitor.fromJson('{"id":"api","name":"API"}');
  /// ```
  static Monitor fromJson(String json) {
    final map = jsonDecode(json) as Map<String, dynamic>;
    return Monitor.fromMap(map);
  }
}
