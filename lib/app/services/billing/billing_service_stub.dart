import 'billing_service.dart';

/// Unsupported-platform fallback [BillingService].
///
/// Selected only when neither `dart.library.html` (web) nor `dart.library.io`
/// (iOS/Android/desktop) resolves, i.e. a platform this app does not ship on.
/// Every method throws [UnsupportedPlatformException]; there is no safe read
/// path here (unlike [BillingServiceIo.currentEntitlement]), since an
/// unrecognized platform has no assumed network stack to fall back on.
class BillingServiceStub implements BillingService {
  const BillingServiceStub();

  static const String _message =
      'Billing is not supported on this platform.';

  @override
  Future<BillingCheckoutSession> checkout({required String priceId}) async {
    throw const UnsupportedPlatformException(_message);
  }

  @override
  Future<void> swap({required String priceId}) async {
    throw const UnsupportedPlatformException(_message);
  }

  @override
  Future<void> cancel() async {
    throw const UnsupportedPlatformException(_message);
  }

  @override
  Future<String> openPortal() async {
    throw const UnsupportedPlatformException(_message);
  }

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    throw const UnsupportedPlatformException(_message);
  }
}

/// Creates the fallback [BillingService] implementation.
BillingService createBillingService() => const BillingServiceStub();
