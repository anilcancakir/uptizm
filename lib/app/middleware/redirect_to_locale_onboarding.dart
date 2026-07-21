import 'package:magic/magic.dart';

import '../services/locale_onboarding_gate.dart';

/// Middleware that sends a freshly authenticated user through the one-time
/// locale + timezone onboarding screen before the dashboard.
///
/// Applied as the `'onboarding'` alias on the uptizm shell route group, layered
/// AFTER `'auth'`: the auth guard resolves an unauthenticated boot to the login
/// route first, then this guard intercepts an authenticated navigation whose
/// device has not yet completed locale onboarding and redirects it to
/// [onboardingRoute].
///
/// The decision reads [LocaleOnboardingGate], a device-scoped first-run flag,
/// NOT the auth action: register and login both land on the shared home route,
/// so keying off "just registered" is impossible without touching the shared
/// magic_starter auth controller. The gate is set on confirm/skip, so once a
/// device is onboarded a later login routes straight to the dashboard.
///
/// It overrides [redirectTarget] (a pre-build synchronous redirect) rather than
/// [handle], mirroring [EnsureAuthenticated]: the router evaluates the redirect
/// before any page builds, so the onboarding screen mounts exactly once.
class RedirectToLocaleOnboarding extends MagicMiddleware {
  /// The standalone onboarding route this guard redirects to.
  static const String onboardingRoute = '/onboarding/locale';

  @override
  String? redirectTarget(String location) {
    // 1. Leave the unauthenticated case to the 'auth' guard so the two do not
    //    fight over the redirect target (and cannot form a loop).
    if (!Auth.check()) return null;

    // 2. An onboarded device (flag set) passes straight through, so a later
    //    login never re-shows onboarding.
    if (LocaleOnboardingGate.instance.isCompleted) return null;

    // 3. Never redirect the onboarding route onto itself: go_router raises
    //    after more than five successive redirects.
    if (location == onboardingRoute) return null;

    return onboardingRoute;
  }
}
