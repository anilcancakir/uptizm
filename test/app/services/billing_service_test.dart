import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/billing_provider.dart' show BillingProvider, billingProviderFromWire;
import 'package:uptizm/app/enums/invoice_status.dart' show InvoiceStatus;
import 'package:uptizm/app/enums/manage_via.dart' show ManageVia, manageViaFromWire;
import 'package:uptizm/app/enums/plan_status.dart' show PlanStatus, planStatusFromWire;
import 'package:uptizm/app/services/billing/billing_service.dart';
import 'package:uptizm/app/services/billing/billing_service_io.dart';
import 'package:uptizm/app/services/billing/billing_service_stub.dart';
import 'package:uptizm/app/services/billing/billing_service_web.dart';
import 'package:uptizm/app/support/billing_types.dart' show Plan;
import 'package:uptizm/app/support/team_types.dart' show PaymentMethod, UsageStat;

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so the write-path failure branches (`Log.error`) resolve
    // the `log` service instead of throwing on an unbound singleton.
    Magic.singleton('log', () => LogManager());
    // Bind LaunchService so the web checkout/portal paths (`Launch.url`)
    // resolve the `launch` service; in production this is bound by
    // `LaunchServiceProvider` at app boot (see `lib/config/app.dart`).
    Magic.singleton('launch', () => LaunchService());
    Http.fake();
  });

  tearDown(() {
    Http.unfake();
    MagicApp.reset();
    Magic.flush();
  });

  group('BillingServiceWeb', () {
    const BillingServiceWeb service = BillingServiceWeb();

    test('checkout posts to /billing/checkout and returns the session', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/checkout': Http.response({
          'checkout_url': 'https://checkout.stripe.com/session_123',
          'session_id': 'session_123',
        }),
      });

      final BillingCheckoutSession session = await service.checkout(
        plan: 'pro',
        successUrl: 'https://uptizm.com/teams/billing?checkout=success',
        cancelUrl: 'https://uptizm.com/teams/billing?checkout=cancel',
      );

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('billing/checkout') &&
            (r.data as Map<String, dynamic>)['plan'] == 'pro' &&
            (r.data as Map<String, dynamic>)['success_url'] ==
                'https://uptizm.com/teams/billing?checkout=success' &&
            (r.data as Map<String, dynamic>)['cancel_url'] ==
                'https://uptizm.com/teams/billing?checkout=cancel',
      );
      expect(session.checkoutUrl, 'https://checkout.stripe.com/session_123');
      expect(session.sessionId, 'session_123');
    });

    test('checkout throws BillingException on a failed response', () async {
      Http.fake({
        'billing/checkout': Http.response({'message': 'No payment method.'}, 422),
      });

      expect(
        () => service.checkout(
          plan: 'pro',
          successUrl: 'https://uptizm.com/teams/billing?checkout=success',
          cancelUrl: 'https://uptizm.com/teams/billing?checkout=cancel',
        ),
        throwsA(isA<BillingException>()),
      );
    });

    test('swap posts to /billing/swap with the new plan', () async {
      final FakeNetworkDriver fake = Http.fake();

      await service.swap(plan: 'business');

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('billing/swap') &&
            (r.data as Map<String, dynamic>)['plan'] == 'business',
      );
    });

    test('cancel posts to /billing/cancel', () async {
      final FakeNetworkDriver fake = Http.fake();

      await service.cancel();

      fake.assertSent(
        (r) => r.method == 'POST' && r.url.contains('billing/cancel'),
      );
    });

    test('openPortal gets /billing/portal and returns the portal url', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/portal': Http.response({
          'portal_url': 'https://billing.stripe.com/portal_abc',
        }),
      });

      final String portalUrl = await service.openPortal(
        returnUrl: 'https://uptizm.com/teams/billing',
      );

      fake.assertSent(
        (r) =>
            r.method == 'GET' &&
            r.url.contains('billing/portal') &&
            r.queryParameters?['return_url'] ==
                'https://uptizm.com/teams/billing',
      );
      expect(portalUrl, 'https://billing.stripe.com/portal_abc');
    });

    test('currentEntitlement gets /billing and decodes all thirteen fields', () async {
      Http.fake({
        // The whole payload is copied from the producer's own exact-JSON
        // assertion for the Play Store rail mid grace period
        // (`backend/tests/Feature/Billing/BillingControllerTest.php`,
        // `test_show_points_a_play_store_team_at_the_store_through_its_grace_period`),
        // which is the only fixture in this file populating every nullable
        // field a store rail can populate. Inventing a key or a value here is
        // what left `BillingEntitlement.status` null in production for the life
        // of the field.
        'billing': Http.response({
          'data': {
            'plan': 'business',
            'plan_status': 'grace',
            'subscribed': true,
            'renews': false,
            'provider': 'play_store',
            'provider_status': 'billing_issue_detected_at',
            'product_id': 'uptizm_business_annual',
            'manage_via': 'play_store',
            'manage_url': 'https://play.google.com/store/account/subscriptions',
            'current_period_end': '2026-08-25T09:00:00+00:00',
            'trial_ends_at': null,
            'grace_period_ends_at': '2026-08-27T09:00:00+00:00',
            'ai_analysis_trials_remaining': null,
          },
        }),
      });

      final BillingEntitlement entitlement = await service.currentEntitlement();

      expect(entitlement.plan, 'business');
      expect(entitlement.planStatus, PlanStatus.grace);
      expect(entitlement.subscribed, isTrue);
      expect(entitlement.renews, isFalse);
      expect(entitlement.provider, BillingProvider.playStore);
      expect(entitlement.providerStatus, 'billing_issue_detected_at');
      expect(entitlement.productId, 'uptizm_business_annual');
      expect(entitlement.manageVia, ManageVia.playStore);
      expect(
        entitlement.manageUrl,
        'https://play.google.com/store/account/subscriptions',
      );
      expect(entitlement.currentPeriodEnd, DateTime.utc(2026, 8, 25, 9));
      expect(entitlement.trialEndsAt, isNull);
      expect(entitlement.gracePeriodEndsAt, DateTime.utc(2026, 8, 27, 9));
      expect(entitlement.aiAnalysisTrialsRemaining, isNull);
    });

    test('currentEntitlement ignores a legacy top-level status key', () async {
      Http.fake({
        'billing': Http.response({
          'data': {'plan': 'pro', 'status': 'active'},
        }),
      });

      final BillingEntitlement entitlement = await service.currentEntitlement();

      // The decoder reads `plan_status` and nothing else: accepting both keys
      // would be a compatibility shim for a payload that never existed. An
      // absent lifecycle is `none`, which is also where an unknown value lands.
      expect(entitlement.planStatus, PlanStatus.none);
    });

    test('getPlans gets /billing/plans and decodes the catalog', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/plans': Http.response({
          'data': [
            {
              'id': 'free',
              'name': 'Free',
              'tagline': 'Kick the tires.',
              'monthly': 0,
              'annual': 0,
              'currency': 'usd',
              'ai_line': 'AI anomaly inbox.',
              'features': ['10 monitors'],
              'responder_add_on': null,
              'recommended': false,
              'limits': {
                'monitors': 10,
                'check_interval_sec': 180,
                'status_pages': 1,
                'subscribers': 100,
                'responders': 1,
                'ai': 'inbox',
                'white_label': false,
                'private_pages': false,
                'sso': false,
              },
            },
            {
              'id': 'pro',
              'name': 'Pro',
              'tagline': 'Startups.',
              'monthly': 34,
              'annual': 29,
              'currency': 'usd',
              'ai_line': 'Full AI incident analysis.',
              'features': ['50 monitors'],
              'responder_add_on': '+\$9/mo per extra responder',
              'recommended': true,
              'limits': {
                'monitors': 50,
                'check_interval_sec': 30,
                'status_pages': 3,
                'subscribers': 1000,
                'responders': 3,
                'ai': 'analysis',
                'white_label': false,
                'private_pages': false,
                'sso': false,
              },
            },
          ],
        }),
      });

      final List<Plan> plans = await service.getPlans();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('billing/plans'),
      );
      expect(plans, hasLength(2));
      expect(plans[0].id, 'free');
      expect(plans[0].monthly, 0);
      expect(plans[0].limits.checkIntervalSec, 180);
      expect(plans[1].id, 'pro');
      expect(plans[1].recommended, isTrue);
      expect(plans[1].responderAddOn, '+\$9/mo per extra responder');
    });

    test('getPlans throws BillingException on a malformed response', () async {
      Http.fake({'billing/plans': Http.response({'oops': true})});

      expect(() => service.getPlans(), throwsA(isA<BillingException>()));
    });

    test('getUsage gets /billing/usage and decodes used/limit', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/usage': Http.response({
          'monitors': {'used': 47, 'limit': 50},
          'responders': {'used': 3, 'limit': 3},
          'checks_this_month': {'used': 128400, 'limit': null},
        }),
      });

      final List<UsageStat> usage = await service.getUsage();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('billing/usage'),
      );
      expect(usage, hasLength(3));
      expect(usage[0].used, 47);
      expect(usage[0].limit, 50);
      expect(usage[2].limit, isNull);
    });

    test('getInvoices gets /billing/invoices and decodes the page', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/invoices': Http.response({
          'data': [
            {
              'id': 'in_test_1',
              'number': 'INV-0001',
              'date': '2026-06-01T00:00:00.000000Z',
              'amount': '\$29.00',
              'status': 'paid',
              'pdf_url': 'https://stripe.test/invoice.pdf',
            },
          ],
          'next_cursor': 'cursor_abc',
        }),
      });

      final BillingInvoicesPage page = await service.getInvoices();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('billing/invoices'),
      );
      expect(page.invoices, hasLength(1));
      expect(page.invoices.first.id, 'in_test_1');
      expect(page.invoices.first.status, InvoiceStatus.paid);
      expect(page.nextCursor, 'cursor_abc');
    });

    test('getInvoices passes the cursor as a query parameter', () async {
      final FakeNetworkDriver fake = Http.fake();

      await service.getInvoices(cursor: 'cursor_abc');

      fake.assertSent(
        (r) =>
            r.method == 'GET' &&
            r.url.contains('billing/invoices') &&
            r.queryParameters?['cursor'] == 'cursor_abc',
      );
    });

    test('getPaymentMethod gets /billing/payment-method and decodes the card', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/payment-method': Http.response({
          'renewal_date': '2026-07-01T00:00:00.000000Z',
          'brand': 'Visa',
          'last4': '4242',
          'exp_month': 8,
          'exp_year': 2027,
        }),
      });

      final PaymentMethod paymentMethod = await service.getPaymentMethod();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('billing/payment-method'),
      );
      expect(paymentMethod.brand, 'Visa');
      expect(paymentMethod.last4, '4242');
      expect(paymentMethod.expiry, '08 / 27');
      expect(paymentMethod.renewalDate, 'Jul 1, 2026');
    });

    test('getPaymentMethod decodes an all-null soft-fail payload without crashing', () async {
      Http.fake({
        'billing/payment-method': Http.response({
          'renewal_date': null,
          'brand': null,
          'last4': null,
          'exp_month': null,
          'exp_year': null,
        }),
      });

      final PaymentMethod paymentMethod = await service.getPaymentMethod();

      expect(paymentMethod.brand, isNull);
      expect(paymentMethod.last4, isNull);
      expect(paymentMethod.expiry, isNull);
      expect(paymentMethod.renewalDate, isNull);
    });
  });

  group('BillingServiceIo (mobile, store rails deferred)', () {
    const BillingServiceIo service = BillingServiceIo();

    test('checkout throws UnsupportedPlatformException, never hits the network', () async {
      final FakeNetworkDriver fake = Http.fake();

      await expectLater(
        () => service.checkout(
          plan: 'pro',
          successUrl: 'https://uptizm.com/teams/billing?checkout=success',
          cancelUrl: 'https://uptizm.com/teams/billing?checkout=cancel',
        ),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      fake.assertNothingSent();
    });

    test('swap throws UnsupportedPlatformException, never hits the network', () async {
      final FakeNetworkDriver fake = Http.fake();

      await expectLater(
        () => service.swap(plan: 'business'),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      fake.assertNothingSent();
    });

    test('cancel throws UnsupportedPlatformException, never hits the network', () async {
      final FakeNetworkDriver fake = Http.fake();

      await expectLater(
        () => service.cancel(),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      fake.assertNothingSent();
    });

    test('openPortal throws UnsupportedPlatformException, never hits the network', () async {
      final FakeNetworkDriver fake = Http.fake();

      await expectLater(
        () => service.openPortal(),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      fake.assertNothingSent();
    });

    test('currentEntitlement is a safe read and still calls GET /billing', () async {
      Http.fake({
        // The App Store rail, copied from the producer's exact-JSON assertion
        // (`test_show_points_an_app_store_team_at_the_store_and_not_at_stripe`).
        // A store-sold team read from a mobile client is the case this platform
        // implementation exists for, and `plan` can only ever be a `Plan` enum
        // value (`free`, `pro`, `business`).
        'billing': Http.response({
          'data': {
            'plan': 'pro',
            'plan_status': 'trialing',
            'subscribed': true,
            'renews': true,
            'provider': 'app_store',
            'provider_status': 'in_trial',
            'product_id': 'uptizm_pro_monthly',
            'manage_via': 'app_store',
            'manage_url': 'https://apps.apple.com/account/subscriptions',
            'current_period_end': '2026-09-01T09:00:00+00:00',
            // Stripe-only by construction: the producer reads it from Cashier's
            // local `subscriptions.trial_ends_at`, so a store trial arrives as
            // `plan_status: trialing` plus the period end instead.
            'trial_ends_at': null,
            'grace_period_ends_at': null,
            'ai_analysis_trials_remaining': null,
          },
        }),
      });

      final BillingEntitlement entitlement = await service.currentEntitlement();

      expect(entitlement.plan, 'pro');
      expect(entitlement.planStatus, PlanStatus.trialing);
      expect(entitlement.provider, BillingProvider.appStore);
      expect(entitlement.manageVia, ManageVia.appStore);
      expect(
        entitlement.manageUrl,
        'https://apps.apple.com/account/subscriptions',
      );
      expect(entitlement.currentPeriodEnd, DateTime.utc(2026, 9, 1, 9));
      expect(entitlement.trialEndsAt, isNull);
    });

    test('getPlans is a safe read and still calls GET /billing/plans', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/plans': Http.response({
          'data': [
            {
              'id': 'free',
              'name': 'Free',
              'tagline': 'Kick the tires.',
              'monthly': 0,
              'annual': 0,
              'ai_line': 'AI anomaly inbox.',
              'features': <String>[],
              'recommended': false,
              'limits': {'check_interval_sec': 180, 'ai': 'inbox'},
            },
          ],
        }),
      });

      final List<Plan> plans = await service.getPlans();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('billing/plans'),
      );
      expect(plans.single.id, 'free');
    });

    test('getUsage is a safe read and still calls GET /billing/usage', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/usage': Http.response({
          'monitors': {'used': 1, 'limit': 10},
          'responders': {'used': 1, 'limit': 1},
          'checks_this_month': {'used': 5, 'limit': null},
        }),
      });

      final List<UsageStat> usage = await service.getUsage();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('billing/usage'),
      );
      expect(usage, hasLength(3));
    });

    test('getInvoices is a safe read and still calls GET /billing/invoices', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/invoices': Http.response({'data': <Map<String, dynamic>>[], 'next_cursor': null}),
      });

      final BillingInvoicesPage page = await service.getInvoices();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('billing/invoices'),
      );
      expect(page.invoices, isEmpty);
    });

    test('getPaymentMethod is a safe read and still calls GET /billing/payment-method', () async {
      final FakeNetworkDriver fake = Http.fake({
        'billing/payment-method': Http.response({
          'renewal_date': null,
          'brand': null,
          'last4': null,
          'exp_month': null,
          'exp_year': null,
        }),
      });

      final PaymentMethod paymentMethod = await service.getPaymentMethod();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('billing/payment-method'),
      );
      expect(paymentMethod.brand, isNull);
    });
  });

  group('BillingServiceStub (unsupported platform fallback)', () {
    const BillingServiceStub service = BillingServiceStub();

    test('every method throws UnsupportedPlatformException', () async {
      await expectLater(
        () => service.checkout(
          plan: 'pro',
          successUrl: 'https://uptizm.com/teams/billing?checkout=success',
          cancelUrl: 'https://uptizm.com/teams/billing?checkout=cancel',
        ),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      await expectLater(
        () => service.swap(plan: 'business'),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      await expectLater(
        () => service.cancel(),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      await expectLater(
        () => service.openPortal(),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      await expectLater(
        () => service.currentEntitlement(),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      await expectLater(
        () => service.getPlans(),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      await expectLater(
        () => service.getUsage(),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      await expectLater(
        () => service.getInvoices(),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      await expectLater(
        () => service.getPaymentMethod(),
        throwsA(isA<UnsupportedPlatformException>()),
      );
    });
  });

  group('BillingEntitlement.fromMap', () {
    /// The never-billed payload, copied from the producer's exact-JSON
    /// assertion (`test_show_emits_the_never_billed_defaults_for_a_free_team`).
    /// It is the fixture that pins WHICH fields the backend guarantees: the
    /// five neutral-vocabulary ones carry their defaults, and the other eight
    /// are nullable, seven of them null here.
    const Map<String, dynamic> neverBilled = {
      'plan': 'free',
      'plan_status': 'none',
      'subscribed': false,
      'renews': null,
      'provider': 'none',
      'provider_status': null,
      'product_id': null,
      'manage_via': 'none',
      'manage_url': null,
      'current_period_end': null,
      'trial_ends_at': null,
      'grace_period_ends_at': null,
      'ai_analysis_trials_remaining': 3,
    };

    test('decodes the five backend-guaranteed fields and nothing more', () {
      final BillingEntitlement entitlement = BillingEntitlement.fromMap(
        neverBilled,
      );

      // The five the producer's docblock guarantees non-null.
      expect(entitlement.plan, 'free');
      expect(entitlement.planStatus, PlanStatus.none);
      expect(entitlement.subscribed, isFalse);
      expect(entitlement.provider, BillingProvider.none);
      expect(entitlement.manageVia, ManageVia.none);

      // And the eight nullable ones, seven null on a never-billed team. A
      // decoder that defaulted any of these would claim a state no rail has
      // reported: `renews: null` is "nobody said", not "does not renew".
      expect(entitlement.renews, isNull);
      expect(entitlement.providerStatus, isNull);
      expect(entitlement.productId, isNull);
      expect(entitlement.manageUrl, isNull);
      expect(entitlement.currentPeriodEnd, isNull);
      expect(entitlement.trialEndsAt, isNull);
      expect(entitlement.gracePeriodEndsAt, isNull);
      expect(entitlement.aiAnalysisTrialsRemaining, 3);
    });

    test('decodes the Stripe rail, whose four store-shaped fields are null by design', () {
      // `test_show_emits_the_neutral_wire_for_a_stripe_team_with_a_customer`.
      final BillingEntitlement entitlement = BillingEntitlement.fromMap({
        'plan': 'pro',
        'plan_status': 'active',
        'subscribed': true,
        'renews': true,
        'provider': 'stripe',
        'provider_status': 'active',
        'product_id': 'price_pro',
        'manage_via': 'portal',
        // Null on the web rail by design: the client calls
        // `GET /billing/portal`, which mints the session live.
        'manage_url': null,
        'current_period_end': '2026-09-10T09:00:00+00:00',
        'trial_ends_at': '2026-08-27T09:00:00+00:00',
        'grace_period_ends_at': null,
        'ai_analysis_trials_remaining': null,
      });

      expect(entitlement.provider, BillingProvider.stripe);
      expect(entitlement.manageVia, ManageVia.portal);
      expect(entitlement.manageUrl, isNull);
      expect(entitlement.renews, isTrue);
      expect(entitlement.currentPeriodEnd, DateTime.utc(2026, 9, 10, 9));
      expect(entitlement.trialEndsAt, DateTime.utc(2026, 8, 27, 9));
      expect(entitlement.gracePeriodEndsAt, isNull);
    });

    test('a rail this build has never heard of decodes to the fallback, not an exception', () {
      final BillingEntitlement entitlement = BillingEntitlement.fromMap({
        ...neverBilled,
        'plan_status': 'chargeback',
        'provider': 'future_rail',
        'manage_via': 'carrier_billing',
      });

      // A newer backend shipping a fourth rail must degrade an older client,
      // not crash it; and the landing place is the non-entitling case on all
      // three vocabularies, mirroring the PHP `fromWire()` fallbacks.
      expect(entitlement.planStatus, PlanStatus.none);
      expect(entitlement.provider, BillingProvider.none);
      expect(entitlement.manageVia, ManageVia.none);
    });

    test('a malformed instant degrades to null instead of throwing', () {
      final BillingEntitlement entitlement = BillingEntitlement.fromMap({
        ...neverBilled,
        'current_period_end': 'not-an-instant',
        'trial_ends_at': '',
        'grace_period_ends_at': 42,
      });

      expect(entitlement.currentPeriodEnd, isNull);
      expect(entitlement.trialEndsAt, isNull);
      expect(entitlement.gracePeriodEndsAt, isNull);
    });

    test('raw keeps a field the value object does not enumerate', () {
      final BillingEntitlement entitlement = BillingEntitlement.fromMap({
        ...neverBilled,
        'a_field_a_newer_backend_added': 'read me through raw',
      });

      expect(entitlement.raw['a_field_a_newer_backend_added'], 'read me through raw');
      expect(entitlement.raw['plan_status'], 'none');
    });
  });

  group('the three server vocabularies', () {
    /*
     * Every list below is copied from the PHP case set, not from the Dart enum
     * beside it, and that is the whole point: the safe fallback is exactly what
     * hides a missing case, so a Dart enum that lost one would decode a real
     * wire value to `none` and degrade a live client silently. Asserting
     * against a list derived from the Dart enum would restate whatever the enum
     * happens to contain and prove nothing.
     *
     * `manual` deserves the note: nothing writes it yet, so it is the value a
     * hand-written Dart list is most likely to omit.
     */
    const List<String> planStatusWire = [
      // backend/app/Enums/PlanStatus.php:27-55
      'none',
      'trialing',
      'active',
      'past_due',
      'grace',
      'canceled',
      'expired',
      'paused',
    ];
    const List<String> billingProviderWire = [
      // backend/app/Enums/BillingProvider.php:26-42
      'none',
      'stripe',
      'app_store',
      'play_store',
      'manual',
    ];
    const List<String> manageViaWire = [
      // No PHP enum backs this one; it is computed by
      // `SubscriptionResource::manageVia()`, whose `match` emits these four.
      'none',
      'portal',
      'app_store',
      'play_store',
    ];

    test('every PlanStatus the backend can send decodes to a distinct case', () {
      for (final String wire in planStatusWire) {
        final PlanStatus decoded = planStatusFromWire(wire);
        if (wire != 'none') {
          expect(
            decoded,
            isNot(PlanStatus.none),
            reason: 'plan_status "$wire" fell back to none: the Dart mirror is '
                'missing the case and a live client would degrade silently.',
          );
        }
      }

      // Distinctness is the half that catches a case mapped onto its
      // neighbour rather than onto the fallback.
      expect(planStatusWire.map(planStatusFromWire).toSet(), hasLength(planStatusWire.length));
      expect(PlanStatus.values, hasLength(planStatusWire.length));
    });

    test('every BillingProvider the backend can send decodes to a distinct case', () {
      for (final String wire in billingProviderWire) {
        final BillingProvider decoded = billingProviderFromWire(wire);
        if (wire != 'none') {
          expect(
            decoded,
            isNot(BillingProvider.none),
            reason: 'provider "$wire" fell back to none: the Dart mirror is '
                'missing the case and a live client would degrade silently.',
          );
        }
      }

      expect(
        billingProviderWire.map(billingProviderFromWire).toSet(),
        hasLength(billingProviderWire.length),
      );
      expect(BillingProvider.values, hasLength(billingProviderWire.length));
    });

    test('every ManageVia the resource can compute decodes to a distinct case', () {
      for (final String wire in manageViaWire) {
        final ManageVia decoded = manageViaFromWire(wire);
        if (wire != 'none') {
          expect(
            decoded,
            isNot(ManageVia.none),
            reason: 'manage_via "$wire" fell back to none: the Dart mirror is '
                'missing the case and a live client would show no way to '
                'manage a paid subscription.',
          );
        }
      }

      expect(manageViaWire.map(manageViaFromWire).toSet(), hasLength(manageViaWire.length));
      expect(ManageVia.values, hasLength(manageViaWire.length));
    });

    test('an unknown or absent wire value lands on the fallback per vocabulary', () {
      expect(planStatusFromWire('chargeback'), PlanStatus.none);
      expect(planStatusFromWire(null), PlanStatus.none);
      expect(billingProviderFromWire('future_rail'), BillingProvider.none);
      expect(billingProviderFromWire(null), BillingProvider.none);
      expect(manageViaFromWire('carrier_billing'), ManageVia.none);
      expect(manageViaFromWire(null), ManageVia.none);
    });
  });
}
