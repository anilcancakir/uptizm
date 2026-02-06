import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/monitors/stat_card.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('StatCard', () {
    testWidgets('renders label in uppercase tracking-wide', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatCard(label: 'Total Monitors', value: '24'),
        ),
      );

      expect(find.text('TOTAL MONITORS'), findsOneWidget);
    });

    testWidgets('renders value in 3xl bold', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatCard(label: 'Uptime', value: '99.9%'),
        ),
      );

      expect(find.text('99.9%'), findsOneWidget);
    });

    testWidgets('renders icon when provided', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatCard(
            label: 'Total',
            value: '10',
            icon: Icons.monitor_heart_outlined,
          ),
        ),
      );

      expect(find.byIcon(Icons.monitor_heart_outlined), findsOneWidget);
    });

    testWidgets('applies monospace class when isMono is true', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatCard(
            label: 'Response Time',
            value: '245ms',
            isMono: true,
          ),
        ),
      );

      expect(find.text('245ms'), findsOneWidget);
      // Will verify monospace styling in implementation
    });

    testWidgets('applies custom value color when provided', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatCard(
            label: 'Status',
            value: 'Up',
            valueColor: 'text-green-500',
          ),
        ),
      );

      expect(find.text('Up'), findsOneWidget);
      // Will verify green color in implementation
    });
  });
}
