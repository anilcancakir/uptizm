import 'dart:math' as math;

import 'package:flutter/foundation.dart';

// ---------------------------------------------------------------------------
// MetricChart contract types
// ---------------------------------------------------------------------------

/// Semantic chart color tones; map to CSS vars so series follow the theme.
///
/// These are the tones the `MetricChart` component understands. Note that
/// `down` and `paused` are absent: those status keys do not have a chart
/// tone variant in the design contract.
enum ChartTone {
  /// Brand primary color; default for single-series charts.
  primary,

  /// Operational green; used for latency series in healthy monitors.
  up,

  /// Informational blue; used for informational or AI-baseline series.
  info,

  /// Amber warning; used for elevated-latency or saturation series.
  degraded,

  /// AI purple; used for AI-learned baseline bands and anomaly series.
  ai,
}

/// One plotted series in a [MetricChart].
///
/// The [key] must match a field name in each [MetricDatum] map. The [tone]
/// drives the line/area color through the CSS variable system.
///
/// ```dart
/// const MetricSeries(key: 'p50', label: 'p50', tone: ChartTone.up)
/// ```
@immutable
class MetricSeries {
  /// Data-map field name this series reads from each [MetricDatum].
  final String key;

  /// Human-readable legend label.
  final String label;

  /// Semantic color tone for the rendered area/line.
  ///
  /// Named `tone` to match the MetricChart TypeScript contract exactly:
  /// `MetricSeries { key, label, tone }`.
  final ChartTone tone;

  const MetricSeries({
    required this.key,
    required this.label,
    required this.tone,
  });
}

/// One x-axis data point for a [MetricChart].
///
/// Carries a `label` (the x-axis tick) plus one numeric entry per series
/// [MetricSeries.key]. A `band` field holds a `(low, high)` record when
/// an AI-learned expected range is being plotted.
///
/// ```dart
/// MetricDatum(
///   label: '00:00',
///   values: {'p50': 84, 'p95': 118, 'p99': 152},
///   band: (60, 140),
/// )
/// ```
@immutable
class MetricDatum {
  /// X-axis tick label, e.g. `"00:00"` or `"Mon"`.
  final String label;

  /// Numeric values keyed by [MetricSeries.key].
  final Map<String, num> values;

  /// AI-learned expected range `(low, high)`. `null` when no band is plotted.
  ///
  /// Named `band` (a `[low, high]` pair) to match the MetricChart contract.
  final (num, num)? band;

  const MetricDatum({required this.label, required this.values, this.band});
}

/// A point Uptizm AI flagged as outside its learned baseline.
///
/// Rendered as a dot in the `down` tone on top of the series line.
///
/// Named `MetricAnomaly` with fields `x` and `y` to match the MetricChart
/// TypeScript contract exactly: `MetricAnomaly { x, y }`.
@immutable
class MetricAnomaly {
  /// X-axis label of the flagged point; matches a [MetricDatum.label].
  final String x;

  /// Y value of the flagged point.
  final num y;

  const MetricAnomaly({required this.x, required this.y});
}

/// A monitor-level metric definition (system or custom).
///
/// Mirrors the `MonitorMetric` shape from the TypeScript design source.
@immutable
class MonitorMetric {
  /// Owning monitor identifier.
  final String monitorId;

  /// Human-readable name, e.g. `"CPU load"`.
  final String label;

  /// Machine key used in API payloads and metric routing.
  final String key;

  /// Unit token: `"%"`, `"ms"`, `"s"`, `"req_s"`, `"bytes"`, `"count"`,
  /// or `""` for dimensionless.
  final String unit;

  /// Current reading.
  final num value;

  /// Whether a higher value is worse (`high`) or a lower value is worse
  /// (`low`).
  final MetricDirection direction;

  /// Warning threshold.
  final num warn;

  /// Critical threshold.
  final num critical;

  /// Whether this metric is collected automatically (`system`) or defined by
  /// the user (`custom`).
  final MetricKind kind;

  /// Extraction rule type, e.g. `"numeric"`. Custom metrics only.
  final String? type;

  /// Data source format, e.g. `"json"` or `"xpath"`. Custom metrics only.
  final String? source;

  /// JSONPath or XPath expression. Custom metrics only.
  final String? path;

  const MonitorMetric({
    required this.monitorId,
    required this.label,
    required this.key,
    required this.unit,
    required this.value,
    required this.direction,
    required this.warn,
    required this.critical,
    required this.kind,
    this.type,
    this.source,
    this.path,
  });

  /// Builds a [MonitorMetric] from a `MonitorMetricResource` payload
  /// (backend `api/v1` snake_case keys).
  ///
  /// The optional nested `latest` object (`{value, band}`) carries the most
  /// recent extracted reading; when absent (metric never extracted yet) the
  /// current value defaults to `0`.
  ///
  /// The backend resource does not expose a `system`/`custom` discriminator
  /// field yet ([MetricKind] has no wire key in the payload above), so every
  /// decoded metric is treated as [MetricKind.custom]; system metrics (e.g.
  /// `response_time`) are still synthesized client-side by
  /// [systemMetricsForMonitors].
  factory MonitorMetric.fromMap(Map<String, dynamic> map) {
    final Object? latest = map['latest'];
    final num latestValue = latest is Map ? (latest['value'] as num?) ?? 0 : 0;
    return MonitorMetric(
      monitorId: map['monitor_id']?.toString() ?? '',
      label: (map['label'] as String?) ?? '',
      key: (map['key'] as String?) ?? '',
      unit: (map['unit'] as String?) ?? '',
      value: latestValue,
      direction: _directionFromWire(map['threshold_direction'] as String?),
      warn: (map['warn_bound'] as num?) ?? 0,
      critical: (map['critical_bound'] as num?) ?? 0,
      kind: MetricKind.custom,
      type: map['type'] as String?,
      source: map['source'] as String?,
      path: map['extraction_path'] as String?,
    );
  }
}

/// Decodes the backend `threshold_direction` wire value
/// (`"high_bad"`/`"low_bad"`) into a [MetricDirection], falling back to
/// [MetricDirection.high] on an unknown or missing value.
MetricDirection _directionFromWire(String? raw) {
  return switch (raw) {
    'high_bad' => MetricDirection.high,
    'low_bad' => MetricDirection.low,
    _ => MetricDirection.high,
  };
}

/// Whether a higher or lower metric reading constitutes a worse state.
enum MetricDirection {
  /// Higher values are more concerning (CPU, latency, error rate).
  high,

  /// Lower values are more concerning (queue headroom, throughput).
  low,
}

/// Collection method for a metric.
enum MetricKind {
  /// Collected automatically from every monitor (response time, error rate).
  system,

  /// Defined by the user pointing at a custom endpoint.
  custom,
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// User-defined custom metrics for the mock monitors.
const List<MonitorMetric> customMetrics = [
  MonitorMetric(
    monitorId: 'api',
    label: 'Memory usage',
    key: 'memory_usage',
    unit: '%',
    value: 73,
    direction: MetricDirection.high,
    warn: 80,
    critical: 95,
    kind: MetricKind.custom,
    type: 'numeric',
    source: 'json',
    path: r'$.system.memory.used_pct',
  ),
  MonitorMetric(
    monitorId: 'api',
    label: 'CPU load',
    key: 'cpu_load',
    unit: '%',
    value: 41,
    direction: MetricDirection.high,
    warn: 70,
    critical: 90,
    kind: MetricKind.custom,
    type: 'numeric',
    source: 'json',
    path: r'$.system.cpu.load_pct',
  ),
  MonitorMetric(
    monitorId: 'api',
    label: 'Request rate',
    key: 'req_rate',
    unit: 'req_s',
    value: 96,
    direction: MetricDirection.low,
    warn: 50,
    critical: 20,
    kind: MetricKind.custom,
    type: 'numeric',
    source: 'json',
    path: r'$.requests.rate',
  ),
  MonitorMetric(
    monitorId: 'marketing',
    label: 'Page load time',
    key: 'dom_load',
    unit: 'ms',
    value: 842,
    direction: MetricDirection.high,
    warn: 1500,
    critical: 3000,
    kind: MetricKind.custom,
    type: 'numeric',
    source: 'xpath',
    path: "//*[@id='load-time']",
  ),
  MonitorMetric(
    monitorId: 'checkout',
    label: 'Queue depth',
    key: 'queue_depth',
    unit: 'count',
    value: 23,
    direction: MetricDirection.high,
    warn: 100,
    critical: 250,
    kind: MetricKind.custom,
    type: 'numeric',
    source: 'json',
    path: r'$.queue.depth',
  ),
];

/// 24-hour response-time series for the API gateway (the degraded monitor).
///
/// Includes three series (p50/p95/p99) and an AI band. Scaled around the
/// monitor's 412 ms baseline with a sine-wave variation to mimic realistic
/// traffic patterns. Used by the MetricChart on the monitor detail page.
final List<MetricDatum> apiResponseSeries = List.generate(24, (i) {
  final int p50 = (412 * (0.85 + 0.3 * _sin(i / 3))).round();
  final int p95 = (p50 * 1.4).round();
  final int p99 = (p50 * 1.8).round();
  final String hour = '${i.toString().padLeft(2, '0')}:00';
  return MetricDatum(
    label: hour,
    values: {'p50': p50, 'p95': p95, 'p99': p99},
    band: (p50 - 60, p50 + 60),
  );
});

/// Series descriptors for the API gateway response-time chart.
const List<MetricSeries> apiResponseSeries_ = [
  MetricSeries(key: 'p50', label: 'p50', tone: ChartTone.up),
  MetricSeries(key: 'p95', label: 'p95', tone: ChartTone.degraded),
  MetricSeries(key: 'p99', label: 'p99', tone: ChartTone.primary),
];

/// Anomaly markers for the API gateway response-time chart.
///
/// The anomaly is pinned to 13:00 (index 13) at the p99 value for that hour,
/// matching the design mock's single-anomaly fixture.
final List<MetricAnomaly> apiResponseAnomalies = [
  MetricAnomaly(x: '13:00', y: apiResponseSeries[13].values['p99']!),
];

/// 24-hour response-time series for the marketing-site monitor.
final List<MetricDatum> marketingResponseSeries = List.generate(24, (i) {
  final int p50 = (84 * (0.85 + 0.3 * _sin(i / 3))).round();
  final int p95 = (p50 * 1.4).round();
  final int p99 = (p50 * 1.8).round();
  final String hour = '${i.toString().padLeft(2, '0')}:00';
  return MetricDatum(
    label: hour,
    values: {'p50': p50, 'p95': p95, 'p99': p99},
    band: (p50 - 20, p50 + 20),
  );
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/// Lookup helpers for consumer code.

/// All metrics (system + custom) available for the given monitor [ids].
List<MonitorMetric> metricsForMonitors(List<String> ids) {
  return [...systemMetricsForMonitors(ids), ...customMetricsForMonitors(ids)];
}

/// Default system response-time metric for each monitor that reports one.
///
/// Matches the `systemMetricsForMonitors` shape from the design source.
List<MonitorMetric> systemMetricsForMonitors(List<String> ids) {
  // Inline the monitor responseMs data to avoid a cross-file import cycle
  // between monitors.dart and metrics.dart.
  const Map<String, (String, int)> systemData = {
    'marketing': ('Marketing site response time', 84),
    'api': ('API gateway response time', 412),
  };
  return [
    for (final id in ids)
      if (systemData.containsKey(id))
        MonitorMetric(
          monitorId: id,
          label: systemData[id]!.$1,
          key: 'response_time',
          unit: 'ms',
          value: systemData[id]!.$2,
          direction: MetricDirection.high,
          warn: 500,
          critical: 1000,
          kind: MetricKind.system,
        ),
  ];
}

/// Custom metrics belonging to any of the given monitor [ids].
List<MonitorMetric> customMetricsForMonitors(List<String> ids) {
  return customMetrics.where((m) => ids.contains(m.monitorId)).toList();
}

// ---------------------------------------------------------------------------
// Private math helper
// ---------------------------------------------------------------------------

/// Sine for the fixture waveforms. Uses `dart:math` so the full input range
/// (i/3 for i in [0,23], which exceeds pi) stays accurate.
double _sin(double x) => math.sin(x);
