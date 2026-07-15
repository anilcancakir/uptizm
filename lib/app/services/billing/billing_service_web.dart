import 'package:magic/magic.dart';

import '../../support/billing_types.dart' show Plan;
import '../../support/team_types.dart' show PaymentMethod, UsageStat;
import 'billing_service.dart';

/// Web [BillingService]: calls the S17 Cashier-backed endpoints directly and
/// opens the Stripe-hosted checkout/portal URLs in an in-app browser tab.
class BillingServiceWeb implements BillingService {
  const BillingServiceWeb();

  @override
  Future<BillingCheckoutSession> checkout({
    required String plan,
    required String successUrl,
    required String cancelUrl,
  }) async {
    final MagicResponse response = await Http.post(
      '/billing/checkout',
      data: {
        'plan': plan,
        'success_url': successUrl,
        'cancel_url': cancelUrl,
      },
    );
    if (!response.successful) {
      Log.error('[BillingServiceWeb.checkout] ${response.errorMessage}');
      throw BillingException(
        response.errorMessage ?? 'Failed to start checkout.',
      );
    }

    final Object? data = response.data;
    if (data is! Map<String, dynamic>) {
      throw const BillingException('Malformed checkout response.');
    }

    final BillingCheckoutSession session = BillingCheckoutSession.fromMap(data);
    await Launch.url(session.checkoutUrl, mode: LaunchMode.inAppWebView);
    return session;
  }

  @override
  Future<void> swap({required String plan}) async {
    final MagicResponse response = await Http.post(
      '/billing/swap',
      data: {'plan': plan},
    );
    if (!response.successful) {
      Log.error('[BillingServiceWeb.swap] ${response.errorMessage}');
      throw BillingException(
        response.errorMessage ?? 'Failed to change plan.',
      );
    }
  }

  @override
  Future<void> cancel() async {
    final MagicResponse response = await Http.post('/billing/cancel');
    if (!response.successful) {
      Log.error('[BillingServiceWeb.cancel] ${response.errorMessage}');
      throw BillingException(
        response.errorMessage ?? 'Failed to cancel subscription.',
      );
    }
  }

  @override
  Future<String> openPortal({String? returnUrl}) async {
    final MagicResponse response = await Http.get(
      '/billing/portal',
      query: returnUrl == null ? null : {'return_url': returnUrl},
    );
    if (!response.successful) {
      Log.error('[BillingServiceWeb.openPortal] ${response.errorMessage}');
      throw BillingException(
        response.errorMessage ?? 'Failed to open the billing portal.',
      );
    }

    final Object? data = response.data;
    final String? portalUrl = data is Map<String, dynamic>
        ? data['portal_url'] as String?
        : null;
    if (portalUrl == null || portalUrl.isEmpty) {
      throw const BillingException('Malformed billing portal response.');
    }

    await Launch.url(portalUrl, mode: LaunchMode.inAppWebView);
    return portalUrl;
  }

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    final MagicResponse response = await Http.get('/billing');
    if (!response.successful) {
      Log.error(
        '[BillingServiceWeb.currentEntitlement] ${response.errorMessage}',
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
      Log.error('[BillingServiceWeb.getPlans] ${response.errorMessage}');
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
      Log.error('[BillingServiceWeb.getUsage] ${response.errorMessage}');
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
      Log.error('[BillingServiceWeb.getInvoices] ${response.errorMessage}');
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
        '[BillingServiceWeb.getPaymentMethod] ${response.errorMessage}',
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

/// Creates the web [BillingService] implementation.
BillingService createBillingService() => const BillingServiceWeb();
