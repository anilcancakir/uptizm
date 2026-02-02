import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/resources/views/components/theme_toggle_button.dart';

void main() {
  group('ThemeToggleButton', () {
    testWidgets('renders brightness icon', (tester) async {
      final windTheme = WindThemeData();

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: windTheme,
            child: Scaffold(
              body: ThemeToggleButton(),
            ),
          ),
        ),
      );

      // Should contain brightness_6_outlined icon
      expect(find.byIcon(Icons.brightness_6_outlined), findsOneWidget);
    });

    testWidgets('button is tappable', (tester) async {
      final windTheme = WindThemeData();

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: windTheme,
            child: Scaffold(
              body: ThemeToggleButton(),
            ),
          ),
        ),
      );

      final iconFinder = find.byIcon(Icons.brightness_6_outlined);
      expect(iconFinder, findsOneWidget);

      // Verify it's in a tappable widget
      final button = find.ancestor(
        of: iconFinder,
        matching: find.byType(GestureDetector),
      );

      expect(
        button,
        findsAtLeastNWidgets(1),
        reason: 'Theme toggle should be tappable',
      );
    });
  });
}
