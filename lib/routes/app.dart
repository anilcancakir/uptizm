import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import '../app/controllers/alert_controller.dart';
import '../app/controllers/analytics_controller.dart';
import '../app/controllers/announcement_controller.dart';
import '../app/controllers/dashboard_controller.dart';
import '../app/controllers/incident_controller.dart';
import '../app/controllers/monitor_controller.dart';
import '../app/controllers/status_page_controller.dart';

/// Application routes.
///
/// Routes call controller actions (Laravel-style).
void registerAppRoutes() {
  // Auth-protected routes with AppLayout
  MagicRoute.group(
    layout: (child) => MagicStarter.view.makeLayout('layout.app', child: child),
    middleware: ['auth'],
    layoutId: 'app',
    routes: () {
      // Dashboard
      MagicRoute.page('/', () => DashboardController.instance.index());

      // Monitors
      MagicRoute.page(
        '/monitors',
        () => MonitorController.instance.index(),
      ).transition(RouteTransition.none);

      // Monitor Create
      MagicRoute.page(
        '/monitors/create',
        () => MonitorController.instance.create(),
      ).transition(RouteTransition.none);

      // Monitor Show
      MagicRoute.page(
        '/monitors/:id',
        (String id) => MonitorController.instance.show(id),
      ).transition(RouteTransition.none);

      // Monitor Edit
      MagicRoute.page(
        '/monitors/:id/edit',
        (String id) => MonitorController.instance.edit(id),
      ).transition(RouteTransition.none);

      // Monitor Analytics
      MagicRoute.page(
        '/monitors/:id/analytics',
        (String id) => AnalyticsController.instance.analytics(id),
      ).transition(RouteTransition.none);

      // Monitor Alerts
      MagicRoute.page(
        '/monitors/:id/alerts',
        (String id) => MonitorController.instance.alerts(id),
      ).transition(RouteTransition.none);

      // Monitor Alert Rules (create/edit for specific monitor)
      MagicRoute.page(
        '/monitors/:id/alert-rules/create',
        (String id) => AlertController.instance.rulesCreate(monitorId: id),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/monitors/:id/alert-rules/:ruleId/edit',
        (String id, String ruleId) => AlertController.instance.rulesEdit(ruleId, monitorId: id),
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
        (String statusPageId) => AnnouncementController.instance.index(statusPageId),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:statusPageId/announcements/create',
        (String statusPageId) => AnnouncementController.instance.create(statusPageId),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:statusPageId/announcements/:id',
        (String statusPageId, String id) => AnnouncementController.instance.show(statusPageId, id),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:statusPageId/announcements/:id/edit',
        (String statusPageId, String id) => AnnouncementController.instance.edit(statusPageId, id),
      ).transition(RouteTransition.none);
    },
  );
}
