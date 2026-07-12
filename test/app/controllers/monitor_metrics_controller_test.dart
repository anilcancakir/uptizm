import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/monitor_metrics_controller.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart';

void main() {
  // The write actions' failure path surfaces a `Magic.error` toast, which
  // reads `MagicRouter.instance.navigatorKey.currentContext`; that getter
  // touches `WidgetsBinding.instance` even with no widget tree mounted, so a
  // plain `test()` needs the binding initialized once up front (it then
  // falls back to a logged warning since no context is mounted, matching
  // `monitor_controller_test.dart`'s documented fallback).
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.error() works inside the write actions' failure
    // path (mirrors monitor_controller_test.dart).
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver; individual tests override it with
    // `Http.fake({...})` to seed a canned envelope, or a callback handler to
    // distinguish GET/POST/PUT/DELETE against the same URL.
    Http.fake();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test(
    'MonitorMetricsController.instance registers and returns a singleton',
    () {
      final MonitorMetricsController first = MonitorMetricsController.instance;
      final MonitorMetricsController second =
          MonitorMetricsController.instance;

      expect(identical(first, second), isTrue);
    },
  );

  test('metricsFor returns an empty list before any reload', () {
    final MonitorMetricsController controller = MonitorMetricsController.instance;

    expect(controller.metricsFor('api'), isEmpty);
  });

  // ---------------------------------------------------------------------------
  // reload: GET /monitors/:id/metrics
  // ---------------------------------------------------------------------------

  group('reload', () {
    test('decodes the custom metric catalog from GET /monitors/:id/metrics', () async {
      Http.fake({
        'monitors/api/metrics': Http.response({
          'data': [
            {
              'id': 'm1',
              'monitor_id': 'api',
              'label': 'Memory usage',
              'key': 'memory_usage',
              'type': 'numeric',
              'source': 'json_path',
              'extraction_path': r'$.system.memory.used_pct',
              'unit': 'percent',
              'threshold_direction': 'high_bad',
              'warn_bound': 80,
              'critical_bound': 95,
              'display_order': 0,
              'latest': {'numeric_value': 73.4},
            },
          ],
        }),
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      await controller.reload('api');

      final List<MonitorMetricRecord> metrics = controller.metricsFor('api');
      expect(metrics, hasLength(1));
      expect(metrics.first.id, equals('m1'));
      expect(metrics.first.form.label, equals('Memory usage'));
      expect(metrics.first.form.key, equals('memory_usage'));
      expect(metrics.first.form.source, equals('json'));
      expect(metrics.first.form.unit, equals('%'));
      expect(metrics.first.form.direction, equals('high'));
      expect(metrics.first.form.warn, equals('80'));
      expect(metrics.first.form.critical, equals('95'));
      expect(metrics.first.form.value, equals(73.4));
    });

    test('reload degrades to the last-known catalog when the network is unavailable', () async {
      Http.unfake();
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      await controller.reload('api');

      expect(controller.metricsFor('api'), isEmpty);
    });
  });

  // ---------------------------------------------------------------------------
  // create: POST /monitors/:id/metrics
  // ---------------------------------------------------------------------------

  group('create', () {
    test('posts the mapped payload and reloads on success', () async {
      final FakeNetworkDriver fake = Http.fake((request) {
        if (request.method == 'POST') {
          return Http.response({'data': {}}, 201);
        }
        return Http.response({
          'data': [
            {
              'id': 'm1',
              'label': 'Memory usage',
              'key': 'memory_usage',
              'type': 'numeric',
              'source': 'json_path',
              'unit': 'percent',
              'threshold_direction': 'high_bad',
              'warn_bound': 80,
              'critical_bound': 95,
            },
          ],
        });
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;
      final MetricForm form = kEmptyMetricForm.copyWith(
        label: 'Memory usage',
        key: 'memory_usage',
        path: r'$.system.memory.used_pct',
        warn: '80',
        critical: '95',
      );

      final bool ok = await controller.create('api', form);

      expect(ok, isTrue);
      fake.assertSent(
        (r) => r.method == 'POST' && r.url == '/monitors/api/metrics',
      );
      final Map<String, dynamic> payload = fake.recorded
          .firstWhere((entry) => entry.$1.method == 'POST')
          .$1
          .data as Map<String, dynamic>;
      expect(payload['label'], equals('Memory usage'));
      expect(payload['key'], equals('memory_usage'));
      expect(payload['source'], equals('json_path'));
      expect(payload['unit'], equals('percent'));
      expect(payload['threshold_direction'], equals('high_bad'));
      expect(payload['warn_bound'], equals(80));
      expect(payload['critical_bound'], equals(95));
      expect(controller.metricsFor('api'), hasLength(1));
    });

    test('returns false and does not reload on a failed create', () async {
      Http.fake({'monitors/api/metrics': Http.response({'message': 'Invalid'}, 422)});
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      final bool ok = await controller.create('api', kEmptyMetricForm);

      expect(ok, isFalse);
      expect(controller.metricsFor('api'), isEmpty);
    });
  });

  // ---------------------------------------------------------------------------
  // update: PUT /monitors/:id/metrics/:metricId
  // ---------------------------------------------------------------------------

  group('update', () {
    test('puts the mapped payload and reloads on success', () async {
      final FakeNetworkDriver fake = Http.fake((request) {
        if (request.method == 'PUT' && request.url == '/monitors/api/metrics/m1') {
          return Http.response({'data': {}});
        }
        return Http.response({'data': []});
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;
      final MetricForm form = kEmptyMetricForm.copyWith(
        label: 'Memory usage (edited)',
        key: 'memory_usage',
      );

      final bool ok = await controller.update('api', 'm1', form);

      expect(ok, isTrue);
      fake.assertSent(
        (r) => r.method == 'PUT' && r.url == '/monitors/api/metrics/m1',
      );
    });

    test('returns false on a failed update', () async {
      Http.fake({
        'monitors/api/metrics/m1': Http.response({'message': 'Nope'}, 422),
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      final bool ok = await controller.update('api', 'm1', kEmptyMetricForm);

      expect(ok, isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  // delete: DELETE /monitors/:id/metrics/:metricId
  // ---------------------------------------------------------------------------

  group('delete', () {
    test('deletes and reloads on success', () async {
      final FakeNetworkDriver fake = Http.fake((request) {
        if (request.method == 'DELETE') {
          return Http.response(null, 204);
        }
        return Http.response({'data': []});
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      final bool ok = await controller.delete('api', 'm1');

      expect(ok, isTrue);
      fake.assertSent(
        (r) => r.method == 'DELETE' && r.url == '/monitors/api/metrics/m1',
      );
    });

    test('returns false on a failed delete', () async {
      Http.fake({
        'monitors/api/metrics/m1': Http.response({'message': 'Nope'}, 404),
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      final bool ok = await controller.delete('api', 'm1');

      expect(ok, isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  // reorder: PUT /monitors/:id/metrics/reorder
  // ---------------------------------------------------------------------------

  group('reorder', () {
    test('puts the ordered id list and reloads on success', () async {
      final FakeNetworkDriver fake = Http.fake((request) {
        if (request.method == 'PUT' && request.url == '/monitors/api/metrics/reorder') {
          return Http.response(null, 204);
        }
        return Http.response({'data': []});
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      final bool ok = await controller.reorder('api', ['m2', 'm1']);

      expect(ok, isTrue);
      final Map<String, dynamic> payload = fake.recorded
          .firstWhere(
            (entry) =>
                entry.$1.method == 'PUT' &&
                entry.$1.url == '/monitors/api/metrics/reorder',
          )
          .$1
          .data as Map<String, dynamic>;
      final List<dynamic> order = payload['order'] as List<dynamic>;
      expect(order[0], equals({'id': 'm2', 'display_order': 0}));
      expect(order[1], equals({'id': 'm1', 'display_order': 1}));
    });

    test('returns false on a failed reorder', () async {
      Http.fake({
        'monitors/api/metrics/reorder': Http.response({'message': 'Nope'}, 404),
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      final bool ok = await controller.reorder('api', ['m1']);

      expect(ok, isFalse);
    });
  });
}
