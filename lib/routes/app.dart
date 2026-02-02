import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:flutter/material.dart';

import '../resources/views/layouts/app_layout.dart';
import '../app/controllers/dashboard_controller.dart';
import '../app/controllers/monitor_controller.dart';
import '../app/controllers/profile_controller.dart';
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

      // Incidents
      MagicRoute.page(
        '/incidents',
        () => Center(
          child: WText('Coming Soon', className: 'text-lg text-gray-500'),
        ),
      );

      // Status Pages
      MagicRoute.page(
        '/status-pages',
        () => Center(
          child: WText('Coming Soon', className: 'text-lg text-gray-500'),
        ),
      );

      // Teams
      MagicRoute.page(
        '/teams/create',
        () => TeamController.instance.create(),
      ).transition(RouteTransition.none);

      // Settings
      MagicRoute.page(
        '/settings',
        () => Center(
          child: WText('Coming Soon', className: 'text-lg text-gray-500'),
        ),
      );

      // Profile Settings
      MagicRoute.page(
        '/settings/profile',
        () => ProfileController.instance.profile(),
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
