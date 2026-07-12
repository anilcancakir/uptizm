import 'billing_service_stub.dart'
    if (dart.library.html) 'billing_service_web.dart'
    if (dart.library.io) 'billing_service_io.dart'
    as impl;

/// Base exception for billing-service failures.
///
/// Thrown when a request against the S17 billing endpoints fails (non-2xx
/// response) or returns a malformed payload. Callers (the eventual billing
/// controller, S19) decide how to surface this: toast + stay on the form for
/// a write action, last-known-state fallback for a read.
class BillingException implements Exception {
  const BillingException(this.message);

  /// A human-readable description of the failure.
  final String message;

  @override
  String toString() => 'BillingException: $message';
}

/// Thrown by the mobile ([BillingServiceIo]) and fallback ([BillingServiceStub])
/// implementations for the purchase-affecting actions ([BillingService.checkout],
/// [BillingService.swap], [BillingService.cancel], [BillingService.openPortal]).
///
/// Store billing rails (StoreKit on iOS, Google Play Billing on Android) are a
/// separate, risk-accepted-deferred scope (plan steps S20/S21); until they
/// land, a purchase action on a non-web platform must fail loudly with this
/// exception rather than silently no-op or fake success.
class UnsupportedPlatformException extends BillingException {
  const UnsupportedPlatformException(super.message);
}

/// A newly created Stripe Checkout session, as returned by
/// `POST /billing/checkout` (`{checkout_url, session_id}`).
class BillingCheckoutSession {
  const BillingCheckoutSession({required this.checkoutUrl, required this.sessionId});

  /// The Stripe-hosted checkout page URL to open in an in-app browser tab.
  final String checkoutUrl;

  /// The Stripe Checkout session id, for reconciliation with the webhook.
  final String sessionId;

  /// Decodes a [BillingCheckoutSession] from the raw `POST /billing/checkout`
  /// response body.
  factory BillingCheckoutSession.fromMap(Map<String, dynamic> map) {
    return BillingCheckoutSession(
      checkoutUrl: (map['checkout_url'] as String?) ?? '',
      sessionId: (map['session_id'] as String?) ?? '',
    );
  }
}

/// The team's current plan/subscription entitlement, as returned by
/// `GET /billing`.
///
/// [plan] and [status] surface the two fields the plan document names
/// explicitly ("current entitlement + subscription"); [raw] keeps the full
/// decoded payload so a caller can read additional `SubscriptionResource`
/// fields without this value object needing to enumerate every one of them.
class BillingEntitlement {
  const BillingEntitlement({required this.plan, required this.status, required this.raw});

  /// The active plan identifier (e.g. `'pro'`), or `null` when absent.
  final String? plan;

  /// The Stripe subscription status (e.g. `'active'`, `'canceled'`), or `null`
  /// when absent.
  final String? status;

  /// The full decoded `GET /billing` payload.
  final Map<String, dynamic> raw;

  /// Decodes a [BillingEntitlement] from the unwrapped `data` object of the
  /// `GET /billing` response.
  factory BillingEntitlement.fromMap(Map<String, dynamic> map) {
    return BillingEntitlement(
      plan: map['plan'] as String?,
      status: map['status'] as String?,
      raw: map,
    );
  }
}

/// Client-side billing abstraction over the S17 Cashier-backed endpoints.
///
/// Resolves to a platform-specific implementation via conditional import,
/// mirroring `magic_notifications`'s OneSignal driver pattern
/// ([BillingServiceWeb] on web, [BillingServiceIo] on iOS/Android/desktop,
/// [BillingServiceStub] as the unsupported-platform fallback):
///
/// ```dart
/// final BillingService billing = BillingService.instance;
/// final session = await billing.checkout(priceId: 'price_pro_monthly');
/// ```
///
/// Store rails (StoreKit/Play Billing) are a separate, deferred scope (S20/
/// S21); on mobile, the purchase-affecting methods throw
/// [UnsupportedPlatformException] rather than silently no-op.
abstract class BillingService {
  /// The platform-resolved [BillingService] singleton instance.
  static BillingService get instance => impl.createBillingService();

  /// Starts a Stripe Checkout session for [priceId] via
  /// `POST /billing/checkout` and returns the resulting [BillingCheckoutSession].
  ///
  /// On web, the checkout URL is opened in an in-app browser tab. On mobile,
  /// throws [UnsupportedPlatformException] (store rails deferred).
  Future<BillingCheckoutSession> checkout({required String priceId});

  /// Swaps the team's subscription to [priceId] via `POST /billing/swap`.
  ///
  /// On mobile, throws [UnsupportedPlatformException] (store rails deferred).
  Future<void> swap({required String priceId});

  /// Cancels the team's subscription via `POST /billing/cancel`.
  ///
  /// On mobile, throws [UnsupportedPlatformException] (store rails deferred).
  Future<void> cancel();

  /// Opens the Stripe billing portal via `GET /billing/portal` and returns
  /// the portal URL.
  ///
  /// On web, the URL is opened in an in-app browser tab. On mobile, throws
  /// [UnsupportedPlatformException] (store rails deferred).
  Future<String> openPortal();

  /// Reads the team's current plan/subscription entitlement via
  /// `GET /billing`.
  ///
  /// A read, not a purchase action: safe on every platform, including
  /// mobile ahead of the deferred store rails.
  Future<BillingEntitlement> currentEntitlement();
}
