import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/controllers/status_page_controller.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/resources/views/status_pages/status_page_create_view.dart';

class MockMonitorController extends MonitorController {
  @override
  Future<void> loadMonitors() async {
    final m1 = Monitor()
      ..id = 1
      ..name = 'Monitor 1'
      ..metricMappings = [
        {'label': 'CPU', 'path': 'data.cpu', 'type': 'numeric'},
        {'label': 'RAM', 'path': 'data.ram', 'type': 'numeric'},
      ];

    final m2 = Monitor()
      ..id = 2
      ..name = 'Monitor 2'; // No metrics

    monitorsNotifier.value = [m1, m2];
  }
}

class MockStatusPageController extends StatusPageController {
  Map<String, dynamic>? lastStoreArgs;

  @override
  Future<void> store({
    required String name,
    required String slug,
    String? description,
    String? logoUrl,
    String? faviconUrl,
    String? primaryColor,
    bool isPublished = false,
    List<int>? monitorIds,
    List<Map<String, dynamic>>? monitors,
  }) async {
    lastStoreArgs = {'name': name, 'monitors': monitors};
  }
}

void main() {
  late MockMonitorController monitorController;
  late MockStatusPageController statusPageController;

  setUp(() {
    Magic.flush();
    monitorController = MockMonitorController();
    statusPageController = MockStatusPageController();
    Magic.put<MonitorController>(monitorController);
    Magic.put<StatusPageController>(statusPageController);
  });

  Widget buildTestApp(Widget child) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(home: Scaffold(body: child)),
    );
  }

  testWidgets('StatusPageCreateView renders and handles metric selection', (
    tester,
  ) async {
    await tester.pumpWidget(buildTestApp(const StatusPageCreateView()));
    await tester.pumpAndSettle();

    // Verify basic rendering
    expect(find.text('status_pages.create_title'), findsOneWidget);

    // Open monitor select dropdown (WSelect)
    // Scroll to it first
    await tester.scrollUntilVisible(
      find.text('status_pages.add_monitor'),
      500.0,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();

    // Tap to open dropdown
    await tester.tap(find.text('status_pages.add_monitor'));
    await tester.pumpAndSettle();

    // Select Monitor 1
    // Monitor 1 text might be in overlay
    await tester.tap(find.text('Monitor 1').last);

    await tester.pumpAndSettle();

    // Verify Monitor 1 is added to the list
    expect(find.text('Monitor 1'), findsAtLeastNWidgets(1));

    // Verify "Custom Metrics" section appears for Monitor 1
    // We expect to see "CPU (data.cpu)" and "RAM (data.ram)"
    expect(find.text('CPU (data.cpu)'), findsOneWidget);
    expect(find.text('RAM (data.ram)'), findsOneWidget);

    // Check CPU metric
    await tester.tap(find.text('CPU (data.cpu)'));
    await tester.pumpAndSettle();

    // Add Monitor 2
    await tester.scrollUntilVisible(
      find.text('status_pages.add_monitor'),
      500.0,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('status_pages.add_monitor'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Monitor 2').last);
    await tester.pumpAndSettle();

    // Verify Monitor 2 added
    expect(find.text('Monitor 2'), findsAtLeastNWidgets(1));

    // Verify NO Custom Metrics section for Monitor 2 (it has no mappings)
    // We can't easily check for "absence of section" visually without keys,
    // but we can check that we don't see any more checkboxes.

    // Fill required fields
    // First input is Name
    await tester.enterText(find.byType(WFormInput).first, 'Test Page');
    await tester.pumpAndSettle();

    // Submit form
    await tester.scrollUntilVisible(
      find.text('common.create'),
      500.0,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('common.create'));
    await tester.pumpAndSettle();

    // Verify store was called with correct data
    final monitors =
        statusPageController.lastStoreArgs?['monitors']
            as List<Map<String, dynamic>>?;
    expect(monitors, isNotNull);
    expect(monitors!.length, 2);

    // Monitor 1 should have metrics
    expect(monitors[0]['monitor_id'], 1);
    expect(monitors[0]['metric_keys'], ['data.cpu']);

    // Monitor 2 should have no metrics
    expect(monitors[1]['monitor_id'], 2);
    expect(monitors[1]['metric_keys'], isEmpty); // Should be empty list
  });
}
