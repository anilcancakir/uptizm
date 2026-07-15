import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/support/settings_types.dart'
    show ChangelogRelease, LegalSection;
import 'package:uptizm/app/mocks/settings.dart';
import 'package:uptizm/resources/views/settings/changelog_settings_view.dart';
import 'package:uptizm/resources/views/settings/help_settings_view.dart';
import 'package:uptizm/resources/views/settings/privacy_settings_view.dart';
import 'package:uptizm/resources/views/settings/terms_settings_view.dart';

/// In-memory language loader supplying every [trans] key exercised by the
/// About & support settings sub-pages (help, changelog, and the two legal
/// docs). The account/security/preferences sub-pages moved to magic_starter,
/// so their keys are gone. Short, wrappable strings avoid RenderFlex overflow
/// at the test viewport.
class _SettingsViewsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      // Help.
      'uptizm.settings.help_title': 'Help & support',
      'uptizm.settings.help_description': 'Answers, guides, and contact.',
      'uptizm.settings.help_search_placeholder': 'Search help',
      'uptizm.settings.help_faq_heading': 'Frequently asked',
      'uptizm.settings.help_contact_heading': 'Contact support',
      'uptizm.settings.help_contact_note': 'We usually reply quickly.',
      'uptizm.settings.help_contact_email_button': 'Email support',
      'uptizm.settings.help_contact_chat_button': 'Start a chat',
      'uptizm.settings.help_link_docs_label': 'Documentation',
      'uptizm.settings.help_link_docs_url': 'uptizm.com/docs',
      'uptizm.settings.help_link_api_label': 'API reference',
      'uptizm.settings.help_link_api_url': 'uptizm.com/api',
      'uptizm.settings.help_link_community_label': 'Community',
      'uptizm.settings.help_link_community_url': 'community.uptizm.com',

      // Changelog.
      'uptizm.settings.changelog_title': 'Changelog',
      'uptizm.settings.changelog_description': "What's new in Uptizm.",
      'uptizm.settings.changelog_version_label': 'Version :version',

      // Privacy.
      'uptizm.settings.privacy_title': 'Privacy Policy',
      'uptizm.settings.privacy_updated': 'Jun 1, 2026',

      // Terms.
      'uptizm.settings.terms_title': 'Terms of Service',
      'uptizm.settings.terms_updated': 'Jun 1, 2026',
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

  group('HelpSettingsView', () {
    testWidgets('renders the FAQ heading and the first question by default', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const HelpSettingsView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.settings.help_faq_heading')),
        findsOneWidget,
      );
      expect(find.text(faqItems.first.question), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  // ChangelogSettingsView
  // ---------------------------------------------------------------------------

  group('ChangelogSettingsView', () {
    testWidgets('renders a card for every fixture release', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const ChangelogSettingsView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      for (final ChangelogRelease release in changelog) {
        expect(find.text(release.date), findsOneWidget);
      }
    });
  });

  // ---------------------------------------------------------------------------
  // PrivacySettingsView
  // ---------------------------------------------------------------------------

  group('PrivacySettingsView', () {
    testWidgets('renders the title and every fixture section heading', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const PrivacySettingsView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.settings.privacy_title')), findsOneWidget);
      for (final LegalSection section in privacySections) {
        expect(find.text(section.heading), findsOneWidget);
      }
    });
  });

  // ---------------------------------------------------------------------------
  // TermsSettingsView
  // ---------------------------------------------------------------------------

  group('TermsSettingsView', () {
    testWidgets('renders the title and every fixture section heading', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const TermsSettingsView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.settings.terms_title')), findsOneWidget);
      for (final LegalSection section in termsSections) {
        expect(find.text(section.heading), findsOneWidget);
      }
    });
  });
}
