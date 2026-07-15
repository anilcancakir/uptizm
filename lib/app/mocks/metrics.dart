import 'dart:math' as math;

import '../enums/chart_tone.dart' show ChartTone;
import '../enums/metric_direction.dart' show MetricDirection;
import '../enums/metric_kind.dart' show MetricKind;
import '../support/metric_types.dart'
    show MetricAnomaly, MetricDatum, MetricSeries, MonitorMetric;

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
