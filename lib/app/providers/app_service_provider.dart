import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_social_auth/magic_social_auth.dart';
import 'package:magic_starter/magic_starter.dart';
import '../models/user.dart';
import '../policies/team_policy.dart';
import '../policies/monitor_policy.dart';

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

    // -----------------------------------------------------------------------
    // Magic Starter: Navigation
    // -----------------------------------------------------------------------
    MagicStarter.useNavigation(
      mainItems: const [
        MagicStarterNavItem(
          icon: Icons.dashboard,
          labelKey: 'nav.dashboard',
          path: '/',
          activeIcon: Icons.dashboard,
        ),
        MagicStarterNavItem(
          icon: Icons.ssid_chart,
          labelKey: 'nav.monitors',
          path: '/monitors',
          activeIcon: Icons.ssid_chart,
        ),
        MagicStarterNavItem(
          icon: Icons.notifications_outlined,
          labelKey: 'nav.alerts',
          path: '/alerts',
          activeIcon: Icons.notifications,
        ),
        MagicStarterNavItem(
          icon: Icons.rule_outlined,
          labelKey: 'nav.alert_rules',
          path: '/alert-rules',
          activeIcon: Icons.rule,
        ),
        MagicStarterNavItem(
          icon: Icons.warning_amber,
          labelKey: 'nav.incidents',
          path: '/incidents',
          activeIcon: Icons.warning_amber,
        ),
        MagicStarterNavItem(
          icon: Icons.dns,
          labelKey: 'nav.status_pages',
          path: '/status-pages',
          activeIcon: Icons.dns,
        ),
      ],
      systemItems: const [],
      bottomItems: const [
        MagicStarterNavItem(
          icon: Icons.dashboard_outlined,
          labelKey: 'nav.dashboard',
          path: '/',
          activeIcon: Icons.dashboard,
        ),
        MagicStarterNavItem(
          icon: Icons.ssid_chart_outlined,
          labelKey: 'nav.monitors',
          path: '/monitors',
          activeIcon: Icons.ssid_chart,
        ),
        MagicStarterNavItem(
          icon: Icons.warning_amber_outlined,
          labelKey: 'nav.incidents',
          path: '/incidents',
          activeIcon: Icons.warning_amber,
        ),
        MagicStarterNavItem(
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
      currentTeam: () => User.current.currentTeam?.toMagicStarterTeam(),
      allTeams: () =>
          User.current.allTeams.map((t) => t.toMagicStarterTeam()).toList(),
      onSwitch: (teamId) async {
        await MagicStarterTeamController.instance.switchTeam(teamId);
      },
    );

    // -----------------------------------------------------------------------
    // Magic Starter: Custom Header
    // -----------------------------------------------------------------------
    // MagicStarter.useHeader((context, isDesktop) {
    //   return AppHeader(showMenuButton: !isDesktop);
    // });

    // -----------------------------------------------------------------------
    // Magic Starter: Social Login
    // -----------------------------------------------------------------------
    // Create a ValueNotifier to track social login loading state
    final socialLoginProviderNotifier = ValueNotifier<String?>('');

    MagicStarter.useSocialLogin((context, isLoading) {
      return ValueListenableBuilder<String?>(
        valueListenable: socialLoginProviderNotifier,
        builder: (context, loadingProvider, _) {
          // When the main form is loading, disable all social buttons
          // by providing a non-null provider that matches no button.
          final effectiveLoadingProvider = isLoading ? '' : loadingProvider;

          return SocialAuthButtons(
            onAuthenticate: (provider) async {
              socialLoginProviderNotifier.value = provider;
              try {
                await SocialAuth.driver(provider).authenticate();
              } catch (e) {
                Log.error('Social login error: $e');
              } finally {
                socialLoginProviderNotifier.value = '';
              }
            },
            loadingProvider: effectiveLoadingProvider,
          );
        },
      );
    });

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
