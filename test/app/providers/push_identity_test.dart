import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart'
    show
        Notify,
        PushDriver,
        PushIdentityChange,
        PushNotificationEvent,
        PushPermissionState;
import 'package:uptizm/app/models/user.dart' show User;
import 'package:uptizm/app/providers/app_service_provider.dart';

/// Locks WHICH account this device is subscribed as, at every moment the app
/// declares one.
///
/// The external id the push SDK holds is what the backend addresses a push to
/// (`external_id = user_<uuid>`), so declaring the wrong one pages the wrong
/// human during an outage and declaring none pages nobody at all. Neither
/// failure is visible on screen, neither raises anything, and the second one is
/// the quieter of the two: `'user_' + ''` is a string the SDK accepts and the
/// reconciler then reports as converged.
///
/// [AppServiceProvider.syncPushIdentity] is uptizm's own, so its cover is too:
/// `magic_notifications` forwards whatever it is given, and `magic_starter`'s
/// sign-out path does not run in this app.
class _RecordingPushDriver extends PushDriver {
  /// Every identity call issued, in call order: `login:<id>` or `logout`.
  ///
  /// The ordered list, rather than a "did it log in" flag: a flag read after the
  /// fact passes on a device subscribed as the wrong person, and the id IS the
  /// subject of every assertion here.
  final List<String> calls = <String>[];

  /// The external id this fake device carries.
  ///
  /// Held and read back the way a real SDK does, because the manager reconciles
  /// against it: a double that always answered `null` would issue a second login
  /// for an identity the device already holds and turn the two idempotence
  /// assertions below into false failures.
  String? _externalId;

  @override
  String get name => 'onesignal';

  @override
  bool get isSupported => true;

  @override
  bool get isOptedIn => true;

  @override
  Future<PushPermissionState> permissionState() async {
    return PushPermissionState.authorized;
  }

  @override
  Future<void> initialize(Map<String, dynamic> config) async {}

  @override
  Future<void> login(String externalId) async {
    calls.add('login:$externalId');
    _externalId = externalId;
  }

  @override
  Future<void> logout() async {
    calls.add('logout');
    _externalId = null;
  }

  @override
  Future<String?> currentExternalId() async => _externalId;

  @override
  Future<String?> currentSubscriptionId() async => 'subscription-1';

  @override
  Future<bool> requestPermission() async => true;

  @override
  Future<void> optIn() async {}

  @override
  Future<void> optOut() async {}

  @override
  Future<void> setTags(Map<String, String> tags) async {}

  @override
  Future<void> removeTag(String key) async {}

  @override
  Stream<PushNotificationEvent> get onNotificationReceived =>
      const Stream<PushNotificationEvent>.empty();

  @override
  Stream<PushNotificationEvent> get onNotificationClicked =>
      const Stream<PushNotificationEvent>.empty();

  @override
  Stream<PushPermissionState> get onPermissionChanged =>
      const Stream<PushPermissionState>.empty();

  @override
  Stream<PushIdentityChange> get onIdentityChanged =>
      const Stream<PushIdentityChange>.empty();
}

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // `syncPushIdentity` logs a rail failure rather than surfacing it, so the
    // log service has to resolve or the failure path throws on the binding
    // instead of on the rail.
    Magic.singleton('log', () => LogManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Registers [driver] as the app's push rail, the way a consumer swaps one.
  ///
  /// No provider is booted: `NotificationManager` resolves lazily from the
  /// factory registry, and with exactly one registered it is the one that
  /// answers regardless of what `notifications.push.driver` names.
  ///
  /// `NotificationManager` is a `static final` singleton that outlives
  /// `MagicApp.reset()`, so the teardown is not hygiene: without it both the
  /// double and the intent it recorded would survive into the next test, and
  /// every "nothing was recorded" assertion here would be a false pass.
  void usePushDriver(_RecordingPushDriver driver) {
    Notify.extend('onesignal', () => driver);
    addTearDown(Notify.forgetDrivers);
  }

  group('push identity follows the auth state', () {
    test('subscribes the device as user_<id>, with the prefix', () async {
      // The backend addresses `external_id = user_<uuid>`
      // (`magic-starter-laravel/src/Traits/HasNotifications.php:155`) and the
      // manager forwards its argument unchanged, so a bare uuid subscribes the
      // device to an id nothing ever sends to.
      Auth.fake(
        user: User.fromMap(<String, dynamic>{'id': 'u1', 'name': 'Ada'}),
      );
      final _RecordingPushDriver driver = _RecordingPushDriver();
      usePushDriver(driver);

      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(driver.calls, <String>['login:user_u1']);
      expect(Notify.manager.pushIntent, 'user_u1');
    });

    test('declares nothing at all for a user whose id has not resolved', () async {
      // The assertion the obvious implementation fails. `User.current.id` is
      // `getAttribute('id')?.toString() ?? ''`, so a session restored before its
      // user resolved answers `''`, and `'user_$id'` is then `'user_'`: a
      // well-formed external id addressing nobody, which the reconciler accepts
      // and reports as CONVERGED. Declaring nothing leaves the previous intent
      // in place, and the next `Auth.stateNotifier` bump carries the identity.
      Auth.fake(user: User.fromMap(<String, dynamic>{'name': 'Ada'}));
      final _RecordingPushDriver driver = _RecordingPushDriver();
      usePushDriver(driver);

      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(driver.calls, isEmpty);
      expect(Notify.manager.pushIntent, isNull);
    });

    test('issues no second login for a session already subscribed', () async {
      // `Auth.stateNotifier` bumps on every restore, and a cold boot plus a
      // token refresh is two bumps for one person. Re-issuing the SDK login on
      // each of them is churn the manager exists to prevent.
      Auth.fake(
        user: User.fromMap(<String, dynamic>{'id': 'u1', 'name': 'Ada'}),
      );
      final _RecordingPushDriver driver = _RecordingPushDriver();
      usePushDriver(driver);

      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(driver.calls, <String>['login:user_u1']);
    });

    test('a team switch changes nothing about who the device is', () async {
      // A team switch ends in `Auth.restore()`, which bumps the same notifier
      // with the same person on a different team. A notification belongs to the
      // PERSON, so the external id is unchanged and the rail must not be
      // touched: the alternative is a device briefly subscribed as nobody in the
      // middle of a switch, which is exactly when an incident page matters.
      Auth.fake(
        user: User.fromMap(<String, dynamic>{
          'id': 'u1',
          'name': 'Ada',
          'current_team': <String, dynamic>{'id': 'team-alpha', 'name': 'Alpha'},
        }),
      );
      final _RecordingPushDriver driver = _RecordingPushDriver();
      usePushDriver(driver);

      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      Auth.fake(
        user: User.fromMap(<String, dynamic>{
          'id': 'u1',
          'name': 'Ada',
          'current_team': <String, dynamic>{'id': 'team-beta', 'name': 'Beta'},
        }),
      );
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(driver.calls, <String>['login:user_u1']);
    });

    test('releases the device on sign-out, whichever path signed out', () async {
      // uptizm sets its own `onLogout`, and magic_starter's dropdown returns
      // early when one is set, so `MagicStarterAuthController.logout()` (the
      // ecosystem's only caller of `Notify.logoutPush()`) never runs here. If
      // this sync did not release the device, the next person to sign in on it
      // would keep receiving the previous one's incident pages.
      Auth.fake(
        user: User.fromMap(<String, dynamic>{'id': 'u1', 'name': 'Ada'}),
      );
      final _RecordingPushDriver driver = _RecordingPushDriver();
      usePushDriver(driver);

      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      await Auth.logout();
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(driver.calls, <String>['login:user_u1', 'logout']);
      expect(Notify.manager.pushIntent, isNull);
    });
  });
}
