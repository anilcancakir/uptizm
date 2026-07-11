import 'dart:async';

import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_starter/magic_starter.dart';
import '../models/user.dart';
import '../services/realtime_service.dart';
import '../../ui/layouts/app_layout.dart';
import '../../ui/layouts/uptizm_hub_extras.dart';

/// Application Service Provider.
///
/// Use this provider to bind your own services to the IoC container and
/// to perform any bootstrap logic that requires other services to be ready.
class AppServiceProvider extends ServiceProvider {
  AppServiceProvider(super.app);

  /// The realtime channel subscription service, held for the app's lifetime.
  final RealtimeService _realtime = RealtimeService();

  @override
  void register() {
    // Bind your services here (sync only — do not resolve other services).
    // Example:
    //   app.singleton('my_service', () => MyService());
  }

  /// Starts or stops notification polling to track [Auth]'s current state.
  ///
  /// Mirrors `MagicStarterAppLayout`'s lifecycle
  /// (magic_starter/lib/src/ui/layouts/magic_starter_app_layout.dart:40-50):
  /// `Auth.stateNotifier` bumps on login, logout, and restore, so this single
  /// listener keeps polling in sync with auth state for the whole app
  /// lifetime instead of being tied to a widget's mount/unmount. Both calls
  /// are idempotent (see `Notify.startPolling`/`stopPolling` docs).
  static void _syncPollingWithAuthState() {
    if (Auth.check()) {
      Notify.startPolling();
    } else {
      Notify.stopPolling();
    }
  }

  /// Re-syncs the realtime channel subscription to track [Auth]'s current
  /// state.
  ///
  /// `Auth.stateNotifier` listeners are synchronous, but
  /// `RealtimeService.syncWithAuthState()` is async (it awaits the Echo
  /// connect/subscribe), so this wraps the call: `unawaited` fires it without
  /// blocking the listener, and the `catchError` guard logs a connect-time
  /// throw instead of letting it escape as an unhandled async error.
  void _syncRealtime() {
    unawaited(
      _realtime.syncWithAuthState().catchError((Object error) {
        Log.error('[AppServiceProvider] realtime sync failed: $error');
      }),
    );
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

    // Notifications: start polling immediately if a session was restored on
    // boot, then keep polling in lockstep with every future login/logout via
    // `Auth.stateNotifier`.
    _syncPollingWithAuthState();
    Auth.stateNotifier.addListener(_syncPollingWithAuthState);

    // Realtime: subscribe to the team's private channel immediately if a
    // session was restored on boot, then keep the subscription in lockstep
    // with every future login/logout/restore/team-switch via
    // `Auth.stateNotifier`.
    _syncRealtime();
    Auth.stateNotifier.addListener(_syncRealtime);
  }
}
