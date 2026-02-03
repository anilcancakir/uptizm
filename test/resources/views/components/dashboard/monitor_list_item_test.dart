import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/resources/views/components/dashboard/monitor_list_item.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('MonitorListItem', () {
    testWidgets('renders monitor name and URL', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const MonitorListItem(
            name: 'API Health',
            url: 'api.example.com/health',
            status: MonitorStatus.up,
            responseTime: '145ms',
          ),
        ),
      );

      expect(find.text('API Health'), findsOneWidget);
      expect(find.text('api.example.com/health'), findsOneWidget);
    });

    testWidgets('shows response time', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const MonitorListItem(
            name: 'API Health',
            url: 'api.example.com/health',
            status: MonitorStatus.up,
            responseTime: '145ms',
          ),
        ),
      );

      expect(find.text('145ms'), findsOneWidget);
    });

    testWidgets('can be tapped', (tester) async {
      var tapped = false;

      await tester.pumpWidget(
        buildTestApp(
          child: MonitorListItem(
            name: 'API Health',
            url: 'api.example.com/health',
            status: MonitorStatus.up,
            responseTime: '145ms',
            onTap: () => tapped = true,
          ),
        ),
      );

      await tester.tap(find.byType(MonitorListItem));
      expect(tapped, isTrue);
    });
  });
}
