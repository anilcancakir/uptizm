import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:uptizm/app/providers/app_service_provider.dart';
import 'package:uptizm/ui/components/notification_center/index.dart';
import 'package:uptizm/ui/components/push_prompt/index.dart';
import 'package:uptizm/ui/layouts/mobile_top_bar.dart';
import 'package:uptizm/ui/layouts/sidebar.dart';

import '../../support/bundled_lang.dart';

/// Feeds [trans] the app's shipped English catalogue, so the shells render the
/// copy an operator reads rather than raw keys at the wrong width.
class _BundledLangLoader implements TranslationLoader {
  const _BundledLangLoader();

  @override
  Future<Map<String, dynamic>> load(Locale requested) async =>
      readBundledLang('en');
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(const _BundledLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    Notify.view.clear();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: widget),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Both bells, because they are two classes and a grep for one misses the other
  // ---------------------------------------------------------------------------

  group('the shells mount the package dropdown', () {
    testWidgets('the desktop sidebar renders exactly one', (tester) async {
      tester.view.devicePixelRatio = 1.0;
      tester.view.physicalSize = const Size(1200, 900);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(wrap(const Sidebar(currentPath: '/')));
      await tester.pump();

      expect(find.byType(NotificationDropdown), findsOneWidget);
    });

    testWidgets('the mobile top bar renders exactly one', (tester) async {
      // Its own case rather than a loop: `_NotificationBell` and `_MobileBell`
      // are two classes, the shell swaps trees at `lg`, and remounting one
      // leaves the app rendering two different notification UIs by width.
      tester.view.devicePixelRatio = 1.0;
      tester.view.physicalSize = const Size(390, 844);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(wrap(const MobileTopBar()));
      await tester.pump();

      expect(find.byType(NotificationDropdown), findsOneWidget);
    });

    /// Asserts the mounted dropdown wears uptizm's tokens, not the package's.
    ///
    /// On the widget's parameters rather than on rendered pixels, because the
    /// override IS the contract: `NotificationDropdown` composes these strings
    /// itself, and each one REPLACES its default outright, so a shell that
    /// passes nothing ships Wind's own palette (`bg-white`, `text-gray-500`,
    /// `bg-red-500`) next to controls written in semantic aliases.
    void expectAppTokens(WidgetTester tester) {
      final NotificationDropdown bell = tester.widget<NotificationDropdown>(
        find.byType(NotificationDropdown),
      );

      expect(bell.triggerClassName, kNotificationBellTriggerClassName);
      expect(bell.triggerIconClassName, kNotificationBellTriggerIconClassName);
      expect(bell.panelClassName, kNotificationBellPanelClassName);
      expect(bell.badgeClassName, kNotificationBellBadgeClassName);
      expect(bell.badgeTextClassName, kNotificationBellBadgeTextClassName);

      // The palette half specifically, so a future edit that keeps the
      // constants wired but empties them of app tokens still turns this red.
      expect(bell.panelClassName, contains('bg-surface-container'));
      expect(bell.badgeClassName, contains('bg-down'));
      expect(bell.badgeTextClassName, contains('text-on-destructive'));
      expect(bell.triggerIconClassName, contains('text-fg-muted'));

      // Both trigger states, because the widget computes them and hands them
      // down as Wind states: this string is the only place they can be given a
      // tone, and an override that carries neither ships a bell that looks
      // identical whether its panel is open or shut. `active:` is the one that
      // matters on a touch device, where there is no hover to fall back on.
      expect(bell.triggerClassName, contains('hover:bg-surface-container'));
      expect(bell.triggerClassName, contains('active:bg-surface-container'));
    }

    testWidgets('the desktop sidebar dresses the bell in the app tokens', (
      tester,
    ) async {
      tester.view.devicePixelRatio = 1.0;
      tester.view.physicalSize = const Size(1200, 900);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(wrap(const Sidebar(currentPath: '/')));
      await tester.pump();

      expectAppTokens(tester);
    });

    testWidgets('the mobile top bar dresses the bell in the app tokens', (
      tester,
    ) async {
      // Its own case for the reason the mount cases above are two: the shells
      // are two classes, and dressing one is not dressing the other.
      tester.view.devicePixelRatio = 1.0;
      tester.view.physicalSize = const Size(390, 844);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(wrap(const MobileTopBar()));
      await tester.pump();

      expectAppTokens(tester);
    });
  });

  // ---------------------------------------------------------------------------
  // The slot: uptizm's dot inside the package's row
  // ---------------------------------------------------------------------------

  group('the registered notification surface', () {
    /// Resolves the type icon the package would render for [type].
    Future<Widget?> resolveTypeIcon(WidgetTester tester, String type) async {
      Widget? resolved;

      await tester.pumpWidget(
        wrap(
          Builder(
            builder: (context) {
              resolved = Notify.view.buildTypeIcon(type, context);

              return const SizedBox.shrink();
            },
          ),
        ),
      );

      return resolved;
    }

    testWidgets('answers a known event type with uptizm\'s own dot', (
      tester,
    ) async {
      AppServiceProvider.registerNotificationSurface();

      final Widget? icon = await resolveTypeIcon(tester, 'incident_resolved');

      expect(icon, isA<NotificationCenter>());
      expect(
        (icon! as NotificationCenter).kind,
        AppNotificationKind.resolved,
      );
    });

    testWidgets('answers an unknown event type through the fallback slot', (
      tester,
    ) async {
      // A type this build has never seen must still get a dot: the package
      // consults the `default` slot before its own neutral bell.
      AppServiceProvider.registerNotificationSurface();

      final Widget? icon = await resolveTypeIcon(tester, 'some_future_event');

      expect(icon, isA<NotificationCenter>());
    });

    testWidgets('mounts the push prompt on the preference screen', (
      tester,
    ) async {
      AppServiceProvider.registerNotificationSurface();

      expect(Notify.view.hasSlot('notifications.preferences', 'header'), isTrue);

      Widget? header;
      await tester.pumpWidget(
        wrap(
          Builder(
            builder: (context) {
              header = Notify.view.buildSlot(
                'notifications.preferences',
                'header',
                context,
              );

              return const SizedBox.shrink();
            },
          ),
        ),
      );

      expect(header, isA<PushPromptHost>());
    });
  });
}
