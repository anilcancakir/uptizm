import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/analytics_data_point.dart';
import 'package:uptizm/app/models/analytics_series.dart';

void main() {
  group('AnalyticsSeries', () {
    test('fromMap creates instance correctly', () {
      final map = {
        'metric_key': 'response_time',
        'metric_label': 'Response Time',
        'metric_type': 'numeric',
        'unit': 'ms',
        'data_points': [
          {'timestamp': '2026-02-04T10:00:00Z', 'value': 245.5},
        ],
      };

      final series = AnalyticsSeries.fromMap(map);

      expect(series.metricKey, 'response_time');
      expect(series.metricLabel, 'Response Time');
      expect(series.metricType, MetricType.numeric);
      expect(series.unit, 'ms');
      expect(series.dataPoints.length, 1);
      expect(
        series.dataPoints.first.timestamp,
        DateTime.utc(2026, 2, 4, 10, 0, 0),
      );
      expect(series.dataPoints.first.value, 245.5);
    });

    test('fromMap handles empty data points', () {
      final map = {
        'metric_key': 'response_time',
        'metric_label': 'Response Time',
        'metric_type': 'numeric',
        'data_points': [],
      };

      final series = AnalyticsSeries.fromMap(map);

      expect(series.dataPoints, isEmpty);
      expect(series.isEmpty, isTrue);
    });

    test('dateRange returns correct range', () {
      final series = AnalyticsSeries(
        metricKey: 'test',
        metricLabel: 'Test',
        metricType: MetricType.numeric,
        dataPoints: [
          AnalyticsDataPoint(timestamp: DateTime.utc(2026, 2, 4, 10, 0, 0)),
          AnalyticsDataPoint(timestamp: DateTime.utc(2026, 2, 4, 12, 0, 0)),
        ],
      );

      final range = series.dateRange;

      expect(range, isNotNull);
      expect(range!.start, DateTime.utc(2026, 2, 4, 10, 0, 0));
      expect(range.end, DateTime.utc(2026, 2, 4, 12, 0, 0));
    });

    test('dateRange returns null when empty', () {
      final series = AnalyticsSeries(
        metricKey: 'test',
        metricLabel: 'Test',
        metricType: MetricType.numeric,
        dataPoints: [],
      );

      expect(series.dateRange, isNull);
    });

    test('toChartSpots returns FlSpot list for numeric data', () {
      final series = AnalyticsSeries(
        metricKey: 'test',
        metricLabel: 'Test',
        metricType: MetricType.numeric,
        dataPoints: [
          AnalyticsDataPoint(
            timestamp: DateTime.utc(2026, 2, 4, 10, 0, 0),
            value: 100,
          ),
          AnalyticsDataPoint(
            timestamp: DateTime.utc(2026, 2, 4, 11, 0, 0),
            value: 200,
          ),
        ],
      );

      final spots = series.toChartSpots();

      expect(spots.length, 2);
      expect(
        spots[0].x,
        DateTime.utc(2026, 2, 4, 10, 0, 0).millisecondsSinceEpoch.toDouble(),
      );
      expect(spots[0].y, 100.0);
      expect(
        spots[1].x,
        DateTime.utc(2026, 2, 4, 11, 0, 0).millisecondsSinceEpoch.toDouble(),
      );
      expect(spots[1].y, 200.0);
    });
  });
}
