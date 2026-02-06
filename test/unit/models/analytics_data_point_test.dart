import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/analytics_data_point.dart';

void main() {
  group('AnalyticsDataPoint', () {
    test('fromMap creates instance with numeric data', () {
      final map = {
        'timestamp': '2026-02-04T10:00:00Z',
        'value': 245.5,
        'min': 200.0,
        'max': 300.0,
        'count': 12,
      };

      final dataPoint = AnalyticsDataPoint.fromMap(map);

      expect(dataPoint.timestamp, DateTime.utc(2026, 2, 4, 10, 0, 0));
      expect(dataPoint.value, 245.5);
      expect(dataPoint.min, 200.0);
      expect(dataPoint.max, 300.0);
      expect(dataPoint.count, 12);
      expect(dataPoint.upCount, isNull);
    });

    test('fromMap creates instance with status data', () {
      final map = {
        'timestamp': '2026-02-04T10:00:00Z',
        'up_count': 10,
        'down_count': 2,
        'total': 12,
      };

      final dataPoint = AnalyticsDataPoint.fromMap(map);

      expect(dataPoint.timestamp, DateTime.utc(2026, 2, 4, 10, 0, 0));
      expect(dataPoint.upCount, 10);
      expect(dataPoint.downCount, 2);
      expect(dataPoint.total, 12);
      expect(dataPoint.value, isNull);
    });

    test('fromMap handles null optional fields', () {
      final map = {'timestamp': '2026-02-04T10:00:00Z'};

      final dataPoint = AnalyticsDataPoint.fromMap(map);

      expect(dataPoint.timestamp, DateTime.utc(2026, 2, 4, 10, 0, 0));
      expect(dataPoint.value, isNull);
      expect(dataPoint.min, isNull);
      expect(dataPoint.max, isNull);
      expect(dataPoint.count, isNull);
    });

    test('uptimePercent calculates correctly', () {
      final dataPoint = AnalyticsDataPoint(
        timestamp: DateTime.now(),
        upCount: 9,
        total: 10,
      );

      expect(dataPoint.uptimePercent, 90.0);
    });

    test('uptimePercent returns null when total is null or zero', () {
      final dataPoint1 = AnalyticsDataPoint(
        timestamp: DateTime.now(),
        upCount: 0,
        total: 0,
      );
      expect(dataPoint1.uptimePercent, isNull);

      final dataPoint2 = AnalyticsDataPoint(
        timestamp: DateTime.now(),
        upCount: 0,
        total: null,
      );
      expect(dataPoint2.uptimePercent, isNull);
    });

    test('handles numeric strings in JSON', () {
      final map = {
        'timestamp': '2026-02-04T10:00:00Z',
        'value': "245.5",
        'min': "200",
        'max': "300",
        'count': "12",
        'up_count': "10",
        'down_count': "2",
        'total': "12",
      };

      final dataPoint = AnalyticsDataPoint.fromMap(map);

      expect(dataPoint.value, 245.5);
      expect(dataPoint.min, 200.0);
      expect(dataPoint.max, 300.0);
      expect(dataPoint.count, 12);
      expect(dataPoint.upCount, 10);
      expect(dataPoint.downCount, 2);
      expect(dataPoint.total, 12);
    });
  });
}
