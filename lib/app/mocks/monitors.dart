import '../enums/status_key.dart' show StatusKey;
import '../models/monitor.dart';
import '../support/monitor_types.dart' show CheckRow, ProbeRegion, UptimeSegment;

// ---------------------------------------------------------------------------
// Uptime history factory
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// Design-lab fixture monitors, projected onto the [Monitor] ORM model.
///
/// Four monitors covering the four representative status states
/// (up / degraded / down / paused). The predecessor `MonitorSummary` DTO was
/// deleted once every controller migrated to [Monitor]; these fixtures are
/// hydrated through [Monitor.fromMap] from `MonitorResource`-shaped maps so the
/// design-lab surfaces (status-page components, form pickers) read the same
/// model the live inventory does. Deterministic; no network.
final List<Monitor> monitors = [
  Monitor.fromMap(<String, dynamic>{
    'id': 'marketing',
    'name': 'Marketing site',
    'url': 'https://uptizm.com',
    'last_status': 'up',
    'last_response_ms': 84,
    'uptime': '100.00%',
    'check_interval_sec': 30,
    'regions': <String>['us-east', 'eu-west'],
    'slo_target': 99.9,
    'slo_uptime_7d': 100,
    'slo_uptime_30d': 100,
  }),
  Monitor.fromMap(<String, dynamic>{
    'id': 'api',
    'name': 'API gateway',
    'url': 'https://api.uptizm.com/health',
    'last_status': 'degraded',
    'last_response_ms': 412,
    'uptime': '99.94%',
    'check_interval_sec': 30,
    'regions': <String>['us-east', 'us-west', 'eu-west', 'ap-southeast'],
    'slo_target': 99.95,
    'slo_uptime_7d': 99.99,
    'slo_uptime_30d': 99.94,
  }),
  Monitor.fromMap(<String, dynamic>{
    'id': 'checkout',
    'name': 'Checkout service',
    'url': 'https://pay.uptizm.com',
    'last_status': 'down',
    'uptime': '99.91%',
    'check_interval_sec': 10,
    'regions': <String>['us-east', 'eu-west'],
    'slo_target': 99.9,
    'slo_uptime_7d': 99.98,
    'slo_uptime_30d': 99.91,
  }),
  Monitor.fromMap(<String, dynamic>{
    'id': 'docs',
    'name': 'Docs',
    'url': 'https://docs.uptizm.com',
    'status': 'paused',
    'uptime': '—',
    'check_interval_sec': 60,
    'regions': <String>['eu-central'],
  }),
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
  ProbeRegion(value: 'ap', label: 'Asia Pacific', flag: '\u{1F30F}'),
];

/// Find a monitor fixture by [id]. Returns `null` when none matches.
Monitor? findMonitor(String? id) {
  if (id == null) return null;
  for (final Monitor m in monitors) {
    if (m.id == id) return m;
  }
  return null;
}
