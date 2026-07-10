import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import '../models/user.dart';
import '../../ui/layouts/app_layout.dart';
import '../../ui/layouts/uptizm_hub_extras.dart';

/// Application Service Provider.
///
/// Use this provider to bind your own services to the IoC container and
/// to perform any bootstrap logic that requires other services to be ready.
class AppServiceProvider extends ServiceProvider {
  AppServiceProvider(super.app);

  @override
  void register() {
    // Bind your services here (sync only — do not resolve other services).
    // Example:
    //   app.singleton('my_service', () => MyService());
  }

  @override
  Future<void> boot() async {
    // Perform async bootstrap logic here.
    //
    // IMPORTANT: Call setUserFactory() so Auth.user<T>() returns your model:
    //   Auth.manager.setUserFactory((data) => User.fromMap(data));
    // Magic Starter: Register user factory for auth session restoration.
    Auth.manager.setUserFactory((data) => User.fromMap(data));
    MagicStarter.useUserModel((data) => User.fromMap(data));

    // Magic Starter: Logout callback.
    MagicStarter.useLogout(() async {
      await Auth.logout();
      MagicRoute.to(MagicStarterConfig.loginRoute());
    });

    // Magic Starter: Supported locale options for profile settings.
    MagicStarter.useLocaleOptions({'en': 'English'});

    // Magic Starter: Team resolver for sidebar team switcher.
    MagicStarter.useTeamResolver(
      currentTeam: () => User.current.currentTeam?.toMagicStarterTeam(),
      allTeams: () =>
          User.current.allTeams.map((t) => t.toMagicStarterTeam()).toList(),
      onSwitch: (teamId) =>
          MagicStarterTeamController.instance.switchTeam(teamId),
    );

    // Magic Starter: Render the starter account/settings routes inside uptizm's
    // own app shell instead of the starter's default layout. The starter
    // resolves its route layout through the `layout.app` view key, so
    // overriding it here gives login-gated account pages the exact same chrome
    // (sidebar, notification bell, team switcher, AI assistant) as the
    // monitoring surface: one consistent shell across the whole app.
    MagicStarter.view.registerLayout(
      'layout.app',
      (child) => AppLayout(child: child),
    );

    // Magic Starter: Inject uptizm's Team + About groups into the settings hub
    // via its footer slot. The starter owns Account/Security/Preferences; these
    // two groups link only the kept uptizm-domain team-ops + static routes.
    MagicStarter.view.slot(
      'settings.hub',
      'footer',
      (context) => const UptizmHubExtras(),
    );
  }
}
