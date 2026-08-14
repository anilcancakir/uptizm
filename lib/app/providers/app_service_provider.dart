import 'dart:async';

import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:sentry_dio/sentry_dio.dart';
import '../models/user.dart';
import '../services/locale_application_service.dart';
import '../services/realtime_service.dart';
import '../support/sentry_network_interceptor.dart';
import '../support/sentry_user_context.dart';
import '../support/web_links.dart';
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

  /// The locale/timezone application service, held for the app's lifetime.
  final LocaleApplicationService _localeApplication =
      LocaleApplicationService();

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

  /// Re-syncs the applied locale to track [Auth]'s current state.
  ///
  /// `Auth.stateNotifier` listeners are synchronous, but
  /// `LocaleApplicationService.syncLocaleWithAuthState()` is async (it may
  /// await `Lang.setLocale`), so this wraps the call the same way
  /// [_syncRealtime] does: `unawaited` fires it without blocking the
  /// listener, and the `catchError` guard logs a failure instead of letting
  /// it escape as an unhandled async error.
  void _syncLocale() {
    unawaited(
      _localeApplication.syncLocaleWithAuthState().catchError((Object error) {
        Log.error('[AppServiceProvider] locale sync failed: $error');
      }),
    );
  }

  /// Re-points magic_starter's legal links at the ACTIVE language.
  ///
  /// `Magic.init` evaluates every config factory before it boots a single
  /// provider, so the `magic_starter.legal` block in
  /// `lib/config/magic_starter.dart` is composed while [Lang] still holds its
  /// pre-detection default. This runs after `LocalizationServiceProvider.boot()`
  /// has resolved the real locale (that provider is registered ahead of this
  /// one in `app.providers`, and boot order follows registration order), and
  /// again on every runtime locale change, so the sign-up screen's Terms and
  /// Privacy links always open the document in the language on screen.
  static void _syncLegalLinks() {
    Config.set('magic_starter.legal', WebLinks.legalConfig);
  }

  /// Attach Sentry to the network driver, in two layers.
  ///
  /// `addSentry()` (from `sentry_dio`) contributes the automatic half: an HTTP
  /// breadcrumb and a span per request, so a captured failure arrives with the
  /// calls that preceded it. `captureFailedRequests` is turned OFF because its
  /// events are raised inside the sentry_dio adapter, which means their stack
  /// trace points at the SDK rather than at the caller, and they cannot carry
  /// the endpoint-based fingerprint this app needs.
  ///
  /// [SentryNetworkInterceptor] is the second layer and does the actual
  /// reporting, from magic's own interceptor chain where the real call site is
  /// still on the stack. It runs after `addSentry()` so its events carry the
  /// breadcrumbs that layer just recorded.
  ///
  /// The driver is resolved from the container rather than constructed, so a
  /// host that never registered one (a bare widget test) would throw here and
  /// take the whole boot with it. Reporting is not worth failing a boot over,
  /// so the failure degrades to a log line, mirroring how magic registers its
  /// own auth interceptor. It is caught NARROWLY and reported, not swallowed.
  static void _registerSentryNetworkInterceptor() {
    try {
      final NetworkDriver driver = Magic.make<NetworkDriver>('network');

      if (driver is DioNetworkDriver) {
        driver.configureDriver((dio) => dio.addSentry(
          captureFailedRequests: false,
        ));
      }

      driver.addInterceptor(SentryNetworkInterceptor());
    } catch (error) {
      Log.warning('Could not wire Sentry into the network driver: $error');
    }
  }

  @override
  Future<void> boot() async {
    // Report HTTP failures that would otherwise be invisible. magic's Http
    // facade returns failures as VALUES rather than throwing, so no global
    // error handler ever sees them and this app's own habit is to log and
    // return quietly, which in a release build means the visitor's console and
    // nowhere else. Registered here rather than in main() because the network
    // driver is resolved from the container, which is only populated once
    // Magic.init has run. Inert without a DSN, like the rest of the SDK.
    _registerSentryNetworkInterceptor();

    // Perform async bootstrap logic here.
    //
    // IMPORTANT: Call setUserFactory() so Auth.user<T>() returns your model:
    //   Auth.manager.setUserFactory((data) => User.fromMap(data));
    // Magic Starter: Register user factory for auth session restoration.
    Auth.manager.setUserFactory((data) => User.fromMap(data));

    // Keep Sentry's user card in step with the session. It follows
    // `Auth.stateNotifier`, so login, logout, a boot-time restore and a team
    // switch all reach it without any of those call sites knowing Sentry
    // exists. See SentryUserContext for why logout has to clear it rather than
    // leave the previous operator attached.
    //
    // AFTER setUserFactory, not before: `install()` reads the current session
    // immediately, and `Auth.user<User>()` cannot hydrate a User until the
    // factory above is registered. Ordered the other way it would report an
    // anonymous first session on every boot that restored a signed-in user.
    SentryUserContext.install();

    // Magic Starter: the identity contract, in one required call. The team
    // callbacks are passed because uptizm enables the teams feature; omitting
    // them would throw rather than silently degrade the team switcher.
    MagicStarter.bootstrap(
      userFactory: (data) => User.fromMap(data),
      onLogout: () async {
        await Auth.logout();
        MagicRoute.to(MagicStarterConfig.loginRoute());
      },
      locales: {'en': 'English', 'tr': 'Türkçe'},
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

    // Magic Starter: uptizm's page geometry, for every page in the app.
    //
    // This is the single place the app decides how wide a page is and how far
    // its content sits from the edges. `MSPageContainer` reads it, and every
    // page goes through `MSPageContainer` (uptizm's own views directly, the
    // starter's account pages through `MSPageScaffold`), so nothing can drift.
    // Sharing the shell was not enough to look like one app: while each surface
    // carried its own answer, the settings header started 64px further out per
    // side than every other page and the team pages capped at nothing at all.
    //
    // The values: `max-w-6xl` is the shared content width from DESIGN.md.
    // Horizontal margins ride the 8pt grid (16px compact, 20px regular, 32px
    // wide). `pb-24` (96px) is the one non-obvious part: the Assistant FAB
    // floats bottom-right OVER the content region, and without that clearance it
    // covered the last row of every scrolled page.
    MagicStarter.manager.pageContainerClassName =
        'max-w-6xl px-4 sm:px-5 lg:px-8 pt-6 sm:pt-8 pb-24';

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

    // Locale: apply the authenticated user's persisted `locale` immediately
    // if a session was restored on boot, then keep it in sync with every
    // future login/restore via `Auth.stateNotifier`. Pre-login, magic's own
    // `auto_detect_locale` already renders the device locale.
    _syncLocale();
    Auth.stateNotifier.addListener(_syncLocale);

    // Legal links: point the sign-up screen's Terms / Privacy links at the
    // website documents in the language that is actually on screen, then keep
    // them there through every locale change (device detection above, the
    // user's persisted preference, or the in-app language picker).
    _syncLegalLinks();
    Lang.addListener(_syncLegalLinks);

    // Session scope: record the identity a restored session boots with, then
    // clear + refetch every registered domain controller on each subsequent
    // login / team switch so no screen keeps the previous identity's rows.
    // Attached last on purpose: polling, realtime and locale should already be
    // pointed at the new session before its data is refetched.
    SessionScopeSync.attach();
  }
}
