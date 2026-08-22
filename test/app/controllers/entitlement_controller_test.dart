import 'dart:ui' show Locale;

import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/entitlement_controller.dart';
import 'package:uptizm/app/enums/ai_level.dart' show AiLevel;
import 'package:uptizm/app/enums/billing_provider.dart' show BillingProvider;
import 'package:uptizm/app/enums/manage_via.dart' show ManageVia;
import 'package:uptizm/app/mocks/billing.dart' show plans;
import 'package:uptizm/app/services/billing/billing_service.dart';
import 'package:uptizm/app/support/billing_types.dart' show Plan;
import 'package:uptizm/app/support/team_types.dart'
    show PaymentMethod, UsageStat;

import '../../support/bundled_lang.dart';

/// In-memory [BillingService] fake feeding the [EntitlementController] the three
/// reads it depends on (`currentEntitlement`, `getPlans`, `getUsage`) with
/// canned values, so the gate predicates can be asserted without a network
/// driver. The purchase-action methods the controller never touches throw
/// [UnimplementedError] loudly (a future caller would fail the test, not
/// silently no-op).
class _FakeBilling implements BillingService {
  _FakeBilling({
    this.entitlementPlan,
    this.usage = const [],
    this.throwOnPlans = false,
    this.entitlementProvider = 'none',
    this.entitlementManageVia = 'none',
    this.entitlementRenews,
    this.entitlementPeriodEnd,
  });

  /// The plan id `currentEntitlement` resolves to; `null` mirrors an absent
  /// entitlement field (mid-load).
  ///
  /// Mutable so a test can model the identity behind the fake CHANGING (the
  /// `resetForSession` case) without building a second controller.
  String? entitlementPlan;

  /// The RAW WIRE words `currentEntitlement` reports for the rail and the
  /// management surface (`stripe`, `app_store`, `portal`, ...), fed through the
  /// real decoder rather than as ready-made enum cases, so a fixture cannot
  /// assert a value the wire could never produce.
  String? entitlementProvider;
  String? entitlementManageVia;

  /// Whether the reported subscription rolls over, and when its paid period
  /// ends. Both nullable: `null` is the wire's "no rail has said".
  bool? entitlementRenews;
  DateTime? entitlementPeriodEnd;

  /// The usage stats `getUsage` returns.
  List<UsageStat> usage;

  /// When true, `getPlans` throws to exercise a degraded leg.
  bool throwOnPlans;

  /// When true, `currentEntitlement` throws to exercise the entitlement leg
  /// degrading on its own while the catalog and usage legs still answer.
  ///
  /// Field-only rather than a constructor parameter: every case that needs it
  /// flips it AFTER a first successful load, because the thing under test is
  /// what survives the failure, and a fake that failed from birth would have
  /// nothing to keep.
  bool throwOnEntitlement = false;

  /// How many times `currentEntitlement` was called, so a test can assert the
  /// one-shot load guard fires exactly once per identity.
  int entitlementCalls = 0;

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    entitlementCalls++;
    if (throwOnEntitlement) throw const BillingException('billing offline');

    // `plan_status` is the key `SubscriptionResource` actually emits, and one
    // local feeds both the parameter and the raw map so the two cannot drift.
    // A `status` key sat here until now and has NEVER existed on this wire; the
    // identical fiction in the real fixtures is what left
    // `BillingEntitlement.status` null in production for the life of the field.
    const String planStatus = 'active';

    return BillingEntitlement(
      plan: entitlementPlan,
      status: planStatus,
      provider: entitlementProvider,
      manageVia: entitlementManageVia,
      renews: entitlementRenews,
      currentPeriodEnd: entitlementPeriodEnd,
      aiAnalysisTrialsRemaining: null,
      raw: {'plan': entitlementPlan, 'plan_status': planStatus},
    );
  }

  /// Returns the real design-lab plan catalog (which mirrors the backend
  /// `config/plans.php` tiers), or throws when [throwOnPlans] exercises a
  /// degraded leg.
  @override
  Future<List<Plan>> getPlans() async {
    if (throwOnPlans) throw const BillingException('catalog offline');
    return plans;
  }

  @override
  Future<List<UsageStat>> getUsage() async => usage;

  @override
  Future<BillingCheckoutSession> checkout({
    required String plan,
    required String successUrl,
    required String cancelUrl,
  }) => throw UnimplementedError();

  @override
  Future<void> swap({required String plan}) => throw UnimplementedError();

  @override
  Future<void> cancel() => throw UnimplementedError();

  @override
  Future<String> openPortal({String? returnUrl}) => throw UnimplementedError();

  @override
  Future<BillingInvoicesPage> getInvoices({String? cursor}) =>
      throw UnimplementedError();

  @override
  Future<PaymentMethod> getPaymentMethod() => throw UnimplementedError();
}

/// Builds the usage list the controller reads by DECODING the wire shape
/// `BillingController::usage()` actually sends.
///
/// It used to hand-build three `UsageStat`s with English labels, and that is
/// exactly why a whole green suite missed a live defect: the fixture and the
/// controller's lookup agreed with each other while neither agreed with the
/// shipped catalogue. Going through the real decoder means the keys under test
/// are the keys production produces, in whatever language the session is in.
List<UsageStat> _usage({required int monitors, required int responders}) =>
    UsageStat.fromWireMap(<String, dynamic>{
      'monitors': {'used': monitors, 'limit': null},
      'responders': {'used': responders, 'limit': null},
      'checks_this_month': {'used': 0, 'limit': null},
    });

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so the degraded-leg catch (`Log.error`) resolves the
    // `log` service instead of throwing "Service [log] is not registered".
    Magic.singleton('log', () => LogManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  group('EntitlementController before load', () {
    test(
      'gates are fully permissive so nothing is wrongly locked mid-load',
      () {
        final controller = EntitlementController(billing: _FakeBilling());

        expect(controller.isLoaded, isFalse);
        expect(controller.planName, isEmpty);
        expect(controller.canCreateMonitor, isTrue);
        expect(controller.minCheckIntervalSec, 0);
        expect(controller.aiLevelAllows(AiLevel.custom), isTrue);
        expect(controller.canUsePrivatePages, isTrue);
        expect(controller.canUseWhiteLabel, isTrue);
        expect(controller.canAddResponder, isTrue);
        expect(controller.monitorsRemaining, isNull);
      },
    );
  });

  group('EntitlementController on a Free plan', () {
    test('at the monitor + responder caps, create/add are blocked', () async {
      final controller = EntitlementController(
        billing: _FakeBilling(
          entitlementPlan: 'free',
          usage: _usage(monitors: 10, responders: 1),
        ),
      );

      await controller.reload();

      expect(controller.isLoaded, isTrue);
      expect(controller.planName, 'Free');
      expect(controller.monitorsUsed, 10);
      expect(controller.respondersUsed, 1);
      expect(controller.canCreateMonitor, isFalse);
      expect(controller.monitorsRemaining, 0);
      expect(controller.minCheckIntervalSec, 180);
      expect(controller.aiLevelAllows(AiLevel.inbox), isTrue);
      expect(controller.aiLevelAllows(AiLevel.analysis), isFalse);
      expect(controller.canUsePrivatePages, isFalse);
      expect(controller.canUseWhiteLabel, isFalse);
      expect(controller.canAddResponder, isFalse);
      expect(controller.respondersRemaining, 0);
    });

    test(
      'below the caps, create/add are allowed with remaining counts',
      () async {
        final controller = EntitlementController(
          billing: _FakeBilling(
            entitlementPlan: 'free',
            usage: _usage(monitors: 3, responders: 0),
          ),
        );

        await controller.reload();

        expect(controller.canCreateMonitor, isTrue);
        expect(controller.monitorsRemaining, 7);
        expect(controller.canAddResponder, isTrue);
        expect(controller.respondersRemaining, 1);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // Locale independence: the usage lookup keys on the wire, not on the copy.
  // ---------------------------------------------------------------------------

  group('EntitlementController in a Turkish session', () {
    setUp(() async {
      Translator.instance.setLoader(_BundledLoader('tr'));
      await Translator.instance.setLocale(const Locale('tr'));
    });

    tearDown(Translator.reset);

    test('reads usage from the wire keys, not from the labels', () async {
      // Regression guard for a live defect: the lookup matched the hardcoded
      // English literal 'Monitors' against a label the decoder had already run
      // through the catalogue. On a Turkish session the catalogue returns
      // 'İzleyiciler', nothing matched, every usage read fell through to 0, and
      // the Free-tier gates stayed permanently open while the meter reported
      // the full cap. The fix is the lookup axis, not the literal.
      final List<UsageStat> usage = UsageStat.fromWireMap(const {
        'monitors': {'used': 10, 'limit': 10},
        'responders': {'used': 1, 'limit': 1},
        'checks_this_month': {'used': 83365, 'limit': null},
      });

      // Guards against a vacuous pass: with no catalogue loaded the labels
      // would be raw keys, English would match nothing either, and the test
      // would prove something other than locale independence.
      expect(usage.first.label, 'İzleyiciler');

      final controller = EntitlementController(
        billing: _FakeBilling(entitlementPlan: 'free', usage: usage),
      );

      await controller.reload();

      expect(controller.monitorsUsed, 10);
      expect(controller.respondersUsed, 1);
      expect(controller.canCreateMonitor, isFalse);
      expect(controller.monitorsRemaining, 0);
      expect(controller.canAddResponder, isFalse);
    });
  });

  group('EntitlementController on paid plans', () {
    test(
      'Pro unlocks analysis + faster interval, still no private pages',
      () async {
        final controller = EntitlementController(
          billing: _FakeBilling(
            entitlementPlan: 'pro',
            usage: _usage(monitors: 5, responders: 1),
          ),
        );

        await controller.reload();

        expect(controller.planName, 'Pro');
        expect(controller.minCheckIntervalSec, 30);
        expect(controller.aiLevelAllows(AiLevel.analysis), isTrue);
        expect(controller.aiLevelAllows(AiLevel.auto), isFalse);
        expect(controller.canUsePrivatePages, isFalse);
        expect(controller.canAddResponder, isTrue);
      },
    );

    test('Business unlocks auto AI, private pages, and white-label', () async {
      final controller = EntitlementController(
        billing: _FakeBilling(
          entitlementPlan: 'business',
          usage: _usage(monitors: 5, responders: 2),
        ),
      );

      await controller.reload();

      expect(controller.planName, 'Business');
      expect(controller.minCheckIntervalSec, 10);
      expect(controller.aiLevelAllows(AiLevel.auto), isTrue);
      expect(controller.aiLevelAllows(AiLevel.custom), isFalse);
      expect(controller.canUsePrivatePages, isTrue);
      expect(controller.canUseWhiteLabel, isTrue);
    });

    test('Enterprise lifts every count cap (unlimited)', () async {
      final controller = EntitlementController(
        billing: _FakeBilling(
          entitlementPlan: 'enterprise',
          usage: _usage(monitors: 9999, responders: 500),
        ),
      );

      await controller.reload();

      expect(controller.planName, 'Enterprise');
      expect(controller.minCheckIntervalSec, 5);
      expect(controller.canCreateMonitor, isTrue);
      expect(controller.monitorsRemaining, isNull);
      expect(controller.canAddResponder, isTrue);
      expect(controller.respondersRemaining, isNull);
      expect(controller.aiLevelAllows(AiLevel.custom), isTrue);
    });
  });

  group('EntitlementController.planNameUnlocking', () {
    test('names the cheapest plan whose limits satisfy the predicate', () async {
      final controller = EntitlementController(
        billing: _FakeBilling(entitlementPlan: 'free'),
      );

      await controller.reload();

      // Analysis-tier AI first appears on Pro; private pages first on Business.
      expect(
        controller.planNameUnlocking(
          (limits) => limits.ai.index >= AiLevel.analysis.index,
        ),
        'Pro',
      );
      expect(
        controller.planNameUnlocking((limits) => limits.privatePages),
        'Business',
      );
    });
  });

  group('EntitlementController resilience', () {
    test('notifies listeners once the reload resolves', () async {
      final controller = EntitlementController(
        billing: _FakeBilling(entitlementPlan: 'pro'),
      );
      var notified = 0;
      controller.addListener(() => notified++);

      await controller.reload();

      expect(notified, greaterThan(0));
      expect(controller.isLoaded, isTrue);
    });

    test(
      'a failing leg degrades to the permissive default without throwing',
      () async {
        final controller = EntitlementController(
          billing: _FakeBilling(
            entitlementPlan: 'free',
            throwOnPlans: true,
            usage: _usage(monitors: 10, responders: 1),
          ),
        );

        // reload must resolve (not rethrow) even though getPlans threw.
        await controller.reload();

        expect(controller.isLoaded, isTrue);
        // With no catalog, the plan cannot resolve, so gates stay permissive
        // rather than wrongly locking on a half-loaded entitlement.
        expect(controller.planName, isEmpty);
        expect(controller.canCreateMonitor, isTrue);
        expect(controller.minCheckIntervalSec, 0);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // The subscription rail: who bills the team, where it is managed, whether it
  // renews and until when. Published off the controller so a view reads it
  // without a second `GET /billing` of its own.
  // ---------------------------------------------------------------------------

  group('EntitlementController subscription rail', () {
    test('publishes the rail, manage surface, renewal and period end', () async {
      final DateTime periodEnd = DateTime.utc(2026, 9, 1, 12);
      final controller = EntitlementController(
        billing: _FakeBilling(
          entitlementPlan: 'pro',
          entitlementProvider: 'app_store',
          entitlementManageVia: 'app_store',
          entitlementRenews: true,
          entitlementPeriodEnd: periodEnd,
        ),
      );

      await controller.reload();

      expect(controller.provider, BillingProvider.appStore);
      expect(controller.manageVia, ManageVia.appStore);
      expect(controller.renews, isTrue);
      expect(controller.currentPeriodEnd, periodEnd);
    });

    test('before the load the rail reads unknown and gates nothing', () {
      final controller = EntitlementController(
        billing: _FakeBilling(
          entitlementPlan: 'free',
          entitlementProvider: 'stripe',
          entitlementManageVia: 'portal',
          usage: _usage(monitors: 10, responders: 1),
        ),
      );

      expect(controller.isLoaded, isFalse);
      // `none` is the honest pre-load reading: nobody has said which rail bills
      // this team, and a client that cannot name the surface must not steer the
      // customer at a guessed one.
      expect(controller.provider, BillingProvider.none);
      expect(controller.manageVia, ManageVia.none);
      expect(controller.renews, isNull);
      expect(controller.currentPeriodEnd, isNull);
      // None of that gates an action: the pre-load state stays permissive, so
      // an unknown rail can never lock a control the backend would have allowed.
      expect(controller.canCreateMonitor, isTrue);
      expect(controller.canAddResponder, isTrue);
      expect(controller.canUsePrivatePages, isTrue);
      expect(controller.minCheckIntervalSec, 0);
    });

    test('a failed GET /billing keeps the last-known rail, not null', () async {
      final DateTime periodEnd = DateTime.utc(2026, 9, 1);
      final fake = _FakeBilling(
        entitlementPlan: 'pro',
        entitlementProvider: 'stripe',
        entitlementManageVia: 'portal',
        entitlementRenews: true,
        entitlementPeriodEnd: periodEnd,
        usage: _usage(monitors: 4, responders: 1),
      );
      final controller = EntitlementController(billing: fake);
      await controller.reload();
      expect(controller.provider, BillingProvider.stripe);

      // Only the entitlement leg fails on the refresh. Each leg keeps its own
      // last-known-good, so the rail must survive verbatim rather than degrade
      // to `none`, which would read as "nobody bills this team" and hide the
      // management surface of a subscription that is still live. The other two
      // legs keep answering, proving one failing leg blanks nothing else.
      fake.throwOnEntitlement = true;
      fake.usage = _usage(monitors: 6, responders: 1);

      await controller.reload();

      expect(controller.provider, BillingProvider.stripe);
      expect(controller.manageVia, ManageVia.portal);
      expect(controller.renews, isTrue);
      expect(controller.currentPeriodEnd, periodEnd);
      expect(controller.planName, 'Pro');
      expect(controller.monitorsUsed, 6);
    });
  });

  group('EntitlementController.instance', () {
    test(
      'resolving the singleton kicks off the load without a manual reload',
      () async {
        // Regression guard: the controller is never a MagicView's backing
        // controller, so magic's `onInit` hook never fires for it. `.instance`
        // MUST trigger the initial load itself, or every gate stays permissively
        // unlocked for the exact Free teams that should be gated. Seed the
        // singleton with a fake-backed instance, then resolve `.instance` and
        // assert the plan loaded WITHOUT any explicit `reload()` call.
        final fake = _FakeBilling(
          entitlementPlan: 'free',
          usage: _usage(monitors: 10, responders: 1),
        );
        Magic.findOrPut(() => EntitlementController(billing: fake));

        final controller = EntitlementController.instance;

        // The load is async and fire-and-forget; pump until it settles.
        for (var i = 0; i < 50 && !controller.isLoaded; i++) {
          await Future<void>.delayed(const Duration(milliseconds: 1));
        }

        expect(
          controller.isLoaded,
          isTrue,
          reason: 'resolving .instance must start the load on its own',
        );
        expect(controller.planName, 'Free');
        expect(controller.canCreateMonitor, isFalse);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // resetForSession: clear the previous identity's entitlement, then refetch.
  // ---------------------------------------------------------------------------

  group('EntitlementController.resetForSession', () {
    test('drops the previous plan even when the refetch resolves none', () async {
      final fake = _FakeBilling(
        entitlementPlan: 'free',
        usage: _usage(monitors: 10, responders: 1),
      );
      final controller = EntitlementController(billing: fake);
      await controller.reload();
      expect(controller.canCreateMonitor, isFalse);

      // The identity changed and the new team's entitlement does not resolve.
      // Each `reload` leg keeps its last-known-good value, so without the
      // clear the new team would be gated by the PREVIOUS team's Free caps.
      fake.entitlementPlan = null;
      fake.throwOnPlans = true;
      fake.usage = const [];
      var notified = 0;
      controller.addListener(() => notified++);

      await controller.resetForSession();

      expect(notified, greaterThan(0));
      expect(controller.planName, isEmpty);
      expect(controller.monitorsUsed, 0);
      expect(controller.respondersUsed, 0);
      expect(controller.aiAnalysisTrialsRemaining, isNull);
      // Cleared means permissive, never the previous team's caps: the backend
      // stays the true enforcer while the new plan is unknown.
      expect(controller.canCreateMonitor, isTrue);
      expect(controller.minCheckIntervalSec, 0);
    });

    test('refetches the plan of the identity that is now authenticated', () async {
      final fake = _FakeBilling(
        entitlementPlan: 'free',
        usage: _usage(monitors: 10, responders: 1),
      );
      final controller = EntitlementController(billing: fake);
      await controller.reload();

      fake.entitlementPlan = 'business';
      fake.usage = _usage(monitors: 5, responders: 2);

      await controller.resetForSession();

      expect(controller.planName, 'Business');
      expect(controller.canCreateMonitor, isTrue);
      expect(controller.canUsePrivatePages, isTrue);
      expect(controller.monitorsUsed, 5);
    });

    test('drops the previous identity rail even when the refetch fails', () async {
      final fake = _FakeBilling(
        entitlementPlan: 'pro',
        entitlementProvider: 'app_store',
        entitlementManageVia: 'app_store',
        entitlementRenews: true,
        entitlementPeriodEnd: DateTime.utc(2026, 9, 1),
      );
      final controller = EntitlementController(billing: fake);
      await controller.reload();
      expect(controller.provider, BillingProvider.appStore);

      // The identity changed and the new team's entitlement does not resolve.
      // The entitlement leg keeps its last-known-good on failure, so without
      // the clear the new team would read the PREVIOUS team's rail and be
      // offered the management surface of someone else's purchase.
      fake.throwOnEntitlement = true;

      await controller.resetForSession();

      expect(controller.provider, BillingProvider.none);
      expect(controller.manageVia, ManageVia.none);
      expect(controller.renews, isNull);
      expect(controller.currentPeriodEnd, isNull);
    });

    test('re-arms the one-shot guard without firing a second load', () async {
      final fake = _FakeBilling(
        entitlementPlan: 'free',
        usage: _usage(monitors: 10, responders: 1),
      );
      Magic.findOrPut(() => EntitlementController(billing: fake));
      final controller = EntitlementController.instance;
      for (var i = 0; i < 50 && !controller.isLoaded; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 1));
      }

      await controller.resetForSession();
      final int callsAfterReset = fake.entitlementCalls;

      // The reset re-arms the guard and immediately claims it for the refetch
      // it awaited, so the next `.instance` read must not load again.
      final resolved = EntitlementController.instance;
      await Future<void>.delayed(const Duration(milliseconds: 5));

      expect(identical(resolved, controller), isTrue);
      expect(callsAfterReset, equals(2));
      expect(fake.entitlementCalls, equals(callsAfterReset));
    });
  });
}

/// Serves the SHIPPED catalogue for one locale, so the labels the decoder
/// produces here are the ones a user reads rather than raw keys.
class _BundledLoader implements TranslationLoader {
  _BundledLoader(this.locale);

  final String locale;

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}
