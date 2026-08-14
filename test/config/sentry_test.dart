import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/config/sentry.dart';

/// Locks the gate that keeps development traffic out of the production project.
///
/// The suite runs with no bundled `.env`, so `APP_ENV` falls back to `local`,
/// which is the same state every developer machine is in. If this ever returns
/// a DSN here, it returns one there too, and the cost is not noise: the plan
/// carries no overage budget, so quota spent locally DROPS real production
/// events for the rest of the month.
///
/// The type matters as much as the value. `Sentry.init` throws on a NULL dsn
/// while treating an empty string as a supported "send nothing", and
/// `appRunner` (which boots this entire app) runs inside that call. A nullable
/// version of this getter would stop the app from booting everywhere except
/// production.
void main() {
  test('no DSN is configured outside production', () {
    expect(sentryDsn, isEmpty);
    expect(sentryEnabled, isFalse);
  });

  test('the disabled value is an empty string, never null', () {
    // A compile-time guarantee today (the getter returns String, not String?).
    // Asserted anyway because the failure mode of changing it is an app that
    // does not start, reported as a Sentry configuration error.
    expect(sentryDsn, isA<String>());
  });
}
