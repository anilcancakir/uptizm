import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

/// Settings hub (`/settings`): the top-level grouped-list index.
///
/// Mirrors the React `SettingsPage.tsx` GROUPS array exactly: 5
/// [SettingsSection]s (Account / Security / Preferences / Team / About &
/// support), each holding [SettingsNavRow]s that drill into a settings
/// sub-page or (for the Team group) a `/teams/*` ComingSoon stub registered
/// in a later step. This is the top-level page, so [SettingsScaffold] has no
/// back affordance.
@immutable
class SettingsHubView extends StatelessWidget {
  /// Creates the [SettingsHubView].
  const SettingsHubView({super.key});

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.hub_title'),
      subtitle: trans('uptizm.settings.hub_description'),
      children: [
        // 1. Account.
        MSSettingsSection(
          header: trans('uptizm.settings.hub_group_account'),
          children: [
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_profile_title'),
              subtitle: trans('uptizm.settings.hub_profile_subtitle'),
              icon: Icons.person_outline,
              to: '/settings/profile',
            ),
          ],
        ),

        // 2. Security.
        MSSettingsSection(
          header: trans('uptizm.settings.hub_group_security'),
          children: [
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_2fa_title'),
              subtitle: trans('uptizm.settings.hub_2fa_subtitle'),
              icon: Icons.shield_outlined,
              to: '/settings/security/2fa',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_password_title'),
              subtitle: trans('uptizm.settings.hub_password_subtitle'),
              icon: Icons.lock_outline,
              to: '/settings/security/password',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_sessions_title'),
              subtitle: trans('uptizm.settings.hub_sessions_subtitle'),
              icon: Icons.devices_outlined,
              to: '/settings/security/sessions',
            ),
          ],
        ),

        // 3. Preferences.
        MSSettingsSection(
          header: trans('uptizm.settings.hub_group_preferences'),
          children: [
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_appearance_title'),
              subtitle: trans('uptizm.settings.hub_appearance_subtitle'),
              icon: Icons.palette_outlined,
              to: '/settings/appearance',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_language_title'),
              subtitle: trans('uptizm.settings.hub_language_subtitle'),
              icon: Icons.language_outlined,
              to: '/settings/language',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_timezone_title'),
              subtitle: trans('uptizm.settings.hub_timezone_subtitle'),
              icon: Icons.schedule_outlined,
              to: '/settings/timezone',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_notifications_title'),
              subtitle: trans('uptizm.settings.hub_notifications_subtitle'),
              icon: Icons.notifications_outlined,
              to: '/settings/notifications',
            ),
          ],
        ),

        // 4. Team. All rows route to `/teams/*` ComingSoon stubs (Step 10).
        MSSettingsSection(
          header: trans('uptizm.settings.hub_group_team'),
          children: [
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_team_settings_title'),
              subtitle: trans('uptizm.settings.hub_team_settings_subtitle'),
              icon: Icons.groups_outlined,
              to: '/teams/settings',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_team_members_title'),
              subtitle: trans('uptizm.settings.hub_team_members_subtitle'),
              icon: Icons.people_outline,
              to: '/teams/members',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_team_channels_title'),
              subtitle: trans('uptizm.settings.hub_team_channels_subtitle'),
              icon: Icons.notifications_outlined,
              to: '/teams/notifications',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_team_escalation_title'),
              subtitle: trans('uptizm.settings.hub_team_escalation_subtitle'),
              icon: Icons.trending_up_outlined,
              to: '/teams/escalation',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_team_oncall_title'),
              subtitle: trans('uptizm.settings.hub_team_oncall_subtitle'),
              icon: Icons.phone_in_talk_outlined,
              to: '/teams/on-call',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_team_billing_title'),
              subtitle: trans('uptizm.settings.hub_team_billing_subtitle'),
              icon: Icons.credit_card_outlined,
              to: '/teams/billing',
            ),
          ],
        ),

        // 5. About & support.
        MSSettingsSection(
          header: trans('uptizm.settings.hub_group_about'),
          children: [
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_help_title'),
              subtitle: trans('uptizm.settings.hub_help_subtitle'),
              icon: Icons.help_outline,
              to: '/settings/help',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_changelog_title'),
              subtitle: trans('uptizm.settings.hub_changelog_subtitle'),
              icon: Icons.article_outlined,
              to: '/settings/changelog',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_privacy_title'),
              subtitle: trans('uptizm.settings.hub_privacy_subtitle'),
              icon: Icons.privacy_tip_outlined,
              to: '/settings/privacy',
            ),
            MSSettingsNavRow(
              title: trans('uptizm.settings.hub_terms_title'),
              subtitle: trans('uptizm.settings.hub_terms_subtitle'),
              icon: Icons.description_outlined,
              to: '/settings/terms',
            ),
          ],
        ),

        // 6. Version footer, centered, muted mono.
        WDiv(
          className: 'mt-2 flex flex-row justify-center',
          child: WText(
            trans('uptizm.settings.hub_version_footer'),
            className: 'font-mono text-xs text-fg-muted',
          ),
        ),
      ],
    );
  }
}
