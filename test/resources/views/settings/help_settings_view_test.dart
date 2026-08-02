import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/resources/views/settings/help_settings_view.dart';

/// Recording [LaunchAdapter]: captures the URL a contact button hands to
/// `Launch.url` instead of asking the platform to open it. Mirrors the
/// pattern established in `test/ui/layouts/uptizm_hub_extras_test.dart`.
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

/// In-memory language loader supplying every [trans] key
/// [HelpSettingsView] renders.
class _HelpSettingsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.settings.help_title': 'Help & support',
      'uptizm.settings.help_description': 'Answers, guides, and contact.',
      'uptizm.settings.hub_title': 'Settings',
      'uptizm.settings.help_contact_heading': 'Contact support',
      'uptizm.settings.help_contact_note': 'We usually reply quickly.',
      'uptizm.settings.help_contact_email_button': 'Email support',
    };
  }
}

void main() {
  late _RecordingLaunchAdapter adapter;

  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so MSSettingsScaffold / MSCard / MSButton
    // resolve their recipes without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());

    // Bind the launch service over a recording adapter: the contact buttons
    // open the website rather than a route, so the assertion is on the URL
    // they launch, not merely that a tap happened.
    adapter = _RecordingLaunchAdapter();
    Magic.singleton('launch', () => LaunchService(adapter: adapter));

    Config.set('app.web_url', 'https://uptizm.com');
    Config.set('localization.locale', 'en');
    Config.set('localization.supported_locales', ['en', 'tr']);

    Translator.instance.setLoader(_HelpSettingsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() async {
    await Translator.instance.setLocale(const Locale('en'));
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme], mirroring
  /// the harness established in `uptizm_hub_extras_test.dart`.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  group('HelpSettingsView', () {
    testWidgets('renders no dead controls: every tap launches a URL', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const HelpSettingsView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text('Contact support'),
        findsOneWidget,
        reason: 'the contact card heading renders',
      );
      expect(find.text('Email support'), findsOneWidget);
      expect(
        find.text('Start a chat'),
        findsNothing,
        reason:
            'the chat button opened the same contact page as its neighbour, '
            'and no chat channel exists in the product to start',
      );
    });

    testWidgets('the FAQ accordion and its hardcoded entries are gone', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const HelpSettingsView()));
      await tester.pump();

      expect(
        find.text('Frequently asked'),
        findsNothing,
        reason: 'the FAQ heading no longer renders',
      );
      expect(
        find.text('How does Uptizm detect incidents?'),
        findsNothing,
        reason: 'the hardcoded FAQ questions no longer render',
      );
    });

    testWidgets('tapping "Email support" opens the locale-correct contact URL', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const HelpSettingsView()));
      await tester.pump();

      await tester.tap(find.text('Email support'));
      await tester.pump();

      expect(
        adapter.launched.map((Uri url) => url.toString()).toList(),
        ['https://uptizm.com/contact'],
      );
    });

    testWidgets('the card offers exactly one way out to the website', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const HelpSettingsView()));
      await tester.pump();

      expect(
        find.byType(MSButton),
        findsOneWidget,
        reason:
            'two buttons wired to one URL read as two support channels; the '
            'card offers one',
      );
    });

    testWidgets('the contact URL carries the active locale', (tester) async {
      await Translator.instance.setLocale(const Locale('tr'));
      addTearDown(() => Translator.instance.setLocale(const Locale('en')));

      await tester.pumpWidget(wrap(const HelpSettingsView()));
      await tester.pump();

      await tester.tap(find.text('Email support'));
      await tester.pump();

      expect(adapter.launched.single.toString(), 'https://uptizm.com/tr/contact');
    });
  });
}
