import 'package:magic/magic.dart';

import '../resources/views/dashboard/dashboard_view.dart';
import '../resources/views/incidents/incident_create_view.dart';
import '../resources/views/incidents/incident_detail_view.dart';
import '../resources/views/incidents/incidents_list_view.dart';
import '../resources/views/auth/welcome_view.dart';
import '../resources/views/incidents/weekly_digest_view.dart';
import '../resources/views/monitors/monitor_create_view.dart';
import '../resources/views/monitors/monitor_detail_view.dart';
import '../resources/views/monitors/monitor_edit_view.dart';
import '../resources/views/monitors/monitors_list_view.dart';
import '../resources/views/settings/changelog_settings_view.dart';
import '../resources/views/settings/help_settings_view.dart';
import '../resources/views/settings/privacy_settings_view.dart';
import '../resources/views/settings/terms_settings_view.dart';
import '../resources/views/status/status_page_editor_view.dart';
import '../resources/views/status/status_page_preview_view.dart';
import '../resources/views/status/status_page_subscribers_view.dart';
import '../resources/views/status/status_pages_list_view.dart';
import '../resources/views/teams/escalation_policies_view.dart';
import '../resources/views/teams/escalation_policy_editor_view.dart';
import '../resources/views/teams/notification_channels_view.dart';
import '../resources/views/teams/on_call_schedule_view.dart';
import '../resources/views/teams/plan_billing_view.dart';
import '../ui/layouts/app_layout.dart';

/// Application Route Definitions.
///
/// Registers the in-scope routes for the uptizm vertical. The whole in-app
/// shell group carries the `'auth'` middleware, so an unauthenticated boot is
/// redirected to the magic_starter login route (the `EnsureAuthenticated`
/// guard); only the standalone `/welcome` onboarding route stays ungated. The
/// account
/// surface (login/register, the Settings hub and its Account / Security /
/// Preferences sub-pages, team create/settings/invitations, notification
/// preferences) is owned by magic_starter and registered in
/// [RouteServiceProvider.boot()]; uptizm keeps only the monitoring-domain
/// routes and the About & support static pages the starter does not provide:
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
/// - `/settings/help` — [HelpSettingsView] inside [AppLayout].
/// - `/settings/changelog` — [ChangelogSettingsView] inside [AppLayout].
/// - `/settings/privacy` — [PrivacySettingsView] inside [AppLayout].
/// - `/settings/terms` — [TermsSettingsView] inside [AppLayout].
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
/// - `/welcome` — [WelcomeView] registered OUTSIDE [AppLayout] (no sidebar/top
///   bar shell) and UNGATED, so a fresh unauthenticated launch can reach the
///   onboarding carousel.
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
    middleware: ['auth'],
    layout: (child) => AppLayout(child: child),
    routes: () {
      // 1. Dashboard: the default landing screen.
      MagicRoute.page(
        '/',
        () => const DashboardView(),
      ).title('uptizm.titles.dashboard').transition(RouteTransition.none);

      // 2. Monitors list: full monitor inventory with status filter.
      MagicRoute.page(
        '/monitors',
        () => const MonitorsListView(),
      ).title('uptizm.titles.monitors').transition(RouteTransition.none);

      // 3. New monitor: static segment registered BEFORE /monitors/:id so the
      //    literal path /monitors/new is never consumed as a dynamic :id param.
      //    go_router resolves routes by first-match in registration order, so
      //    placing this static route ahead of the dynamic one is the guarantee.
      MagicRoute.page(
        '/monitors/new',
        () => const MonitorCreateView(),
      ).title('uptizm.titles.monitor_new').transition(RouteTransition.none);

      // 4. Monitor detail: resolves :id from the path to the fixture.
      MagicRoute.page(
        '/monitors/:id',
        (String id) => MonitorDetailView(id: id),
      ).title('uptizm.titles.monitor').transition(RouteTransition.none);

      // 5. Edit monitor: /monitors/:id/edit is distinct from /monitors/:id so
      //    ordering relative to the detail route does not matter, but it is
      //    placed immediately after for readability.
      MagicRoute.page(
        '/monitors/:id/edit',
        (String id) => MonitorEditView(id: id),
      ).title('uptizm.titles.monitor_edit').transition(RouteTransition.none);

      // 6. Incidents list: full incident inventory with filter + search.
      MagicRoute.page(
        '/incidents',
        () => const IncidentsListView(),
      ).title('uptizm.titles.incidents').transition(RouteTransition.none);

      // 7. New incident: static segment registered BEFORE /incidents/:id so
      //    the literal path /incidents/new is never consumed as a dynamic
      //    :id param, mirroring the /monitors/new ordering above. The view
      //    reads an optional `?from=<id>` suggestion prefill itself via
      //    MagicRouter.instance.queryParameters, so no builder arg is needed.
      MagicRoute.page(
        '/incidents/new',
        () => const IncidentCreateView(),
      ).title('uptizm.titles.incident_new').transition(RouteTransition.none);

      // 8. Weekly AI digest: static segment registered BEFORE /incidents/:id so
      //    the literal path /incidents/digest is never consumed as a dynamic
      //    :id param (same ordering rule as /incidents/new). Linked from the
      //    dashboard AI inbox's "Weekly digest" affordance.
      MagicRoute.page(
        '/incidents/digest',
        () => const WeeklyDigestView(),
      ).title('uptizm.titles.digest').transition(RouteTransition.none);

      // 9. Incident detail: resolves :id from the path to the fixture.
      MagicRoute.page(
        '/incidents/:id',
        (String id) => IncidentDetailView(id: id),
      ).title('uptizm.titles.incident').transition(RouteTransition.none);

      // 9. Status pages list: full status page inventory.
      MagicRoute.page(
        '/status',
        () => const StatusPagesListView(),
      ).title('uptizm.titles.status_pages').transition(RouteTransition.none);

      // 10. New status page: static segment registered BEFORE /status/:id so
      //     the literal path /status/new is never consumed as a dynamic :id
      //     param, mirroring the /monitors/new and /incidents/new ordering
      //     above. Zero-arg: the editor reads nothing from the path for a
      //     new draft.
      MagicRoute.page(
        '/status/new',
        () => const StatusPageEditorView(),
      ).title('uptizm.titles.status_page_new').transition(RouteTransition.none);

      // 11. Status page editor: resolves :id from the path to the fixture.
      MagicRoute.page(
        '/status/:id',
        (String id) => StatusPageEditorView(id: id),
      ).title('uptizm.titles.status_page_edit').transition(RouteTransition.none);

      // 12. Status page preview: /status/:id/preview is distinct from
      //     /status/:id so ordering relative to the editor route does not
      //     matter, but it is placed immediately after for readability.
      MagicRoute.page(
        '/status/:id/preview',
        (String id) => StatusPagePreviewView(id: id),
      ).title('uptizm.titles.status_page_preview').transition(RouteTransition.none);

      // 13. Status page subscribers: /status/:id/subscribers is distinct
      //     from /status/:id, same ordering note as the preview route above.
      MagicRoute.page(
        '/status/:id/subscribers',
        (String id) => StatusPageSubscribersView(id: id),
      ).title('uptizm.titles.status_page_subscribers').transition(RouteTransition.none);

      // 14. Settings — About & support sub-pages only. The Settings hub index
      //     and its Account / Security / Preferences sub-pages are now owned by
      //     magic_starter (registerMagicStarterProfileRoutes), which serves
      //     `/settings`, `/settings/profile`, `/settings/appearance`,
      //     `/settings/language`, `/settings/timezone`, `/settings/notifications`,
      //     `/settings/security/{password,sessions,two-factor}`. Uptizm keeps
      //     only the domain-specific static pages the starter does not provide.
      //     All static paths (no :id), so registration order carries no
      //     first-match concern.
      MagicRoute.page(
        '/settings/help',
        () => const HelpSettingsView(),
      ).title('uptizm.titles.help').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/changelog',
        () => const ChangelogSettingsView(),
      ).title('uptizm.titles.changelog').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/privacy',
        () => const PrivacySettingsView(),
      ).title('uptizm.titles.privacy').transition(RouteTransition.none);

      MagicRoute.page(
        '/settings/terms',
        () => const TermsSettingsView(),
      ).title('uptizm.titles.terms').transition(RouteTransition.none);

      // 16. Team destinations — uptizm monitoring-ops pages only. Team
      //     creation (`/teams/create`), team settings (`/teams/settings`) and
      //     member management (folded into `/teams/settings`) are now owned by
      //     magic_starter (registerMagicStarterTeamRoutes); uptizm keeps the
      //     domain-specific team-operations pages the starter does not provide.
      MagicRoute.page(
        '/teams/notifications',
        () => const NotificationChannelsView(),
      ).title('uptizm.titles.notification_channels').transition(RouteTransition.none);

      MagicRoute.page(
        '/teams/escalation',
        () => const EscalationPoliciesView(),
      ).title('uptizm.titles.escalation_policies').transition(RouteTransition.none);

      // 17. New escalation policy: static segment registered BEFORE
      //     /teams/escalation/:id so the literal path /teams/escalation/new
      //     is never consumed as a dynamic :id param, mirroring the
      //     /monitors/new ordering above. Zero-arg: the editor reads nothing
      //     from the path for a new draft.
      MagicRoute.page(
        '/teams/escalation/new',
        () => const EscalationPolicyEditorView(),
      ).title('uptizm.titles.escalation_policy_new').transition(RouteTransition.none);

      // 18. Escalation policy editor: resolves :id from the path to the
      //     fixture (edits an existing policy).
      MagicRoute.page(
        '/teams/escalation/:id',
        (String id) => EscalationPolicyEditorView(id: id),
      ).title('uptizm.titles.escalation_policy_edit').transition(RouteTransition.none);

      MagicRoute.page(
        '/teams/on-call',
        () => const OnCallScheduleView(),
      ).title('uptizm.titles.on_call').transition(RouteTransition.none);

      MagicRoute.page(
        '/teams/billing',
        () => const PlanBillingView(),
      ).title('uptizm.titles.billing').transition(RouteTransition.none);
    },
  );

  // 19. Onboarding: the first-launch welcome carousel, registered OUTSIDE the
  //     AppLayout group so it renders full-screen with no shell chrome (mirrors
  //     the React router keeping /welcome outside the app shell). It stays
  //     UNGATED (no 'auth' middleware) so a fresh, unauthenticated launch can
  //     reach onboarding. Invitation acceptance is now owned by magic_starter
  //     (`/invitations/:token/accept`), so uptizm no longer registers
  //     `/invite/:token`. Standalone routes carry no `.title(...)`.
  MagicRoute.page(
    '/welcome',
    () => const WelcomeView(),
  ).transition(RouteTransition.none);
}
