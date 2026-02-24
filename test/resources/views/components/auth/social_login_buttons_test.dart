import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_social_auth/magic_social_auth.dart';
import 'package:magic_social_auth/src/ui/social_provider_icons.dart';

// ignore: deprecated_member_use_from_same_package
import 'package:uptizm/resources/views/components/auth/social_login_buttons.dart';

/// Mock driver for testing — supports all platforms.
class _MockDriver extends SocialDriver {
  _MockDriver(super.config);

  @override
  String get name => config['provider_name'] as String? ?? 'mock';

  @override
  Set<SocialPlatform> get supportedPlatforms => {
    SocialPlatform.ios,
    SocialPlatform.android,
    SocialPlatform.web,
    SocialPlatform.macos,
    SocialPlatform.windows,
    SocialPlatform.linux,
  };

  @override
  Future<SocialToken> getToken() async {
    return const SocialToken(provider: 'mock', accessToken: 'mock_token');
  }

  @override
  Future<void> signOut() async {}
}

void main() {
  setUp(() {
    MagicApp.reset();
    Config.flush();
    SocialProviderIcons.reset();

    // Register SocialAuthManager in the container.
    final manager = SocialAuthManager();
    MagicApp.instance.singleton('social_auth', () => manager);

    // Register mock drivers for all three providers.
    manager.extend('google', (config) => _MockDriver(config));
    manager.extend('microsoft', (config) => _MockDriver(config));
    manager.extend('github', (config) => _MockDriver(config));

    // Set config so the plugin widget renders all three.
    Config.set('social_auth.providers', {
      'google': {'enabled': true},
      'microsoft': {'enabled': true},
      'github': {'enabled': true},
    });
  });

  // ignore: deprecated_member_use_from_same_package
  group('SocialLoginButtons (deprecated — delegates to plugin)', () {
    testWidgets('renders 3 social provider buttons (WButton widgets)', (
      tester,
    ) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: Scaffold(
              // ignore: deprecated_member_use_from_same_package
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

      // Should find 3 WButton widgets (one for each provider).
      expect(find.byType(WButton), findsNWidgets(3));
    });

    testWidgets('renders divider with WDiv structure', (tester) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: Scaffold(
              // ignore: deprecated_member_use_from_same_package
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

      // Should find multiple WDiv (container + button internals).
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
              // ignore: deprecated_member_use_from_same_package
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

      // Find all WButton widgets.
      final buttons = find.byType(WButton);
      expect(buttons, findsNWidgets(3));

      // Tap first button (Google — order 1 from built-in defaults).
      await tester.tap(buttons.at(0));
      await tester.pump();
      expect(googleTapped, true);

      // Tap second button (Microsoft — order 2).
      await tester.tap(buttons.at(1));
      await tester.pump();
      expect(microsoftTapped, true);

      // Tap third button (GitHub — order 3).
      await tester.tap(buttons.at(2));
      await tester.pump();
      expect(githubTapped, true);
    });
  });
}
