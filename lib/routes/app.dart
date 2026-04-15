import 'package:magic/magic.dart';
import '../app/controllers/alert_controller.dart';
import '../app/controllers/announcement_controller.dart';
import '../app/controllers/dashboard_controller.dart';
import '../app/controllers/incident_controller.dart';
import '../app/controllers/status_page_controller.dart';
import '../resources/views/layouts/app_shell.dart';
import '../resources/views/monitors/monitor_create_view.dart';
import '../resources/views/monitors/monitor_show_view.dart';
import '../resources/views/monitors/monitors_list_view.dart';

/// Application routes.
///
/// Routes call controller actions (Laravel-style).
void registerAppRoutes() {
  MagicRoute.group(
    layout: (child) => AppShell(child: child),
    layoutId: 'app',
    middleware: ['auth'],
    routes: () {
      // Dashboard
      MagicRoute.page('/', () => DashboardController.instance.index());

      // Monitors
      MagicRoute.page(
        '/monitors',
        () => const MonitorsListView(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/monitors/create',
        () => const MonitorCreateView(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/monitors/:id',
        (String id) => MonitorShowView(monitorId: id),
      ).transition(RouteTransition.none);

      // Alert Rules
      MagicRoute.page(
        '/alert-rules',
        () => AlertController.instance.rulesIndex(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/alert-rules/create',
        () => AlertController.instance.rulesCreate(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/alert-rules/:id/edit',
        (String id) => AlertController.instance.rulesEdit(id),
      ).transition(RouteTransition.none);

      // Alerts History
      MagicRoute.page(
        '/alerts',
        () => AlertController.instance.alertsIndex(),
      ).transition(RouteTransition.none);

      // Incidents
      MagicRoute.page(
        '/incidents',
        () => IncidentController.instance.index(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/incidents/create',
        () => IncidentController.instance.create(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/incidents/:id',
        (String id) => IncidentController.instance.show(id),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/incidents/:id/edit',
        (String id) => IncidentController.instance.edit(id),
      ).transition(RouteTransition.none);

      // Status Pages
      MagicRoute.page(
        '/status-pages',
        () => StatusPageController.instance.index(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/create',
        () => StatusPageController.instance.create(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:id',
        (String id) => StatusPageController.instance.show(id),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:id/edit',
        (String id) => StatusPageController.instance.edit(id),
      ).transition(RouteTransition.none);

      // Status Page Announcements
      MagicRoute.page(
        '/status-pages/:statusPageId/announcements',
        (String statusPageId) =>
            AnnouncementController.instance.index(statusPageId),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:statusPageId/announcements/create',
        (String statusPageId) =>
            AnnouncementController.instance.create(statusPageId),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:statusPageId/announcements/:id',
        (String statusPageId, String id) =>
            AnnouncementController.instance.show(statusPageId, id),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:statusPageId/announcements/:id/edit',
        (String statusPageId, String id) =>
            AnnouncementController.instance.edit(statusPageId, id),
      ).transition(RouteTransition.none);
    },
  );
}
