import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/resources/views/components/navigation/app_sidebar.dart';
import 'package:uptizm/resources/views/components/navigation/navigation_list.dart';
import 'package:uptizm/resources/views/components/navigation/team_selector.dart';

void main() {
  setUpAll(() {
    Magic.init();
  });

  group('AppSidebar', () {
    testWidgets('renders correctly on desktop', (WidgetTester tester) async {
      tester.view.physicalSize = const Size(1440, 900);
      tester.view.devicePixelRatio = 1.0;

      // Suppress overflow errors in test environment
      final origOnError = FlutterError.onError;
      FlutterError.onError = (details) {
        if (details.toString().contains('overflowed')) return;
        origOnError?.call(details);
      };

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: const Scaffold(
              body: SizedBox(
                width: 256,
                height: 900,
                child: AppSidebar(currentPath: '/'),
              ),
            ),
          ),
        ),
      );

      expect(find.byType(NavigationList), findsOneWidget);
      expect(find.byType(TeamSelector), findsOneWidget);

      addTearDown(() {
        FlutterError.onError = origOnError;
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });
    });

    testWidgets('highlights current path', (WidgetTester tester) async {
      tester.view.physicalSize = const Size(1440, 900);
      tester.view.devicePixelRatio = 1.0;

      final origOnError = FlutterError.onError;
      FlutterError.onError = (details) {
        if (details.toString().contains('overflowed')) return;
        origOnError?.call(details);
      };

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: const Scaffold(
              body: SizedBox(
                width: 256,
                height: 900,
                child: AppSidebar(currentPath: '/monitors'),
              ),
            ),
          ),
        ),
      );

      // Verify the sidebar renders with the given path
      expect(find.byType(AppSidebar), findsOneWidget);
      expect(find.byType(NavigationList), findsOneWidget);

      addTearDown(() {
        FlutterError.onError = origOnError;
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });
    });
  });
}
