import 'dart:ui' show Locale;

import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/metric_direction.dart' show MetricDirection;
import 'package:uptizm/app/support/metric_types.dart'
    show MetricDatum, MonitorMetric;
import 'package:uptizm/app/mocks/metrics.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart';

/// Language loader carrying every `metrics_unit_*` key (Step 2), so
/// [kMetricUnits]'s labels resolve to real strings rather than leaking the raw
/// `uptizm.monitors.metrics_unit_*` key into the label under test.
class _UnitLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.monitors.metrics_unit_bytes_auto': 'Bytes (auto-scale)',
      'uptizm.monitors.metrics_unit_bytes': 'Bytes',
      'uptizm.monitors.metrics_unit_kilobyte': 'Kilobytes (KB)',
      'uptizm.monitors.metrics_unit_megabyte': 'Megabytes (MB)',
      'uptizm.monitors.metrics_unit_gigabyte': 'Gigabytes (GB)',
      'uptizm.monitors.metrics_unit_terabyte': 'Terabytes (TB)',
      'uptizm.monitors.metrics_unit_duration_auto': 'Duration (auto-scale)',
      'uptizm.monitors.metrics_unit_ms': 'Milliseconds (ms)',
      'uptizm.monitors.metrics_unit_s': 'Seconds (s)',
      'uptizm.monitors.metrics_unit_minute': 'Minutes',
      'uptizm.monitors.metrics_unit_hour': 'Hours',
      'uptizm.monitors.metrics_unit_percent': 'Percent (%)',
      'uptizm.monitors.metrics_unit_ratio': 'Ratio',
      'uptizm.monitors.metrics_unit_count': 'Count',
      'uptizm.monitors.metrics_unit_count_short': 'Count (compact)',
      'uptizm.monitors.metrics_unit_custom': 'Custom',
    };
  }
}

void main() {
  // ---------------------------------------------------------------------------
  // slugify
  // ---------------------------------------------------------------------------

  group('slugify', () {
    test('converts spaces to underscores', () {
      expect(slugify('Memory Usage'), equals('memory_usage'));
    });

    test('strips leading and trailing non-letter characters', () {
      expect(slugify('  CPU %  '), equals('cpu'));
    });

    test('replaces runs of non-alphanumeric chars with a single underscore', () {
      expect(slugify('API!!! gateway'), equals('api_gateway'));
    });

    test('does not throw RangeError on a long label (truncates to <=40 chars)', () {
      final String long = 'A very long metric label name that definitely exceeds forty characters in total';
      final String result = slugify(long);
      expect(result.length, lessThanOrEqualTo(40));
    });

    test('returns empty string for all-special-character input', () {
      expect(slugify('!!!'), equals(''));
    });
  });

  // ---------------------------------------------------------------------------
  // resolveJson
  // ---------------------------------------------------------------------------

  group('resolveJson', () {
    test('resolves \$.system.memory.used_pct to 73.4', () {
      expect(resolveJson(r'$.system.memory.used_pct'), equals(73.4));
    });

    test('resolves \$.requests.rate to 96', () {
      expect(resolveJson(r'$.requests.rate'), equals(96));
    });

    test('returns null for a missing path', () {
      expect(resolveJson(r'$.does.not.exist'), isNull);
    });

    test('returns null when the terminal value is non-numeric', () {
      // kMetricSample has no string values; confirm a nested map also returns null.
      expect(resolveJson(r'$.system'), isNull);
    });

    test('resolves a nested numeric via bare \$ prefix', () {
      // The leading '$.' is stripped; a bare '$' path is also valid.
      expect(resolveJson(r'$.system.cpu.load_pct'), equals(41.2));
    });
  });

  // ---------------------------------------------------------------------------
  // fallbackValue
  // ---------------------------------------------------------------------------

  group('fallbackValue', () {
    test('returns 142 for ms', () {
      expect(fallbackValue('ms'), equals(142));
    });

    test('returns 1.4 for s', () {
      expect(fallbackValue('s'), equals(1.4));
    });

    test('returns 73.4 for %', () {
      expect(fallbackValue('%'), equals(73.4));
    });

    test('returns 23 for count', () {
      expect(fallbackValue('count'), equals(23));
    });

    test('returns 2048 for bytes', () {
      expect(fallbackValue('bytes'), equals(2048));
    });

    test('returns 96 for req_s', () {
      expect(fallbackValue('req_s'), equals(96));
    });

    test('returns 142 for an unknown unit (default)', () {
      expect(fallbackValue('custom'), equals(142));
    });
  });

  // ---------------------------------------------------------------------------
  // fmt
  // ---------------------------------------------------------------------------

  group('fmt', () {
    test('appends % suffix', () {
      expect(fmt(73.4, '%'), equals('73.4 %'));
    });

    test('appends ms suffix', () {
      expect(fmt(142, 'ms'), equals('142 ms'));
    });

    test('omits suffix for count', () {
      expect(fmt(23, 'count'), equals('23'));
    });

    test('omits suffix for custom', () {
      expect(fmt(5, 'custom'), equals('5'));
    });

    test('an unknown unit gets no invented suffix', () {
      // The `req_s` option this used to cover was removed: the backend
      // MetricUnit enum has no throughput unit, so it mapped to `custom` and
      // decoded back as "req/s", silently corrupting a metric saved as Custom.
      expect(fmt(96, 'req_s'), equals('96'));
    });

    test('appends KB suffix at its own fixed magnitude', () {
      expect(fmt(2, 'kb'), equals('2 KB'));
    });

    test('appends min suffix at its own fixed magnitude', () {
      expect(fmt(3, 'min'), equals('3 min'));
    });

    test('ratio shares the % suffix with percent (MetricUnit::defaultSuffix)', () {
      expect(fmt(0.5, 'ratio'), equals('0.5 %'));
    });

    test('omits suffix for count_short', () {
      expect(fmt(1200, 'count_short'), equals('1200'));
    });
  });

  // ---------------------------------------------------------------------------
  // fmt — bytes_auto / duration_auto scaling
  //
  // Thresholds: bytes_auto climbs a step (B -> KB -> MB -> GB -> TB) at each
  // 1024 boundary, matching the backend's own IEC-prefixed fixed units
  // (`MetricUnit::defaultSuffix()`: Byte 'B', Kilobyte 'KB', ...). duration_auto
  // assumes a millisecond sample and climbs ms -> s at x1000, s -> min at x60,
  // min -> h at x60, matching Millisecond/Second/Minute/Hour's own suffixes.
  // The scaled number is rounded to one decimal place so a clean division like
  // 1536 / 1024 renders "1.5" rather than a long or repeating decimal.
  // ---------------------------------------------------------------------------

  group('fmt — bytes_auto scaling', () {
    test('below 1024 stays in bytes', () {
      expect(fmt(512, 'bytes_auto'), equals('512 B'));
    });

    test('1536 bytes scales to 1.5 KB', () {
      expect(fmt(1536, 'bytes_auto'), equals('1.5 KB'));
    });

    test('a value at the megabyte boundary scales to MB', () {
      expect(fmt(1024 * 1024 * 2, 'bytes_auto'), equals('2 MB'));
    });

    test('a value at the gigabyte boundary scales to GB', () {
      expect(fmt(1024 * 1024 * 1024 * 3, 'bytes_auto'), equals('3 GB'));
    });
  });

  group('fmt — duration_auto scaling', () {
    test('below 1000ms stays in milliseconds', () {
      expect(fmt(500, 'duration_auto'), equals('500 ms'));
    });

    test('90000ms scales to 1.5 min', () {
      expect(fmt(90000, 'duration_auto'), equals('1.5 min'));
    });

    test('a value at the hour boundary scales to h', () {
      expect(fmt(3600000 * 2, 'duration_auto'), equals('2 h'));
    });

    test('a value below the minute boundary stays in seconds', () {
      expect(fmt(45000, 'duration_auto'), equals('45 s'));
    });
  });

  // ---------------------------------------------------------------------------
  // kMetricUnits — full MetricUnit coverage (Step 5)
  // ---------------------------------------------------------------------------

  group('kMetricUnits', () {
    setUp(() async {
      Translator.instance.setLoader(_UnitLangLoader());
      await Translator.instance.setLocale(const Locale('en'));
    });

    tearDown(() {
      Translator.reset();
    });

    test('has sixteen entries, one per backend MetricUnit case', () {
      expect(kMetricUnits.length, equals(16));
    });

    test('every label resolves through trans() instead of leaking the raw key', () {
      for (final MetricOption option in kMetricUnits) {
        expect(
          option.label.startsWith('uptizm.'),
          isFalse,
          reason: 'unit "${option.value}" leaked its raw i18n key',
        );
      }
    });

    test('every value is unique', () {
      final Set<String> values = kMetricUnits.map((o) => o.value).toSet();
      expect(values.length, equals(kMetricUnits.length));
    });
  });

  // ---------------------------------------------------------------------------
  // bandOf
  // ---------------------------------------------------------------------------

  group('bandOf — direction high (higher is worse)', () {
    const String direction = 'high';
    const String warn = '80';
    const String critical = '95';

    test('value >= critical returns down', () {
      expect(bandOf(95, warn, critical, direction), equals(StatusKey.down));
    });

    test('value above critical returns down', () {
      expect(bandOf(100, warn, critical, direction), equals(StatusKey.down));
    });

    test('value >= warn but below critical returns degraded', () {
      expect(bandOf(80, warn, critical, direction), equals(StatusKey.degraded));
    });

    test('value between warn and critical returns degraded', () {
      expect(bandOf(87, warn, critical, direction), equals(StatusKey.degraded));
    });

    test('value below warn returns up', () {
      expect(bandOf(70, warn, critical, direction), equals(StatusKey.up));
    });
  });

  group('bandOf — direction low (lower is worse)', () {
    const String direction = 'low';
    const String warn = '50';
    const String critical = '20';

    test('value <= critical returns down', () {
      expect(bandOf(20, warn, critical, direction), equals(StatusKey.down));
    });

    test('value below critical returns down', () {
      expect(bandOf(10, warn, critical, direction), equals(StatusKey.down));
    });

    test('value <= warn but above critical returns degraded', () {
      expect(bandOf(50, warn, critical, direction), equals(StatusKey.degraded));
    });

    test('value between critical and warn returns degraded', () {
      expect(bandOf(35, warn, critical, direction), equals(StatusKey.degraded));
    });

    test('value above warn returns up', () {
      expect(bandOf(96, warn, critical, direction), equals(StatusKey.up));
    });
  });

  group('bandOf — unparseable thresholds default to up', () {
    test('non-numeric warn/critical ignored; returns up', () {
      expect(bandOf(999, 'n/a', 'n/a', 'high'), equals(StatusKey.up));
    });
  });

  // ---------------------------------------------------------------------------
  // chartData
  // ---------------------------------------------------------------------------

  group('chartData', () {
    late MetricForm form;

    setUp(() {
      form = kEmptyMetricForm.copyWith(
        label: 'Memory usage',
        key: 'memory_usage',
        path: r'$.system.memory.used_pct',
        unit: '%',
        direction: 'high',
      );
    });

    test('returns exactly 24 data points', () {
      expect(chartData(form).length, equals(24));
    });

    test('every datum carries a numeric "value" entry', () {
      for (final MetricDatum d in chartData(form)) {
        expect(d.values['value'], isA<num>());
      }
    });

    test('every datum has a null band (band lives in the detail widget)', () {
      for (final MetricDatum d in chartData(form)) {
        expect(d.band, isNull);
      }
    });

    test('labels are formatted as HH:00', () {
      final List<MetricDatum> data = chartData(form);
      expect(data.first.label, equals('00:00'));
      expect(data.last.label, equals('23:00'));
    });
  });

  // ---------------------------------------------------------------------------
  // latestOf
  // ---------------------------------------------------------------------------

  group('latestOf', () {
    test('returns the value from the last of the 24 data points', () {
      final MetricForm form = kEmptyMetricForm.copyWith(
        path: r'$.system.memory.used_pct',
        unit: '%',
      );
      final num latest = latestOf(form);
      final num last = chartData(form).last.values['value']!;
      expect(latest, equals(last));
    });
  });

  // ---------------------------------------------------------------------------
  // fromCatalog
  // ---------------------------------------------------------------------------

  group('fromCatalog', () {
    test('maps label, key, unit from the catalog metric', () {
      final MonitorMetric m = customMetricsForMonitors(['api']).first;
      final MetricForm form = fromCatalog(m);

      expect(form.label, equals(m.label));
      expect(form.key, equals(m.key));
      expect(form.unit, equals(m.unit));
    });

    test('maps MetricDirection.high to "high" string', () {
      final MonitorMetric m = customMetricsForMonitors(['api']).first;
      // The first 'api' metric has direction == MetricDirection.high.
      expect(m.direction, equals(MetricDirection.high));
      expect(fromCatalog(m).direction, equals('high'));
    });

    test('maps MetricDirection.low to "low" string', () {
      // Request rate has direction == MetricDirection.low.
      final MonitorMetric m = customMetricsForMonitors(['api']).firstWhere(
        (m) => m.direction == MetricDirection.low,
      );
      expect(fromCatalog(m).direction, equals('low'));
    });

    test('carries the catalog value as a num on the form', () {
      final MonitorMetric m = customMetricsForMonitors(['api']).first;
      final MetricForm form = fromCatalog(m);
      expect(form.value, isA<num>());
      expect(form.value, equals(m.value));
    });
  });

  // ---------------------------------------------------------------------------
  // kKeyRe — accept / reject
  // ---------------------------------------------------------------------------

  // ---------------------------------------------------------------------------
  // normalizeMatchValue — Dart mirror of
  // `ThresholdEvaluator::normalizeMatchValue()`. The literal inputs here are
  // the same ones pinned in `ThresholdEvaluatorTest.php`
  // (`test_normalize_match_value_lowercases_and_trims_ascii_whitespace`,
  // `test_normalize_match_value_trims_non_breaking_space`), so the two suites
  // are pinned against each other rather than each against itself.
  // ---------------------------------------------------------------------------

  group('normalizeMatchValue', () {
    test('lowercases and trims ASCII whitespace', () {
      expect(normalizeMatchValue('  OK\t\n'), equals('ok'));
    });

    test('trims a leading and trailing U+00A0 non-breaking space', () {
      expect(normalizeMatchValue('\u{00A0}OK\u{00A0}'), equals('ok'));
    });
  });

  // ---------------------------------------------------------------------------
  // MetricForm — string-band fields (Step 12)
  // ---------------------------------------------------------------------------

  group('MetricForm string-band fields', () {
    test('kEmptyMetricForm starts with empty lists and an empty unmatchedBand', () {
      expect(kEmptyMetricForm.okValues, isEmpty);
      expect(kEmptyMetricForm.warnValues, isEmpty);
      expect(kEmptyMetricForm.criticalValues, isEmpty);
      expect(kEmptyMetricForm.unmatchedBand, equals(''));
    });

    test('copyWith replaces the string-band fields', () {
      final MetricForm form = kEmptyMetricForm.copyWith(
        okValues: ['ok'],
        warnValues: ['degraded'],
        criticalValues: ['down'],
        unmatchedBand: 'critical',
      );

      expect(form.okValues, equals(['ok']));
      expect(form.warnValues, equals(['degraded']));
      expect(form.criticalValues, equals(['down']));
      expect(form.unmatchedBand, equals('critical'));
    });

    test('fromCatalog seeds empty string-band fields (MonitorMetric carries none)', () {
      final MonitorMetric m = customMetricsForMonitors(['api']).first;
      final MetricForm form = fromCatalog(m);

      expect(form.okValues, isEmpty);
      expect(form.warnValues, isEmpty);
      expect(form.criticalValues, isEmpty);
      expect(form.unmatchedBand, equals(''));
    });
  });

  group('kKeyRe', () {
    test('accepts a simple lowercase key', () {
      expect(kKeyRe.hasMatch('memory_usage'), isTrue);
    });

    test('accepts a key with digits', () {
      expect(kKeyRe.hasMatch('cpu1_load'), isTrue);
    });

    test('rejects a key starting with a digit', () {
      expect(kKeyRe.hasMatch('1cpu'), isFalse);
    });

    test('rejects a key with uppercase letters', () {
      expect(kKeyRe.hasMatch('MemoryUsage'), isFalse);
    });

    test('rejects a key with hyphens', () {
      expect(kKeyRe.hasMatch('memory-usage'), isFalse);
    });

    test('rejects an empty string', () {
      expect(kKeyRe.hasMatch(''), isFalse);
    });

    test('accepts a single lowercase letter', () {
      expect(kKeyRe.hasMatch('a'), isTrue);
    });
  });

  // ---------------------------------------------------------------------------
  // isReadingStale
  // ---------------------------------------------------------------------------

  group('isReadingStale', () {
    // What this pins: a metric that STOPS reporting used to read as healthy.
    // Rename the key a rule extracts in a monitored deploy and no new value is
    // recorded, so the tab kept showing the last good reading with its last good
    // band indefinitely. Nothing on screen is a wrong value, which is what makes
    // that state so quiet.
    final DateTime now = DateTime.utc(2026, 8, 5, 12, 0, 0);

    test('a reading inside the window is current', () {
      expect(
        isReadingStale(
          now.subtract(const Duration(seconds: 45)),
          checkIntervalSec: 30,
          now: now,
        ),
        isFalse,
        reason: 'one missed tick is ordinary: a late probe is not a dead rule',
      );
    });

    test('a reading past two intervals is stale', () {
      expect(
        isReadingStale(
          now.subtract(const Duration(seconds: 61)),
          checkIntervalSec: 30,
          now: now,
        ),
        isTrue,
      );
    });

    test('the boundary itself is not stale', () {
      // Exactly two intervals is the last current moment, so the check is
      // strictly greater-than and a reading landing on the boundary is kept.
      expect(
        isReadingStale(
          now.subtract(const Duration(seconds: 60)),
          checkIntervalSec: 30,
          now: now,
        ),
        isFalse,
      );
    });

    test("the window scales with the monitor's own interval", () {
      // Five minutes old is fine for a 10-minute monitor and long dead for a
      // 30-second one, which is why the bound is the interval and not a constant.
      final DateTime fiveMinutesAgo = now.subtract(const Duration(minutes: 5));

      expect(isReadingStale(fiveMinutesAgo, checkIntervalSec: 600, now: now), isFalse);
      expect(isReadingStale(fiveMinutesAgo, checkIntervalSec: 30, now: now), isTrue);
    });

    test('never having recorded is not stale', () {
      // A different state, which the tab already renders as an em-dash. Calling
      // it stale would claim readings stopped arriving when none ever did.
      expect(isReadingStale(null, checkIntervalSec: 30, now: now), isFalse);
    });

    test('a missing interval never fabricates a warning', () {
      expect(
        isReadingStale(now.subtract(const Duration(days: 7)), checkIntervalSec: 0, now: now),
        isFalse,
      );
    });

    test('a local-time reading compares by absolute instant', () {
      // The wire carries ISO-8601 UTC and `now` is local; `difference` compares
      // instants, so no conversion is needed and none must be invented.
      expect(
        isReadingStale(
          now.subtract(const Duration(seconds: 90)).toLocal(),
          checkIntervalSec: 30,
          now: now,
        ),
        isTrue,
      );
    });
  });
}
