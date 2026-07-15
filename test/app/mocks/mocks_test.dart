import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/incident_impact.dart' show IncidentImpact;
import 'package:uptizm/app/enums/incident_lifecycle.dart' show IncidentLifecycle;
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/app/enums/chart_tone.dart' show ChartTone;
import 'package:uptizm/app/enums/metric_kind.dart' show MetricKind;
import 'package:uptizm/app/support/metric_types.dart'
    show MetricAnomaly, MetricSeries;
import 'package:uptizm/app/mocks/metrics.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/enums/status_key.dart';

void main() {
  group('StatusKey', () {
    test('has exactly 6 values', () {
      expect(StatusKey.values, hasLength(6));
    });

    test('statusKeys list covers all 6 values in canonical order', () {
      expect(statusKeys, equals(StatusKey.values));
    });

    test('each value has a non-empty label', () {
      for (final key in StatusKey.values) {
        expect(key.label, isNotEmpty, reason: '${key.name} label is empty');
      }
    });
  });

  group('Monitor fixtures', () {
    test('monitors list is non-empty', () {
      expect(monitors, isNotEmpty);
    });

    test('all four status states are represented', () {
      final statuses = monitors.map((m) => m.status).toSet();
      expect(
        statuses,
        containsAll([
          StatusKey.up,
          StatusKey.down,
          StatusKey.degraded,
          StatusKey.paused,
        ]),
      );
    });

    test('findMonitor returns the matching fixture', () {
      final monitor = findMonitor('api');
      expect(monitor, isNotNull);
      expect(monitor!.name, equals('API gateway'));
    });

    test('findMonitor returns null for unknown id', () {
      expect(findMonitor('nonexistent'), isNull);
    });

    test('recentChecks list is non-empty', () {
      expect(recentChecks, isNotEmpty);
    });

    test('allRegions list is non-empty', () {
      expect(allRegions, isNotEmpty);
    });
  });

  group('Incident fixtures', () {
    test('incidents list is non-empty', () {
      expect(incidents, isNotEmpty);
    });

    test('at least one AI-owned incident exists', () {
      expect(incidents.any((i) => i.aiOwned), isTrue);
    });

    test('at least one resolved incident exists', () {
      expect(
        incidents.any((i) => i.lifecycle == IncidentLifecycle.resolved),
        isTrue,
      );
    });

    test('findIncident returns the matching fixture', () {
      final incident = findIncident('checkout-503');
      expect(incident, isNotNull);
      expect(incident!.impact, equals(IncidentImpact.down));
    });

    test('findIncident returns null for unknown id', () {
      expect(findIncident('nonexistent'), isNull);
    });

    test('incidentsForMonitor returns incidents touching the given name', () {
      final result = incidentsForMonitor('Checkout service');
      expect(result, isNotEmpty);
    });

    test('every incident has a non-empty timeline', () {
      for (final incident in incidents) {
        expect(
          incident.timeline,
          isNotEmpty,
          reason: '${incident.id} has an empty timeline',
        );
      }
    });
  });

  group('Metric fixtures', () {
    test('customMetrics list is non-empty', () {
      expect(customMetrics, isNotEmpty);
    });

    test('apiResponseSeries has 24 data points', () {
      expect(apiResponseSeries, hasLength(24));
    });

    test('each data point has p50, p95, and p99 values', () {
      for (final datum in apiResponseSeries) {
        expect(datum.values.containsKey('p50'), isTrue);
        expect(datum.values.containsKey('p95'), isTrue);
        expect(datum.values.containsKey('p99'), isTrue);
      }
    });

    test('band field is set on every API response datum', () {
      for (final datum in apiResponseSeries) {
        expect(datum.band, isNotNull);
        final (low, high) = datum.band!;
        expect(low, lessThan(high));
      }
    });

    test('apiResponseAnomalies is non-empty', () {
      expect(apiResponseAnomalies, isNotEmpty);
    });

    test('MetricSeries fields match the chart contract (key, label, tone)', () {
      const series = MetricSeries(key: 'p50', label: 'p50', tone: ChartTone.up);
      expect(series.key, equals('p50'));
      expect(series.label, equals('p50'));
      expect(series.tone, equals(ChartTone.up));
    });

    test('MetricAnomaly fields match the chart contract (x, y)', () {
      const anomaly = MetricAnomaly(x: '13:00', y: 500);
      expect(anomaly.x, equals('13:00'));
      expect(anomaly.y, equals(500));
    });

    test('ChartTone has 5 values (no down, no paused)', () {
      expect(ChartTone.values, hasLength(5));
    });

    test('metricsForMonitors returns system + custom metrics', () {
      final result = metricsForMonitors(['api', 'marketing']);
      expect(result, isNotEmpty);
      expect(result.any((m) => m.kind == MetricKind.system), isTrue);
      expect(result.any((m) => m.kind == MetricKind.custom), isTrue);
    });
  });
}
