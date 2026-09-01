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
