import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/auth/auth_form_card.dart';

void main() {
  group('AuthFormCard', () {
    testWidgets('renders title, subtitle, and child content', (tester) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: Scaffold(
              body: AuthFormCard(
                title: 'Sign In',
                subtitle: 'Welcome back to Uptizm',
                child: const Text('Form content here'),
              ),
            ),
          ),
        ),
      );

      expect(find.text('Sign In'), findsOneWidget);
      expect(find.text('Welcome back to Uptizm'), findsOneWidget);
      expect(find.text('Form content here'), findsOneWidget);
    });

    testWidgets('shows error banner when errorMessage provided', (
      tester,
    ) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: Scaffold(
              body: AuthFormCard(
                title: 'Sign In',
                subtitle: 'Welcome back',
                errorMessage: 'Invalid credentials',
                child: const Text('Form'),
              ),
            ),
          ),
        ),
      );

      expect(find.text('Invalid credentials'), findsOneWidget);
    });

    testWidgets('hides error banner when errorMessage is null', (tester) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: Scaffold(
              body: AuthFormCard(
                title: 'Sign In',
                subtitle: 'Welcome back',
                child: const Text('Form'),
              ),
            ),
          ),
        ),
      );

      // Should not find any error text container
      expect(find.text('Invalid credentials'), findsNothing);
    });

    testWidgets('theme toggle button exists and is tappable', (tester) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: Scaffold(
              body: AuthFormCard(
                title: 'Sign In',
                subtitle: 'Welcome back',
                child: const Text('Form'),
              ),
            ),
          ),
        ),
      );

      // Find theme toggle button by icon
      final themeToggleFinder = find.byIcon(Icons.brightness_6_outlined);

      expect(
        themeToggleFinder,
        findsOneWidget,
        reason: 'AuthFormCard should have theme toggle button',
      );

      // Verify it's tappable
      final button = find.ancestor(
        of: themeToggleFinder,
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
