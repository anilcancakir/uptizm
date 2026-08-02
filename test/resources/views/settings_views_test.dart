import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/resources/views/settings/changelog_settings_view.dart';

/// In-memory language loader supplying every [trans] key exercised by the
/// About & support settings sub-pages (help and changelog). The
/// account/security/preferences sub-pages moved to magic_starter and the legal
/// documents moved to the website, so their keys are gone. Short, wrappable
/// strings avoid RenderFlex overflow at the test viewport.
class _SettingsViewsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      // Help.
      'uptizm.settings.help_title': 'Help & support',
      'uptizm.settings.help_description': 'Answers, guides, and contact.',
      'uptizm.settings.help_contact_heading': 'Contact support',
      'uptizm.settings.help_contact_note': 'We usually reply quickly.',
      'uptizm.settings.help_contact_email_button': 'Email support',
      'uptizm.settings.help_contact_chat_button': 'Start a chat',

      // Changelog.
      'uptizm.settings.changelog_title': 'Changelog',
      'uptizm.settings.changelog_description': "What's new in Uptizm.",
      'uptizm.settings.changelog_empty_title': 'No releases yet',
      'uptizm.settings.changelog_empty_description':
          'We have not published a release history yet.',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / SettingsScaffold / Button /
    // Input / Switch / Badge resolve their themes via MagicStarter.* without
    // a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());

    Translator.instance.setLoader(_SettingsViewsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] under a
  /// configurable [MediaQuery] size, mirroring the harness established in
  /// `status_views_test.dart`.
  Widget wrap(Widget widget, {Size size = const Size(1280, 3200)}) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // HelpSettingsView
  // ---------------------------------------------------------------------------

  // The Help view's own coverage moved to
  // test/resources/views/settings/help_settings_view_test.dart, which asserts
  // the URL handed to the launcher. Nothing is asserted here any more: the
  // in-app FAQ accordion this group covered was deleted deliberately, because
  // the web FAQ derives its figures from config while the Dart copy was typed
  // by hand and had already started contradicting the plan limits.

  // ---------------------------------------------------------------------------
  // ChangelogSettingsView
  // ---------------------------------------------------------------------------

  group('ChangelogSettingsView', () {
    testWidgets('renders the honest empty state instead of a release list', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const ChangelogSettingsView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.settings.changelog_empty_title')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.settings.changelog_empty_description')),
        findsOneWidget,
      );
    });
  });
}
