import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/analytics_response.dart';
import 'package:uptizm/app/models/analytics_series.dart';

void main() {
  group('AnalyticsResponse', () {
    test('fromMap parses valid response correctly', () {
      final map = {
        'data': {
          'monitor_id': 'test-monitor-uuid-1',
          'date_from': '2026-01-01T00:00:00Z',
          'date_to': '2026-01-02T00:00:00Z',
          'granularity': 'hourly',
          'series': [
            {
              'metric_key': 'response_time',
              'metric_label': 'Response Time',
              'metric_type': 'numeric',
              'unit': 'ms',
              'data_points': [],
            },
            {
              'metric_key': 'api_status',
              'metric_label': 'API Status',
              'metric_type': 'status',
              'data_points': [],
            },
          ],
          'summary': {
            'total_checks': 100,
            'uptime_percent': 99.5,
            'avg_response_time': 200.5,
          },
        },
      };

      final response = AnalyticsResponse.fromMap(map);

      expect(response.monitorId, 'test-monitor-uuid-1');
      expect(response.granularity, 'hourly');
      expect(response.series.length, 2);
      expect(response.summary.totalChecks, 100);
      expect(response.summary.uptimePercent, 99.5);
    });

    test('getSeriesByKey returns correct series', () {
      final response = AnalyticsResponse(
        monitorId: 'test-monitor-uuid-1',
        dateFrom: DateTime.now(),
        dateTo: DateTime.now(),
        granularity: 'hourly',
        series: [
          AnalyticsSeries(
            metricKey: 'response_time',
            metricLabel: 'Response Time',
            metricType: MetricType.numeric,
            dataPoints: [],
          ),
        ],
        summary: AnalyticsSummary.empty(),
      );

      expect(response.getSeriesByKey('response_time'), isNotNull);
      expect(response.getSeriesByKey('unknown'), isNull);
    });

    test('numericSeries and statusSeries getters filter correctly', () {
      final response = AnalyticsResponse(
        monitorId: 'test-monitor-uuid-1',
        dateFrom: DateTime.now(),
        dateTo: DateTime.now(),
        granularity: 'hourly',
        series: [
          AnalyticsSeries(
            metricKey: 's1',
            metricLabel: 'S1',
            metricType: MetricType.numeric,
            dataPoints: [],
          ),
          AnalyticsSeries(
            metricKey: 's2',
            metricLabel: 'S2',
            metricType: MetricType.status,
            dataPoints: [],
          ),
        ],
        summary: AnalyticsSummary.empty(),
      );

      expect(response.numericSeries.length, 1);
      expect(response.numericSeries.first.metricKey, 's1');
      expect(response.statusSeries.length, 1);
      expect(response.statusSeries.first.metricKey, 's2');
    });

    test('fromMap handles String summary values from API', () {
      final map = {
        'data': {
          'monitor_id': 'test-monitor-uuid-1',
          'date_from': '2026-01-01T00:00:00Z',
          'date_to': '2026-01-02T00:00:00Z',
          'granularity': 'hourly',
          'series': [],
          'summary': {
            'total_checks': '100',
            'uptime_percent': '99.5',
            'avg_response_time': '200.5',
          },
        },
      };

      final response = AnalyticsResponse.fromMap(map);

      expect(response.summary.totalChecks, 100);
      expect(response.summary.uptimePercent, 99.5);
      expect(response.summary.avgResponseTime, 200.5);
    });
  });

  group('AnalyticsSummary handles String values from API', () {
    test('parses all summary fields from Strings', () {
      final summary = AnalyticsSummary.fromMap({
        'total_checks': '100',
        'uptime_percent': '99.5',
        'avg_response_time': '200.5',
      });

      expect(summary.totalChecks, 100);
      expect(summary.uptimePercent, 99.5);
      expect(summary.avgResponseTime, 200.5);
    });

    test('handles null values with defaults', () {
      final summary = AnalyticsSummary.fromMap({
        'total_checks': null,
        'uptime_percent': null,
        'avg_response_time': null,
      });

      expect(summary.totalChecks, 0);
      expect(summary.uptimePercent, 0.0);
      expect(summary.avgResponseTime, 0.0);
    });

    test('handles empty String values with defaults', () {
      final summary = AnalyticsSummary.fromMap({
        'total_checks': '',
        'uptime_percent': '',
        'avg_response_time': '',
      });

      expect(summary.totalChecks, 0);
      expect(summary.uptimePercent, 0.0);
      expect(summary.avgResponseTime, 0.0);
    });

    test('handles String "0" correctly', () {
      final summary = AnalyticsSummary.fromMap({
        'total_checks': '0',
        'uptime_percent': '0.0',
        'avg_response_time': '0',
      });

      expect(summary.totalChecks, 0);
      expect(summary.uptimePercent, 0.0);
      expect(summary.avgResponseTime, 0.0);
    });
  });
}
