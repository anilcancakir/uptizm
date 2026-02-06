import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/navigation/app_header.dart';

void main() {
  group('AppHeader', () {
    testWidgets('header should contain theme toggle button', (tester) async {
      final windTheme = WindThemeData();

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: windTheme,
            child: Scaffold(body: AppHeader()),
          ),
        ),
      );

      // Look for brightness_6_outlined icon which is used for theme toggle
      final themToggleFinder = find.byIcon(Icons.brightness_6_outlined);

      expect(
        themToggleFinder,
        findsOneWidget,
        reason:
            'AppHeader should contain theme toggle button with brightness icon',
      );
    });

    testWidgets('theme toggle button should be tappable', (tester) async {
      final windTheme = WindThemeData();

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: windTheme,
            child: Scaffold(body: AppHeader()),
          ),
        ),
      );

      // Find the theme toggle button
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
