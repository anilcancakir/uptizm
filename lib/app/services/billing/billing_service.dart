import '../../enums/billing_provider.dart' show BillingProvider, billingProviderFromWire;
import '../../enums/manage_via.dart' show ManageVia, manageViaFromWire;
import '../../enums/plan_status.dart' show PlanStatus, planStatusFromWire;
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
/// The thirteen fields mirror `SubscriptionResource::toArray()` one for one, in
/// its rail-neutral vocabulary: nothing here names a payment rail's own dialect
/// except [providerStatus], which is debug text and must never reach a gate or
/// a computed field.
///
/// FIVE of the thirteen are non-null guaranteed by the producer, and this
/// decoder relies on it: [plan], [planStatus], [subscribed], [provider] and
/// [manageVia]. The other eight are nullable, four of them on the Stripe rail BY
/// DESIGN rather than by accident: [manageUrl] and [gracePeriodEndsAt] have no
/// Stripe source at all, and [providerStatus] and [productId] stay null until a
/// rail writes them. A decoder that defaulted any of the eight would claim a
/// state no rail has reported, which is a different sentence from "not reported".
///
/// [raw] keeps the full decoded payload, so a caller can read a field this value
/// object has not enumerated (a key a newer backend added) without waiting for a
/// client release.
class BillingEntitlement {
  /// Builds an entitlement, decoding the three neutral vocabularies from their
  /// RAW WIRE WORDS rather than taking already-decoded cases.
  ///
  /// The wire word is the only thing that ever reaches this constructor, so
  /// putting the `*FromWire()` fallbacks here means an unrecognised value cannot
  /// enter the object undecoded by any path, including a hand-built test double.
  /// [status] carries the wire's `plan_status`; the field it feeds is
  /// [planStatus], named for the key rather than for the parameter.
  ///
  /// The nine fields added with the rail-neutral wire are optional so a fake
  /// entitlement can still be built from the four values a plan gate cares
  /// about. [subscribed] defaults to `false` and the two vocabularies default to
  /// their `none` case, which is the same non-entitling landing place their
  /// `*FromWire()` fallbacks use: an entitlement nobody described must never
  /// read as a paid one.
  BillingEntitlement({
    required this.plan,
    required String? status,
    this.subscribed = false,
    this.renews,
    String? provider,
    this.providerStatus,
    this.productId,
    String? manageVia,
    this.manageUrl,
    this.currentPeriodEnd,
    this.trialEndsAt,
    this.gracePeriodEndsAt,
    required this.aiAnalysisTrialsRemaining,
    required this.raw,
  }) : planStatus = planStatusFromWire(status),
       provider = billingProviderFromWire(provider),
       manageVia = manageViaFromWire(manageVia);

  /// The active plan identifier (e.g. `'pro'`), or `null` when absent.
  final String? plan;

  /// Where the paid plan stands in its lifecycle, in the neutral vocabulary.
  final PlanStatus planStatus;

  /// Whether the team currently holds a paid plan.
  ///
  /// Trusted from the wire, never recomputed here: the server derives it from
  /// the entitlement tier plus `PlanStatus::grants()`, and a second client-side
  /// definition could disagree with the one that actually gates. A customer with
  /// a failed charge stays subscribed while their rail retries.
  final bool subscribed;

  /// Whether the subscription rolls over at [currentPeriodEnd].
  ///
  /// Nullable on purpose: `null` means no rail has said, which is not the claim
  /// `false` makes.
  final bool? renews;

  /// Which rail granted the entitlement.
  final BillingProvider provider;

  /// The rail's OWN status word, verbatim, including words the neutral
  /// vocabulary has none for. Debug and support text only: never a gate, never
  /// an input to a computed field.
  final String? providerStatus;

  /// The rail's product identifier (a Stripe price id, a store product id), or
  /// `null` until a rail writes one.
  final String? productId;

  /// Where the customer manages this subscription, computed server-side from the
  /// rail so no client has to learn the rail-to-surface mapping.
  final ManageVia manageVia;

  /// The destination that pairs with a store [manageVia], or `null`.
  ///
  /// Null on the Stripe rail by design (the portal session is minted live by
  /// `GET /billing/portal`), and also possible on a store rail whose management
  /// URL has not arrived. A null on a store rail renders a statement WITHOUT a
  /// link rather than a dead button.
  final String? manageUrl;

  /// When the paid period ends, whether or not it renews, or `null` when no rail
  /// has reported one.
  final DateTime? currentPeriodEnd;

  /// When the trial ends. Stripe-only by construction: the producer reads it
  /// from Cashier's local `subscriptions.trial_ends_at`, and a store trial
  /// arrives as [planStatus] `trialing` plus [currentPeriodEnd] instead.
  final DateTime? trialEndsAt;

  /// When the dunning grace period ends, or `null` when the team is not in one.
  /// Non-null is itself the answer to "is this team in a grace period"; there is
  /// no separate boolean on this wire.
  final DateTime? gracePeriodEndsAt;

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
      subscribed: (map['subscribed'] as bool?) ?? false,
      renews: map['renews'] as bool?,
      provider: map['provider'] as String?,
      providerStatus: map['provider_status'] as String?,
      productId: map['product_id'] as String?,
      manageVia: map['manage_via'] as String?,
      manageUrl: map['manage_url'] as String?,
      currentPeriodEnd: _instantFromWire(map['current_period_end']),
      trialEndsAt: _instantFromWire(map['trial_ends_at']),
      gracePeriodEndsAt: _instantFromWire(map['grace_period_ends_at']),
      aiAnalysisTrialsRemaining:
          (map['ai_analysis_trials_remaining'] as num?)?.toInt(),
      raw: map,
    );
  }
}

/// Decodes one of the entitlement's three ISO8601 instants.
///
/// `DateTime.tryParse` rather than `parse`, and an `Object?` rather than a
/// `String?` in: a malformed or wrongly-typed instant degrades to "not
/// reported" exactly as an unrecognised enum value degrades to its `none` case.
/// A billing screen that cannot render a date must still render the plan.
DateTime? _instantFromWire(Object? raw) {
  return raw is String ? DateTime.tryParse(raw) : null;
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
