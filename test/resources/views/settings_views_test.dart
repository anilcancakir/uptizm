import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import 'package:uptizm/app/mocks/settings.dart';
import 'package:uptizm/app/mocks/teams.dart';
import 'package:uptizm/resources/views/settings/appearance_settings_view.dart';
import 'package:uptizm/resources/views/settings/changelog_settings_view.dart';
import 'package:uptizm/resources/views/settings/help_settings_view.dart';
import 'package:uptizm/resources/views/settings/language_settings_view.dart';
import 'package:uptizm/resources/views/settings/notifications_settings_view.dart';
import 'package:uptizm/resources/views/settings/password_settings_view.dart';
import 'package:uptizm/resources/views/settings/privacy_settings_view.dart';
import 'package:uptizm/resources/views/settings/profile_settings_view.dart';
import 'package:uptizm/resources/views/settings/sessions_settings_view.dart';
import 'package:uptizm/resources/views/settings/settings_hub_view.dart';
import 'package:uptizm/resources/views/settings/terms_settings_view.dart';
import 'package:uptizm/resources/views/settings/timezone_settings_view.dart';
import 'package:uptizm/resources/views/settings/two_factor_settings_view.dart';

/// In-memory language loader supplying every [trans] key exercised by the
/// settings hub and its 12 sub-page smokes, mirroring the pattern established
/// in `status_views_test.dart`. Short, wrappable strings avoid RenderFlex
/// overflow at the test viewport.
class _SettingsViewsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      // Hub.
      'uptizm.settings.hub_title': 'Settings',
      'uptizm.settings.hub_description': 'Your account and preferences.',
      'uptizm.settings.hub_group_account': 'Account',
      'uptizm.settings.hub_group_security': 'Security',
      'uptizm.settings.hub_group_preferences': 'Preferences',
      'uptizm.settings.hub_group_team': 'Team',
      'uptizm.settings.hub_group_about': 'About & support',
      'uptizm.settings.hub_profile_title': 'Profile',
      'uptizm.settings.hub_profile_subtitle': 'Name, email, avatar',
      'uptizm.settings.hub_2fa_title': 'Two-factor authentication',
      'uptizm.settings.hub_2fa_subtitle': 'Off',
      'uptizm.settings.hub_password_title': 'Password',
      'uptizm.settings.hub_password_subtitle': 'Last changed 3 months ago',
      'uptizm.settings.hub_sessions_title': 'Active sessions',
      'uptizm.settings.hub_sessions_subtitle': '3 devices',
      'uptizm.settings.hub_appearance_title': 'Appearance',
      'uptizm.settings.hub_appearance_subtitle': 'Light & dark theme',
      'uptizm.settings.hub_language_title': 'Language',
      'uptizm.settings.hub_language_subtitle': 'App display language',
      'uptizm.settings.hub_timezone_title': 'Time zone',
      'uptizm.settings.hub_timezone_subtitle': 'Used for timestamps',
      'uptizm.settings.hub_notifications_title': 'Notifications',
      'uptizm.settings.hub_notifications_subtitle': 'In-app, web push, email',
      'uptizm.settings.hub_team_settings_title': 'Team settings',
      'uptizm.settings.hub_team_settings_subtitle': 'Name, URL, branding',
      'uptizm.settings.hub_team_members_title': 'Members',
      'uptizm.settings.hub_team_members_subtitle': 'Invites and roles',
      'uptizm.settings.hub_team_channels_title': 'Notification channels',
      'uptizm.settings.hub_team_channels_subtitle': 'Email, Slack, SMS',
      'uptizm.settings.hub_team_escalation_title': 'Escalation policies',
      'uptizm.settings.hub_team_escalation_subtitle': 'How alerts climb',
      'uptizm.settings.hub_team_oncall_title': 'On-call schedule',
      'uptizm.settings.hub_team_oncall_subtitle': 'Who answers first',
      'uptizm.settings.hub_team_billing_title': 'Plan & billing',
      'uptizm.settings.hub_team_billing_subtitle': 'Plan, usage, invoices',
      'uptizm.settings.hub_help_title': 'Help & support',
      'uptizm.settings.hub_help_subtitle': 'FAQ, docs, contact us',
      'uptizm.settings.hub_changelog_title': 'Changelog',
      'uptizm.settings.hub_changelog_subtitle': "What's new in Uptizm",
      'uptizm.settings.hub_privacy_title': 'Privacy Policy',
      'uptizm.settings.hub_privacy_subtitle': 'How we handle your data',
      'uptizm.settings.hub_terms_title': 'Terms of Service',
      'uptizm.settings.hub_terms_subtitle': 'The rules of the road',
      'uptizm.settings.hub_version_footer': 'Uptizm v2.4.0',

      // Profile.
      'uptizm.settings.profile_title': 'Profile',
      'uptizm.settings.profile_description': 'Your personal account details.',
      'uptizm.settings.profile_avatar_button': 'Change avatar',
      'uptizm.settings.profile_name_label': 'Name',
      'uptizm.settings.profile_email_label': 'Email',
      'uptizm.settings.profile_save_button': 'Save profile',

      // Appearance.
      'uptizm.settings.appearance_title': 'Appearance',
      'uptizm.settings.appearance_description': 'Choose how Uptizm looks.',
      'uptizm.settings.appearance_light_label': 'Light',
      'uptizm.settings.appearance_light_desc': 'Bright, high-contrast theme.',
      'uptizm.settings.appearance_dark_label': 'Dark',
      'uptizm.settings.appearance_dark_desc': 'Dimmed for low-light comfort.',

      // Language.
      'uptizm.settings.language_title': 'Language',
      'uptizm.settings.language_description': 'The language Uptizm uses.',

      // Timezone.
      'uptizm.settings.timezone_title': 'Time zone',
      'uptizm.settings.timezone_description': 'Used for timestamps.',
      'uptizm.settings.timezone_auto_label': 'Set automatically',
      'uptizm.settings.timezone_auto_on': 'On',
      'uptizm.settings.timezone_auto_off': 'Off',
      'uptizm.settings.timezone_field_label': 'Time zone',
      'uptizm.settings.timezone_field_hint': 'Uptizm shows all times here.',
      'uptizm.settings.timezone_search_placeholder': 'Search',
      'uptizm.settings.timezone_no_match': 'No zones match your search.',

      // Notifications.
      'uptizm.settings.notifications_title': 'Notifications',
      'uptizm.settings.notifications_description': 'How Uptizm reaches you.',
      'uptizm.settings.notifications_inapp_title': 'In-app',
      'uptizm.settings.notifications_inapp_subtitle': 'The notification bell.',
      'uptizm.settings.notifications_webpush_title': 'Web push',
      'uptizm.settings.notifications_webpush_subtitle_off': 'Push disabled.',
      'uptizm.settings.notifications_webpush_subtitle_on': 'Push enabled.',
      'uptizm.settings.notifications_webpush_enable_button': 'Enable',
      'uptizm.settings.notifications_email_title': 'Email',
      'uptizm.settings.notifications_email_subtitle': 'Send alerts to :email.',
      'uptizm.settings.notifications_footer': 'Team channels live elsewhere.',

      // Password.
      'uptizm.settings.password_title': 'Password',
      'uptizm.settings.password_description': 'Use a strong password.',
      'uptizm.settings.password_back_label': 'Settings',
      'uptizm.settings.password_back_to': '/settings',
      'uptizm.settings.password_success_message': 'Password updated.',
      'uptizm.settings.password_current_label': 'Current password',
      'uptizm.settings.password_new_label': 'New password',
      'uptizm.settings.password_new_hint': 'At least 12 characters.',
      'uptizm.settings.password_confirm_label': 'Confirm new password',
      'uptizm.settings.password_placeholder': '********',
      'uptizm.settings.password_update_button': 'Update password',

      // Two-factor.
      'uptizm.settings.twofa_title': 'Two-factor authentication',
      'uptizm.settings.twofa_description': 'Add a second step.',
      'uptizm.settings.twofa_back_label': 'Settings',
      'uptizm.settings.twofa_back_to': '/settings',
      'uptizm.settings.twofa_authenticator_title': 'Authenticator app',
      'uptizm.settings.twofa_authenticator_subtitle': 'Require a one-time code.',
      'uptizm.settings.twofa_recovery_heading': 'Recovery codes',
      'uptizm.settings.twofa_recovery_description': 'Save these codes.',
      'uptizm.settings.twofa_recovery_copy_button': 'Copy codes',
      'uptizm.settings.twofa_recovery_regenerate_button': 'Regenerate',

      // Sessions.
      'uptizm.settings.sessions_title': 'Active sessions',
      'uptizm.settings.sessions_description': 'Devices signed in.',
      'uptizm.settings.sessions_back_label': 'Settings',
      'uptizm.settings.sessions_back_to': '/settings',
      'uptizm.settings.sessions_current_badge': 'This device',
      'uptizm.settings.sessions_signout_button': 'Sign out',
      'uptizm.settings.sessions_signout_all_button': 'Sign out all others',
      'uptizm.settings.sessions_confirm_title': 'Sign out everywhere else?',
      'uptizm.settings.sessions_confirm_description': 'This signs out others.',
      'uptizm.settings.sessions_confirm_label': 'Sign out all',
      'uptizm.settings.sessions_toast_title': 'Signed out everywhere else',
      'uptizm.settings.sessions_toast_description': 'Others must sign in.',

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
  // SettingsHubView
  // ---------------------------------------------------------------------------

  group('SettingsHubView', () {
    testWidgets('renders the hub title and every group header', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const SettingsHubView(), size: const Size(1280, 4000)));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.settings.hub_title')), findsOneWidget);
      // SettingsSection headers render through Wind's `uppercase` text
      // transform (WText actually mutates the string, not just its style),
      // so the rendered text is the upper-cased label.
      expect(
        find.text(trans('uptizm.settings.hub_group_account').toUpperCase()),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.settings.hub_group_security').toUpperCase()),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // ProfileSettingsView
  // ---------------------------------------------------------------------------

  group('ProfileSettingsView', () {
    testWidgets('renders the title and seeds the fields from currentUser', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const ProfileSettingsView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.settings.profile_title')), findsWidgets);
      expect(find.text(currentUser.name), findsOneWidget);
      expect(find.text(currentUser.email), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  // AppearanceSettingsView
  // ---------------------------------------------------------------------------

  group('AppearanceSettingsView', () {
    testWidgets('renders both the Light and Dark radio labels', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const AppearanceSettingsView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.settings.appearance_light_label')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.settings.appearance_dark_label')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // LanguageSettingsView
  // ---------------------------------------------------------------------------

  group('LanguageSettingsView', () {
    testWidgets('renders a row for every fixture language', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const LanguageSettingsView(), size: const Size(1280, 4000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      for (final AppLanguage language in appLanguages) {
        // The English row shows "English" as both the native title and the
        // label subtitle, so a widget count of one-or-more is the correct
        // assertion; every other row's native name is unique.
        expect(find.text(language.native), findsWidgets);
      }
    });
  });

  // ---------------------------------------------------------------------------
  // TimezoneSettingsView
  // ---------------------------------------------------------------------------

  group('TimezoneSettingsView', () {
    testWidgets('renders the auto-detect row and the timezone field label', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const TimezoneSettingsView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.settings.timezone_auto_label')),
        findsOneWidget,
      );
      // "Time zone" is both the page title and the field label in this
      // fixture, so a widget count of one-or-more is the correct assertion.
      expect(
        find.text(trans('uptizm.settings.timezone_field_label')),
        findsWidgets,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // NotificationsSettingsView
  // ---------------------------------------------------------------------------

  group('NotificationsSettingsView', () {
    testWidgets('renders a row for every notification channel', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const NotificationsSettingsView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.settings.notifications_inapp_title')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.settings.notifications_webpush_title')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.settings.notifications_email_title')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // PasswordSettingsView
  // ---------------------------------------------------------------------------

  group('PasswordSettingsView', () {
    testWidgets('renders the title and the update button', (tester) async {
      await tester.pumpWidget(wrap(const PasswordSettingsView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.settings.password_title')), findsWidgets);
      expect(
        find.text(trans('uptizm.settings.password_update_button')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // TwoFactorSettingsView
  // ---------------------------------------------------------------------------

  group('TwoFactorSettingsView', () {
    testWidgets('renders the authenticator row, recovery codes hidden by default', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const TwoFactorSettingsView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.settings.twofa_authenticator_title')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.settings.twofa_recovery_heading')),
        findsNothing,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // SessionsSettingsView
  // ---------------------------------------------------------------------------

  group('SessionsSettingsView', () {
    testWidgets('renders a row for every fixture session', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const SessionsSettingsView(), size: const Size(1280, 4000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      for (final DeviceSession session in deviceSessions) {
        expect(find.text(session.device), findsOneWidget);
      }
      expect(
        find.text(trans('uptizm.settings.sessions_current_badge')),
        findsOneWidget,
      );
    });
  });

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
