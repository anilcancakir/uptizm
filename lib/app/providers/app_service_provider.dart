import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:magic_deeplink/magic_deeplink.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_social_auth/magic_social_auth.dart';
import 'package:magic_starter/magic_starter.dart';
import '../controllers/team_controller.dart' as app;
import '../models/user.dart';
import '../policies/team_policy.dart';
import '../policies/monitor_policy.dart';
import '../../resources/views/components/navigation/app_header.dart';
import '../../resources/views/profile/profile_settings_view.dart';

class AppServiceProvider extends ServiceProvider {
  AppServiceProvider(super.app);

  @override
  void register() {
    //
  }

  @override
  Future<void> boot() async {
    // Register User factory for Auth session restoration
    Auth.manager.setUserFactory((data) => User.fromMap(data));
    MagicStarter.useUserModel((data) => User.fromMap(data));
    // Register policies
    TeamPolicy().register();
    MonitorPolicy().register();
    final configPaths = Config.get('deeplink.paths');
    final paths = (configPaths as List? ?? [])
        .map((e) => e.toString())
        .toList();
    DeeplinkManager().registerHandler(RouteDeeplinkHandler(paths: paths));
    // -----------------------------------------------------------------------
    // Magic Starter: View Overrides
    // -----------------------------------------------------------------------
    MagicStarter.view.register(
      'profile.settings',
      () => const ProfileSettingsView(),
    );

    // -----------------------------------------------------------------------
    // Magic Starter: Navigation
    // -----------------------------------------------------------------------
    MagicStarter.useNavigation(
      mainItems: const [
        StarterNavItem(
          icon: Icons.dashboard,
          labelKey: 'nav.dashboard',
          path: '/',
          activeIcon: Icons.dashboard,
        ),
        StarterNavItem(
          icon: Icons.ssid_chart,
          labelKey: 'nav.monitors',
          path: '/monitors',
          activeIcon: Icons.ssid_chart,
        ),
        StarterNavItem(
          icon: Icons.notifications_outlined,
          labelKey: 'nav.alerts',
          path: '/alerts',
          activeIcon: Icons.notifications,
        ),
        StarterNavItem(
          icon: Icons.rule_outlined,
          labelKey: 'nav.alert_rules',
          path: '/alert-rules',
          activeIcon: Icons.rule,
        ),
        StarterNavItem(
          icon: Icons.warning_amber,
          labelKey: 'nav.incidents',
          path: '/incidents',
          activeIcon: Icons.warning_amber,
        ),
        StarterNavItem(
          icon: Icons.dns,
          labelKey: 'nav.status_pages',
          path: '/status-pages',
          activeIcon: Icons.dns,
        ),
      ],
      systemItems: const [
        StarterNavItem(
          icon: Icons.people_outline,
          labelKey: 'nav.team_members',
          path: '/teams/members',
          activeIcon: Icons.people,
        ),
        StarterNavItem(
          icon: Icons.settings,
          labelKey: 'nav.settings',
          path: '/settings',
          activeIcon: Icons.settings,
        ),
      ],
      bottomItems: const [
        StarterNavItem(
          icon: Icons.dashboard_outlined,
          labelKey: 'nav.dashboard',
          path: '/',
          activeIcon: Icons.dashboard,
        ),
        StarterNavItem(
          icon: Icons.ssid_chart_outlined,
          labelKey: 'nav.monitors',
          path: '/monitors',
          activeIcon: Icons.ssid_chart,
        ),
        StarterNavItem(
          icon: Icons.warning_amber_outlined,
          labelKey: 'nav.incidents',
          path: '/incidents',
          activeIcon: Icons.warning_amber,
        ),
        StarterNavItem(
          icon: Icons.settings_outlined,
          labelKey: 'nav.settings',
          path: '/settings',
          activeIcon: Icons.settings,
        ),
      ],
    );

    // -----------------------------------------------------------------------
    // Magic Starter: Team Resolver
    // -----------------------------------------------------------------------
    MagicStarter.useTeamResolver(
      currentTeam: () => User.current.currentTeam?.toStarterTeam(),
      allTeams: () => User.current.allTeams
          .map((t) => t.toStarterTeam())
          .toList(),
      onSwitch: (teamId) async {
        final team = User.current.allTeams.firstWhere(
          (t) => t.id.toString() == teamId.toString(),
        );
        await app.TeamController.instance.switchTeam(team);
      },
    );

    // -----------------------------------------------------------------------
    // Magic Starter: Custom Header
    // -----------------------------------------------------------------------
    MagicStarter.useHeader((context, isDesktop) {
      return AppHeader(showMenuButton: !isDesktop);
    });

    // -----------------------------------------------------------------------
    // Magic Starter: Custom Logout
    // -----------------------------------------------------------------------
    MagicStarter.useLogout(() async {
      // 1. Remove push notification external ID
      try {
        await Notify.logoutPush();
      } catch (e) {
        Log.error('Error logging out from push: $e');
      }

      // 2. Stop notification polling
      try {
        Notify.stopPolling();
      } catch (e) {
        Log.error('Error stopping notification polling: $e');
      }

      // 3. Sign out from social providers
      await SocialAuth.signOut();

      // 4. Logout from the app
      await Auth.logout();

      // 5. Navigate to login
      MagicRoute.to('/auth/login');
    });
  }

}