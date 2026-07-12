import 'package:magic/magic.dart';

import 'billing_service.dart';

/// Web [BillingService]: calls the S17 Cashier-backed endpoints directly and
/// opens the Stripe-hosted checkout/portal URLs in an in-app browser tab.
class BillingServiceWeb implements BillingService {
  const BillingServiceWeb();

  @override
  Future<BillingCheckoutSession> checkout({required String priceId}) async {
    final MagicResponse response = await Http.post(
      '/billing/checkout',
      data: {'price_id': priceId},
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
  Future<void> swap({required String priceId}) async {
    final MagicResponse response = await Http.post(
      '/billing/swap',
      data: {'price_id': priceId},
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
  Future<String> openPortal() async {
    final MagicResponse response = await Http.get('/billing/portal');
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
}

/// Creates the web [BillingService] implementation.
BillingService createBillingService() => const BillingServiceWeb();
