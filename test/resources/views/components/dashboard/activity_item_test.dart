import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/resources/views/components/dashboard/activity_item.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('ActivityItem', () {
    testWidgets('renders activity title and description', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const ActivityItem(
            title: 'Monitor Down',
            description: 'api.example.com is not responding',
            timeAgo: '5m ago',
          ),
        ),
      );

      expect(find.text('Monitor Down'), findsOneWidget);
      expect(find.text('api.example.com is not responding'), findsOneWidget);
    });

    testWidgets('renders timestamp', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const ActivityItem(
            title: 'Monitor Down',
            description: 'api.example.com is not responding',
            timeAgo: '5m ago',
          ),
        ),
      );

      expect(find.text('5m ago'), findsOneWidget);
    });

    testWidgets('renders different icons for different types', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const Column(
            children: [
              ActivityItem(
                title: 'Incident',
                description: 'Error',
                timeAgo: '1m',
                type: ActivityType.incident,
              ),
              ActivityItem(
                title: 'Recovery',
                description: 'Fixed',
                timeAgo: '2m',
                type: ActivityType.recovery,
              ),
            ],
          ),
        ),
      );

      expect(find.byIcon(Icons.error_outline), findsOneWidget);
      expect(find.byIcon(Icons.check_circle_outline), findsOneWidget);
    });
  });
}
