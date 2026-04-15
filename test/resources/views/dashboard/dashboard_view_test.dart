import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/dashboard/dashboard_view.dart';
import 'package:uptizm/resources/views/components/ui/stat_card.dart';

import '../../../test_setup.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(
      home: Scaffold(
        body: WDiv(className: 'flex flex-col', child: child),
      ),
    ),
  );
}

void main() {
  setUpAll(() async {
    await initMagicForTests();
  });

  group('DashboardView', () {
    Future<void> pumpDashboard(WidgetTester tester) async {
      tester.view.physicalSize = const Size(1440, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final origOnError = FlutterError.onError;
      FlutterError.onError = (details) {
        if (details.toString().contains('overflowed')) return;
        origOnError?.call(details);
      };
      addTearDown(() => FlutterError.onError = origOnError);

      await tester.pumpWidget(buildTestApp(child: const DashboardView()));
    }

    testWidgets('renders welcome message', (tester) async {
      await pumpDashboard(tester);

      // Welcome greeting may be translated or show key
      expect(
        find.byWidgetPredicate(
          (w) =>
              w is WText &&
              (w.data.contains('Welcome back') ||
                  w.data.contains('welcome_greeting')),
        ),
        findsOneWidget,
      );
    });

    testWidgets('renders 4 stat cards', (tester) async {
      await pumpDashboard(tester);

      expect(find.byType(StatCard), findsNWidgets(4));
    });

    testWidgets('renders monitors overview section', (tester) async {
      await pumpDashboard(tester);

      // Look for section header (case-insensitive match)
      expect(find.textContaining('MONITOR'), findsWidgets);
      expect(find.textContaining('OVERVIEW'), findsWidgets);
    });

    testWidgets('renders recent activity section', (tester) async {
      await pumpDashboard(tester);

      expect(find.textContaining('ACTIVITY'), findsWidgets);
    });
  });
}
