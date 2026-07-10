import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

/// Uptizm's injected settings-hub extras.
///
/// Rendered into the starter settings hub's `settings.hub.footer` slot (wired in
/// [AppServiceProvider.boot]). The starter owns the Account / Security /
/// Preferences groups; this widget appends the two uptizm-domain groups that the
/// deleted `settings_hub_view.dart` used to carry:
///
/// 1. **Team** --- team-ops sub-pages (`/teams/notifications`, `/teams/escalation`,
///    `/teams/on-call`, `/teams/billing`).
/// 2. **About & support** --- static informational pages (`/settings/help`,
///    `/settings/changelog`, `/settings/privacy`, `/settings/terms`).
///
/// It is composed from the same starter components the hub itself uses
/// ([MSSettingsSection] + [MSSettingsNavRow]), so the injected groups are visually
/// indistinguishable from the native ones. All titles/subtitles come from the
/// existing `uptizm.settings.hub_*` i18n keys.
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
  static const _iconPrivacy = Icons.privacy_tip_outlined;
  static const _iconTerms = Icons.description_outlined;

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

        // 2. About & support: static informational pages.
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
            MSSettingsNavRow(
              icon: _iconPrivacy,
              title: trans('uptizm.settings.hub_privacy_title'),
              subtitle: trans('uptizm.settings.hub_privacy_subtitle'),
              to: '/settings/privacy',
            ),
            MSSettingsNavRow(
              icon: _iconTerms,
              title: trans('uptizm.settings.hub_terms_title'),
              subtitle: trans('uptizm.settings.hub_terms_subtitle'),
              to: '/settings/terms',
            ),
          ],
        ),
      ],
    );
  }
}
