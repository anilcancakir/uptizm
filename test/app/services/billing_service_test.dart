import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/invoice_status.dart' show InvoiceStatus;
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

    test('currentEntitlement gets /billing and decodes the entitlement', () async {
      Http.fake({
        // Every key here is copied verbatim from
        // `SubscriptionResource::toArray()`. The resource has never emitted a
        // `status` key, so a fixture that invents one certifies a decoder bug
        // instead of catching it.
        'billing': Http.response({
          'data': {
            'plan': 'pro',
            'plan_status': 'active',
            'ai_analysis_trials_remaining': 3,
            'subscribed': true,
            'on_grace_period': false,
            'stripe_price': 'price_pro_monthly',
            'stripe_status': 'active',
            'trial_ends_at': null,
            'ends_at': null,
          },
        }),
      });

      final BillingEntitlement entitlement = await service.currentEntitlement();

      expect(entitlement.plan, 'pro');
      expect(entitlement.status, 'active');
      expect(entitlement.aiAnalysisTrialsRemaining, 3);
    });

    test('currentEntitlement ignores a legacy top-level status key', () async {
      Http.fake({
        'billing': Http.response({
          'data': {'plan': 'pro', 'status': 'active'},
        }),
      });

      final BillingEntitlement entitlement = await service.currentEntitlement();

      // The decoder reads `plan_status` and nothing else: accepting both keys
      // would be a compatibility shim for a payload that never existed.
      expect(entitlement.status, isNull);
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
        // The real `SubscriptionResource::toArray()` key set again; `plan` can
        // only ever be a `Plan` enum value (`free`, `pro`, `business`).
        'billing': Http.response({
          'data': {
            'plan': 'business',
            'plan_status': 'active',
            'ai_analysis_trials_remaining': null,
            'subscribed': true,
            'on_grace_period': false,
            'stripe_price': 'price_business_monthly',
            'stripe_status': 'active',
            'trial_ends_at': null,
            'ends_at': null,
          },
        }),
      });

      final BillingEntitlement entitlement = await service.currentEntitlement();

      expect(entitlement.plan, 'business');
      expect(entitlement.status, 'active');
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
}
