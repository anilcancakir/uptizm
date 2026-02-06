import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/dashboard/dashboard_view.dart';
import 'package:uptizm/resources/views/components/dashboard/stat_card.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('DashboardView', () {
    testWidgets('renders welcome message', (tester) async {
      await tester.pumpWidget(buildTestApp(child: const DashboardView()));

      // Welcome greeting may be translated or show key
      expect(
        find.byWidgetPredicate((w) =>
            w is WText &&
            (w.data.contains('Welcome back') ||
                w.data.contains('welcome_greeting'))),
        findsOneWidget,
      );
    });

    testWidgets('renders 4 stat cards', (tester) async {
      await tester.pumpWidget(buildTestApp(child: const DashboardView()));

      expect(find.byType(StatCard), findsNWidgets(4));
    });

    testWidgets('renders monitors overview section', (tester) async {
      await tester.pumpWidget(buildTestApp(child: const DashboardView()));

      // Look for section header (case-insensitive match)
      expect(find.textContaining('MONITOR'), findsWidgets);
      expect(find.textContaining('OVERVIEW'), findsWidgets);
    });

    testWidgets('renders recent activity section', (tester) async {
      await tester.pumpWidget(buildTestApp(child: const DashboardView()));

      expect(find.textContaining('ACTIVITY'), findsWidgets);
    });
  });
}
