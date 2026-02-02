import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/resources/views/components/auth/social_login_buttons.dart';

void main() {
  group('SocialLoginButtons', () {
    testWidgets('renders 3 social provider buttons (WButton widgets)', (tester) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: Scaffold(
              body: SocialLoginButtons(
                loadingProvider: null,
                onGoogle: () async {},
                onMicrosoft: () async {},
                onGithub: () async {},
              ),
            ),
          ),
        ),
      );

      // Should find 3 WButton widgets (one for each provider)
      expect(find.byType(WButton), findsNWidgets(3));
    });

    testWidgets('renders divider with WDiv structure', (tester) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: Scaffold(
              body: SocialLoginButtons(
                loadingProvider: null,
                onGoogle: () async {},
                onMicrosoft: () async {},
                onGithub: () async {},
              ),
            ),
          ),
        ),
      );

      // Should find multiple WDiv (divider structure + button containers)
      expect(find.byType(WDiv), findsWidgets);
    });

    testWidgets('fires callbacks when buttons tapped', (tester) async {
      bool googleTapped = false;
      bool microsoftTapped = false;
      bool githubTapped = false;

      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: Scaffold(
              body: SocialLoginButtons(
                loadingProvider: null,
                onGoogle: () async => googleTapped = true,
                onMicrosoft: () async => microsoftTapped = true,
                onGithub: () async => githubTapped = true,
              ),
            ),
          ),
        ),
      );

      // Find all WButton widgets
      final buttons = find.byType(WButton);
      expect(buttons, findsNWidgets(3));

      // Tap first button (Google)
      await tester.tap(buttons.at(0));
      await tester.pump();
      expect(googleTapped, true);

      // Tap second button (Microsoft)
      await tester.tap(buttons.at(1));
      await tester.pump();
      expect(microsoftTapped, true);

      // Tap third button (GitHub)
      await tester.tap(buttons.at(2));
      await tester.pump();
      expect(githubTapped, true);
    });
  });
}
