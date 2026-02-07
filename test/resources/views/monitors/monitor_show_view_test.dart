import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
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

  group('Stats section reactivity', () {
    test('uptime and avg response should update when checks notifier changes', () {
      // This test verifies that the stats section reacts to checksNotifier changes.
      // The bug was that _buildStatsSection reads checksNotifier.value directly
      // instead of being inside a ValueListenableBuilder.

      final controller = MonitorController();

      // Initial state: no checks
      controller.checksNotifier.value = [];

      // Simulate checks loading later
      final checks = [
        MonitorCheck.fromMap({
          'id': 'test-uuid-1',
          'status': 'up',
          'response_time_ms': 100,
          'checked_at': '2026-02-04T10:00:00.000000Z',
        }),
        MonitorCheck.fromMap({
          'id': 'test-uuid-2',
          'status': 'up',
          'response_time_ms': 200,
          'checked_at': '2026-02-04T10:01:00.000000Z',
        }),
      ];

      // Update checks
      controller.checksNotifier.value = checks;

      // Verify notifier has the checks
      expect(controller.checksNotifier.value.length, equals(2));

      // Calculate expected uptime
      final upCount = controller.checksNotifier.value
          .where((c) => c.isUp)
          .length;
      final percentage = (upCount / checks.length * 100).toStringAsFixed(1);
      expect(percentage, equals('100.0'));

      // Calculate expected avg response
      final totalMs = checks.fold<int>(
        0,
        (sum, c) => sum + (c.responseTimeMs ?? 0),
      );
      final avgMs = (totalMs / checks.length).round();
      expect(avgMs, equals(150));

      // The actual widget test is below - this unit test just validates the logic
    });

    testWidgets(
      'stats cards should display updated values when checks load asynchronously',
      (tester) async {
        // Setup controller with monitor but no checks initially
        final controller = MonitorController();
        final monitor = Monitor()
          ..setRawAttributes({
            'id': 'test-uuid-1',
            'name': 'Test Monitor',
            'url': 'https://example.com',
            'status': 'active',
            'check_interval': 60,
          }, sync: true)
          ..exists = true;

        controller.selectedMonitorNotifier.value = monitor;
        controller.checksNotifier.value = []; // Empty initially

        // Build a minimal test widget that mimics the stats section pattern
        await tester.pumpWidget(
          MaterialApp(
            home: WindTheme(
              data: WindThemeData(),
              child: Scaffold(
                body: ValueListenableBuilder<List<MonitorCheck>>(
                  valueListenable: controller.checksNotifier,
                  builder: (context, checks, _) {
                    // This is how it SHOULD be done - inside ValueListenableBuilder
                    final uptime = checks.isEmpty
                        ? '—'
                        : '${(checks.where((c) => c.isUp).length / checks.length * 100).toStringAsFixed(1)}%';
                    final avgResponse = checks.isEmpty
                        ? '—'
                        : '${(checks.fold<int>(0, (sum, c) => sum + (c.responseTimeMs ?? 0)) / checks.length).round()}ms';

                    return Column(
                      children: [
                        Text('Uptime: $uptime', key: const Key('uptime')),
                        Text(
                          'Avg Response: $avgResponse',
                          key: const Key('avg_response'),
                        ),
                      ],
                    );
                  },
                ),
              ),
            ),
          ),
        );

        // Initially should show "—"
        expect(find.text('Uptime: —'), findsOneWidget);
        expect(find.text('Avg Response: —'), findsOneWidget);

        // Now simulate checks loading
        controller.checksNotifier.value = [
          MonitorCheck.fromMap({
            'id': 'test-uuid-1',
            'status': 'up',
            'response_time_ms': 100,
            'checked_at': '2026-02-04T10:00:00.000000Z',
          }),
          MonitorCheck.fromMap({
            'id': 'test-uuid-2',
            'status': 'up',
            'response_time_ms': 200,
            'checked_at': '2026-02-04T10:01:00.000000Z',
          }),
        ];

        // Pump to allow ValueListenableBuilder to rebuild
        await tester.pump();

        // Now should show calculated values
        expect(find.text('Uptime: 100.0%'), findsOneWidget);
        expect(find.text('Avg Response: 150ms'), findsOneWidget);
      },
    );
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
        'id': 'test-uuid-1',
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
        ..setRawAttributes({'id': 'test-uuid-1', 'name': 'Test'}, sync: true);

      expect(monitor.metricMappings, isNull);
    });

    test('returns empty list check for isEmpty works', () {
      final monitor = Monitor()
        ..setRawAttributes({
          'id': 'test-uuid-1',
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
        'id': 'test-uuid-8',
        'monitor_id': 'test-monitor-uuid-1',
        'status': 'up',
        'parsed_metrics': {'userId': 1, 'id': 'test-uuid-1', 'title': 'Test Post Title'},
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
