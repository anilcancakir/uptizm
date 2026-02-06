import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/models/monitor_metric_value.dart';
import 'package:uptizm/resources/views/components/monitors/status_metrics_panel.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('MonitorShowView Status Metrics', () {
    late MonitorController controller;

    setUp(() {
      controller = MonitorController.instance;
      // Clear state
      controller.statusMetricsNotifier.value = [];
    });

    testWidgets('shows StatusMetricsPanel when status metrics exist', (
      tester,
    ) async {
      // Setup Status Metrics
      final metrics = [
        MonitorMetricValue.fromMap({
          'id': 1,
          'monitor_id': 1,
          'check_id': 100,
          'metric_key': 'health',
          'metric_label': 'Health',
          'status_value': 'up',
          'recorded_at': '2026-02-04T12:00:00.000000Z',
        }),
      ];

      // This test verifies that StatusMetricsPanel renders correctly with metrics
      // The integration with MonitorShowView is tested via manual QA
      await tester.pumpWidget(
        buildTestApp(
          child: MagicBuilder(
            listenable: controller.statusMetricsNotifier,
            builder: (statusMetrics) => statusMetrics.isNotEmpty
                ? StatusMetricsPanel(metrics: statusMetrics)
                : const SizedBox.shrink(),
          ),
        ),
      );

      // Initially empty
      expect(find.byType(StatusMetricsPanel), findsNothing);

      // Set metrics
      controller.statusMetricsNotifier.value = metrics;
      await tester.pump();

      // Verify StatusMetricsPanel is present
      expect(find.byType(StatusMetricsPanel), findsOneWidget);
    });
  });
}
