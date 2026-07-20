import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/enums/status_key.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.warning() works inside MagicFeedback.showSnackbar
    // (pause/resume/delete call Magic.success, which falls through to a
    // warning log when no navigator context is mounted, as here).
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver so the wired controller resolves the
    // `network` service. Individual tests override it with `Http.fake({...})`
    // to seed a canned envelope, or call `Http.unfake()` to exercise the
    // network-unavailable degradation path.
    Http.fake();
    // Force-build the lazy GoRouter so MagicRoute.to (used by delete/create/
    // save) does not throw StateError('Router not initialized...'). In
    // production the router is built once at app boot before any controller
    // action fires; accessing the getter here reproduces that precondition
    // without mounting a widget tree.
    MagicRouter.instance.routerConfig;
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test('MonitorController.instance registers and returns a singleton', () {
    final MonitorController first = MonitorController.instance;
    final MonitorController second = MonitorController.instance;

    expect(identical(first, second), isTrue);
  });

  // The controller now sources its inventory from `GET /monitors`, so the
  // former `controller.monitors == monitors` fixture-equality assertions are
  // replaced by seeded-envelope decode + degradation assertions against the
  // wired behavior.
  test('reload decodes the monitor inventory from GET /monitors', () async {
    Http.fake({
      'monitors': Http.response({
        'data': [
          {
            'id': 'api',
            'name': 'API',
            'url': 'https://api.uptizm.com',
            'last_status': 'up',
            'last_response_ms': 120,
            'uptime': '99.98%',
            'check_interval_sec': 30,
            'regions': ['us-east', 'eu-west'],
          },
          {'id': 'marketing', 'name': 'Marketing site', 'status': 'paused'},
        ],
      }),
    });
    final MonitorController controller = MonitorController.instance;

    await controller.reload();

    expect(
      controller.monitors.map((Monitor m) => m.id).toList(),
      equals(['api', 'marketing']),
    );
    expect(controller.monitors.first.status, equals(StatusKey.up));
    expect(controller.monitors.first.responseMs, equals(120));
    expect(controller.monitors.last.status, equals(StatusKey.paused));
  });

  test('monitorById resolves a decoded monitor after a reload', () async {
    Http.fake({
      'monitors': Http.response({
        'data': [
          {
            'id': 'api',
            'name': 'API',
            'url': 'https://api.uptizm.com',
            'last_status': 'up',
          },
        ],
      }),
    });
    final MonitorController controller = MonitorController.instance;
    await controller.reload();

    final Monitor? resolved = controller.monitorById('api');

    expect(resolved, isNotNull);
    expect(resolved!.id, equals('api'));
    expect(resolved.name, equals('API'));
  });

  test('monitorById returns null for an unknown or null id', () {
    final MonitorController controller = MonitorController.instance;

    expect(controller.monitorById('does-not-exist'), isNull);
    expect(controller.monitorById(null), isNull);
  });

  test(
    'reload degrades to an empty inventory when the network is unavailable',
    () async {
      // Drop the faked network so `Http.get` resolves an unregistered service:
      // the defensive `reload` must swallow it and never throw out of onInit.
      Http.unfake();
      final MonitorController controller = MonitorController.instance;

      await controller.reload();

      expect(controller.monitors, isEmpty);
    },
  );

  // ---------------------------------------------------------------------------
  // Business actions: toast + navigation side-effects only.
  //
  // The `monitors` fixture is a compile-time `const List`; the pre-controller
  // views never mutated it, so these actions carry no state to mutate and call
  // no refreshUI() (behavior parity, see plan Wave 2 controller-behavior note).
  // Each assertion below confirms both halves of that contract: the action
  // completes without throwing, AND it does not notify listeners.
  // ---------------------------------------------------------------------------

  group('business actions do not mutate state or notify listeners', () {
    test('pause does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.pause(monitors.first.id), returnsNormally);
      expect(notifications, equals(0));
    });

    test('pause on an unknown id is a no-op', () {
      final MonitorController controller = MonitorController.instance;

      expect(() => controller.pause('does-not-exist'), returnsNormally);
    });

    test('resume does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.resume(monitors.first.id), returnsNormally);
      expect(notifications, equals(0));
    });

    test('resume on an unknown id is a no-op', () {
      final MonitorController controller = MonitorController.instance;

      expect(() => controller.resume('does-not-exist'), returnsNormally);
    });

    test('delete does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.delete(monitors.first.id), returnsNormally);
      expect(notifications, equals(0));
    });

    test('delete on an unknown id is a no-op', () {
      final MonitorController controller = MonitorController.instance;

      expect(() => controller.delete('does-not-exist'), returnsNormally);
    });

    test('create does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.create(), returnsNormally);
      expect(notifications, equals(0));
    });

    test('save does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.save(monitors.first.id), returnsNormally);
      expect(notifications, equals(0));
    });
  });

  // ---------------------------------------------------------------------------
  // analyze (S38): POST /monitors/analyze.
  // ---------------------------------------------------------------------------

  group('analyze', () {
    test(
      'posts the url to /monitors/analyze and decodes the prefill on success',
      () async {
        final fake = Http.fake({
          'monitors/analyze': Http.response({
            'data': {
              'url': 'https://api.example.com/health',
              'name': 'api.example.com',
              'recommended_interval_seconds': 60,
              'recommended_warn_threshold_ms': 300,
              'recommended_critical_threshold_ms': 1000,
              'recommended_regions': ['us-east', 'eu-west'],
              'rationale': 'Stable JSON API, 60s checks are sufficient.',
              'probe': {
                'region': 'us-east',
                'status_code': 200,
                'response_ms': 120,
              },
            },
          }),
        });
        final MonitorController controller = MonitorController.instance;

        final MonitorAnalysis? result = await controller.analyze(
          'https://api.example.com/health',
        );

        expect(result, isNotNull);
        expect(result!.url, equals('https://api.example.com/health'));
        expect(result.name, equals('api.example.com'));
        expect(result.recommendedIntervalSeconds, equals(60));
        expect(result.recommendedWarnThresholdMs, equals(300));
        expect(result.recommendedCriticalThresholdMs, equals(1000));
        expect(result.recommendedRegions, equals(['us-east', 'eu-west']));
        expect(
          result.rationale,
          equals('Stable JSON API, 60s checks are sufficient.'),
        );
        fake.assertSent(
          (request) =>
              request.method == 'POST' &&
              request.url.contains('monitors/analyze') &&
              (request.data as Map)['url'] == 'https://api.example.com/health',
        );
      },
    );

    test('returns null and does not throw on a non-2xx response', () async {
      Http.fake({
        'monitors/analyze': Http.response({
          'message': 'The url field is required.',
        }, 422),
      });
      final MonitorController controller = MonitorController.instance;

      final MonitorAnalysis? result = await controller.analyze('not-a-url');

      expect(result, isNull);
    });

    test(
      'returns null and does not throw when the network is unavailable',
      () async {
        Http.unfake();
        final MonitorController controller = MonitorController.instance;

        final MonitorAnalysis? result = await controller.analyze(
          'https://api.example.com/health',
        );

        expect(result, isNull);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // 90-day uptime bucket mapping (S10).
  // ---------------------------------------------------------------------------

  group('mapBucketsToUptime90', () {
    test('leaves a day with no bucket data as null (no-data, not up)', () {
      final segments = MonitorController.mapBucketsToUptime90(const []);

      expect(segments, hasLength(90));
      expect(segments.every((s) => s.status == null), isTrue);
    });

    test('maps a bucket to the down status on its day offset', () {
      final DateTime now = DateTime(2026, 7, 12, 12);
      final segments = MonitorController.mapBucketsToUptime90([
        {'checked_at': '2026-07-12T09:00:00.000Z', 'status': 'down'},
      ], now: now);

      // Today (daysAgo == 0) is the LAST segment (index 89), matching the
      // "90 days ago" (left) / "today" (right) axis labels.
      expect(segments.last.status, equals(StatusKey.down));
      expect(segments.last.label, equals('today'));
      // The other 89 days had no check, so they stay null (no-data), not up.
      expect(segments.sublist(0, 89).every((s) => s.status == null), isTrue);
    });

    test(
      'folds a day with mixed buckets to the worst status (down > degraded)',
      () {
        final DateTime now = DateTime(2026, 7, 12, 12);
        final segments = MonitorController.mapBucketsToUptime90([
          {'checked_at': '2026-07-10T01:00:00.000Z', 'status': 'up'},
          {'checked_at': '2026-07-10T05:00:00.000Z', 'status': 'degraded'},
          {'checked_at': '2026-07-10T09:00:00.000Z', 'status': 'up'},
        ], now: now);

        // 2026-07-10 is 2 days before 2026-07-12 -> index 89 - 2 = 87.
        expect(segments[87].status, equals(StatusKey.degraded));
      },
    );

    test('a down bucket outranks a degraded bucket on the same day', () {
      final DateTime now = DateTime(2026, 7, 12, 12);
      final segments = MonitorController.mapBucketsToUptime90([
        {'checked_at': '2026-07-12T01:00:00.000Z', 'status': 'degraded'},
        {'checked_at': '2026-07-12T05:00:00.000Z', 'status': 'down'},
      ], now: now);

      expect(segments.last.status, equals(StatusKey.down));
    });

    test('ignores buckets outside the trailing 90-day window', () {
      final DateTime now = DateTime(2026, 7, 12, 12);
      final segments = MonitorController.mapBucketsToUptime90([
        {'checked_at': '2026-01-01T00:00:00.000Z', 'status': 'down'},
      ], now: now);

      // The out-of-window bucket is ignored, so every day is no-data (null).
      expect(segments.every((s) => s.status == null), isTrue);
    });
  });

  group('loadUptime90', () {
    test('decodes bucketed response-times into 90 daily segments', () async {
      // `loadUptime90` buckets against the live `DateTime.now()` (it does not
      // thread a fixed `now:` into `mapBucketsToUptime90`), so the fixture's
      // `checked_at` MUST be derived from the current wall clock rather than a
      // hard-coded calendar date. A bucket dated "today" lands in the LAST
      // segment (index 89, daysAgo == 0) regardless of when the suite runs.
      final DateTime today = DateTime.now();
      Http.fake({
        'monitors/api/response-times*': Http.response({
          'data': [
            {'checked_at': today.toIso8601String(), 'status': 'down'},
          ],
        }),
      });
      final MonitorController controller = MonitorController.instance;

      final segments = await controller.loadUptime90('api');

      expect(segments, hasLength(90));
      expect(segments.last.status, equals(StatusKey.down));
    });

    test('degrades to an empty list when the network is unavailable', () async {
      Http.unfake();
      final MonitorController controller = MonitorController.instance;

      final segments = await controller.loadUptime90('api');

      expect(segments, isEmpty);
    });
  });
}
