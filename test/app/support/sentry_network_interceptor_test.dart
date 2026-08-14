import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/support/sentry_network_interceptor.dart';

/// Locks the two decisions that separate a useful Sentry inbox from an
/// abandoned one.
///
/// This client's HTTP layer never throws: `Http.get()` hands back a
/// `MagicResponse` and the caller reads `response.successful`, so a failure is a
/// VALUE. Nothing reaches Sentry unless this interceptor puts it there, which
/// makes both decisions below load-bearing rather than cosmetic.
///
/// The first is what deserves an issue at all. A 401 is a session that expired
/// and a 422 is a form the user filled in wrong: both are the product working,
/// and filing them as issues is how an inbox becomes something nobody opens.
/// A 500 or a dead connection is the opposite.
///
/// The second is grouping. Sentry groups by fingerprint, and a fingerprint
/// carrying a raw path means `/monitors/<uuid>` opens a NEW issue for every
/// monitor a customer owns. One broken endpoint would then arrive as a thousand
/// separate issues, which is indistinguishable from a thousand separate bugs.
void main() {
  group('dispositionFor', () {
    test('a dead connection earns an issue', () {
      // `MagicError.statusCode` answers 0 when there is no response at all,
      // which is the shape a timeout or a refused connection arrives in.
      expect(
        SentryNetworkInterceptor.dispositionFor(0),
        SentryHttpDisposition.event,
      );
    });

    test('a server fault earns an issue', () {
      expect(
        SentryNetworkInterceptor.dispositionFor(500),
        SentryHttpDisposition.event,
      );
      expect(
        SentryNetworkInterceptor.dispositionFor(503),
        SentryHttpDisposition.event,
      );
    });

    test('an expected client answer is only a breadcrumb', () {
      for (final status in [400, 401, 403, 404, 409, 422, 429]) {
        expect(
          SentryNetworkInterceptor.dispositionFor(status),
          SentryHttpDisposition.breadcrumb,
          reason: '$status is the product working, not a fault to page anyone about.',
        );
      }
    });
  });

  group('normalizeEndpoint', () {
    test('it collapses a numeric id so one endpoint is one issue', () {
      expect(
        SentryNetworkInterceptor.normalizeEndpoint('/monitors/123/checks'),
        '/monitors/{id}/checks',
      );
    });

    test('it collapses a uuid, which is what this backend actually issues', () {
      // Migrations here are UUID-optional (`MigrationHelper::primaryKey()`), so
      // both forms reach the client and both have to collapse.
      expect(
        SentryNetworkInterceptor.normalizeEndpoint(
          '/monitors/9f8e7d6c-5b4a-4321-8765-0fedcba98765/metrics',
        ),
        '/monitors/{id}/metrics',
      );
    });

    test('it leaves a static path alone', () {
      expect(
        SentryNetworkInterceptor.normalizeEndpoint('/dashboard/active-incidents'),
        '/dashboard/active-incidents',
      );
    });

    test('it does not mistake a word for an id', () {
      // A segment being long or hex-ish is not enough; `incidents` must survive.
      expect(
        SentryNetworkInterceptor.normalizeEndpoint('/incidents/acknowledged'),
        '/incidents/acknowledged',
      );
    });

    test('it drops a query string, which would explode grouping', () {
      expect(
        SentryNetworkInterceptor.normalizeEndpoint('/monitors?page=3&per_page=50'),
        '/monitors',
      );
    });
  });

  group('deduplication', () {
    setUp(SentryNetworkInterceptor.reportedFailures.clear);

    test('the same failure is only reported once per session', () {
      // The app polls notifications every 30 seconds, so a backend outage is
      // not one failure, it is one failure repeated for as long as the tab is
      // open. Reported every time, a single hour-long outage across a couple of
      // hundred tabs would spend most of a 50k MONTHLY error allowance on one
      // fact somebody already knows.
      final interceptor = SentryNetworkInterceptor();
      final error = MagicError(message: 'gateway down');

      interceptor.onError(error);
      interceptor.onError(error);
      interceptor.onError(error);

      expect(SentryNetworkInterceptor.reportedFailures, hasLength(1));
    });

    test('a different endpoint or status is its own report', () {
      final interceptor = SentryNetworkInterceptor();

      interceptor.onError(MagicError(message: 'a'));
      interceptor.onError(
        MagicError(
          message: 'b',
          request: MagicRequest(url: '/monitors', method: 'GET'),
        ),
      );

      expect(SentryNetworkInterceptor.reportedFailures, hasLength(2));
    });
  });

  group('onError', () {
    setUp(SentryNetworkInterceptor.reportedFailures.clear);

    test('it returns the error untouched so the interceptor chain survives', () {
      // magic reads the return value: a `MagicResponse` RESOLVES the failure as
      // a success, anything else lets it continue. Reporting must never change
      // the outcome of a request, so this returns exactly what it was handed.
      final error = MagicError(message: 'boom');

      expect(
        identical(SentryNetworkInterceptor().onError(error), error),
        isTrue,
      );
    });
  });
}
