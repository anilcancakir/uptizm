import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_payments/magic_payments.dart'
    show Payments, PaymentsManager, StoreBillingService;
import 'package:uptizm/app/models/user.dart' show User;
import 'package:uptizm/app/providers/app_service_provider.dart';

/// Locks WHICH team the store rail is bound to, at every moment the app binds
/// one.
///
/// The App User ID the rail holds is what the vendor's webhook attributes a
/// purchase to, so binding the wrong one hands a subscription to a team that
/// never paid for it, and binding none hands it to nobody at all. Neither
/// failure is visible on screen and neither raises anything: the purchase
/// simply lands somewhere else.
///
/// The billing SCREEN moved into `magic_starter`, but this did not: the package
/// has no store-identity sync and never took one, because who the paying
/// subject is (a team here, a user elsewhere) is the consumer's own answer.
/// [AppServiceProvider.switchTeamAndIdentifyStore] and
/// [AppServiceProvider.syncStoreIdentity] are uptizm's, so their cover is too.
///
/// These four cases were extracted verbatim, in substance, from the old
/// `test/resources/views/teams/plan_billing_view_test.dart` when that file was
/// deleted with the screen it covered.
class _RecordingStoreRail implements StoreBillingService {
  /// Every `appUserId` passed to [identify], in call order.
  ///
  /// The list, rather than a "did it identify" flag: a flag read before the call
  /// would pass on a binding made to the wrong team, and the id IS the subject
  /// of every assertion here.
  final List<String> identifiedIds = [];

  @override
  Future<void> identify(String appUserId) async {
    identifiedIds.add(appUserId);
  }

  @override
  Future<bool> purchase({required String plan}) async => false;

  @override
  Future<bool> restore() async => false;

  @override
  Future<void> openStoreManagement() async {}
}

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // `_identifyStoreCustomer` logs a rail failure rather than surfacing it, so
    // the log service has to resolve or the failure path throws on the binding
    // instead of on the rail.
    Magic.singleton('log', () => LogManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Registers [store] as the app's STORE rail, the way a consumer swaps one.
  ///
  /// `PaymentsManager` is a `static final` singleton that outlives
  /// `MagicApp.reset()`, so the teardown is not hygiene: without it the fake
  /// would answer `Payments.store` for every later test in this file and turn
  /// every "no store rail" assertion into a false pass.
  void useStoreRail(_RecordingStoreRail store) {
    Payments.extend(PaymentsManager.storeRole, () => store);
    addTearDown(Payments.forgetDrivers);
  }

  group('the team switch re-identifies the store customer', () {
    test('identifies with the NEW team id once the switch has resolved', () async {
      final FakeNetworkDriver network = Http.fake();
      Auth.fake();
      final _RecordingStoreRail store = _RecordingStoreRail();
      useStoreRail(store);

      await AppServiceProvider.switchTeamAndIdentifyStore('team-beta');

      network.assertSent(
        (MagicRequest request) => request.url.contains('user/current-team'),
      );
      // Asserted at the point the identify HAPPENS, not on a flag read before
      // it: the App User ID the rail is bound to is what a webhook attributes a
      // purchase to, so the id itself is the assertion.
      expect(store.identifiedIds, ['team-beta']);
    });

    test('a session that never switched still identifies the team it is on', () async {
      // The common path on a fresh install: sign in, open billing, buy. Without
      // this the rail would still hold its anonymous id and the purchase would
      // arrive attributed to nobody, so identifying on the SWITCH alone is not
      // enough even though the switch is the case that goes wrong silently.
      Http.fake();
      Auth.fake(
        user: User.fromMap(<String, dynamic>{
          'id': 'u1',
          'name': 'Ada',
          'current_team': <String, dynamic>{'id': 'team-alpha', 'name': 'Alpha'},
        }),
      );
      final _RecordingStoreRail store = _RecordingStoreRail();
      useStoreRail(store);

      AppServiceProvider.syncStoreIdentity();
      await pumpEventQueue();

      expect(store.identifiedIds, ['team-alpha']);
    });

    test('a logged-out session identifies nothing', () async {
      // There is nobody to attribute a purchase to, and the rail keeps whatever
      // the previous session bound: the contract has no logout, and the billing
      // screen is behind auth, so nothing can spend against it meanwhile.
      Http.fake();
      Auth.fake();
      final _RecordingStoreRail store = _RecordingStoreRail();
      useStoreRail(store);

      AppServiceProvider.syncStoreIdentity();
      await pumpEventQueue();

      expect(store.identifiedIds, isEmpty);
    });

    test('does not re-identify when the backend refused the switch', () async {
      // A failed switch leaves the app on the team it was already on, so
      // re-identifying would bind the store account to a team the user never
      // landed on and hand the next purchase to it.
      Http.fake(<String, MagicResponse>{
        'user/current-team': Http.response(<String, dynamic>{
          'message': 'That team is not yours.',
        }, 422),
      });
      Auth.fake();
      final _RecordingStoreRail store = _RecordingStoreRail();
      useStoreRail(store);

      await AppServiceProvider.switchTeamAndIdentifyStore('team-beta');

      expect(store.identifiedIds, isEmpty);
    });
  });
}
