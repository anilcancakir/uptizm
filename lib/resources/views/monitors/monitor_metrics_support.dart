import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';

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
///
/// A getter (not a `const`) so each display label resolves through [trans] at
/// the current locale; the [MetricOption.value] wire tokens stay fixed.
List<MetricOption> get kMetricTypes => [
  MetricOption(label: trans('uptizm.monitors.metrics_type_numeric'), value: 'numeric'),
  MetricOption(label: trans('uptizm.monitors.metrics_type_status'), value: 'status'),
  MetricOption(label: trans('uptizm.monitors.metrics_type_string'), value: 'string'),
];

/// Data source formats. Matches the `SOURCES` constant in the React source.
List<MetricOption> get kMetricSources => [
  MetricOption(label: trans('uptizm.monitors.metrics_source_json'), value: 'json'),
  MetricOption(label: trans('uptizm.monitors.metrics_source_regex'), value: 'regex'),
  MetricOption(label: trans('uptizm.monitors.metrics_source_xpath'), value: 'xpath'),
  MetricOption(label: trans('uptizm.monitors.metrics_source_header'), value: 'header'),
  MetricOption(label: trans('uptizm.monitors.metrics_source_http_status'), value: 'http_status'),
];

/// Measurement units, one option per `MetricUnit` backend case
/// (`backend/app/Enums/MetricUnit.php:21-38`), grouped size/duration/percent/
/// count/custom to match the enum's own case ordering.
///
/// The four original tokens (`ms`, `s`, `%`, `bytes`, plus `count` and
/// `custom`) keep their short names; the other ten were added so every
/// `MetricUnit` value has a form-side pairing in the private `_unitToWire` map
/// in `monitor_metrics_controller.dart` (not linkable from here: it is private
/// to that library) and none of them collapse to `custom` on decode.
List<MetricOption> get kMetricUnits => [
  MetricOption(label: trans('uptizm.monitors.metrics_unit_bytes_auto'), value: 'bytes_auto'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_bytes'), value: 'bytes'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_kilobyte'), value: 'kb'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_megabyte'), value: 'mb'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_gigabyte'), value: 'gb'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_terabyte'), value: 'tb'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_duration_auto'), value: 'duration_auto'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_ms'), value: 'ms'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_s'), value: 's'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_minute'), value: 'min'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_hour'), value: 'h'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_percent'), value: '%'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_ratio'), value: 'ratio'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_count'), value: 'count'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_count_short'), value: 'count_short'),
  MetricOption(label: trans('uptizm.monitors.metrics_unit_custom'), value: 'custom'),
];

/// Threshold directions. Matches the `DIRECTIONS` constant in the React
/// source.
List<MetricOption> get kMetricDirections => [
  MetricOption(label: trans('uptizm.monitors.metrics_direction_high'), value: 'high'),
  MetricOption(label: trans('uptizm.monitors.metrics_direction_low'), value: 'low'),
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
/// A getter (not a `const`) so each hint resolves through [trans] at the
/// current locale. Matches `PATH_HINT` in the React source.
Map<String, String> get kPathHint => {
  'json': trans('uptizm.monitors.metrics_source_hint_json'),
  'regex': trans('uptizm.monitors.metrics_source_hint_regex'),
  'xpath': trans('uptizm.monitors.metrics_source_hint_xpath'),
  'header': trans('uptizm.monitors.metrics_source_hint_header'),
  'http_status': trans('uptizm.monitors.metrics_source_hint_http_status'),
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
  'custom': '',
  // Added for full `MetricUnit` coverage (Step 5). `bytes_auto` and
  // `duration_auto` are intentionally absent: `fmt()` scales those two
  // dynamically and picks the suffix per value instead of a fixed one.
  'kb': 'KB',
  'mb': 'MB',
  'gb': 'GB',
  'tb': 'TB',
  'min': 'min',
  'h': 'h',
  // `MetricUnit::defaultSuffix()` pairs Ratio with the same '%' suffix as
  // Percent (`backend/app/Enums/MetricUnit.php:73`); mirrored here rather
  // than guessed at.
  'ratio': '%',
  'count_short': '',
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
  okValues: [],
  warnValues: [],
  criticalValues: [],
  unmatchedBand: '',
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

  /// Values that band a `string`-typed metric as `ok` when the extracted
  /// value matches one, after normalization on both sides.
  ///
  /// Unlike [warn] and [critical], these are real lists rather than a raw
  /// string: there is no partially-typed intermediate state for a chip list
  /// the way there is for a number being typed digit by digit.
  final List<String> okValues;

  /// Values that band a `string`-typed metric as `warn`. See [okValues].
  final List<String> warnValues;

  /// Values that band a `string`-typed metric as `critical`. See [okValues].
  final List<String> criticalValues;

  /// The band (`"ok"` / `"warn"` / `"critical"`) applied to a `string`-typed
  /// metric's value when it matches none of [okValues], [warnValues], or
  /// [criticalValues]. Empty when unset, meaning an unmatched value alerts
  /// nothing.
  final String unmatchedBand;

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
    // Defaulted rather than required: a numeric or status metric never
    // populates these, and defaulting here keeps every existing
    // `MetricForm(...)` call site (fixtures, other tests) compiling instead
    // of forcing an unrelated edit at each one.
    this.okValues = const [],
    this.warnValues = const [],
    this.criticalValues = const [],
    this.unmatchedBand = '',
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
    List<String>? okValues,
    List<String>? warnValues,
    List<String>? criticalValues,
    String? unmatchedBand,
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
      okValues: okValues ?? this.okValues,
      warnValues: warnValues ?? this.warnValues,
      criticalValues: criticalValues ?? this.criticalValues,
      unmatchedBand: unmatchedBand ?? this.unmatchedBand,
      value: value ?? this.value,
    );
  }

  /// The form with [source] applied, and the unit corrected when the new source
  /// cannot carry the old one.
  ///
  /// The blank form defaults to `%`, which is right for the JSON-path example it
  /// is shaped around (a memory-used percentage) and wrong the moment the source
  /// becomes the HTTP status code: a status code has no percentage in it, and the
  /// unit is only ever rendered, never validated, so nothing downstream objected.
  /// Measured live: a metric created as HTTP status with the defaults untouched
  /// rendered its reading as "200 %".
  ///
  /// Only the impossible pairing is corrected, and only in that direction. A unit
  /// the operator chose for a source that can carry it survives, and moving AWAY
  /// from `http_status` leaves the unit alone, because by then it is a deliberate
  /// choice rather than an unexamined default.
  MetricForm withSource(String source) {
    if (source != 'http_status' || _countishUnits.contains(unit)) {
      return copyWith(source: source);
    }

    return copyWith(source: source, unit: 'count');
  }

  /// Units a plain number like an HTTP status code can honestly wear.
  static const Set<String> _countishUnits = {'count', 'count_short', ''};
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
/// [MetricForm.okValues]/[MetricForm.warnValues]/[MetricForm.criticalValues]/
/// [MetricForm.unmatchedBand] seed empty here: [MonitorMetric] carries no
/// string-band data (it serves only the mocks and the client-synthesized
/// `response_time` system metric), so a form seeded from the catalog starts
/// with no string-band configuration regardless of the source metric's type.
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
    okValues: const [],
    warnValues: const [],
    criticalValues: const [],
    unmatchedBand: '',
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

/// Scale steps for the `bytes_auto` [MetricOption], each threshold the byte
/// count at which the unit above it becomes the more readable choice.
///
/// The 1024-boundary IEC steps mirror the backend's fixed `Byte`/`Kilobyte`/
/// `Megabyte`/`Gigabyte`/`Terabyte` cases and their suffixes
/// (`backend/app/Enums/MetricUnit.php:65-69`), so `bytes_auto` never invents a
/// suffix the fixed variants do not already use.
const List<MapEntry<double, String>> _byteAutoSteps = [
  MapEntry(1, 'B'),
  MapEntry(1024, 'KB'),
  MapEntry(1024 * 1024, 'MB'),
  MapEntry(1024 * 1024 * 1024, 'GB'),
  MapEntry(1024 * 1024 * 1024 * 1024, 'TB'),
];

/// Scale steps for the `duration_auto` [MetricOption], assuming the raw
/// sample is in milliseconds (the same base [MetricUnit.Millisecond] uses):
/// ms -> s at x1000, s -> min at x60, min -> h at x60. Mirrors the backend's
/// fixed `Millisecond`/`Second`/`Minute`/`Hour` suffixes.
const List<MapEntry<double, String>> _durationAutoSteps = [
  MapEntry(1, 'ms'),
  MapEntry(1000, 's'),
  MapEntry(60000, 'min'),
  MapEntry(3600000, 'h'),
];

/// Drops a trailing `.0` on an integral value so a chart-derived double like
/// 73.0 prints "73", matching the React `${value}` (JS numbers have no
/// distinct double type, so 73.0 renders as "73"). Non-integral values keep
/// their decimals (73.4 -> "73.4").
String _formatNumber(num value) {
  return value == value.roundToDouble() ? value.toStringAsFixed(0) : '$value';
}

/// Scales [value] against [steps] (ascending thresholds, each paired with the
/// suffix it should render at) and picks the highest step [value] still
/// clears, rounding the scaled result to one decimal place so a clean
/// division like `1536 / 1024` renders as `1.5` rather than a long or
/// repeating decimal.
String _fmtAutoScale(num value, List<MapEntry<double, String>> steps) {
  MapEntry<double, String> chosen = steps.first;
  for (final MapEntry<double, String> step in steps) {
    if (value.abs() >= step.key) chosen = step;
  }
  final double scaled = ((value / chosen.key) * 10).round() / 10;
  return '${_formatNumber(scaled)} ${chosen.value}';
}

/// Formats [value] with the appropriate suffix for [unit].
///
/// Returns `"<value> <suffix>"` when the unit has a non-empty suffix, or
/// `"<value>"` for dimensionless units such as `count` and `custom`. The two
/// `*_auto` units (`bytes_auto`, `duration_auto`) scale [value] up through
/// [_byteAutoSteps]/[_durationAutoSteps] first and pick their suffix from the
/// chosen step rather than a fixed [kUnitSuffix] entry; every other unit
/// renders at its own fixed magnitude.
///
/// ```dart
/// fmt(73.4, '%')             // '73.4 %'
/// fmt(23,   'count')         // '23'
/// fmt(1536, 'bytes_auto')    // '1.5 KB'
/// fmt(90000, 'duration_auto') // '1.5 min'
/// ```
String fmt(num value, String unit) {
  if (unit == 'bytes_auto') return _fmtAutoScale(value, _byteAutoSteps);
  if (unit == 'duration_auto') return _fmtAutoScale(value, _durationAutoSteps);

  final String number = _formatNumber(value);
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

/// Dart mirror of `ThresholdEvaluator::normalizeMatchValue()`
/// (`backend/app/Services/Monitoring/ThresholdEvaluator.php`): lowercases
/// [value] and strips leading and trailing Unicode whitespace, including
/// U+00A0 (non-breaking space), which a plain [String.trim] does not strip.
///
/// Exists solely so Step 13's client-side overlap check (no value may appear
/// in two of the three string-band lists) compares the same way the server
/// will when it re-validates the same fields; the band itself is never
/// computed here (the server always supplies it, via `latestBand` on read
/// and `preview()`'s `band` on a draft).
///
/// ```dart
/// normalizeMatchValue('  OK\t\n')          // 'ok'
/// normalizeMatchValue('\u{00A0}OK\u{00A0}') // 'ok'
/// ```
String normalizeMatchValue(String value) {
  // `String.trim()` is enough here, measured: Dart's whitespace set already
  // includes U+00A0, so both `trim()` and a `\s`-based regex strip it. The PHP
  // side has to name `\x{00A0}` explicitly only because PCRE's `\s` stays
  // ASCII-only under `/u` and PHP's own `trim()` is byte-wise; neither
  // limitation exists on this side, so the simpler call is the honest one.
  return value.trim().toLowerCase();
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

// ---------------------------------------------------------------------------
// Reading staleness
// ---------------------------------------------------------------------------

/// How many check intervals a reading may fall behind before it counts as
/// stale.
///
/// Two, not one. A single missed tick is ordinary: the queue hiccups, a probe
/// runs a few seconds late, and flagging that would put a warning on healthy
/// metrics constantly. Two consecutive misses is the rule having stopped
/// producing, which is the thing worth saying out loud.
const int kStaleReadingIntervals = 2;

/// Whether a reading recorded at [recordedAt] is too old to still be presented
/// as the metric's current state, for a monitor checking every
/// [checkIntervalSec] seconds.
///
/// ## Why this exists
///
/// A metric that goes silent used to read as healthy. When a monitored deploy
/// renames the key a rule extracts, no new value is recorded, and the tab kept
/// showing the last good reading with its last good band indefinitely: "94ms,
/// green" for something nobody had measured in a week. There is no wrong VALUE
/// on screen in that state, which is what makes it so quiet, and the honest
/// bound is the monitor's own interval, because a monitor that checks every 30s
/// and has said nothing for ten minutes is not reporting.
///
/// A null [recordedAt] is NOT stale: the metric has never recorded anything, a
/// different state the tab already renders as an em-dash. Saying "stale" there
/// would claim a reading stopped arriving when none ever did.
///
/// A non-positive [checkIntervalSec] answers false rather than guessing a
/// window, so a monitor whose interval is missing never gets a fabricated
/// warning.
bool isReadingStale(
  DateTime? recordedAt, {
  required int checkIntervalSec,
  DateTime? now,
}) {
  if (recordedAt == null || checkIntervalSec <= 0) return false;

  // `difference` compares absolute instants, so a UTC wire timestamp and a local
  // `now` need no conversion between them.
  final Duration age = (now ?? DateTime.now()).difference(recordedAt);

  return age > Duration(seconds: checkIntervalSec * kStaleReadingIntervals);
}
