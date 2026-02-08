import 'package:magic/magic.dart';

import '../resources/views/layouts/app_layout.dart';
import '../app/controllers/alert_controller.dart';
import '../app/controllers/analytics_controller.dart';
import '../app/controllers/announcement_controller.dart';
import '../app/controllers/dashboard_controller.dart';
import '../app/controllers/incident_controller.dart';
import '../app/controllers/monitor_controller.dart';
import '../app/controllers/notification_controller.dart';
import '../app/controllers/profile_controller.dart';
import '../app/controllers/status_page_controller.dart';
import '../app/controllers/team_controller.dart';

/// Application routes.
///
/// Routes call controller actions (Laravel-style).
void registerAppRoutes() {
  // Auth-protected routes with AppLayout
  MagicRoute.group(
    layout: (child) => AppLayout(child: child),
    middleware: ['auth'],
    routes: () {
      // Dashboard
      MagicRoute.page('/', () => DashboardController.instance.index());

      // Notifications List
      MagicRoute.page(
        '/notifications',
        () => NotificationController.instance.index(),
      ).transition(RouteTransition.none);

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

      // Monitor Show - ID extracted from URL in view
      MagicRoute.page(
        '/monitors/:id',
        () => MonitorController.instance.show(),
      ).transition(RouteTransition.none);

      // Monitor Edit - ID extracted from URL in view
      MagicRoute.page(
        '/monitors/:id/edit',
        () => MonitorController.instance.edit(),
      ).transition(RouteTransition.none);

      // Monitor Analytics
      MagicRoute.page(
        '/monitors/:id/analytics',
        () => AnalyticsController.instance.analytics(),
      ).transition(RouteTransition.none);

      // Monitor Alerts
      MagicRoute.page(
        '/monitors/:id/alerts',
        () => MonitorController.instance.alerts(),
      ).transition(RouteTransition.none);

      // Monitor Alert Rules (create/edit for specific monitor)
      MagicRoute.page(
        '/monitors/:id/alert-rules/create',
        () => AlertController.instance.rulesCreate(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/monitors/:id/alert-rules/:ruleId/edit',
        () => AlertController.instance.rulesEdit(),
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
        () => AlertController.instance.rulesEdit(),
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
        () => IncidentController.instance.show(
          MagicRouter.instance.pathParameter('id') ?? '',
        ),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/incidents/:id/edit',
        () => IncidentController.instance.edit(
          MagicRouter.instance.pathParameter('id') ?? '',
        ),
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
        () => StatusPageController.instance.show(),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:id/edit',
        () => StatusPageController.instance.edit(),
      ).transition(RouteTransition.none);

      // Status Page Announcements
      MagicRoute.page(
        '/status-pages/:statusPageId/announcements',
        () => AnnouncementController.instance.index(
          MagicRouter.instance.pathParameter('statusPageId')!,
        ),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:statusPageId/announcements/create',
        () => AnnouncementController.instance.create(
          MagicRouter.instance.pathParameter('statusPageId')!,
        ),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:statusPageId/announcements/:id',
        () => AnnouncementController.instance.show(
          MagicRouter.instance.pathParameter('statusPageId')!,
          MagicRouter.instance.pathParameter('id')!,
        ),
      ).transition(RouteTransition.none);

      MagicRoute.page(
        '/status-pages/:statusPageId/announcements/:id/edit',
        () => AnnouncementController.instance.edit(
          MagicRouter.instance.pathParameter('statusPageId')!,
          MagicRouter.instance.pathParameter('id')!,
        ),
      ).transition(RouteTransition.none);

      // Teams
      MagicRoute.page(
        '/teams/create',
        () => TeamController.instance.create(),
      ).transition(RouteTransition.none);

      // Profile Settings
      MagicRoute.page(
        '/settings/profile',
        () => ProfileController.instance.profile(),
      ).transition(RouteTransition.none);

      // Notification Preferences
      MagicRoute.page(
        '/settings/notifications',
        () => NotificationController.instance.preferences(),
      ).transition(RouteTransition.none);

      // Team Settings
      MagicRoute.page(
        '/teams/settings',
        () => TeamController.instance.edit(),
      ).transition(RouteTransition.none);

      // Team Members
      MagicRoute.page(
        '/teams/members',
        () => TeamController.instance.membersPage(),
      ).transition(RouteTransition.none);
    },
  );
}
