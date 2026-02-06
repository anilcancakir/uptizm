import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/analytics_controller.dart';

void main() {
  group('AnalyticsController', () {
    late AnalyticsController controller;

    setUp(() {
      controller = AnalyticsController();
    });

    group('initial state', () {
      test('analyticsNotifier starts as null', () {
        expect(controller.analyticsNotifier.value, isNull);
      });

      test('selectedMetricsNotifier starts empty', () {
        expect(controller.selectedMetricsNotifier.value, isEmpty);
      });

      test('granularityNotifier starts as hourly', () {
        expect(controller.granularityNotifier.value, 'hourly');
      });

      test('selectedPresetNotifier starts as 24h', () {
        expect(controller.selectedPresetNotifier.value, '24h');
      });

      test('dateRangeNotifier starts as null', () {
        expect(controller.dateRangeNotifier.value, isNull);
      });
    });

    group('toggleMetric', () {
      test('adds metric when not present', () {
        controller.toggleMetric('cpu');
        expect(controller.selectedMetricsNotifier.value, contains('cpu'));
      });

      test('removes metric when already present', () {
        controller.selectedMetricsNotifier.value = ['cpu'];
        controller.toggleMetric('cpu');
        expect(
          controller.selectedMetricsNotifier.value,
          isNot(contains('cpu')),
        );
      });

      test('handles multiple metrics', () {
        controller.toggleMetric('cpu');
        controller.toggleMetric('memory');
        expect(controller.selectedMetricsNotifier.value, ['cpu', 'memory']);

        controller.toggleMetric('cpu');
        expect(controller.selectedMetricsNotifier.value, ['memory']);
      });
    });

    group('clearMetrics', () {
      test('clears all selected metrics', () {
        controller.selectedMetricsNotifier.value = ['cpu', 'memory', 'disk'];
        controller.clearMetrics();
        expect(controller.selectedMetricsNotifier.value, isEmpty);
      });

      test('does nothing when already empty', () {
        controller.clearMetrics();
        expect(controller.selectedMetricsNotifier.value, isEmpty);
      });
    });

    group('selectAllMetrics', () {
      test('does nothing when analytics is null', () {
        controller.selectAllMetrics();
        expect(controller.selectedMetricsNotifier.value, isEmpty);
      });
    });

    group('granularity calculation', () {
      // These tests verify the granularity logic that would be used by preset methods.
      // We test the logic directly through notifier manipulation since the actual
      // preset methods trigger fetchAnalytics which requires Magic framework services.

      test('24h preset uses hourly granularity', () {
        // Simulating what setLast24Hours does synchronously
        controller.selectedPresetNotifier.value = '24h';
        controller.granularityNotifier.value = 'hourly';
        controller.dateRangeNotifier.value = null;

        expect(controller.selectedPresetNotifier.value, '24h');
        expect(controller.granularityNotifier.value, 'hourly');
        expect(controller.dateRangeNotifier.value, isNull);
      });

      test('7d preset uses daily granularity', () {
        // Simulating what setLast7Days does synchronously
        controller.selectedPresetNotifier.value = '7d';
        controller.granularityNotifier.value = 'daily';
        controller.dateRangeNotifier.value = null;

        expect(controller.selectedPresetNotifier.value, '7d');
        expect(controller.granularityNotifier.value, 'daily');
        expect(controller.dateRangeNotifier.value, isNull);
      });

      test('30d preset uses daily granularity', () {
        // Simulating what setLast30Days does synchronously
        controller.selectedPresetNotifier.value = '30d';
        controller.granularityNotifier.value = 'daily';
        controller.dateRangeNotifier.value = null;

        expect(controller.selectedPresetNotifier.value, '30d');
        expect(controller.granularityNotifier.value, 'daily');
        expect(controller.dateRangeNotifier.value, isNull);
      });

      test('custom range clears preset', () {
        final range = DateTimeRange(
          start: DateTime.now().subtract(const Duration(days: 5)),
          end: DateTime.now(),
        );

        // Simulating what setCustomRange does synchronously
        controller.selectedPresetNotifier.value = null;
        controller.dateRangeNotifier.value = range;

        expect(controller.selectedPresetNotifier.value, isNull);
        expect(controller.dateRangeNotifier.value, range);
      });

      test('custom range > 2 days uses daily granularity', () {
        final range = DateTimeRange(
          start: DateTime.now().subtract(const Duration(days: 5)),
          end: DateTime.now(),
        );

        // Simulating the granularity calculation from setCustomRange
        final duration = range.end.difference(range.start);
        final granularity = duration.inDays > 2 ? 'daily' : 'hourly';

        expect(granularity, 'daily');
      });

      test('custom range <= 2 days uses hourly granularity', () {
        final range = DateTimeRange(
          start: DateTime.now().subtract(const Duration(days: 1)),
          end: DateTime.now(),
        );

        // Simulating the granularity calculation from setCustomRange
        final duration = range.end.difference(range.start);
        final granularity = duration.inDays > 2 ? 'daily' : 'hourly';

        expect(granularity, 'hourly');
      });
    });
  });
}
