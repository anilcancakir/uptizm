import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/components/charts/response_time_sparkline.dart';

void main() {
  group('ResponseTimeSparkline', () {
    test('can be instantiated with data', () {
      const widget = ResponseTimeSparkline(
        data: [150, 200, 180, 220, 190],
      );
      expect(widget, isA<ResponseTimeSparkline>());
      expect(widget.data.length, equals(5));
    });

    test('accepts optional height and width', () {
      const widget = ResponseTimeSparkline(
        data: [100, 150, 120],
        height: 40,
        width: 100,
      );
      expect(widget.height, equals(40));
      expect(widget.width, equals(100));
    });

    test('accepts optional showDots parameter', () {
      const widget = ResponseTimeSparkline(
        data: [100, 150],
        showDots: true,
      );
      expect(widget.showDots, isTrue);
    });

    test('handles empty data gracefully', () {
      const widget = ResponseTimeSparkline(data: []);
      expect(widget.data.isEmpty, isTrue);
    });

    testWidgets('renders without error', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: ResponseTimeSparkline(
              data: [150, 200, 180, 220, 190],
              height: 50,
              width: 120,
            ),
          ),
        ),
      );

      expect(find.byType(ResponseTimeSparkline), findsOneWidget);
    });
  });
}
