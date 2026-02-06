import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/analytics_series.dart';
import 'package:uptizm/resources/views/components/analytics/metric_selector.dart';

class TestAppWrapper extends StatelessWidget {
  final Widget child;
  const TestAppWrapper({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(home: Scaffold(body: child)),
    );
  }
}

void main() {
  group('MetricSelector', () {
    testWidgets('renders empty state when no metrics', (tester) async {
      await tester.pumpWidget(
        TestAppWrapper(
          child: MetricSelector(
            availableMetrics: [],
            selectedKeys: [],
            onToggle: (_) {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('No metrics'), findsNothing);
    });

    testWidgets('renders available metrics and handles toggle', (tester) async {
      final series = [
        AnalyticsSeries(
          metricKey: 'response_time',
          metricLabel: 'Response Time',
          metricType: MetricType.numeric,
          unit: 'ms',
          dataPoints: [],
        ),
        AnalyticsSeries(
          metricKey: 'cpu',
          metricLabel: 'CPU',
          metricType: MetricType.numeric,
          unit: '%',
          dataPoints: [],
        ),
      ];

      String? toggledKey;

      await tester.pumpWidget(
        TestAppWrapper(
          child: MetricSelector(
            availableMetrics: series,
            selectedKeys: const ['response_time'],
            onToggle: (key) => toggledKey = key,
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Response Time'), findsOneWidget);
      expect(find.text('CPU'), findsOneWidget);

      await tester.tap(find.text('CPU'));
      expect(toggledKey, 'cpu');
    });
  });
}
