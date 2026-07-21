import 'package:magic/magic.dart';

import 'package:uptizm/app/support/monitor_types.dart' show ProbeRegion;
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart';
import 'package:uptizm/ui/components/region_picker/region_picker.dart';

// ---------------------------------------------------------------------------
// Option-list constants (label / value pairs for dropdowns and segmented
// controls in the monitor create/edit form).
//
// Values match the TYPES / METHODS / INTERVALS / INTERVAL_SECONDS /
// SLO_TARGETS constants in the React MonitorForm.tsx source.
// ---------------------------------------------------------------------------

/// Monitor check types.
///
/// Used to populate the type segmented control on the monitor form. Scoped to
/// the two protocols the backend + regional checker support end to end: HTTP
/// (request at the URL) and TCP (socket connect to host:port). Ping / keyword /
/// SSL are on the product roadmap (see docs/uptizm-system/product.md) but are
/// NOT wired through the `MonitorType` enum or the worker, so offering them here
/// only produced a silent 422 on submit. Add a type back only once its whole
/// path (enum, request validation, worker probe) exists.
const List<MetricOption> kMonitorTypes = [
  MetricOption(label: 'HTTP', value: 'http'),
  MetricOption(label: 'TCP', value: 'tcp'),
];

/// HTTP request methods for the advanced section. Matches `METHODS` in
/// MonitorForm.tsx.
const List<MetricOption> kHttpMethods = [
  MetricOption(label: 'GET', value: 'get'),
  MetricOption(label: 'POST', value: 'post'),
  MetricOption(label: 'HEAD', value: 'head'),
];

/// Check interval options shown in the interval select. Matches `INTERVALS` in
/// MonitorForm.tsx.
///
/// A getter (not a `const`) so each display label resolves through [trans] at
/// the current locale; the [MetricOption.value] wire tokens stay fixed.
List<MetricOption> get kCheckIntervals => [
  MetricOption(label: trans('uptizm.monitors.interval_10s'), value: '10s'),
  MetricOption(label: trans('uptizm.monitors.interval_30s'), value: '30s'),
  MetricOption(label: trans('uptizm.monitors.interval_1m'), value: '1m'),
  MetricOption(label: trans('uptizm.monitors.interval_5m'), value: '5m'),
];

/// Maps each interval token to its equivalent duration in seconds. Matches
/// `INTERVAL_SECONDS` in MonitorForm.tsx.
///
/// ```dart
/// final seconds = kIntervalSeconds['1m']; // 60
/// ```
const Map<String, int> kIntervalSeconds = {
  '10s': 10,
  '30s': 30,
  '1m': 60,
  '5m': 300,
};

/// SLO target options for the uptime SLO select. Matches `SLO_TARGETS` in
/// MonitorForm.tsx. An empty [MetricOption.value] means no SLO target is set.
List<MetricOption> get kSloTargets => [
  MetricOption(label: trans('uptizm.monitors.slo_target_none'), value: ''),
  MetricOption(label: trans('uptizm.monitors.slo_target_999'), value: '99.9'),
  MetricOption(label: trans('uptizm.monitors.slo_target_9995'), value: '99.95'),
  MetricOption(label: trans('uptizm.monitors.slo_target_9999'), value: '99.99'),
];

/// AI-assist mode options for the monitor form's `ai_mode` control.
///
/// Only `off`/`suggest` ship; `auto` (fully autonomous incident creation) is
/// deferred, so it is deliberately absent from this list.
List<MetricOption> get kAiModes => [
  MetricOption(label: trans('uptizm.monitors.ai_mode_off'), value: 'off'),
  MetricOption(label: trans('uptizm.monitors.ai_mode_suggest'), value: 'suggest'),
];

// ---------------------------------------------------------------------------
// AI analyze-step strings and suggested metric seeds.
// Matches ANALYZE_STEPS and aiMetrics in MonitorCreatePage.tsx.
// ---------------------------------------------------------------------------

/// Ordered list of status strings shown while the AI probes the endpoint.
///
/// A getter (not a `const`) so each step resolves through [trans] at the
/// current locale. Matches `ANALYZE_STEPS` in MonitorCreatePage.tsx.
List<String> get kAnalyzeSteps => [
  trans('uptizm.monitors.create_ai_step_1'),
  trans('uptizm.monitors.create_ai_step_2'),
  trans('uptizm.monitors.create_ai_step_3'),
  trans('uptizm.monitors.create_ai_step_4'),
  trans('uptizm.monitors.create_ai_step_5'),
];

/// A single AI-suggested metric seed carrying label, key, unit, source, path,
/// and raw warn/critical threshold strings.
///
/// Mirrors the inline `aiMetrics` array shape from MonitorCreatePage.tsx.
/// Threshold strings are kept raw so the metric form can display them without
/// a lossy parse/round-trip.
class AiMetricSeed {
  /// Human-readable label, e.g. `"p95 latency"`.
  final String label;

  /// Machine key used in API payloads, e.g. `"p95_ms"`.
  final String key;

  /// Measurement unit string, e.g. `"ms"`.
  final String unit;

  /// Data source format, e.g. `"json"`.
  final String source;

  /// JSONPath or equivalent extraction path.
  final String path;

  /// Raw warning threshold string; empty when not applicable.
  final String warn;

  /// Raw critical threshold string; empty when not applicable.
  final String critical;

  /// Creates an [AiMetricSeed].
  const AiMetricSeed({
    required this.label,
    required this.key,
    required this.unit,
    required this.source,
    required this.path,
    required this.warn,
    required this.critical,
  });
}

/// The three AI-suggested metrics pre-filled in the review step. Matches the
/// `aiMetrics` array in MonitorCreatePage.tsx.
List<AiMetricSeed> get kAiMetrics => [
  AiMetricSeed(
    label: trans('uptizm.monitors.ai_metric_p95_latency'),
    key: 'p95_ms',
    unit: 'ms',
    source: 'json',
    path: r'$.latency.p95',
    warn: '300',
    critical: '1000',
  ),
  AiMetricSeed(
    label: trans('uptizm.monitors.ai_metric_error_rate'),
    key: 'error_rate',
    unit: '%',
    source: 'json',
    path: r'$.errors.rate',
    warn: '1',
    critical: '5',
  ),
  AiMetricSeed(
    label: trans('uptizm.monitors.ai_metric_active_connections'),
    key: 'active_conns',
    unit: 'count',
    source: 'json',
    path: r'$.pool.active',
    warn: '',
    critical: '',
  ),
];

// ---------------------------------------------------------------------------
// Pure helper functions.
// ---------------------------------------------------------------------------

/// Derives a display name from [url] by stripping the scheme and any path,
/// leaving only the bare hostname.
///
/// Returns `"New monitor"` when [url] is blank or yields an empty host after
/// stripping.
///
/// ```dart
/// aiNameFromUrl('https://api.example.com/health') // 'api.example.com'
/// aiNameFromUrl('')                                // 'New monitor'
/// aiNameFromUrl('not-a-url')                       // 'not-a-url'
/// ```
///
/// Matches the `aiName` derivation in MonitorCreatePage.tsx:
/// ```
/// url.replace(/^https?:\/\//, "").replace(/\/.*$/, "")
/// ```
String aiNameFromUrl(String url) {
  if (url.isEmpty) return trans('uptizm.monitors.new_monitor');

  // 1. Strip leading scheme (http:// or https://).
  final String withoutScheme = url.replaceFirst(RegExp(r'^https?://'), '');

  // 2. Strip path (everything from the first `/` onward).
  final String host = withoutScheme.replaceFirst(RegExp(r'/.*$'), '');

  return host.isEmpty ? trans('uptizm.monitors.new_monitor') : host;
}

/// Maps [src] (a list of [ProbeRegion] mocks) to [Region] instances expected
/// by [RegionPicker].
///
/// The field mapping is direct: [ProbeRegion.label] -> [Region.label],
/// [ProbeRegion.value] -> [Region.value], [ProbeRegion.flag] -> [Region.flag].
///
/// ```dart
/// final regions = probeRegionsToRegions(allRegions);
/// RegionPicker(regions: regions, value: selected, onChanged: _onChanged);
/// ```
List<Region> probeRegionsToRegions(List<ProbeRegion> src) {
  return [
    for (final ProbeRegion r in src)
      Region(
        label: r.label,
        value: r.value,
        flag: r.flag,
      ),
  ];
}
