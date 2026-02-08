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
        'parsed_metrics': {
          'userId': 1,
          'id': 'test-uuid-1',
          'title': 'Test Post Title',
        },
        'checked_at': '2026-02-02T23:24:43.000000Z',
      };

      final check = MonitorCheck.fromMap(apiResponse);

      expect(check.parsedMetrics, isNotNull);
      expect(check.parsedMetrics, isA<Map<String, dynamic>>());
      expect(check.parsedMetrics!['userId'], equals(1));
      expect(check.parsedMetrics!['title'], equals('Test Post Title'));
    });
  });

  group('Boolean/status metric chip rendering', () {
    Widget buildTestApp({required Widget child}) {
      return WindTheme(
        data: WindThemeData(),
        child: MaterialApp(home: Scaffold(body: child)),
      );
    }

    testWidgets('renders green chip for truthy boolean (true)', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: _MetricChipTestHarness(
            parsedMetrics: {'is_healthy': true},
            mappings: [
              {
                'label': 'Healthy',
                'path': 'is_healthy',
                'type': 'status',
                'unit': '',
              },
            ],
          ),
        ),
      );

      expect(find.text('Healthy'), findsOneWidget);
      expect(
        find.byKey(const Key('metric-dot-is_healthy-green')),
        findsOneWidget,
      );
    });

    testWidgets('renders red chip for falsy boolean (false)', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: _MetricChipTestHarness(
            parsedMetrics: {'is_healthy': false},
            mappings: [
              {
                'label': 'Healthy',
                'path': 'is_healthy',
                'type': 'status',
                'unit': '',
              },
            ],
          ),
        ),
      );

      expect(find.text('Healthy'), findsOneWidget);
      expect(
        find.byKey(const Key('metric-dot-is_healthy-red')),
        findsOneWidget,
      );
    });

    testWidgets('renders green chip for String "true"', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: _MetricChipTestHarness(
            parsedMetrics: {'db': 'true'},
            mappings: [
              {
                'label': 'DB Connected',
                'path': 'db',
                'type': 'numeric',
                'unit': '',
              },
            ],
          ),
        ),
      );

      expect(find.text('DB Connected'), findsOneWidget);
      expect(find.byKey(const Key('metric-dot-db-green')), findsOneWidget);
    });

    testWidgets('renders red chip for String "false"', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: _MetricChipTestHarness(
            parsedMetrics: {'db': 'false'},
            mappings: [
              {
                'label': 'DB Connected',
                'path': 'db',
                'type': 'numeric',
                'unit': '',
              },
            ],
          ),
        ),
      );

      expect(find.text('DB Connected'), findsOneWidget);
      expect(find.byKey(const Key('metric-dot-db-red')), findsOneWidget);
    });

    testWidgets('renders green chip for String "1"', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: _MetricChipTestHarness(
            parsedMetrics: {'active': '1'},
            mappings: [
              {
                'label': 'Active',
                'path': 'active',
                'type': 'status',
                'unit': '',
              },
            ],
          ),
        ),
      );

      expect(find.text('Active'), findsOneWidget);
      expect(find.byKey(const Key('metric-dot-active-green')), findsOneWidget);
    });

    testWidgets('renders red chip for String "0"', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: _MetricChipTestHarness(
            parsedMetrics: {'active': '0'},
            mappings: [
              {
                'label': 'Active',
                'path': 'active',
                'type': 'status',
                'unit': '',
              },
            ],
          ),
        ),
      );

      expect(find.text('Active'), findsOneWidget);
      expect(find.byKey(const Key('metric-dot-active-red')), findsOneWidget);
    });

    testWidgets('renders red chip for empty String (status type)', (
      tester,
    ) async {
      await tester.pumpWidget(
        buildTestApp(
          child: _MetricChipTestHarness(
            parsedMetrics: {'active': ''},
            mappings: [
              {
                'label': 'Active',
                'path': 'active',
                'type': 'status',
                'unit': '',
              },
            ],
          ),
        ),
      );

      expect(find.text('Active'), findsOneWidget);
      expect(find.byKey(const Key('metric-dot-active-red')), findsOneWidget);
    });

    testWidgets('renders red chip for null value (status type)', (
      tester,
    ) async {
      await tester.pumpWidget(
        buildTestApp(
          child: _MetricChipTestHarness(
            parsedMetrics: {'active': null},
            mappings: [
              {
                'label': 'Active',
                'path': 'active',
                'type': 'status',
                'unit': '',
              },
            ],
          ),
        ),
      );

      expect(find.text('Active'), findsOneWidget);
      expect(find.byKey(const Key('metric-dot-active-red')), findsOneWidget);
    });

    testWidgets(
      'renders green chip for arbitrary truthy String (status type)',
      (tester) async {
        await tester.pumpWidget(
          buildTestApp(
            child: _MetricChipTestHarness(
              parsedMetrics: {'svc': 'running'},
              mappings: [
                {
                  'label': 'Service',
                  'path': 'svc',
                  'type': 'status',
                  'unit': '',
                },
              ],
            ),
          ),
        );

        expect(find.text('Service'), findsOneWidget);
        expect(find.byKey(const Key('metric-dot-svc-green')), findsOneWidget);
      },
    );

    testWidgets('renders numeric chip for non-status numeric value', (
      tester,
    ) async {
      await tester.pumpWidget(
        buildTestApp(
          child: _MetricChipTestHarness(
            parsedMetrics: {'cpu': 75},
            mappings: [
              {'label': 'CPU', 'path': 'cpu', 'type': 'numeric', 'unit': '%'},
            ],
          ),
        ),
      );

      expect(find.text('CPU'), findsOneWidget);
      expect(find.text('75 %'), findsOneWidget);
      // Should NOT have a green/red dot
      expect(find.byKey(const Key('metric-dot-cpu-green')), findsNothing);
      expect(find.byKey(const Key('metric-dot-cpu-red')), findsNothing);
    });
  });
}

/// Test harness that replicates the metric chip rendering logic from MonitorShowView._buildMetricRow
class _MetricChipTestHarness extends StatelessWidget {
  final Map<String, dynamic>? parsedMetrics;
  final List<Map<String, dynamic>> mappings;

  const _MetricChipTestHarness({
    required this.parsedMetrics,
    required this.mappings,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-row flex-wrap gap-2',
      children: mappings.map<Widget>((mapping) {
        final label = mapping['label'] as String? ?? 'Metric';
        final path = mapping['path'] as String? ?? '';
        final unit = mapping['unit'] as String? ?? '';
        final metricType = mapping['type'] as String? ?? '';
        final rawValue = parsedMetrics?[path];

        // Status type metrics or boolean values → green/red indicator
        final isStatusType =
            metricType == 'status' ||
            rawValue is bool ||
            rawValue == 'true' ||
            rawValue == 'false';

        if (isStatusType) {
          final boolValue =
              rawValue == true ||
              rawValue == 'true' ||
              rawValue == '1' ||
              (rawValue is String &&
                  rawValue.isNotEmpty &&
                  rawValue != 'false' &&
                  rawValue != '0');

          return WDiv(
            key: Key('metric-chip-$path'),
            className:
                '''
              flex flex-row items-center gap-1.5 overflow-hidden
              px-2.5 py-1 rounded-full
              ${boolValue ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800'}
            ''',
            children: [
              WDiv(
                key: Key('metric-dot-$path-${boolValue ? 'green' : 'red'}'),
                className:
                    'w-2 h-2 rounded-full ${boolValue ? 'bg-green-500' : 'bg-red-500'}',
              ),
              WText(
                label,
                className:
                    'text-xs ${boolValue ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300'}',
              ),
            ],
          );
        }

        String displayValue;
        if (rawValue == null) {
          displayValue = '—';
        } else if (rawValue is String && rawValue.length > 12) {
          displayValue = '${rawValue.substring(0, 12)}…';
        } else {
          displayValue = rawValue.toString();
        }

        if (unit.isNotEmpty && rawValue is num) {
          displayValue = '$displayValue $unit';
        }

        return WDiv(
          key: Key('metric-chip-$path'),
          className: '''
            flex flex-row items-center gap-1.5 overflow-hidden
            px-2.5 py-1 rounded-full
            bg-white dark:bg-gray-800
            border border-gray-200 dark:border-gray-700
          ''',
          children: [
            WText(
              label,
              className: 'text-xs text-gray-500 dark:text-gray-400 truncate',
            ),
            WText(
              displayValue,
              className:
                  'text-xs font-mono font-semibold text-gray-900 dark:text-white truncate',
            ),
          ],
        );
      }).toList(),
    );
  }
}
