import 'package:magic/magic.dart';

import '../resources/views/coming_soon_view.dart';
import '../resources/views/dashboard_view.dart';
import '../resources/views/incident_create_view.dart';
import '../resources/views/incident_detail_view.dart';
import '../resources/views/incidents_list_view.dart';
import '../resources/views/monitor_create_view.dart';
import '../resources/views/monitor_detail_view.dart';
import '../resources/views/monitor_edit_view.dart';
import '../resources/views/monitors_list_view.dart';
import '../resources/views/status_page_editor_view.dart';
import '../resources/views/status_page_preview_view.dart';
import '../resources/views/status_page_subscribers_view.dart';
import '../resources/views/status_pages_list_view.dart';
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

      // 14. Deferred destinations. The shell always shows Settings, but that
      //     screen ships in a later milestone. Register it to a "coming
      //     soon" placeholder so the nav target gives feedback rather than
      //     a silent no-op. A follow-up vertical replaces this.
      MagicRoute.page(
        '/settings',
        () => const ComingSoonView(feature: 'Settings'),
      ).transition(RouteTransition.none);
    },
  );
}
