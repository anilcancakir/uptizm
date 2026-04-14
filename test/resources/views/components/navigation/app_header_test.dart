import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/navigation/app_header.dart';

import '../../../../test_setup.dart';

void main() {
  setUpAll(() async {
    await initMagicForTests();
  });
  group('AppHeader', () {
    Future<void> pumpHeader(WidgetTester tester) async {
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

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(body: AppHeader()),
          ),
        ),
      );
    }

    testWidgets('header should contain theme toggle button', (tester) async {
      await pumpHeader(tester);

      final themToggleFinder = find.byIcon(Icons.brightness_6_outlined);

      expect(
        themToggleFinder,
        findsOneWidget,
        reason:
            'AppHeader should contain theme toggle button with brightness icon',
      );
    });

    testWidgets('theme toggle button should be tappable', (tester) async {
      await pumpHeader(tester);

      final themeToggleFinder = find.byIcon(Icons.brightness_6_outlined);

      expect(themeToggleFinder, findsOneWidget);

      // Verify it's in a tappable widget hierarchy
      final button = find.ancestor(
        of: themeToggleFinder,
        matching: find.byType(GestureDetector),
      );

      expect(
        button,
        findsAtLeastNWidgets(1),
        reason: 'Theme toggle icon should be inside a tappable widget',
      );
    });
  });
}
