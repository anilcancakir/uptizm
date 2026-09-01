import 'dart:async';

import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_payments/magic_payments.dart'
    show Payments, StoreBillingService;
import 'package:magic_starter/magic_starter.dart';
import 'package:sentry_dio/sentry_dio.dart';
import '../models/team.dart' show Team;
import '../models/user.dart';
import '../services/locale_application_service.dart';
import '../services/realtime_service.dart';
import '../support/formatters.dart' show formatCount;
import '../support/sentry_network_interceptor.dart';
import '../support/sentry_user_context.dart';
import '../support/team_types.dart' show withUsageCopy;
import '../support/web_links.dart';
import '../../ui/components/notification_center/index.dart';
import '../../ui/components/push_prompt/index.dart';
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

  /// Starts or stops notification delivery to track [Auth]'s current state.
  ///
  /// Mirrors `MagicStarterAppLayout`'s lifecycle
  /// (magic_starter/lib/src/ui/layouts/magic_starter_app_layout.dart:40-50):
  /// `Auth.stateNotifier` bumps on login, logout, and restore, so this single
  /// listener keeps delivery in sync with auth state for the whole app lifetime
  /// instead of being tied to a widget's mount/unmount. Every call is idempotent
  /// (see `Notify.startRealtime`/`startPolling`/`stopPolling` docs).
  ///
  /// The socket comes FIRST and the timer is the fallback. `Notify.startRealtime`
  /// subscribes to this user's private notification channel and returns false when
  /// the app has no broadcast driver; `startPolling()` then no-ops if the socket
  /// came up and arms the 30-second timer if it did not. Chaining rather than
  /// firing both at once is what makes that ordering hold: `startPolling()` cannot
  /// know a socket is coming.
  ///
  /// The channel is the USER's, not the team's, and a team switch therefore does
  /// not move it: a notification belongs to one person, and the team channel every
  /// teammate is subscribed to would hand them each other's inbox. The backend
  /// authorises this name in `routes/channels.php` and shapes the frame for it in
  /// `User::receivesBroadcastNotificationsOn()`.
  void _syncNotificationDeliveryWithAuthState() {
    if (!Auth.check()) {
      Notify.stopRealtime();
      Notify.stopPolling();

      return;
    }

    final String userId = User.current.id;
    if (userId.isEmpty) {
      // A restored session whose user has not resolved yet. Poll for now; the
      // next `Auth.stateNotifier` bump arrives with the identity and upgrades.
      Notify.startPolling();

      return;
    }

    // Wrapped the way [_syncRealtime] is: the listener is synchronous and this is
    // not, so `unawaited` fires it without blocking, and the guard logs a
    // connect-time throw instead of letting it escape as an unhandled async error.
    unawaited(
      Notify.startRealtime(channel: 'App.Models.User.$userId')
          .then((_) => Notify.startPolling())
          .catchError((Object error) {
            Log.error(
              '[AppServiceProvider] notification realtime sync failed: $error',
            );
            // Never leave the bell with neither path.
            Notify.startPolling();
          }),
    );
  }

  /// Declares WHO this device should receive push notifications as, tracking
  /// [Auth]'s current state.
  ///
  /// Attached to `Auth.stateNotifier` like the four syncs around it, so a login,
  /// a sign-out, a boot-time session restore and a team switch all reach it
  /// without any of those call sites knowing a push rail exists. Nothing in this
  /// app called it before: `magic_starter` never wired push either.
  ///
  /// ## The `user_` prefix is this caller's job
  ///
  /// `Notify.initializePush` records its argument as the intent and forwards it
  /// to the SDK unchanged, and the backend addresses `external_id = user_<uuid>`
  /// (`magic-starter-laravel/src/Traits/HasNotifications.php:155`, which uptizm's
  /// `User` inherits). A bare uuid subscribes the device as an id the server
  /// never addresses, so every push simply reaches nobody, on a device that
  /// reports itself correctly subscribed and with nothing anywhere raising.
  ///
  /// ## An empty id declares NOTHING, and never `user_`
  ///
  /// `User.current.id` answers `''` for a session whose user has not resolved
  /// yet (it is `getAttribute('id')?.toString() ?? ''`), and `'user_$userId'`
  /// would turn that into `'user_'`: a well-formed external id addressing
  /// nobody, which the SDK accepts and the reconciler then reports as CONVERGED,
  /// so nothing ever corrects it. Declaring no intent instead leaves whatever
  /// the reconciler already held, and the next `Auth.stateNotifier` bump arrives
  /// with the identity. This is the same branch [_syncNotificationDeliveryWithAuthState]
  /// carries, for the same reason.
  ///
  /// ## Sign-out is released HERE, not by magic_starter
  ///
  /// `MagicStarterAuthController.logout()` is the ecosystem's only caller of
  /// `Notify.logoutPush()`, and it never runs in this app: the starter's profile
  /// dropdown returns early when a custom `onLogout` is set, which [boot] sets.
  /// Releasing the device in this same sync means the release does not depend on
  /// which sign-out path fired, so the next person to sign in on a shared device
  /// cannot be paged for the previous one's outage.
  ///
  /// A team switch bumps this same notifier and issues no SDK call at all: the
  /// person is unchanged, so the intent is unchanged, and the manager reads the
  /// device back before touching the SDK.
  ///
  /// Public only so a test can call it; see `test/app/providers/push_identity_test.dart`.
  /// `Auth.fake(user: ...)` sets its user without bumping the notifier, so a
  /// test cannot reach this through the listener.
  @visibleForTesting
  static void syncPushIdentity() {
    // Wrapped the way [_syncRealtime] is: the notifier's listeners are
    // synchronous and neither call below is. Both guards log at error level
    // rather than swallowing, because nothing retries: until the next bump the
    // device carries the previous person's id, and the manager's
    // `isPushIdentityConverged` is the only other place that says so.
    if (!Auth.check()) {
      unawaited(
        Notify.logoutPush().catchError((Object error) {
          Log.error('[AppServiceProvider] push identity release failed: $error');
        }),
      );

      return;
    }

    final String userId = User.current.id;
    if (userId.isEmpty) return;

    unawaited(
      Notify.initializePush('user_$userId').catchError((Object error) {
        Log.error('[AppServiceProvider] push identity sync failed: $error');
      }),
    );
  }

  // ---------------------------------------------------------------------------
  // Push taps: the other half of a page
  // ---------------------------------------------------------------------------

  /// The push payload key carrying the in-app path a tap should open.
  static const String _pushDeepLinkKey = 'deep_link';

  /// The push payload key naming the team that OWNS what the tap opens.
  static const String _pushTeamKey = 'team_id';

  /// The tap subscription, held statically rather than per instance so a second
  /// boot replaces it instead of stacking a second listener on the same stream.
  static StreamSubscription<PushNotificationEvent>? _pushTapSubscription;

  /// Opens what a tapped push points at.
  ///
  /// `magic_notifications` republishes every tap that passed its own subject
  /// guard on `Notify.manager.onPushClicked`, and nothing in this app was
  /// listening: the backend has always put the incident's path in `deep_link`
  /// (`IncidentOpened::pushData()`), and a tap opened the app wherever it
  /// happened to be. For a product whose push wakes an on-call responder, the
  /// tap IS the page; the notification is only its ring.
  ///
  /// Subscribed here rather than through `magic_deeplink`, whose
  /// `OneSignalDeeplinkHandler` the payload's docblock used to name: that
  /// package's provider boots BEFORE the notifications one, so the driver it
  /// tries to read is not resolvable yet, and the read it attempts is a cast
  /// that throws into an empty catch. Depending on it would have been depending
  /// on a handler that cannot run.
  ///
  /// Idempotent, and that is the whole reason it is a method rather than two
  /// lines in [boot]: a widget test boots this provider repeatedly, and a
  /// subscription per boot means one tap opening one incident N times.
  static void listenForPushTaps() {
    stopListeningForPushTaps();

    _pushTapSubscription = Notify.manager.onPushClicked.listen(
      (PushNotificationEvent event) => unawaited(openTappedPush(event)),
    );
  }

  /// Drops the tap subscription, if there is one.
  ///
  /// The disposal half of [listenForPushTaps], which calls it before
  /// subscribing again. Public so a test can leave the manager's stream as it
  /// found it; the app itself never stops listening, because a tap is
  /// meaningful for as long as the process is alive.
  @visibleForTesting
  static void stopListeningForPushTaps() {
    final StreamSubscription<PushNotificationEvent>? attached =
        _pushTapSubscription;
    _pushTapSubscription = null;

    if (attached != null) unawaited(attached.cancel());
  }

  /// Navigates to what [event] points at.
  ///
  /// Public only so a test can drive the handler directly; the app reaches it
  /// through [listenForPushTaps].
  @visibleForTesting
  static Future<void> openTappedPush(PushNotificationEvent event) async {
    // 1. Where the push says to go. Required to be an in-app PATH: the payload
    //    is server-authored, but a push can also be composed by hand in the
    //    OneSignal dashboard, and handing an arbitrary string to the router is
    //    not a navigation this app should perform.
    final Object? destination = event.data[_pushDeepLinkKey];
    final String deepLink = destination is String ? destination.trim() : '';
    if (!deepLink.startsWith('/')) {
      Log.warning(
        '[AppServiceProvider] push tap names no in-app destination: '
        '"${destination ?? ''}"',
      );

      return;
    }

    // 2. Whose incident it is. An ABSENT team is not evidence of a mismatch,
    //    the way an absent `subject` is not evidence of misaddressing in
    //    `NotificationManager._addressedToIntent`: a server older than this key
    //    must not leave a paged responder on whatever screen they were on.
    final String owner = event.data[_pushTeamKey]?.toString().trim() ?? '';
    if (owner.isEmpty || owner == (User.current.currentTeam?.id ?? '')) {
      MagicRoute.to(deepLink);

      return;
    }

    // 3. The page came from a TEAM-scoped rota and the responder is sitting on
    //    another team, so the incident would 404: `IncidentController::
    //    authorizeTeam` resolves it against `users.current_team_id`, and that
    //    404 is a deliberate non-disclosure choice rather than something to
    //    weaken. Switch rather than ask: during an outage the responder wants
    //    the incident, not a dialog, and refusing to move would leave the tap
    //    doing nothing at all. Through [switchTeamAndIdentifyStore] rather than
    //    the controller directly, because the team is also the paying subject
    //    and the store rail has to follow it.
    final bool switched = await switchTeamAndIdentifyStore(owner);
    if (!switched) {
      // NOT navigating is the point: the backend still resolves the incident
      // against a team the session is not on, so going anyway lands on the same
      // 404 with an extra step. Logged as well as surfaced because nothing
      // retries, and a responder who cannot reach a live incident is an
      // operational failure rather than a UI hiccup.
      Log.error(
        '[AppServiceProvider] push tap could not switch to team $owner; '
        'staying put rather than opening $deepLink on a 404',
      );
      Magic.error(trans('common.error_occurred'), trans('teams.switch_failed'));

      return;
    }

    // 4. Say so. The switch is silent otherwise, and a responder who resolves
    //    the incident and carries on would be reading another team's dashboard
    //    believing they are still on their own.
    Magic.success(
      trans('uptizm.incidents.push_team_switch_toast_title'),
      trans('uptizm.incidents.push_team_switch_toast_description'),
    );
    MagicRoute.to(deepLink);
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

  /// Switches the active team, then binds the STORE rail to the team the app
  /// landed on.
  ///
  /// This is the app's `onSwitch` handler, and the identify call rides the TEAM
  /// SWITCH rather than the app login because the paying subject here is the
  /// team, not the person: the RevenueCat App User ID is the team id, so a
  /// customer who signs in, switches team and then buys would otherwise have the
  /// purchase attributed to the team they left. It is the same class of bug as a
  /// controller keeping the previous tenant's rows after a switch.
  ///
  /// AFTER the switch resolves, and only when it succeeded. A refused switch
  /// leaves the app on the team it was already on, so re-identifying there would
  /// bind the store account to a team the user never landed on. The id passed is
  /// the one the switch was accepted for, which is what the backend's
  /// `users.current_team_id` now holds and therefore what `GET /billing` and the
  /// rail's webhook will both resolve.
  ///
  /// Answers whether the switch took, which is what [openTappedPush] turns on:
  /// a tap that navigates after a refused switch lands on the same 404 it was
  /// switching to avoid. `MagicStarter.bootstrap`'s `onSwitch` declares a
  /// `Future<void>`, and a `Future<bool>` satisfies it, so the team switcher is
  /// unaffected by the answer being there.
  ///
  /// Public only so a test can call the real handler; see the test group in
  /// `test/app/providers/store_identity_test.dart`, which asserts the id at the
  /// point the identify happens rather than on a flag set before it.
  @visibleForTesting
  static Future<bool> switchTeamAndIdentifyStore(dynamic teamId) async {
    final bool switched = await MagicStarterTeamController.instance.switchTeam(
      teamId,
    );
    if (!switched) return false;

    await _identifyStoreCustomer(teamId?.toString() ?? '');

    return true;
  }

  /// Re-points the STORE rail at the team the current session is on.
  ///
  /// Attached to `Auth.stateNotifier` like the three syncs above, so a login and
  /// a boot-time session restore both reach it without either call site knowing
  /// a store rail exists. It does NOT replace
  /// [switchTeamAndIdentifyStore]: identifying at login alone is what leaves a
  /// purchase made after a switch attributed to the team the customer left, and
  /// identifying on the switch alone leaves a fresh install that never switched
  /// bound to the rail's own anonymous id, which the backend cannot map to any
  /// team at all. Both moments are needed, and they overlap harmlessly:
  /// `switchTeam` finishes with `Auth.restore()`, which bumps this notifier while
  /// the restored payload may still name the previous team, so the switch's own
  /// call is the authoritative one and it runs last.
  ///
  /// A signed-out session identifies nothing and does not unbind what the
  /// previous one set. `User.current` falls back to a blank `User()` with no
  /// team, so the empty id below is what makes the logged-out case a no-op;
  /// there is deliberately no second `Auth.check()` guard in front of it,
  /// because two guards on one outcome mean neither can be tested alone. And
  /// nothing unbinds on logout: the contract has no logout call (the store
  /// account belongs to the device, not to our session), nothing can spend
  /// against a stale binding meanwhile because the billing screen is behind
  /// auth, and the next login overwrites it.
  ///
  /// Public only so a test can call it; see the test group in
  /// `test/app/providers/store_identity_test.dart`.
  @visibleForTesting
  static void syncStoreIdentity() {
    // Wrapped the way [_syncRealtime] is: the notifier's listeners are
    // synchronous and this is not.
    unawaited(_identifyStoreCustomer(User.current.currentTeam?.id ?? ''));
  }

  /// Tells the STORE rail which team it is buying for, when there is a rail and
  /// a team to name.
  ///
  /// A build with no store rail (web, desktop) has nothing to identify, and that
  /// absence is an answer rather than an error. An empty id is the same kind of
  /// absence: a session whose team has not resolved yet, whose next
  /// `Auth.stateNotifier` bump arrives with one.
  static Future<void> _identifyStoreCustomer(String appUserId) async {
    final StoreBillingService? store = Payments.store;
    if (store == null || appUserId.isEmpty) return;

    try {
      await store.identify(appUserId);
    } catch (error) {
      // Logged at error level rather than swallowed, and deliberately not
      // surfaced to the user: whatever prompted this (a login, a restore, a
      // completed switch) succeeded, so failing it now would strand them. What
      // survives is the previously bound account, which is why this is an error
      // and not a warning: until the next successful identify, a store purchase
      // would be attributed to the previous team.
      Log.error(
        '[AppServiceProvider] store identify failed for team $appUserId: $error',
      );
    }
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

  // ---------------------------------------------------------------------------
  // Billing: everything magic_starter's screen deliberately does not know
  // ---------------------------------------------------------------------------

  /// The endpoint answering which of the caller's teams a store account already
  /// funds. See [readStoreFundedTeam] for why this app asks it directly.
  static const String _storeFundedTeamPath = '/billing/store-funded-team';

  /// The one key that endpoint answers with: `null`, or an object naming the
  /// team.
  static const String _storeFundedTeamKey = 'store_funded_team';

  /// The membership role that owns a team's billing, as the server names it in
  /// the team payload's `user_role`.
  static const String _ownerRole = 'owner';

  /// Wires magic_starter's billing screen into this app.
  ///
  /// The package ships the whole screen and none of the product: the words for
  /// a metered resource, the separator between a number's thousands, the two
  /// product lines on a plan card, the cross-team store check and the caller's
  /// membership role are all things a published package cannot answer without
  /// picking one adopter's answer for everybody. Each arrives here.
  ///
  /// ## Why `Magic.put` rather than the usual `findOrPut`
  ///
  /// [MagicStarterBillingController] takes required collaborators, so there is
  /// no zero-argument constructor for `findOrPut` to call, and the view
  /// resolves it through `Magic.find` on mount. It therefore has to be in the
  /// registry before the route builds, which is what this call at boot
  /// guarantees.
  ///
  /// ## Why AFTER `SessionScopeSync.attach()`
  ///
  /// The load-bearing half of the ordering. `attach()` records the identity a
  /// restored session boots with, and recording a first non-null identity IS a
  /// change, so every [SessionScopedController] already in the registry is
  /// reset (cleared and refetched) right then. Every other controller in this
  /// app is a `findOrPut` singleton that only enters the registry when a view
  /// mounts, so at boot that loop is empty. This one is put eagerly, so putting
  /// it BEFORE the attach would fire all six billing reads on every launch,
  /// for every customer, including the ones who never open the screen.
  ///
  /// Registered at boot rather than lazily on the route because the reset is
  /// the only thing that refreshes this controller after its first mount:
  /// `onInit` runs once per instance lifetime, so a customer who opens billing,
  /// switches team and opens it again would otherwise be looking at the
  /// previous team's plan, invoices and card.
  static void registerBillingSurface() {
    Magic.put(
      MagicStarterBillingController(
        usageCopy: withUsageCopy,
        formatNumber: formatCount,
        storeFundedTeamReader: readStoreFundedTeam,
        isOwnerReader: readTeamOwnership,
      ),
    );

    // The one slot the package's plan card leaves open, and it carries the
    // whole catalogue row so this app renders BOTH of the fields the package
    // never typed. `bg-ai-soft` is the reason the tile lives on this side:
    // it is uptizm's own supplement (`lib/config/uptizm_status_tokens.dart`),
    // and Wind drops an unknown token silently, so a shared component
    // referencing it would render no background at all in every other app.
    MagicStarter.view.slot(
      'teams.billing',
      'plan_card_highlight',
      (BuildContext context) => const _PlanCardHighlight(),
    );
  }

  /// Names another of this user's teams that a store account already funds, or
  /// `null` when none does.
  ///
  /// magic_starter's cross-team store check, which the package cannot answer
  /// for itself: the question is about TEAMS, which `magic_payments` knows
  /// nothing about. A store account holds at most one active subscription per
  /// subscription group, so a second purchase does not open a second
  /// subscription, it TRANSFERS the one that exists and silently stops funding
  /// the team that had it. The screen refuses by NAME before offering to buy,
  /// and asks again at the tap.
  ///
  /// Every unusable answer degrades to `null`, which the screen reads
  /// PERMISSIVELY: a non-2xx, a payload with no `store_funded_team` object, an
  /// object with no name. That is the deliberate side to fail on, because the
  /// backend's transfer handling keeps the entitlement itself honest either
  /// way, so what this check prevents is a surprised customer rather than a
  /// wrong charge.
  ///
  /// A THROW is deliberately not caught here. The controller wraps this call
  /// and logs the failure (`loadStoreFundedTeam`), which is what keeps the
  /// degradation from being silent: swallowing it once let a real 500 on this
  /// endpoint pass a transfer through with nothing in any log to explain it
  /// afterwards.
  static Future<String?> readStoreFundedTeam() async {
    final MagicResponse response = await Http.get(_storeFundedTeamPath);
    if (!response.successful) return null;

    final Object? funded = response[_storeFundedTeamKey];
    if (funded is! Map) return null;

    final Object? name = funded['name'];
    if (name is! String || name.isEmpty) return null;

    return name;
  }

  /// Whether the signed-in user owns the current team, or `null` when that is
  /// genuinely unresolved.
  ///
  /// magic_starter's ownership seam. `MagicStarterTeam` carries an id, a name,
  /// a photo and whether the team is personal, and no membership ROLE at all,
  /// so the caller's own role exists only in this app's [Team] model.
  ///
  /// Tri-state rather than a bool, because the two negative answers lead
  /// somewhere different: a KNOWN non-owner is told the owner handles billing,
  /// while an UNRESOLVED membership must not stand between an owner and paying
  /// us. Four things answer `null` rather than `false` for that reason: no auth
  /// container, nobody signed in, no current team, and a team payload that
  /// carried no role and no owner id.
  ///
  /// The auth read is guarded behind the container binding, mirroring
  /// `LocalePromptBanner.shouldShow`: the running app always binds `auth`, but a
  /// widget test mounting the screen without one must not crash on the probe.
  /// This is asked during build and on every gate read, so it ANSWERS rather
  /// than throwing; an exception out of a gate takes down a screen whose whole
  /// design is that no single read can.
  ///
  /// `user_role` is preferred over the owner-id comparison because it is the
  /// caller's OWN membership as the server resolved it, which is the same
  /// question the server's `BillingPolicy` answers; the `owner_id` comparison is
  /// the fallback for a payload that carried no role.
  static bool? readTeamOwnership() {
    if (!Magic.bound('auth') || !Auth.check()) return null;

    final Team? team = User.current.currentTeam;
    if (team == null) return null;

    final String? role = team.userRole;
    if (role != null) return role == _ownerRole;

    return team.ownerId == null ? null : team.isOwner;
  }

  /// Registers the two things `magic_notifications` asks this app for.
  ///
  /// Both are slots on `Notify.view`, the notification package's own view
  /// registry, and it is deliberately the same shape as the
  /// `MagicStarter.view.slot('settings.hub', ...)` call above: a published
  /// package owns the screen, the adopter fills the parts only it can answer.
  ///
  /// 1. **What a notification type looks like.** The package dropped its
  ///    hardcoded `monitor_down` / `monitor_up` / `monitor_degraded` icon map,
  ///    because that is one monitoring product's vocabulary, and asks through
  ///    the `notifications.icon` slot family instead: the slot NAME is the
  ///    notification type. Uptizm answers with its own status dot, per type,
  ///    plus the `default` slot so a type this build has never seen still gets
  ///    a dot rather than the package's neutral bell.
  /// 2. **The push soft prompt**, into the preference screen's header slot.
  ///    The package deliberately ships no prompt widget (the one it had was a
  ///    Material dialog with hardcoded English), only the four-state
  ///    reachability read this app's [PushPromptHost] renders.
  ///
  /// A `static` method rather than inline in [boot] for the same reason
  /// [registerBillingSurface] is one: a widget test can call it directly and
  /// assert the seam resolves, which is what catches a bell remounted on one
  /// side of the `lg` breakpoint and not the other.
  static void registerNotificationSurface() {
    for (final MapEntry<String, AppNotificationKind> entry
        in kNotificationKindsByEventType.entries) {
      Notify.view.slot(
        NotificationViewRegistry.typeIconSlotView,
        entry.key,
        (context) => NotificationCenter(kind: entry.value),
      );
    }

    Notify.view.slot(
      NotificationViewRegistry.typeIconSlotView,
      NotificationViewRegistry.typeIconFallbackSlot,
      (context) =>
          const NotificationCenter(kind: AppNotificationKind.incident),
    );

    Notify.view.slot(
      'notifications.preferences',
      'header',
      (context) => const PushPromptHost(),
    );
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
      // Not `switchTeam` directly: the store rail has to be told which team it
      // is buying for, and a switch is the one moment where the app's answer to
      // that changes without a new session (which `syncStoreIdentity` covers).
      onSwitch: switchTeamAndIdentifyStore,
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

    // Notifications: the two things `magic_notifications` asks the adopter for.
    registerNotificationSurface();

    // Notifications: start polling immediately if a session was restored on
    // boot, then keep polling in lockstep with every future login/logout via
    // `Auth.stateNotifier`.
    _syncNotificationDeliveryWithAuthState();
    Auth.stateNotifier.addListener(_syncNotificationDeliveryWithAuthState);

    // Push identity: subscribe this device as the person a restored session
    // boots with, then follow every login and sign-out. The delivery sync above
    // covers the bell inside the app; this one covers the notification that
    // arrives while the app is closed, which is the one an on-call responder is
    // actually paged by. See [syncPushIdentity] for why the `user_` prefix, the
    // empty-id branch and the sign-out release all belong on this side.
    syncPushIdentity();
    Auth.stateNotifier.addListener(syncPushIdentity);

    // Push taps: the other half of that page. The identity sync above decides
    // WHO gets woken up; this decides where they land when they act on it. See
    // [listenForPushTaps] for why the subscription lives here rather than in
    // `magic_deeplink`, and why it is idempotent.
    listenForPushTaps();

    // Realtime: subscribe to the team's private channel immediately if a
    // session was restored on boot, then keep the subscription in lockstep
    // with every future login/logout/restore/team-switch via
    // `Auth.stateNotifier`.
    _syncRealtime();
    Auth.stateNotifier.addListener(_syncRealtime);

    // Store rail: point it at the team a restored session boots on, then keep it
    // there through every login. A team SWITCH is identified by the `onSwitch`
    // handler above rather than here; see syncStoreIdentity for why both
    // moments are needed and why the overlap is harmless.
    syncStoreIdentity();
    Auth.stateNotifier.addListener(syncStoreIdentity);

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

    // Billing: the collaborators magic_starter's screen resolves at render
    // time. Registered AFTER the attach above, and that ordering is the whole
    // reason this call sits last: see [registerBillingSurface].
    registerBillingSurface();
  }
}

/// Uptizm's two product lines on a magic_starter plan card.
///
/// Rendered into the billing screen's `teams.billing.plan_card_highlight` slot
/// (wired in [AppServiceProvider.registerBillingSurface]). The package types the
/// eight fields every billing screen needs and leaves the rest of a catalogue
/// row in `MagicStarterPlan.raw`, which is what this reads, because both of
/// these are uptizm's own product rather than anything a payment rail
/// understands:
///
/// 1. **The AI tile** (`ai_line`), the value each upgrade buys. It carries
///    `bg-ai-soft` and `text-ai`, uptizm's own status supplement, which is
///    exactly why the tile could not stay in the package: Wind drops an unknown
///    token silently, so a shared component referencing one renders no
///    background at all in every app that has not hand-authored it.
/// 2. **The responder surcharge** (`responder_add_on`, e.g. `+$9/mo per extra
///    responder` on two tiers). This one is a recurring CHARGE, so dropping it
///    takes money out of a purchase decision, which is a different class of
///    harm from dropping the claim about value above it.
///
/// A tier carrying neither renders nothing. The plan card's own column is
/// `gap-4`, so the empty box still costs one gap slot: the package gates the
/// slot on a builder being REGISTERED rather than on the widget it built, and a
/// slot builder must return a widget. Every tier uptizm ships carries an
/// `ai_line`, so nothing in this product reaches that arm today.
class _PlanCardHighlight extends StatelessWidget {
  const _PlanCardHighlight();

  /// The glyph on the AI tile.
  static const IconData _sparkleIcon = Icons.auto_awesome;

  @override
  Widget build(BuildContext context) {
    // 1. The whole catalogue row, from the scope the package wraps each card's
    //    slot subtree in.
    final MagicStarterPlan plan = MagicStarterPlanCardScope.of(context);

    // 2. Read both opaque fields defensively. The catalogue is served verbatim
    //    from the backend, and a tier that carries neither key (or carries a
    //    null, as the free and enterprise tiers do for the surcharge) draws no
    //    line rather than an empty one.
    final Object? rawAiLine = plan.raw['ai_line'];
    final Object? rawAddOn = plan.raw['responder_add_on'];
    final String? aiLine = rawAiLine is String && rawAiLine.isNotEmpty
        ? rawAiLine
        : null;
    final String? responderAddOn = rawAddOn is String && rawAddOn.isNotEmpty
        ? rawAddOn
        : null;

    // 3. Nothing to say.
    if (aiLine == null && responderAddOn == null) {
      return const SizedBox.shrink();
    }

    return WDiv(
      className: 'flex flex-col gap-3',
      children: <Widget>[
        if (aiLine != null)
          WDiv(
            className:
                'flex flex-row items-start gap-2 rounded-md '
                'bg-ai-soft p-2.5',
            children: <Widget>[
              WIcon(_sparkleIcon, className: 'text-[16px] text-ai'),
              WText(
                aiLine,
                className: 'flex-1 text-xs leading-relaxed text-fg',
              ),
            ],
          ),
        if (responderAddOn != null)
          WText(responderAddOn, className: 'text-xs text-fg-muted'),
      ],
    );
  }
}
