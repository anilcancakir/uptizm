import 'package:flutter/foundation.dart';

import 'status.dart';

/// Summary of a single monitor as shown in the monitor list.
///
/// All fields are immutable. Optional fields (`responseMs`, `sloTarget`, etc.)
/// are absent for paused monitors or monitor types that do not report them.
///
/// ```dart
/// final monitor = monitors.first;
/// print('${monitor.name}: ${monitor.status.label}');
/// ```
@immutable
class MonitorSummary {
  /// Stable identifier used for routing (e.g. `'marketing'`, `'api'`).
  final String id;

  /// Human-readable display name.
  final String name;

  /// Probed URL.
  final String url;

  /// Current health status.
  final StatusKey status;

  /// Most-recent check response time in milliseconds.
  ///
  /// `null` when the monitor is paused or the last check produced no timing.
  final int? responseMs;

  /// Human-formatted trailing uptime string, e.g. `"99.94%"` or `"—"`.
  final String uptime;

  /// Human-readable check interval label, e.g. `"30s"` or `"60s"`.
  final String intervalLabel;

  /// Probe region identifiers, e.g. `['us-east', 'eu-west']`.
  final List<String> regions;

  /// SLO target as a percentage, e.g. `99.9`. Drives error-budget cards.
  ///
  /// `null` when no SLO is configured for this monitor.
  final double? sloTarget;

  /// Trailing-7-day uptime percentage for the short error-budget window.
  final double? sloUptime7d;

  /// Trailing-30-day uptime percentage for the contractual error-budget window.
  final double? sloUptime30d;

  const MonitorSummary({
    required this.id,
    required this.name,
    required this.url,
    required this.status,
    this.responseMs,
    required this.uptime,
    required this.intervalLabel,
    required this.regions,
    this.sloTarget,
    this.sloUptime7d,
    this.sloUptime30d,
  });

  /// Builds a [MonitorSummary] from a `MonitorResource` payload (backend
  /// `api/v1` snake_case keys).
  ///
  /// The backend exposes two status fields: `last_status` (probe health:
  /// up/down/degraded/paused) and `status` (admin state: active/paused).
  /// When `status` is `'paused'` the monitor is administratively paused and
  /// [StatusKey.paused] wins regardless of `last_status`, matching how the
  /// fixture data already represents a paused monitor.
  ///
  /// `uptime`, `sloUptime7d`, and `sloUptime30d` are rollup fields the
  /// current `MonitorResource` does not emit; they default to `'—'`/`null`
  /// until a backend uptime-rollup endpoint exists.
  factory MonitorSummary.fromMap(Map<String, dynamic> map) {
    final bool isAdminPaused = map['status'] == 'paused';
    final int? checkIntervalSec = (map['check_interval_sec'] as num?)?.toInt();
    return MonitorSummary(
      id: map['id']?.toString() ?? '',
      name: (map['name'] as String?) ?? '',
      url: (map['url'] as String?) ?? '',
      status: isAdminPaused
          ? StatusKey.paused
          : statusKeyFromWire(map['last_status'] as String?),
      responseMs: (map['last_response_ms'] as num?)?.toInt(),
      uptime: (map['uptime'] as String?) ?? '—',
      intervalLabel: checkIntervalSec != null ? '${checkIntervalSec}s' : '—',
      regions: switch (map['regions']) {
        List<dynamic> raw => raw.map((e) => e.toString()).toList(),
        _ => const [],
      },
      sloTarget: (map['slo_target'] as num?)?.toDouble(),
      sloUptime7d: (map['slo_uptime_7d'] as num?)?.toDouble(),
      sloUptime30d: (map['slo_uptime_30d'] as num?)?.toDouble(),
    );
  }

  /// Serializes the editable subset of this monitor for `POST`/`PUT`
  /// create/edit requests.
  ///
  /// Only fields present on [MonitorSummary] are emitted; the backend's
  /// wider write surface (`type`, `method`, `timeout_sec`,
  /// `expected_status_code`, `show_on_status_page`, `only_show_if_degraded`,
  /// ...) has no corresponding property on this mock shape yet, so those
  /// keys are intentionally absent from the payload.
  Map<String, dynamic> toMap() {
    return {
      'id': id,
      'name': name,
      'url': url,
      'regions': regions,
      if (sloTarget != null) 'slo_target': sloTarget,
      if (intervalLabel.endsWith('s'))
        'check_interval_sec': int.tryParse(
          intervalLabel.substring(0, intervalLabel.length - 1),
        ),
    };
  }
}

/// A single segment of the 90-day uptime history bar.
///
/// Each segment carries the health [status] at that point in time and a
/// human-readable [label] (e.g. `"7d ago"`) for tooltips.
@immutable
class UptimeSegment {
  /// Health state for this day.
  final StatusKey status;

  /// Tooltip label, e.g. `"7d ago"`.
  final String label;

  const UptimeSegment({required this.status, required this.label});
}

/// Builds a deterministic 90-day uptime history.
///
/// Pass day indices (0 = today, 89 = 90 days ago) into [down] or [degraded]
/// to mark specific days. All other days default to [StatusKey.up].
///
/// ```dart
/// final history = uptime90(down: [0, 1], degraded: [3]);
/// ```
List<UptimeSegment> uptime90({
  List<int> down = const [],
  List<int> degraded = const [],
}) {
  return List.generate(90, (i) {
    final StatusKey status;
    if (down.contains(i)) {
      status = StatusKey.down;
    } else if (degraded.contains(i)) {
      status = StatusKey.degraded;
    } else {
      status = StatusKey.up;
    }
    return UptimeSegment(status: status, label: '${90 - i}d ago');
  });
}

/// A single row in the recent-checks table.
///
/// Represents the outcome of one probe from a specific region.
@immutable
class CheckRow {
  /// Time of the check, e.g. `"14:32:05"`.
  final String time;

  /// Region identifier, e.g. `"us-east"`.
  final String region;

  /// Result status of this individual check.
  final StatusKey status;

  /// Response time in milliseconds. `null` when the probe timed out or failed
  /// before receiving a response.
  final int? responseMs;

  /// HTTP status code returned by the probe target.
  final int? statusCode;

  const CheckRow({
    required this.time,
    required this.region,
    required this.status,
    this.responseMs,
    this.statusCode,
  });

  /// Builds a [CheckRow] from a `MonitorCheckResource` payload (backend
  /// `api/v1` snake_case keys).
  ///
  /// `checked_at` is an ISO-8601 timestamp; it is reduced to the wall-clock
  /// `HH:mm:ss` string the table renders. An unparsable or missing timestamp
  /// falls back to `'—'` rather than throwing.
  factory CheckRow.fromMap(Map<String, dynamic> map) {
    return CheckRow(
      time: _formatTimeOfDay(map['checked_at'] as String?),
      region: (map['region'] as String?) ?? '',
      status: statusKeyFromWire(map['status'] as String?),
      responseMs: (map['response_ms'] as num?)?.toInt(),
      statusCode: (map['status_code'] as num?)?.toInt(),
    );
  }
}

/// Formats an ISO-8601 timestamp string as a local `HH:mm:ss` wall-clock
/// string. Returns `'—'` when [raw] is `null` or fails to parse.
String _formatTimeOfDay(String? raw) {
  if (raw == null) return '—';
  final DateTime? parsed = DateTime.tryParse(raw);
  if (parsed == null) return '—';
  final DateTime local = parsed.toLocal();
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(local.hour)}:${two(local.minute)}:${two(local.second)}';
}

/// A selectable probe region shown in the monitor form.
@immutable
class ProbeRegion {
  /// Machine identifier used in API payloads.
  final String value;

  /// Human-readable display name.
  final String label;

  /// Flag emoji for visual disambiguation in pickers.
  final String flag;

  const ProbeRegion({
    required this.value,
    required this.label,
    required this.flag,
  });
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// Design-lab fixture monitors. Deterministic; no network.
///
/// Four monitors covering the four representative status states
/// (up / degraded / down / paused).
const List<MonitorSummary> monitors = [
  MonitorSummary(
    id: 'marketing',
    name: 'Marketing site',
    url: 'https://uptizm.com',
    status: StatusKey.up,
    responseMs: 84,
    uptime: '100.00%',
    intervalLabel: '30s',
    regions: ['us-east', 'eu-west'],
    sloTarget: 99.9,
    sloUptime7d: 100,
    sloUptime30d: 100,
  ),
  MonitorSummary(
    id: 'api',
    name: 'API gateway',
    url: 'https://api.uptizm.com/health',
    status: StatusKey.degraded,
    responseMs: 412,
    uptime: '99.94%',
    intervalLabel: '30s',
    regions: ['us-east', 'us-west', 'eu-west', 'ap-southeast'],
    sloTarget: 99.95,
    sloUptime7d: 99.99,
    sloUptime30d: 99.94,
  ),
  MonitorSummary(
    id: 'checkout',
    name: 'Checkout service',
    url: 'https://pay.uptizm.com',
    status: StatusKey.down,
    uptime: '99.91%',
    intervalLabel: '10s',
    regions: ['us-east', 'eu-west'],
    sloTarget: 99.9,
    sloUptime7d: 99.98,
    sloUptime30d: 99.91,
  ),
  MonitorSummary(
    id: 'docs',
    name: 'Docs',
    url: 'https://docs.uptizm.com',
    status: StatusKey.paused,
    uptime: '—',
    intervalLabel: '60s',
    regions: ['eu-central'],
  ),
];

/// Recent probe results shown in the monitor-detail checks table.
const List<CheckRow> recentChecks = [
  CheckRow(
    time: '14:32:05',
    region: 'us-east',
    status: StatusKey.up,
    responseMs: 142,
    statusCode: 200,
  ),
  CheckRow(
    time: '14:31:58',
    region: 'eu-west',
    status: StatusKey.up,
    responseMs: 168,
    statusCode: 200,
  ),
  CheckRow(
    time: '14:31:50',
    region: 'ap-southeast',
    status: StatusKey.degraded,
    responseMs: 894,
    statusCode: 200,
  ),
  CheckRow(
    time: '14:31:42',
    region: 'us-west',
    status: StatusKey.down,
    responseMs: 5021,
    statusCode: 503,
  ),
  CheckRow(
    time: '14:31:33',
    region: 'sa-east',
    status: StatusKey.down,
    statusCode: 504,
  ),
  CheckRow(
    time: '14:31:25',
    region: 'eu-central',
    status: StatusKey.up,
    responseMs: 203,
    statusCode: 200,
  ),
];

/// All available probe regions for the monitor creation/edit form.
const List<ProbeRegion> allRegions = [
  ProbeRegion(value: 'us-east', label: 'US East', flag: '\u{1F1FA}\u{1F1F8}'),
  ProbeRegion(value: 'us-west', label: 'US West', flag: '\u{1F1FA}\u{1F1F8}'),
  ProbeRegion(value: 'eu-west', label: 'EU West', flag: '\u{1F1EE}\u{1F1EA}'),
  ProbeRegion(
    value: 'eu-central',
    label: 'EU Central',
    flag: '\u{1F1E9}\u{1F1EA}',
  ),
  ProbeRegion(
    value: 'ap-southeast',
    label: 'AP Southeast',
    flag: '\u{1F1F8}\u{1F1EC}',
  ),
  ProbeRegion(
    value: 'ap-northeast',
    label: 'AP Northeast',
    flag: '\u{1F1EF}\u{1F1F5}',
  ),
];

/// Find a monitor fixture by [id]. Returns `null` when none matches.
MonitorSummary? findMonitor(String? id) {
  if (id == null) return null;
  for (final m in monitors) {
    if (m.id == id) return m;
  }
  return null;
}
