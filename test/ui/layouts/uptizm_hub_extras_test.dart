import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/ui/layouts/uptizm_hub_extras.dart';

/// Recording [LaunchAdapter]: captures the URL an external row hands to
/// `Launch.url` instead of asking the platform to open it.
class _RecordingLaunchAdapter implements LaunchAdapter {
  /// Every URL launched during the test, in order.
  final List<Uri> launched = [];

  @override
  Future<bool> launch(
    Uri url, {
    LaunchMode mode = LaunchMode.externalApplication,
  }) async {
    launched.add(url);
    return true;
  }

  @override
  Future<bool> canLaunch(Uri url) async => true;
}

void main() {
  late _RecordingLaunchAdapter adapter;

  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so MSSettingsSection / MSSettingsNavRow /
    // MSSettingsRow resolve their recipes the same way `settings_views_test.dart`
    // does.
    Magic.singleton('magic_starter', () => MagicStarterManager());

    // Bind the launch service over a recording adapter: the three legal /
    // contact rows open the website rather than navigating, so the assertion
    // is on the URL they launch.
    adapter = _RecordingLaunchAdapter();
    Magic.singleton('launch', () => LaunchService(adapter: adapter));

    Config.set('app.web_url', 'https://uptizm.com');
    Config.set('localization.locale', 'en');
    Config.set('localization.supported_locales', ['en', 'tr']);
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme], mirroring
  /// the harness established in `app_layout_test.dart`.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  group('UptizmHubExtras', () {
    testWidgets('renders the Team and About & support sections', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const UptizmHubExtras()));
      await tester.pump();

      // trans() returns the raw key when no lang file is loaded (test env),
      // matching the convention in `app_layout_test.dart`. The section header
      // caption applies an uppercase text transform (see
      // `settingsSectionCaptionRecipe`), so the rendered text is upper-cased.
      expect(find.text('UPTIZM.SETTINGS.HUB_GROUP_TEAM'), findsOneWidget);
      expect(find.text('UPTIZM.SETTINGS.HUB_GROUP_ABOUT'), findsOneWidget);
    });

    testWidgets('renders all 4 Team nav rows and all 5 About rows', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const UptizmHubExtras()));
      await tester.pump();

      expect(
        find.text('uptizm.settings.hub_team_channels_title'),
        findsOneWidget,
      );
      expect(
        find.text('uptizm.settings.hub_team_escalation_title'),
        findsOneWidget,
      );
      expect(
        find.text('uptizm.settings.hub_team_oncall_title'),
        findsOneWidget,
      );
      expect(
        find.text('uptizm.settings.hub_team_billing_title'),
        findsOneWidget,
      );

      expect(find.text('uptizm.settings.hub_help_title'), findsOneWidget);
      expect(
        find.text('uptizm.settings.hub_changelog_title'),
        findsOneWidget,
      );
      expect(find.text('uptizm.settings.hub_contact_title'), findsOneWidget);
      expect(find.text('uptizm.settings.hub_privacy_title'), findsOneWidget);
      expect(find.text('uptizm.settings.hub_terms_title'), findsOneWidget);
    });

    testWidgets('the legal and contact rows open the website, not a route', (
      tester,
    ) async {
      // A tall surface: the About rows sit below the default 600px viewport and
      // `tap()` refuses an off-screen hit.
      await tester.binding.setSurfaceSize(const Size(1280, 2000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const UptizmHubExtras()));
      await tester.pump();

      for (final String key in [
        'uptizm.settings.hub_contact_title',
        'uptizm.settings.hub_privacy_title',
        'uptizm.settings.hub_terms_title',
      ]) {
        await tester.tap(find.text(key));
        await tester.pump();
      }

      // No MagicRouter is bound in this harness, so an in-app navigation would
      // have thrown here instead of launching a URL.
      expect(tester.takeException(), isNull);
      expect(
        adapter.launched.map((Uri url) => url.toString()).toList(),
        [
          'https://uptizm.com/contact',
          'https://uptizm.com/privacy',
          'https://uptizm.com/terms',
        ],
      );
    });

    testWidgets('the rows carry the active locale into the URL', (
      tester,
    ) async {
      await Translator.instance.setLocale(const Locale('tr'));
      addTearDown(() => Translator.instance.setLocale(const Locale('en')));
      await tester.binding.setSurfaceSize(const Size(1280, 2000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const UptizmHubExtras()));
      await tester.pump();

      await tester.tap(find.text('uptizm.settings.hub_terms_title'));
      await tester.pump();

      expect(adapter.launched.single.toString(), 'https://uptizm.com/tr/terms');
    });
  });
}
