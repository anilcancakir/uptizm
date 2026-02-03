import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/components/charts/response_time_chart.dart';

void main() {
  group('ResponseTimeChart', () {
    test('can be instantiated with data points', () {
      final widget = ResponseTimeChart(
        dataPoints: [
          ChartDataPoint(timestamp: DateTime.now(), value: 150),
          ChartDataPoint(timestamp: DateTime.now(), value: 200),
        ],
      );
      expect(widget, isA<ResponseTimeChart>());
      expect(widget.dataPoints.length, equals(2));
    });

    test('accepts optional height', () {
      final widget = ResponseTimeChart(
        dataPoints: [],
        height: 250,
      );
      expect(widget.height, equals(250));
    });

    test('accepts showTooltip parameter', () {
      final widget = ResponseTimeChart(
        dataPoints: [],
        showTooltip: true,
      );
      expect(widget.showTooltip, isTrue);
    });

    test('handles empty data gracefully', () {
      final widget = ResponseTimeChart(dataPoints: []);
      expect(widget.dataPoints.isEmpty, isTrue);
    });

    testWidgets('renders without error', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ResponseTimeChart(
              dataPoints: [
                ChartDataPoint(timestamp: DateTime.now().subtract(const Duration(minutes: 5)), value: 150),
                ChartDataPoint(timestamp: DateTime.now(), value: 200),
              ],
              height: 200,
            ),
          ),
        ),
      );

      expect(find.byType(ResponseTimeChart), findsOneWidget);
    });
  });

  group('ChartDataPoint', () {
    test('stores timestamp and value', () {
      final now = DateTime.now();
      final point = ChartDataPoint(timestamp: now, value: 150);
      expect(point.timestamp, equals(now));
      expect(point.value, equals(150));
    });

    test('accepts optional status', () {
      final point = ChartDataPoint(
        timestamp: DateTime.now(),
        value: 150,
        status: 'up',
      );
      expect(point.status, equals('up'));
    });
  });
}
