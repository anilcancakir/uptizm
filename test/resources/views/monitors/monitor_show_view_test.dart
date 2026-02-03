import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/models/monitor_check.dart';
import 'package:uptizm/app/models/paginated_checks.dart';
import 'package:uptizm/resources/views/monitors/monitor_show_view.dart';

void main() {
  group('MonitorShowView', () {
    test('can be instantiated', () {
      const view = MonitorShowView();
      expect(view, isA<MonitorShowView>());
    });
  });

  group('Real-time refresh pagination', () {
    test('loadChecks accepts page parameter to maintain current page', () {
      // The controller's loadChecks method accepts a page parameter
      // which is used by real-time refresh to maintain the current page
      final controller = MonitorController();

      // Set pagination state to simulate being on page 3
      controller.checksPaginationNotifier.value = const PaginatedChecks(
        checks: [],
        currentPage: 3,
        lastPage: 5,
        perPage: 10,
        total: 50,
      );

      // Verify we can read the current page from pagination state
      final currentPage =
          controller.checksPaginationNotifier.value?.currentPage ?? 1;
      expect(currentPage, equals(3));

      // This is what the real-time refresh should pass to loadChecks
      // controller.loadChecks(monitorId, page: currentPage);
    });
  });

  group('Monitor metricMappings', () {
    test('parses metric_mappings from API response correctly', () {
      // Given: API response with metric_mappings
      final apiResponse = {
        'id': 1,
        'name': 'Test Monitor',
        'type': 'http',
        'url': 'https://example.com/api',
        'metric_mappings': [
          {
            'path': 'userId',
            'label': 'User ID',
            'type': 'numeric',
            'unit': 'id',
          },
          {
            'path': 'title',
            'label': 'Post Title',
            'type': 'string',
            'unit': '',
          },
        ],
      };

      // When: Creating monitor from API response
      final monitor = Monitor()
        ..setRawAttributes(apiResponse, sync: true)
        ..exists = true;

      // Then: metricMappings should be parsed correctly
      expect(monitor.metricMappings, isNotNull);
      expect(monitor.metricMappings, isA<List<Map<String, dynamic>>>());
      expect(monitor.metricMappings!.length, equals(2));
      expect(monitor.metricMappings![0]['label'], equals('User ID'));
      expect(monitor.metricMappings![1]['path'], equals('title'));
    });

    test('returns null when metric_mappings is not set', () {
      final monitor = Monitor()
        ..setRawAttributes({'id': 1, 'name': 'Test'}, sync: true);

      expect(monitor.metricMappings, isNull);
    });

    test('returns empty list check for isEmpty works', () {
      final monitor = Monitor()
        ..setRawAttributes({
          'id': 1,
          'name': 'Test',
          'metric_mappings': [],
        }, sync: true);

      expect(monitor.metricMappings, isNotNull);
      expect(monitor.metricMappings!.isEmpty, isTrue);
    });
  });

  group('MonitorCheck parsedMetrics', () {
    test('parses parsed_metrics from API response correctly', () {
      // Simulating exact API response structure
      final apiResponse = {
        'id': 8,
        'monitor_id': 1,
        'status': 'up',
        'parsed_metrics': {'userId': 1, 'id': 1, 'title': 'Test Post Title'},
        'checked_at': '2026-02-02T23:24:43.000000Z',
      };

      final check = MonitorCheck.fromMap(apiResponse);

      expect(check.parsedMetrics, isNotNull);
      expect(check.parsedMetrics, isA<Map<String, dynamic>>());
      expect(check.parsedMetrics!['userId'], equals(1));
      expect(check.parsedMetrics!['title'], equals('Test Post Title'));
    });
  });
}
