import 'package:magic/magic.dart';

import '../resources/views/dashboard/dashboard_view.dart';
import '../resources/views/incidents/incident_create_view.dart';
import '../resources/views/incidents/incident_detail_view.dart';
import '../resources/views/incidents/incidents_list_view.dart';
import '../resources/views/monitors/monitor_create_view.dart';
import '../resources/views/monitors/monitor_detail_view.dart';
import '../resources/views/monitors/monitor_edit_view.dart';
import '../resources/views/monitors/monitors_list_view.dart';
import '../resources/views/settings/appearance_settings_view.dart';
import '../resources/views/settings/changelog_settings_view.dart';
import '../resources/views/settings/help_settings_view.dart';
import '../resources/views/settings/language_settings_view.dart';
import '../resources/views/settings/notifications_settings_view.dart';
import '../resources/views/settings/password_settings_view.dart';
import '../resources/views/settings/privacy_settings_view.dart';
import '../resources/views/settings/profile_settings_view.dart';
import '../resources/views/settings/sessions_settings_view.dart';
import '../resources/views/settings/settings_hub_view.dart';
import '../resources/views/settings/terms_settings_view.dart';
import '../resources/views/settings/timezone_settings_view.dart';
import '../resources/views/settings/two_factor_settings_view.dart';
import '../resources/views/status/status_page_editor_view.dart';
import '../resources/views/status/status_page_preview_view.dart';
import '../resources/views/status/status_page_subscribers_view.dart';
import '../resources/views/status/status_pages_list_view.dart';
import '../resources/views/teams/escalation_policies_view.dart';
import '../resources/views/teams/escalation_policy_editor_view.dart';
import '../resources/views/teams/invite_accept_view.dart';
import '../resources/views/teams/notification_channels_view.dart';
import '../resources/views/teams/on_call_schedule_view.dart';
import '../resources/views/teams/plan_billing_view.dart';
import '../resources/views/teams/team_create_view.dart';
import '../resources/views/teams/team_members_view.dart';
import '../resources/views/teams/team_settings_view.dart';
import '../ui/layouts/app_layout.dart';

/// Application Route Definitions.
///
/// Registers the in-scope routes for the uptizm vertical:
///
/// - `/` — [DashboardView] inside [AppLayout] (the persistent shell).
/// - `/monitors` — [MonitorsListView] inside [AppLayout].
/// - `/monitors/new` — [MonitorCreateView] inside [AppLayout]. Registered
///   BEFORE `/monitors/:id` so go_router's first-match wins and the literal
///   segment `new` is never captured as a dynamic `:id` parameter.
/// - `/monitors/:id` — [MonitorDetailView] inside [AppLayout]; the `:id`
///   path parameter is passed positionally to the builder.
/// - `/monitors/:id/edit` — [MonitorEditView] inside [AppLayout].
/// - `/incidents` — [IncidentsListView] inside [AppLayout].
/// - `/incidents/new` — [IncidentCreateView] inside [AppLayout]. Registered
///   BEFORE `/incidents/:id` for the same first-match reason as
///   `/monitors/new`. The view reads an optional `?from=<id>` suggestion
///   prefill itself via `MagicRouter.instance.queryParameters`.
/// - `/incidents/:id` — [IncidentDetailView] inside [AppLayout]; the `:id`
///   path parameter is passed positionally to the builder.
/// - `/status` — [StatusPagesListView] inside [AppLayout].
/// - `/status/new` — [StatusPageEditorView] inside [AppLayout], zero-arg
///   (creates a new draft). Registered BEFORE `/status/:id` for the same
///   first-match reason as `/monitors/new` and `/incidents/new`.
/// - `/status/:id` — [StatusPageEditorView] inside [AppLayout]; the `:id`
///   path parameter is passed positionally to the builder (edits an
///   existing status page).
/// - `/status/:id/preview` — [StatusPagePreviewView] inside [AppLayout]; the
///   in-app full-screen mockup of the public status page.
/// - `/status/:id/subscribers` — [StatusPageSubscribersView] inside
///   [AppLayout]; subscriber management for a status page.
/// - `/settings` — [SettingsHubView] inside [AppLayout]; the grouped-list
///   settings index (Account / Security / Preferences / Team / About).
/// - `/settings/profile` — [ProfileSettingsView] inside [AppLayout].
/// - `/settings/appearance` — [AppearanceSettingsView] inside [AppLayout].
/// - `/settings/language` — [LanguageSettingsView] inside [AppLayout].
/// - `/settings/timezone` — [TimezoneSettingsView] inside [AppLayout].
/// - `/settings/notifications` — [NotificationsSettingsView] inside
///   [AppLayout].
/// - `/settings/help` — [HelpSettingsView] inside [AppLayout].
/// - `/settings/changelog` — [ChangelogSettingsView] inside [AppLayout].
/// - `/settings/privacy` — [PrivacySettingsView] inside [AppLayout].
/// - `/settings/terms` — [TermsSettingsView] inside [AppLayout].
/// - `/settings/security/2fa` — [TwoFactorSettingsView] inside [AppLayout].
/// - `/settings/security/password` — [PasswordSettingsView] inside
///   [AppLayout].
/// - `/settings/security/sessions` — [SessionsSettingsView] inside
///   [AppLayout].
/// - `/teams/new` — [TeamCreateView] inside [AppLayout].
/// - `/teams/settings` — [TeamSettingsView] inside [AppLayout].
/// - `/teams/members` — [TeamMembersView] inside [AppLayout].
/// - `/teams/notifications` — [NotificationChannelsView] inside [AppLayout].
/// - `/teams/escalation` — [EscalationPoliciesView] inside [AppLayout].
/// - `/teams/escalation/new` — [EscalationPolicyEditorView] inside
///   [AppLayout], zero-arg (creates a new draft). Registered BEFORE
///   `/teams/escalation/:id` for the same first-match reason as
///   `/monitors/new`.
/// - `/teams/escalation/:id` — [EscalationPolicyEditorView] inside
///   [AppLayout]; the `:id` path parameter is passed positionally to the
///   builder (edits an existing policy).
/// - `/teams/on-call` — [OnCallScheduleView] inside [AppLayout].
/// - `/teams/billing` — [PlanBillingView] inside [AppLayout].
/// - `/invite/:token` — [InviteAcceptView] registered OUTSIDE [AppLayout] (no
///   sidebar/top bar shell), matching how the React router keeps the invite
///   acceptance screen standalone; the `:token` path parameter is passed
///   positionally to the builder.
///
/// All in-app routes use [RouteTransition.none] for an instant,
/// design-lab-faithful navigation feel. `/preview` is registered separately
/// by [RouteServiceProvider] via [MagicPreview.registerRoutes], outside
/// [AppLayout], matching the React router structure.
///
/// This function is called by [RouteServiceProvider.boot()] during the Magic
/// bootstrap lifecycle.
///
/// See also: `lib/app/kernel.dart` for middleware registration.
void registerAppRoutes() {
  // All in-app routes render inside ONE persistent [AppLayout] shell, wired as
  // a go_router ShellRoute via MagicRoute.group(layout:). The shell (sidebar /
  // top bar / bottom nav) is built once and survives navigation; only the
  // routed content swaps. Wrapping each page in its own AppLayout instead would
  // rebuild the whole chrome on every navigation (a full-screen flash between,
  // say, Home and Monitors).
  MagicRoute.group(
    layout: (child) => AppLayout(child: child),
    routes: () {
      // 1. Dashboard: the default landing screen.
      MagicRoute.page(
        '/',
        () => const DashboardView(),
      ).title('Dashboard | Uptizm').transition(RouteTransition.none);

      // 2. Monitors list: full monitor inventory with status filter.
      MagicRoute.page(
        '/monitors',
        () => const MonitorsListView(),
      ).title('Monitors | Uptizm').transition(RouteTransition.none);

      // 3. New monitor: static segment registered BEFORE /monitors/:id so the
      //    literal path /monitors/new is never consumed as a dynamic :id param.
      //    go_router resolves routes by first-match in registration order, so
      //    placing this static route ahead of the dynamic one is the guarantee.
      MagicRoute.page(
        '/monitors/new',
        () => const MonitorCreateView(),
      ).title('New monitor | Uptizm').transition(RouteTransition.none);

      // 4. Monitor detail: resolves :id from the path to the fixture.
      MagicRoute.page(
        '/monitors/:id',
        (String id) => MonitorDetailView(id: id),
      ).title('Monitor | Uptizm').transition(RouteTransition.none);

      // 5. Edit monitor: /monitors/:id/edit is distinct from /monitors/:id so
      //    ordering relative to the detail route does not matter, but it is
      //    placed immediately after for readability.
      MagicRoute.page(
        '/monitors/:id/edit',
        (String id) => MonitorEditView(id: id),
      ).title('Edit monitor | Uptizm').transition(RouteTransition.none);

      // 6. Incidents list: full incident inventory with filter + search.
      MagicRoute.page(
        '/incidents',
        () => const IncidentsListView(),
      ).title('Incidents | Uptizm').transition(RouteTransition.none);

      // 7. New incident: static segment registered BEFORE /incidents/:id so
      //    the literal path /incidents/new is never consumed as a dynamic
      //    :id param, mirroring the /monitors/new ordering above. The view
      //    reads an optional `?from=<id>` suggestion prefill itself via
      //    MagicRouter.instance.queryParameters, so no builder arg is needed.
      MagicRoute.page(
        '/incidents/new',
        () => const IncidentCreateView(),
      ).title('New incident | Uptizm').transition(RouteTransition.none);

      // 8. Incident detail: resolves :id from the path to the fixture.
      MagicRoute.page(
        '/incidents/:id',
        (String id) => IncidentDetailView(id: id),
      ).title('Incident | Uptizm').transition(RouteTransition.none);

      // 9. Status pages list: full status page inventory.
      MagicRoute.page(
        '/status',
        () => const StatusPagesListView(),
      ).title('Status pages | Uptizm').transition(RouteTransition.none);

      // 10. New status page: static segment registered BEFORE /status/:id so
      //     the literal path /status/new is never consumed as a dynamic :id
      //     param, mirroring the /monitors/new and /incidents/new ordering
      //     above. Zero-arg: the editor reads nothing from the path for a
      //     new draft.
      MagicRoute.page(
        '/status/new',
        () => const StatusPageEditorView(),
      ).title('New status page | Uptizm').transition(RouteTransition.none);

      // 11. Status page editor: resolves :id from the path to the fixture.
      MagicRoute.page(
        '/status/:id',
        (String id) => StatusPageEditorView(id: id),
      ).title('Edit status page | Uptizm').transition(RouteTransition.none);

      // 12. Status page preview: /status/:id/preview is distinct from
      //     /status/:id so ordering relative to the editor route does not
      //     matter, but it is placed immediately after for readability.
      MagicRoute.page(
        '/status/:id/preview',
        (String id) => StatusPagePreviewView(id: id),
      ).title('Status page preview | Uptizm').transition(RouteTransition.none);

      // 13. Status page subscribers: /status/:id/subscribers is distinct
      //     from /status/:id, same ordering note as the preview route above.
      MagicRoute.page(
        '/status/:id/subscribers',
        (String id) => StatusPageSubscribersView(id: id),
      ).title('Status page subscribers | Uptizm').transition(RouteTransition.none);

      // 14. Settings hub: the grouped-list index (Account / Security /
      //     Preferences / Team / About & support).
      MagicRoute.page(
        '/settings',
        () => const SettingsHubView(),
      ).title('Settings | Uptizm').transition(RouteTransition.none);

      // 15. Settings sub-pages. All static paths (no :id), so registration
      //     order among them carries no first-match concern; grouped here
      //     for readability, mirroring the hub's section order.
      MagicRoute.page(
        '/settings/profile',
        () => const ProfileSettingsView(),
      ).title('Profile | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/security/2fa',
        () => const TwoFactorSettingsView(),
      ).title('Two-factor authentication | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/security/password',
        () => const PasswordSettingsView(),
      ).title('Password | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/security/sessions',
        () => const SessionsSettingsView(),
      ).title('Active sessions | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/appearance',
        () => const AppearanceSettingsView(),
      ).title('Appearance | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/language',
        () => const LanguageSettingsView(),
      ).title('Language | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/timezone',
        () => const TimezoneSettingsView(),
      ).title('Time zone | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/notifications',
        () => const NotificationsSettingsView(),
      ).title('Notifications | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/help',
        () => const HelpSettingsView(),
      ).title('Help | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/changelog',
        () => const ChangelogSettingsView(),
      ).title('Changelog | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/privacy',
        () => const PrivacySettingsView(),
      ).title('Privacy policy | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/terms',
        () => const TermsSettingsView(),
      ).title('Terms of service | Uptizm').transition(RouteTransition.none);

      // 16. Team destinations. The Settings hub's Team group links here.
      MagicRoute.page(
        '/teams/new',
        () => const TeamCreateView(),
      ).title('New team | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/teams/settings',
        () => const TeamSettingsView(),
      ).title('Team settings | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/teams/members',
        () => const TeamMembersView(),
      ).title('Members | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/teams/notifications',
        () => const NotificationChannelsView(),
      ).title('Notification channels | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/teams/escalation',
        () => const EscalationPoliciesView(),
      ).title('Escalation policies | Uptizm').transition(RouteTransition.none);

      // 17. New escalation policy: static segment registered BEFORE
      //     /teams/escalation/:id so the literal path /teams/escalation/new
      //     is never consumed as a dynamic :id param, mirroring the
      //     /monitors/new ordering above. Zero-arg: the editor reads nothing
      //     from the path for a new draft.
      MagicRoute.page(
        '/teams/escalation/new',
        () => const EscalationPolicyEditorView(),
      ).title('New escalation policy | Uptizm').transition(RouteTransition.none);

      // 18. Escalation policy editor: resolves :id from the path to the
      //     fixture (edits an existing policy).
      MagicRoute.page(
        '/teams/escalation/:id',
        (String id) => EscalationPolicyEditorView(id: id),
      ).title('Edit escalation policy | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/teams/on-call',
        () => const OnCallScheduleView(),
      ).title('On-call | Uptizm').transition(RouteTransition.none);

      MagicRoute.page(
        '/teams/billing',
        () => const PlanBillingView(),
      ).title('Plan & billing | Uptizm').transition(RouteTransition.none);
    },
  );

  // 19. Invite acceptance: registered OUTSIDE the AppLayout group above, so
  //     it renders standalone (no sidebar/top bar), mirroring how the React
  //     router keeps /invite/:token outside the app shell. The :token path
  //     parameter is passed positionally to the builder.
  MagicRoute.page(
    '/invite/:token',
    (String token) => InviteAcceptView(token: token),
  ).transition(RouteTransition.none);
}
