import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/entitlement_controller.dart';
import 'package:uptizm/app/enums/ai_level.dart' show AiLevel;
import 'package:uptizm/app/mocks/billing.dart' show plans;
import 'package:uptizm/app/services/billing/billing_service.dart';
import 'package:uptizm/app/support/billing_types.dart' show Plan;
import 'package:uptizm/app/support/team_types.dart'
    show PaymentMethod, UsageStat;

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
  });

  /// The plan id `currentEntitlement` resolves to; `null` mirrors an absent
  /// entitlement field (mid-load).
  final String? entitlementPlan;

  /// The usage stats `getUsage` returns.
  final List<UsageStat> usage;

  /// When true, `getPlans` throws to exercise a degraded leg.
  final bool throwOnPlans;

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    return BillingEntitlement(
      plan: entitlementPlan,
      status: 'active',
      aiAnalysisTrialsRemaining: null,
      raw: {'plan': entitlementPlan, 'status': 'active'},
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

/// Builds the two usage stats the controller reads (`Monitors`, `Responders`)
/// plus the always-present checks row, matching `UsageStat.fromWireMap`'s order
/// and labels.
List<UsageStat> _usage({required int monitors, required int responders}) => [
  UsageStat(label: 'Monitors', used: monitors, limit: null, unit: ''),
  UsageStat(label: 'Responders', used: responders, limit: null, unit: ''),
  UsageStat(label: 'Checks this month', used: 0, limit: null, unit: 'checks'),
];

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
}
