import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/middleware/redirect_to_locale_onboarding.dart';
import 'package:uptizm/app/services/locale_onboarding_gate.dart';

/// Minimal authenticated user for the fake auth manager, mirroring the
/// `_FakeUser` helper in `ensure_authenticated_test.dart`.
class _FakeUser extends Model with Authenticatable {
  @override
  String get table => 'users';

  @override
  String get resource => 'users';

  @override
  List<String> get fillable => ['id', 'name'];
}

_FakeUser _fakeUser() {
  final user = _FakeUser();
  user.fill({'id': 1, 'name': 'Alice'});
  user.exists = true;
  return user;
}

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    LocaleOnboardingGate.instance.resetForTesting();
  });

  tearDown(() {
    Auth.unfake();
    LocaleOnboardingGate.instance.resetForTesting();
  });

  group('RedirectToLocaleOnboarding.redirectTarget', () {
    test(
      'routes an already-onboarded authenticated user straight through (flag set)',
      () {
        Auth.fake(user: _fakeUser());
        LocaleOnboardingGate.instance.resetForTesting(completed: true);
        final middleware = RedirectToLocaleOnboarding();

        // The core contract: with the first-run flag SET, a home navigation is
        // NOT intercepted, so a later login of an onboarded user reaches the
        // dashboard rather than re-showing onboarding.
        expect(middleware.redirectTarget('/'), isNull);
        expect(middleware.redirectTarget('/monitors'), isNull);
      },
    );

    test(
      'redirects an authenticated user who has not completed onboarding',
      () {
        Auth.fake(user: _fakeUser());
        LocaleOnboardingGate.instance.resetForTesting();
        final middleware = RedirectToLocaleOnboarding();

        expect(
          middleware.redirectTarget('/'),
          RedirectToLocaleOnboarding.onboardingRoute,
        );
      },
    );

    test('does not intercept an unauthenticated navigation', () {
      Auth.fake();
      LocaleOnboardingGate.instance.resetForTesting();
      final middleware = RedirectToLocaleOnboarding();

      // The 'auth' guard owns the unauthenticated case; this middleware must
      // stay out of its way so the two do not fight over the redirect target.
      expect(middleware.redirectTarget('/'), isNull);
    });

    test('allows a user already resting on the onboarding route (no loop)', () {
      Auth.fake(user: _fakeUser());
      LocaleOnboardingGate.instance.resetForTesting();
      final middleware = RedirectToLocaleOnboarding();

      expect(
        middleware.redirectTarget(RedirectToLocaleOnboarding.onboardingRoute),
        isNull,
      );
    });
  });
}
