import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/dashboard/stat_card.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('StatCard', () {
    testWidgets('renders title and value', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatCard(label: 'Total Monitors', value: '24'),
        ),
      );

      expect(find.text('TOTAL MONITORS'), findsOneWidget);
      expect(find.text('24'), findsOneWidget);
    });

    testWidgets('renders icon when provided', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatCard(
            label: 'Total Monitors',
            value: '24',
            icon: Icons.monitor_heart,
          ),
        ),
      );

      expect(find.byIcon(Icons.monitor_heart), findsOneWidget);
    });

    testWidgets('renders subtitle when provided', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatCard(
            label: 'Total Monitors',
            value: '24',
            subtitle: '+2 this week',
          ),
        ),
      );

      expect(find.text('+2 this week'), findsOneWidget);
    });
  });
}
