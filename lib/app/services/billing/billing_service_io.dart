import 'package:magic/magic.dart';

import '../../support/billing_types.dart' show Plan;
import '../../support/team_types.dart' show PaymentMethod, UsageStat;
import 'billing_service.dart';

/// Mobile (iOS/Android) [BillingService].
///
/// Store billing (StoreKit on iOS, Google Play Billing on Android) is a
/// separate, risk-accepted-deferred scope (plan steps S20/S21): every
/// purchase-affecting action ([checkout], [swap], [cancel], [openPortal])
/// throws [UnsupportedPlatformException] with a clear deferred message rather
/// than silently no-op or fake success. [currentEntitlement], [getPlans],
/// [getUsage], [getInvoices], and [getPaymentMethod] are reads, so they still
/// call the real `api/v1` endpoints.
class BillingServiceIo implements BillingService {
  const BillingServiceIo();

  /// Shown when a purchase-affecting action is attempted on a store platform.
  ///
  /// It deliberately names no other purchase method. The clause "manage your
  /// subscription on the web" used to end this sentence, and on iOS that is a
  /// user who tapped Upgrade being pointed at an outside payment method, which
  /// App Review Guideline 3.1.3(a) forbids. Saying only what is true here costs
  /// nothing and removes the whole class of problem: an app may state that a
  /// feature is unavailable, it may not route the customer around IAP.
  static const String _deferredMessage =
      'In-app purchases are not yet available on this platform. Store '
      'billing (StoreKit on iOS, Google Play Billing on Android) is planned '
      'but not implemented.';

  @override
  Future<BillingCheckoutSession> checkout({
    required String plan,
    required String successUrl,
    required String cancelUrl,
  }) async {
    throw const UnsupportedPlatformException(_deferredMessage);
  }

  @override
  Future<void> swap({required String plan}) async {
    throw const UnsupportedPlatformException(_deferredMessage);
  }

  @override
  Future<void> cancel() async {
    throw const UnsupportedPlatformException(_deferredMessage);
  }

  @override
  Future<String> openPortal({String? returnUrl}) async {
    throw const UnsupportedPlatformException(_deferredMessage);
  }

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    final MagicResponse response = await Http.get('/billing');
    if (!response.successful) {
      Log.error(
        '[BillingServiceIo.currentEntitlement] ${response.errorMessage}',
      );
      throw BillingException(
        response.errorMessage ?? 'Failed to load the billing entitlement.',
      );
    }

    final Object? raw = response.data is Map<String, dynamic>
        ? (response.data as Map<String, dynamic>)['data']
        : null;
    if (raw is! Map<String, dynamic>) {
      throw const BillingException('Malformed billing entitlement response.');
    }

    return BillingEntitlement.fromMap(raw);
  }

  @override
  Future<List<Plan>> getPlans() async {
    final MagicResponse response = await Http.get('/billing/plans');
    if (!response.successful) {
      Log.error('[BillingServiceIo.getPlans] ${response.errorMessage}');
      throw BillingException(
        response.errorMessage ?? 'Failed to load the plan catalog.',
      );
    }

    final Object? raw = response.data is Map<String, dynamic>
        ? (response.data as Map<String, dynamic>)['data']
        : null;
    if (raw is! List) {
      throw const BillingException('Malformed plan catalog response.');
    }

    return raw.whereType<Map<String, dynamic>>().map(Plan.fromMap).toList();
  }

  @override
  Future<List<UsageStat>> getUsage() async {
    final MagicResponse response = await Http.get('/billing/usage');
    if (!response.successful) {
      Log.error('[BillingServiceIo.getUsage] ${response.errorMessage}');
      throw BillingException(
        response.errorMessage ?? 'Failed to load usage.',
      );
    }

    final Object? data = response.data;
    if (data is! Map<String, dynamic>) {
      throw const BillingException('Malformed usage response.');
    }

    return UsageStat.fromWireMap(data);
  }

  @override
  Future<BillingInvoicesPage> getInvoices({String? cursor}) async {
    final MagicResponse response = await Http.get(
      '/billing/invoices',
      query: cursor == null ? null : {'cursor': cursor},
    );
    if (!response.successful) {
      Log.error('[BillingServiceIo.getInvoices] ${response.errorMessage}');
      throw BillingException(
        response.errorMessage ?? 'Failed to load invoices.',
      );
    }

    final Object? data = response.data;
    if (data is! Map<String, dynamic>) {
      throw const BillingException('Malformed invoices response.');
    }

    return BillingInvoicesPage.fromMap(data);
  }

  @override
  Future<PaymentMethod> getPaymentMethod() async {
    final MagicResponse response = await Http.get('/billing/payment-method');
    if (!response.successful) {
      Log.error(
        '[BillingServiceIo.getPaymentMethod] ${response.errorMessage}',
      );
      throw BillingException(
        response.errorMessage ?? 'Failed to load the payment method.',
      );
    }

    final Object? data = response.data;
    if (data is! Map<String, dynamic>) {
      throw const BillingException('Malformed payment method response.');
    }

    return PaymentMethod.fromMap(data);
  }
}

/// Creates the mobile [BillingService] implementation.
BillingService createBillingService() => const BillingServiceIo();
