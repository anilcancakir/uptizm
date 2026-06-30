import 'package:magic/magic.dart';

import '../resources/views/coming_soon_view.dart';
import '../resources/views/dashboard_view.dart';
import '../resources/views/monitor_create_view.dart';
import '../resources/views/monitor_detail_view.dart';
import '../resources/views/monitor_edit_view.dart';
import '../resources/views/monitors_list_view.dart';
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

      // 6. Deferred destinations. The shell always shows Incidents / Status /
      //    Settings (and the dashboard links to incident detail/create), but
      //    those screens ship in a later milestone. Register them to a "coming
      //    soon" placeholder so every nav target gives feedback rather than a
      //    silent no-op. The follow-up verticals replace these.
      for (final r in const [
        ['/incidents', 'Incidents'],
        ['/incidents/new', 'Incidents'],
        ['/status', 'Status pages'],
        ['/settings', 'Settings'],
      ]) {
        MagicRoute.page(
          r[0],
          () => ComingSoonView(feature: r[1]),
        ).transition(RouteTransition.none);
      }
      MagicRoute.page(
        '/incidents/:id',
        (String id) => const ComingSoonView(feature: 'Incidents'),
      ).transition(RouteTransition.none);
    },
  );
}
