import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/services/billing/billing_service.dart';
import 'package:uptizm/app/services/billing/billing_service_io.dart';
import 'package:uptizm/app/services/billing/billing_service_stub.dart';
import 'package:uptizm/app/services/billing/billing_service_web.dart';

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
        priceId: 'price_pro_monthly',
      );

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('billing/checkout') &&
            (r.data as Map<String, dynamic>)['price_id'] == 'price_pro_monthly',
      );
      expect(session.checkoutUrl, 'https://checkout.stripe.com/session_123');
      expect(session.sessionId, 'session_123');
    });

    test('checkout throws BillingException on a failed response', () async {
      Http.fake({
        'billing/checkout': Http.response({'message': 'No payment method.'}, 422),
      });

      expect(
        () => service.checkout(priceId: 'price_pro_monthly'),
        throwsA(isA<BillingException>()),
      );
    });

    test('swap posts to /billing/swap with the new price', () async {
      final FakeNetworkDriver fake = Http.fake();

      await service.swap(priceId: 'price_pro_annual');

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('billing/swap') &&
            (r.data as Map<String, dynamic>)['price_id'] == 'price_pro_annual',
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
      Http.fake({
        'billing/portal': Http.response({
          'portal_url': 'https://billing.stripe.com/portal_abc',
        }),
      });

      final String portalUrl = await service.openPortal();

      expect(portalUrl, 'https://billing.stripe.com/portal_abc');
    });

    test('currentEntitlement gets /billing and decodes the entitlement', () async {
      Http.fake({
        'billing': Http.response({
          'data': {'plan': 'pro', 'status': 'active'},
        }),
      });

      final BillingEntitlement entitlement = await service.currentEntitlement();

      expect(entitlement.plan, 'pro');
      expect(entitlement.status, 'active');
    });
  });

  group('BillingServiceIo (mobile, store rails deferred)', () {
    const BillingServiceIo service = BillingServiceIo();

    test('checkout throws UnsupportedPlatformException, never hits the network', () async {
      final FakeNetworkDriver fake = Http.fake();

      await expectLater(
        () => service.checkout(priceId: 'price_pro_monthly'),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      fake.assertNothingSent();
    });

    test('swap throws UnsupportedPlatformException, never hits the network', () async {
      final FakeNetworkDriver fake = Http.fake();

      await expectLater(
        () => service.swap(priceId: 'price_pro_annual'),
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
        'billing': Http.response({
          'data': {'plan': 'starter', 'status': 'active'},
        }),
      });

      final BillingEntitlement entitlement = await service.currentEntitlement();

      expect(entitlement.plan, 'starter');
      expect(entitlement.status, 'active');
    });
  });

  group('BillingServiceStub (unsupported platform fallback)', () {
    const BillingServiceStub service = BillingServiceStub();

    test('every method throws UnsupportedPlatformException', () async {
      await expectLater(
        () => service.checkout(priceId: 'price_pro_monthly'),
        throwsA(isA<UnsupportedPlatformException>()),
      );
      await expectLater(
        () => service.swap(priceId: 'price_pro_annual'),
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
    });
  });
}
