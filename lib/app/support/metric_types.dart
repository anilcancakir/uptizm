import 'package:flutter/foundation.dart';

import '../enums/chart_tone.dart' show ChartTone;
import '../enums/metric_direction.dart' show MetricDirection;
import '../enums/metric_kind.dart' show MetricKind;

/// One plotted series in a `MetricChart`.
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

/// One x-axis data point for a `MetricChart`.
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
  /// `systemMetricsForMonitors`.
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
