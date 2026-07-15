import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';

import 'package:uptizm/app/enums/metric_direction.dart' show MetricDirection;
import 'package:uptizm/app/support/metric_types.dart'
    show MetricDatum, MonitorMetric;
import 'package:uptizm/app/enums/status_key.dart';

// ---------------------------------------------------------------------------
// Option-list constants (label / value pairs for dropdowns and segmented
// controls in the metric create/edit form).
// ---------------------------------------------------------------------------

/// A label/value option used in [kMetricTypes], [kMetricSources],
/// [kMetricUnits], and [kMetricDirections].
@immutable
class MetricOption {
  /// Human-readable label shown in the UI.
  final String label;

  /// Machine value stored in [MetricForm].
  final String value;

  const MetricOption({required this.label, required this.value});
}

/// Extraction rule types. Matches the `TYPES` constant in the React source.
const List<MetricOption> kMetricTypes = [
  MetricOption(label: 'Numeric', value: 'numeric'),
  MetricOption(label: 'Status', value: 'status'),
  MetricOption(label: 'String', value: 'string'),
];

/// Data source formats. Matches the `SOURCES` constant in the React source.
const List<MetricOption> kMetricSources = [
  MetricOption(label: 'JSON path', value: 'json'),
  MetricOption(label: 'Regex', value: 'regex'),
  MetricOption(label: 'XPath (HTML/XML)', value: 'xpath'),
  MetricOption(label: 'Header', value: 'header'),
  MetricOption(label: 'HTTP status', value: 'http_status'),
];

/// Measurement units. Matches the `UNITS` constant in the React source.
const List<MetricOption> kMetricUnits = [
  MetricOption(label: 'Milliseconds (ms)', value: 'ms'),
  MetricOption(label: 'Seconds (s)', value: 's'),
  MetricOption(label: 'Percent (%)', value: '%'),
  MetricOption(label: 'Count', value: 'count'),
  MetricOption(label: 'Bytes', value: 'bytes'),
  MetricOption(label: 'Requests / sec', value: 'req_s'),
  MetricOption(label: 'Custom', value: 'custom'),
];

/// Threshold directions. Matches the `DIRECTIONS` constant in the React
/// source.
const List<MetricOption> kMetricDirections = [
  MetricOption(label: 'Higher is worse', value: 'high'),
  MetricOption(label: 'Lower is worse', value: 'low'),
];

// ---------------------------------------------------------------------------
// Path placeholder and hint maps (keyed by source value).
// ---------------------------------------------------------------------------

/// Placeholder text shown in the path input for each [kMetricSources] value.
///
/// Matches `PATH_PLACEHOLDER` in the React source.
const Map<String, String> kPathPlaceholder = {
  'json': r'$.system.memory.used_pct',
  'regex': r'/load: (\d+)ms/',
  'xpath': "//*[@id='load-time']",
  'header': 'Server-Timing',
  'http_status': '',
};

/// Helper hint shown below the path input for each [kMetricSources] value.
///
/// Matches `PATH_HINT` in the React source.
const Map<String, String> kPathHint = {
  'json': 'JSON path into the response body.',
  'regex': 'Regex with one capture group.',
  'xpath': 'XPath into an HTML/XML body (e.g. a DOM node\'s text).',
  'header': 'Response header name.',
  'http_status': 'Uses the HTTP status code; no path needed.',
};

// ---------------------------------------------------------------------------
// Sample JSON fixture used for live "fetch & test" preview.
// ---------------------------------------------------------------------------

/// Nested sample data that mirrors the `SAMPLE` constant in the React source.
///
/// Used by [resolveJson] to evaluate a JSON path without a real network
/// request.
const Map<String, Object> kMetricSample = {
  'system': {
    'memory': {'used_pct': 73.4, 'total_mb': 16384},
    'cpu': {'load_pct': 41.2},
  },
  'requests': {'rate': 96, 'p95_ms': 142},
  'queue': {'depth': 23},
};

/// Pretty-printed JSON representation of [kMetricSample].
///
/// Displayed verbatim in the "Test extraction" panel when a JSON path is
/// resolved (or fails to resolve) during the preview fetch.
final String kMetricSampleJson = const JsonEncoder.withIndent('  ').convert(kMetricSample);

// ---------------------------------------------------------------------------
// Unit-suffix and key-validation constants.
// ---------------------------------------------------------------------------

/// Abbreviated unit suffixes appended to formatted values. Empty string means
/// no suffix is appended. Matches `UNIT_SUFFIX` in the React source.
const Map<String, String> kUnitSuffix = {
  'ms': 'ms',
  's': 's',
  '%': '%',
  'count': '',
  'bytes': 'B',
  'req_s': '/s',
  'custom': '',
};

/// Validation pattern for metric keys: lowercase letter start, then lowercase
/// letters, digits, or underscores only. Matches `KEY_RE` in the React source.
final RegExp kKeyRe = RegExp(r'^[a-z][a-z0-9_]*$');

// ---------------------------------------------------------------------------
// Empty-form sentinel.
// ---------------------------------------------------------------------------

/// Blank [MetricForm] used as the initial state when opening the create sheet.
///
/// Matches `EMPTY_FORM` in the React source.
const MetricForm kEmptyMetricForm = MetricForm(
  label: '',
  key: '',
  type: 'numeric',
  source: 'json',
  path: '',
  unit: '%',
  direction: 'high',
  warn: '',
  critical: '',
);

// ---------------------------------------------------------------------------
// MetricForm — string-backed edit model.
// ---------------------------------------------------------------------------

/// String-backed edit model for the metric create/edit form.
///
/// All threshold fields ([warn], [critical]) are kept as raw [String]s so the
/// form can hold partially-typed numeric input without lossy round-trips.
/// [value] is the catalog baseline carried from [MonitorMetric.value] and read
/// by [baseFor]; it is never edited directly by the user.
///
/// Create with [kEmptyMetricForm] or [fromCatalog]. Mutate via [copyWith].
///
/// ```dart
/// final form = fromCatalog(myMetric).copyWith(warn: '85');
/// ```
@immutable
class MetricForm {
  /// Human-readable name, e.g. `"Memory usage"`.
  final String label;

  /// Machine key used in API payloads and alert rules, e.g. `"memory_usage"`.
  final String key;

  /// Extraction rule type string, e.g. `"numeric"`.
  final String type;

  /// Data source format string, e.g. `"json"`.
  final String source;

  /// JSONPath, XPath, regex, or header name. Empty for `http_status`.
  final String path;

  /// Unit string, e.g. `"%"` or `"ms"`.
  final String unit;

  /// Threshold direction: `"high"` (higher is worse) or `"low"` (lower is
  /// worse).
  final String direction;

  /// Raw warning threshold input, e.g. `"80"`. Empty when not yet entered.
  final String warn;

  /// Raw critical threshold input, e.g. `"95"`. Empty when not yet entered.
  final String critical;

  /// Catalog baseline value carried from [MonitorMetric.value].
  ///
  /// Non-null when the form was seeded via [fromCatalog]. Used by [baseFor]
  /// as the first-priority baseline before falling back to [resolveJson] or
  /// [fallbackValue].
  final num? value;

  const MetricForm({
    required this.label,
    required this.key,
    required this.type,
    required this.source,
    required this.path,
    required this.unit,
    required this.direction,
    required this.warn,
    required this.critical,
    this.value,
  });

  /// Returns a copy of this form with the given fields replaced.
  MetricForm copyWith({
    String? label,
    String? key,
    String? type,
    String? source,
    String? path,
    String? unit,
    String? direction,
    String? warn,
    String? critical,
    num? value,
  }) {
    return MetricForm(
      label: label ?? this.label,
      key: key ?? this.key,
      type: type ?? this.type,
      source: source ?? this.source,
      path: path ?? this.path,
      unit: unit ?? this.unit,
      direction: direction ?? this.direction,
      warn: warn ?? this.warn,
      critical: critical ?? this.critical,
      value: value ?? this.value,
    );
  }
}

// ---------------------------------------------------------------------------
// fromCatalog — seed a MetricForm from a MonitorMetric.
// ---------------------------------------------------------------------------

/// Seeds a [MetricForm] from a catalog [MonitorMetric].
///
/// Maps [m.direction] to its string name so the form stores uniform string
/// values. Numeric [m.warn] and [m.critical] are stringified so the form
/// fields can display them as-is without a round-trip.
///
/// ```dart
/// final form = fromCatalog(customMetrics.first);
/// ```
MetricForm fromCatalog(MonitorMetric m) {
  return MetricForm(
    label: m.label,
    key: m.key,
    type: m.type ?? 'numeric',
    source: m.source ?? 'json',
    path: m.path ?? '',
    unit: m.unit,
    direction: m.direction == MetricDirection.low ? 'low' : 'high',
    warn: m.warn.toString(),
    critical: m.critical.toString(),
    value: m.value,
  );
}

// ---------------------------------------------------------------------------
// Pure helpers.
// ---------------------------------------------------------------------------

/// Converts a human label into a valid metric key.
///
/// Steps:
/// 1. Lowercase the entire string.
/// 2. Replace runs of non-alphanumeric characters with a single underscore.
/// 3. Strip leading non-letter characters so the result starts with [a-z].
/// 4. Strip trailing underscores.
/// 5. Truncate to 40 characters.
///
/// ```dart
/// slugify('Memory Usage') // 'memory_usage'
/// slugify('  CPU %  ')    // 'cpu'
/// ```
String slugify(String v) {
  final String s = v
      .toLowerCase()
      .replaceAll(RegExp(r'[^a-z0-9]+'), '_')
      .replaceAll(RegExp(r'^[^a-z]+'), '')
      .replaceAll(RegExp(r'_+$'), '');
  // Bound on the transformed string's own length: collapsing/trimming can make
  // `s` shorter than `v`, so `math.min(v.length, 40)` could exceed `s.length`
  // and throw a RangeError on inputs like '  CPU %  ' (v.length 9, s 'cpu').
  return s.substring(0, math.min(s.length, 40));
}

/// Walks a `$.a.b.c` JSON path against [kMetricSample] and returns the
/// resolved [num], or `null` when the path does not exist or resolves to a
/// non-numeric value.
///
/// The leading `$.` prefix (and a bare `$`) is stripped before splitting on
/// `.`. Empty segments are ignored.
///
/// ```dart
/// resolveJson(r'$.system.memory.used_pct') // 73.4
/// resolveJson(r'$.does.not.exist')         // null
/// ```
num? resolveJson(String path) {
  // 1. Strip the `$` or `$.` prefix and split into path segments.
  final String stripped = path.replaceFirst(RegExp(r'^\$\.?'), '');
  final List<String> parts = stripped.split('.').where((s) => s.isNotEmpty).toList();

  // 2. Walk the sample map one segment at a time.
  Object? cur = kMetricSample;
  for (final String part in parts) {
    if (cur is Map && cur.containsKey(part)) {
      cur = cur[part] as Object?;
    } else {
      return null;
    }
  }

  // 3. Return the value only when it is numeric.
  return cur is num ? cur : null;
}

/// Returns a reasonable demo value for the given [unit] when neither
/// [MetricForm.value] nor [resolveJson] yields a result.
///
/// Matches the fallback table in the React source.
num fallbackValue(String unit) {
  return const <String, num>{
    'ms': 142,
    's': 1.4,
    '%': 73.4,
    'count': 23,
    'bytes': 2048,
    'req_s': 96,
  }[unit] ??
      142;
}

/// Formats [value] with the appropriate suffix for [unit].
///
/// Returns `"<value> <suffix>"` when the unit has a non-empty suffix, or
/// `"<value>"` for dimensionless units such as `count` and `custom`.
///
/// ```dart
/// fmt(73.4, '%')    // '73.4 %'
/// fmt(23,   'count') // '23'
/// ```
String fmt(num value, String unit) {
  // Drop a trailing `.0` on integral values so a chart-derived double like 73.0
  // prints "73", matching the React `${value}` (JS numbers have no distinct
  // double type, so 73.0 renders as "73"). Non-integral values keep their
  // decimals (73.4 -> "73.4").
  final String number = value == value.roundToDouble()
      ? value.toStringAsFixed(0)
      : '$value';
  final String suffix = kUnitSuffix[unit] ?? '';
  return suffix.isNotEmpty ? '$number $suffix' : number;
}

/// Classifies [value] against the parsed [warn] and [critical] thresholds,
/// returning a [StatusKey] suitable for driving a `StatusDot`.
///
/// When [direction] is `"low"`, a value at or below a threshold is "worse"
/// (e.g. throughput dropping). When [direction] is `"high"`, a value at or
/// above a threshold is "worse" (e.g. CPU rising).
///
/// Thresholds that cannot be parsed as numbers are ignored (the band defaults
/// to [StatusKey.up]).
///
/// Returns [StatusKey.down] when the critical threshold is breached, then
/// [StatusKey.degraded] when the warning threshold is breached, and
/// [StatusKey.up] otherwise.
StatusKey bandOf(
  num value,
  String warn,
  String critical,
  String direction,
) {
  final num? w = num.tryParse(warn);
  final num? c = num.tryParse(critical);

  bool worse(num threshold) =>
      direction == 'low' ? value <= threshold : value >= threshold;

  if (c != null && worse(c)) return StatusKey.down;
  if (w != null && worse(w)) return StatusKey.degraded;
  return StatusKey.up;
}

/// Resolves the chart baseline for [form].
///
/// Priority order (mirrors React lines 166-169):
/// 1. [MetricForm.value] when non-null (catalog reading).
/// 2. [resolveJson] against [kMetricSample] for JSON-sourced metrics.
/// 3. [fallbackValue] keyed on [MetricForm.unit].
num baseFor(MetricForm form) {
  if (form.value != null) return form.value!;
  return resolveJson(form.path) ?? fallbackValue(form.unit);
}

/// Generates exactly 24 deterministic data points for [form]'s metric.
///
/// Each point is a [MetricDatum] with a `"HH:00"` tick label and a single
/// `"value"` series entry. The value is a sine-wave ±18% around [baseFor],
/// rounded to one decimal place. Matches the `chartData` helper at React
/// lines 170-176.
///
/// No band or anomaly is included here; those are computed in the detail
/// view (Step 4).
List<MetricDatum> chartData(MetricForm form) {
  final num base = baseFor(form);
  return List.generate(24, (int i) {
    final String label = '${i.toString().padLeft(2, '0')}:00';
    final num raw = base + math.sin(i / 3) * base * 0.18;
    final num v = (raw * 10).round() / 10;
    return MetricDatum(
      label: label,
      values: {'value': v},
    );
  });
}

/// Returns the latest value from [chartData] for [form].
///
/// Equivalent to reading the last point of the 24-hour series.
num latestOf(MetricForm form) {
  final List<MetricDatum> data = chartData(form);
  return data.last.values['value']!;
}
