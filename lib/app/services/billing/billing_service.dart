import '../../support/billing_types.dart' show Plan;
import '../../support/team_types.dart' show Invoice, PaymentMethod, UsageStat;
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
  const BillingEntitlement({
    required this.plan,
    required this.status,
    required this.aiAnalysisTrialsRemaining,
    required this.raw,
  });

  /// The active plan identifier (e.g. `'pro'`), or `null` when absent.
  final String? plan;

  /// The entitlement's status as the backend stores it (e.g. `'active'`,
  /// `'canceled'`), decoded from the wire's `plan_status`, or `null` when
  /// absent.
  final String? status;

  /// Metered AI monitor setups the team has left, or `null` when the tier
  /// entitles AI analysis outright (nothing to count down).
  final int? aiAnalysisTrialsRemaining;

  /// The full decoded `GET /billing` payload.
  final Map<String, dynamic> raw;

  /// Decodes a [BillingEntitlement] from the unwrapped `data` object of the
  /// `GET /billing` response.
  ///
  /// [status] reads `plan_status`, which is the only status key
  /// `SubscriptionResource` has ever emitted; a `status` key does not exist on
  /// this wire.
  factory BillingEntitlement.fromMap(Map<String, dynamic> map) {
    return BillingEntitlement(
      plan: map['plan'] as String?,
      status: map['plan_status'] as String?,
      aiAnalysisTrialsRemaining:
          (map['ai_analysis_trials_remaining'] as num?)?.toInt(),
      raw: map,
    );
  }
}

/// A cursor-paginated page of the team's Stripe invoices, as returned by
/// `GET /billing/invoices`.
class BillingInvoicesPage {
  const BillingInvoicesPage({required this.invoices, required this.nextCursor});

  /// The page of invoices, most recent first (the order Cashier's
  /// `cursorPaginateInvoices` returns).
  final List<Invoice> invoices;

  /// The encoded cursor for the next page, or `null` when this is the last
  /// page.
  final String? nextCursor;

  /// Decodes a [BillingInvoicesPage] from the raw `GET /billing/invoices`
  /// response body (`{data: [...], next_cursor}`).
  factory BillingInvoicesPage.fromMap(Map<String, dynamic> map) {
    final Object? rawData = map['data'];
    return BillingInvoicesPage(
      invoices: rawData is List
          ? rawData.whereType<Map<String, dynamic>>().map(Invoice.fromMap).toList()
          : const [],
      nextCursor: map['next_cursor'] as String?,
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
/// final session = await billing.checkout(
///   plan: 'pro',
///   successUrl: 'https://uptizm.com/teams/billing?checkout=success',
///   cancelUrl: 'https://uptizm.com/teams/billing?checkout=cancel',
/// );
/// ```
///
/// Store rails (StoreKit/Play Billing) are a separate, deferred scope (S20/
/// S21); on mobile, the purchase-affecting methods throw
/// [UnsupportedPlatformException] rather than silently no-op.
abstract class BillingService {
  /// The platform-resolved [BillingService] singleton instance.
  static BillingService get instance => impl.createBillingService();

  /// Starts a Stripe Checkout session for [plan] via `POST /billing/checkout`
  /// and returns the resulting [BillingCheckoutSession].
  ///
  /// [plan] is the backend `Plan` enum value (`'pro'` or `'business'`), never
  /// a Stripe price id; the backend resolves the price via its own
  /// `cashier.plans` config map. [successUrl] and [cancelUrl] are the pages
  /// Stripe Checkout redirects back to on completion/abort.
  ///
  /// On web, the checkout URL is opened in an in-app browser tab. On mobile,
  /// throws [UnsupportedPlatformException] (store rails deferred).
  Future<BillingCheckoutSession> checkout({
    required String plan,
    required String successUrl,
    required String cancelUrl,
  });

  /// Swaps the team's subscription to [plan] via `POST /billing/swap`.
  ///
  /// [plan] is the backend `Plan` enum value (`'pro'` or `'business'`), never
  /// a Stripe price id.
  ///
  /// On mobile, throws [UnsupportedPlatformException] (store rails deferred).
  Future<void> swap({required String plan});

  /// Cancels the team's subscription via `POST /billing/cancel`.
  ///
  /// On mobile, throws [UnsupportedPlatformException] (store rails deferred).
  Future<void> cancel();

  /// Opens the Stripe billing portal via `GET /billing/portal` and returns
  /// the portal URL. [returnUrl] is the page Stripe's portal returns to when
  /// the customer is done, sent as the `return_url` query parameter.
  ///
  /// On web, the URL is opened in an in-app browser tab. On mobile, throws
  /// [UnsupportedPlatformException] (store rails deferred).
  Future<String> openPortal({String? returnUrl});

  /// Reads the team's current plan/subscription entitlement via
  /// `GET /billing`.
  ///
  /// A read, not a purchase action: safe on every platform, including
  /// mobile ahead of the deferred store rails.
  Future<BillingEntitlement> currentEntitlement();

  /// Reads the static plan catalog via `GET /billing/plans`, cheapest tier
  /// first (the order the backend's `config/plans.php` serves and the
  /// upgrade/downgrade CTA depends on).
  ///
  /// A read, not a purchase action: safe on every platform.
  Future<List<Plan>> getPlans();

  /// Reads the team's current-cycle resource usage against its plan limits
  /// via `GET /billing/usage`.
  ///
  /// A read, not a purchase action: safe on every platform.
  Future<List<UsageStat>> getUsage();

  /// Cursor-paginates the team's Stripe invoices via `GET /billing/invoices`.
  ///
  /// [cursor] is the encoded cursor from a previous [BillingInvoicesPage],
  /// omitted for the first page. A read, not a purchase action: safe on
  /// every platform.
  Future<BillingInvoicesPage> getInvoices({String? cursor});

  /// Reads the team's on-file card + renewal date via
  /// `GET /billing/payment-method`.
  ///
  /// This is the only Stripe-live billing read; the backend soft-fails a
  /// Stripe outage to an all-null [PaymentMethod] with a 200, so callers
  /// should still give this its own loading/error UI rather than assuming a
  /// resolved [PaymentMethod] always has a card. A read, not a purchase
  /// action: safe on every platform.
  Future<PaymentMethod> getPaymentMethod();
}
