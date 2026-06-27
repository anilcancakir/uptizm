import 'package:magic/magic.dart';

import '../resources/views/dashboard_view.dart';
import '../resources/views/monitor_detail_view.dart';
import '../resources/views/monitors_list_view.dart';
import '../ui/layouts/app_layout.dart';

/// Application Route Definitions.
///
/// Registers the four in-scope routes for the uptizm vertical:
///
/// - `/` — [DashboardView] inside [AppLayout] (the persistent shell).
/// - `/monitors` — [MonitorsListView] inside [AppLayout].
/// - `/monitors/:id` — [MonitorDetailView] inside [AppLayout]; the `:id`
///   path parameter is passed positionally to the builder.
///
/// All three in-app routes use [RouteTransition.none] for an instant,
/// design-lab-faithful navigation feel. `/preview` is registered separately
/// by [RouteServiceProvider] via [MagicPreview.registerRoutes], outside
/// [AppLayout] — matching the React router structure.
///
/// This function is called by [RouteServiceProvider.boot()] during the Magic
/// bootstrap lifecycle.
///
/// See also: `lib/app/kernel.dart` for middleware registration.
void registerAppRoutes() {
  // 1. Dashboard: the default landing screen.
  MagicRoute.page(
    '/',
    () => const AppLayout(child: DashboardView()),
  ).title('Dashboard | Uptizm').transition(RouteTransition.none);

  // 2. Monitors list: full monitor inventory with status filter.
  MagicRoute.page(
    '/monitors',
    () => const AppLayout(child: MonitorsListView()),
  ).title('Monitors | Uptizm').transition(RouteTransition.none);

  // 3. Monitor detail: resolves :id from the path to the fixture.
  MagicRoute.page(
    '/monitors/:id',
    (String id) => AppLayout(child: MonitorDetailView(id: id)),
  ).title('Monitor | Uptizm').transition(RouteTransition.none);
}
