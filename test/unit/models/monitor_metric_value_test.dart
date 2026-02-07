import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/monitor_metric_value.dart';
import 'package:uptizm/app/enums/metric_status_value.dart';

void main() {
  group('MonitorMetricValue', () {
    test('fromMap parses all fields including status_value', () {
      final map = {
        'id': 'test-uuid-1',
        'monitor_id': 'test-monitor-uuid-10',
        'check_id': 'test-check-uuid-100',
        'metric_key': 'is_healthy',
        'metric_label': 'Service Health',
        'numeric_value': null,
        'string_value': 'true',
        'status_value': 'up',
        'unit': null,
        'recorded_at': '2026-02-04T12:00:00.000000Z',
      };

      final metricValue = MonitorMetricValue.fromMap(map);

      expect(metricValue.id, 'test-uuid-1');
      expect(metricValue.monitorId, 'test-monitor-uuid-10');
      expect(metricValue.checkId, 'test-check-uuid-100');
      expect(metricValue.metricKey, 'is_healthy');
      expect(metricValue.metricLabel, 'Service Health');
      expect(metricValue.numericValue, isNull);
      expect(metricValue.stringValue, 'true');
      expect(metricValue.statusValue, MetricStatusValue.up);
      expect(metricValue.unit, isNull);
      expect(metricValue.recordedAt, isNotNull);
    });

    test('fromMap parses numeric metric', () {
      final map = {
        'id': 'test-uuid-2',
        'monitor_id': 'test-monitor-uuid-10',
        'check_id': 'test-check-uuid-100',
        'metric_key': 'response_time',
        'metric_label': 'Response Time',
        'numeric_value': 150.5,
        'string_value': null,
        'status_value': null,
        'unit': 'ms',
        'recorded_at': '2026-02-04T12:00:00.000000Z',
      };

      final metricValue = MonitorMetricValue.fromMap(map);

      expect(metricValue.numericValue, 150.5);
      expect(metricValue.statusValue, isNull);
      expect(metricValue.unit, 'ms');
    });

    test('statusValue getter returns MetricStatusValue enum', () {
      final map = {
        'id': 'test-uuid-1',
        'monitor_id': 'test-monitor-uuid-10',
        'check_id': 'test-check-uuid-100',
        'metric_key': 'db_connected',
        'metric_label': 'Database',
        'status_value': 'down',
        'recorded_at': '2026-02-04T12:00:00.000000Z',
      };

      final metricValue = MonitorMetricValue.fromMap(map);

      expect(metricValue.statusValue, MetricStatusValue.down);
    });

    test('isUp getter returns true when status is up', () {
      final map = {
        'id': 'test-uuid-1',
        'monitor_id': 'test-monitor-uuid-10',
        'check_id': 'test-check-uuid-100',
        'metric_key': 'is_healthy',
        'metric_label': 'Health',
        'status_value': 'up',
        'recorded_at': '2026-02-04T12:00:00.000000Z',
      };

      final metricValue = MonitorMetricValue.fromMap(map);

      expect(metricValue.isUp, isTrue);
      expect(metricValue.isDown, isFalse);
    });

    test('isDown getter returns true when status is down', () {
      final map = {
        'id': 'test-uuid-1',
        'monitor_id': 'test-monitor-uuid-10',
        'check_id': 'test-check-uuid-100',
        'metric_key': 'is_healthy',
        'metric_label': 'Health',
        'status_value': 'down',
        'recorded_at': '2026-02-04T12:00:00.000000Z',
      };

      final metricValue = MonitorMetricValue.fromMap(map);

      expect(metricValue.isUp, isFalse);
      expect(metricValue.isDown, isTrue);
    });

    test('isStatusMetric returns true when status_value is present', () {
      final statusMap = {
        'id': 'test-uuid-1',
        'monitor_id': 'test-monitor-uuid-10',
        'check_id': 'test-check-uuid-100',
        'metric_key': 'is_healthy',
        'metric_label': 'Health',
        'status_value': 'up',
        'recorded_at': '2026-02-04T12:00:00.000000Z',
      };

      final numericMap = {
        'id': 'test-uuid-2',
        'monitor_id': 'test-monitor-uuid-10',
        'check_id': 'test-check-uuid-100',
        'metric_key': 'response_time',
        'metric_label': 'Response Time',
        'numeric_value': 150,
        'status_value': null,
        'recorded_at': '2026-02-04T12:00:00.000000Z',
      };

      final statusMetric = MonitorMetricValue.fromMap(statusMap);
      final numericMetric = MonitorMetricValue.fromMap(numericMap);

      expect(statusMetric.isStatusMetric, isTrue);
      expect(numericMetric.isStatusMetric, isFalse);
    });

    test('handles integer numeric_value from API', () {
      final map = {
        'id': 'test-uuid-1',
        'monitor_id': 'test-monitor-uuid-10',
        'check_id': 'test-check-uuid-100',
        'metric_key': 'cpu_usage',
        'metric_label': 'CPU Usage',
        'numeric_value': 75,
        'unit': '%',
        'recorded_at': '2026-02-04T12:00:00.000000Z',
      };

      final metricValue = MonitorMetricValue.fromMap(map);

      expect(metricValue.numericValue, 75.0);
    });
  });
}
