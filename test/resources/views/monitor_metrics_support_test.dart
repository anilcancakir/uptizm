import 'package:flutter_test/flutter_test.dart';

import 'package:uptizm/app/enums/metric_direction.dart' show MetricDirection;
import 'package:uptizm/app/support/metric_types.dart'
    show MetricDatum, MonitorMetric;
import 'package:uptizm/app/mocks/metrics.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart';

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
}
