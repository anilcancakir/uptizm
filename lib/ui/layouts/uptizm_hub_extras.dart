import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../app/support/web_links.dart';

/// Uptizm's injected settings-hub extras.
///
/// Rendered into the starter settings hub's `settings.hub.footer` slot (wired in
/// [AppServiceProvider.boot]). The starter owns the Account / Security /
/// Preferences groups; this widget appends the two uptizm-domain groups that the
/// deleted `settings_hub_view.dart` used to carry:
///
/// 1. **Team** --- team-ops sub-pages (`/teams/notifications`, `/teams/escalation`,
///    `/teams/on-call`, `/teams/billing`).
/// 2. **About & support** --- the in-app static pages (`/settings/help`,
///    `/settings/changelog`) plus the three documents the WEBSITE owns
///    (Contact, Privacy, Terms), opened externally through [WebLinks].
///
/// The legal rows used to drill into in-app copies of Privacy and Terms. Those
/// views are gone: the website serves the real documents, so there is exactly
/// one text of each and the version a user reads is the version that governs.
/// The three external rows are [MSSettingsRow] (a plain tappable row with a
/// trailing slot) rather than [MSSettingsNavRow], because a drill chevron would
/// promise an in-app screen; they carry an open-in-new icon instead.
///
/// It is composed from the same starter components the hub itself uses
/// ([MSSettingsSection] + [MSSettingsNavRow] + [MSSettingsRow]), so the injected
/// groups are visually indistinguishable from the native ones. All
/// titles/subtitles come from the existing `uptizm.settings.hub_*` i18n keys.
@immutable
class UptizmHubExtras extends StatelessWidget {
  /// Creates the [UptizmHubExtras] slot content.
  const UptizmHubExtras({super.key});

  // Icons referenced in build() are hoisted to static fields so Flutter web
  // tree-shaking keeps them (const tear-offs inside build() get dropped), matching
  // the starter hub's own icon-hoisting convention.
  static const _iconChannels = Icons.notifications_outlined;
  static const _iconEscalation = Icons.trending_up_outlined;
  static const _iconOnCall = Icons.phone_in_talk_outlined;
  static const _iconBilling = Icons.credit_card_outlined;
  static const _iconHelp = Icons.help_outline;
  static const _iconChangelog = Icons.article_outlined;
  static const _iconContact = Icons.mail_outline;
  static const _iconPrivacy = Icons.privacy_tip_outlined;
  static const _iconTerms = Icons.description_outlined;
  static const _iconExternal = Icons.open_in_new;

  /// Builds a row that opens [url] in the browser instead of navigating.
  ///
  /// [Launch] never throws and returns `false` on failure (it also refuses an
  /// empty URL outright), so a misconfigured `WEB_URL` degrades to a row that
  /// does nothing rather than to an exception during a tap.
  Widget _externalRow({
    required IconData icon,
    required String title,
    required String subtitle,
    required String url,
  }) {
    return MSSettingsRow(
      icon: icon,
      title: title,
      subtitle: subtitle,
      trailing: const WIcon(_iconExternal, className: 'text-fg-muted'),
      onTap: () => Launch.url(url),
    );
  }

  @override
  Widget build(BuildContext context) {
    // Mirror the scaffold's `flex flex-col gap-6` children area so the injected
    // Team + About sections carry the same inter-section spacing as the native
    // Account / Security / Preferences groups above them.
    return WDiv(
      className: 'flex flex-col gap-6',
      children: [
        // 1. Team: uptizm team-ops sub-pages.
        MSSettingsSection(
          header: trans('uptizm.settings.hub_group_team'),
          children: [
            MSSettingsNavRow(
              icon: _iconChannels,
              title: trans('uptizm.settings.hub_team_channels_title'),
              subtitle: trans('uptizm.settings.hub_team_channels_subtitle'),
              to: '/teams/notifications',
            ),
            MSSettingsNavRow(
              icon: _iconEscalation,
              title: trans('uptizm.settings.hub_team_escalation_title'),
              subtitle: trans('uptizm.settings.hub_team_escalation_subtitle'),
              to: '/teams/escalation',
            ),
            MSSettingsNavRow(
              icon: _iconOnCall,
              title: trans('uptizm.settings.hub_team_oncall_title'),
              subtitle: trans('uptizm.settings.hub_team_oncall_subtitle'),
              to: '/teams/on-call',
            ),
            MSSettingsNavRow(
              icon: _iconBilling,
              title: trans('uptizm.settings.hub_team_billing_title'),
              subtitle: trans('uptizm.settings.hub_team_billing_subtitle'),
              to: '/teams/billing',
            ),
          ],
        ),

        // 2. About & support: the two in-app static pages, then the three
        //    documents the website owns. Contact / Privacy / Terms open the
        //    website in the active language (`/terms` in the default language,
        //    `/tr/terms` in Turkish), so the app never carries a second copy of
        //    a text the site already publishes.
        MSSettingsSection(
          header: trans('uptizm.settings.hub_group_about'),
          children: [
            MSSettingsNavRow(
              icon: _iconHelp,
              title: trans('uptizm.settings.hub_help_title'),
              subtitle: trans('uptizm.settings.hub_help_subtitle'),
              to: '/settings/help',
            ),
            MSSettingsNavRow(
              icon: _iconChangelog,
              title: trans('uptizm.settings.hub_changelog_title'),
              subtitle: trans('uptizm.settings.hub_changelog_subtitle'),
              to: '/settings/changelog',
            ),
            _externalRow(
              icon: _iconContact,
              title: trans('uptizm.settings.hub_contact_title'),
              subtitle: trans('uptizm.settings.hub_contact_subtitle'),
              url: WebLinks.contact,
            ),
            _externalRow(
              icon: _iconPrivacy,
              title: trans('uptizm.settings.hub_privacy_title'),
              subtitle: trans('uptizm.settings.hub_privacy_subtitle'),
              url: WebLinks.privacy,
            ),
            _externalRow(
              icon: _iconTerms,
              title: trans('uptizm.settings.hub_terms_title'),
              subtitle: trans('uptizm.settings.hub_terms_subtitle'),
              url: WebLinks.terms,
            ),
          ],
        ),
      ],
    );
  }
}
