import 'package:magic/magic.dart';

import 'billing_service.dart';

/// Mobile (iOS/Android) [BillingService].
///
/// Store billing (StoreKit on iOS, Google Play Billing on Android) is a
/// separate, risk-accepted-deferred scope (plan steps S20/S21): every
/// purchase-affecting action ([checkout], [swap], [cancel], [openPortal])
/// throws [UnsupportedPlatformException] with a clear deferred message rather
/// than silently no-op or fake success. [currentEntitlement] is a read, so it
/// still calls the real `GET /billing` endpoint.
class BillingServiceIo implements BillingService {
  const BillingServiceIo();

  static const String _deferredMessage =
      'In-app purchases are not yet available on this platform. Store '
      'billing (StoreKit on iOS, Google Play Billing on Android) is planned '
      'but not implemented; manage your subscription on the web.';

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
}

/// Creates the mobile [BillingService] implementation.
BillingService createBillingService() => const BillingServiceIo();
