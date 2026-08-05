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
  // Unit round-trip: every backend `MetricUnit` value must decode through
  // `_unitFromWire` (exercised here via `MonitorMetricRecord.fromMap`) and
  // re-encode through `_unitToWireValue` (exercised via `create`'s payload)
  // back to itself. Before Step 5, only six of these sixteen values were
  // paired; the other ten collapsed to `custom` on decode and were then
  // written back as literal `custom` on the next save.
  //
  // The sixteen values are enumerated here, not derived from the map under
  // test, so a seventeenth backend `MetricUnit` case added later without a
  // matching form-side pairing fails this test loudly instead of silently
  // collapsing to `custom`.
  // ---------------------------------------------------------------------------

  group('unit round-trip across all sixteen MetricUnit values', () {
    const List<String> backendUnits = [
      'bytes_auto',
      'byte',
      'kilobyte',
      'megabyte',
      'gigabyte',
      'terabyte',
      'duration_auto',
      'millisecond',
      'second',
      'minute',
      'hour',
      'percent',
      'ratio',
      'count',
      'count_short',
      'custom',
    ];

    for (final String wireUnit in backendUnits) {
      test('$wireUnit decodes then re-encodes to itself', () async {
        final MonitorMetricRecord decoded = MonitorMetricRecord.fromMap({
          'id': 'm1',
          'label': 'Probe',
          'key': 'probe',
          'type': 'numeric',
          'source': 'json_path',
          'unit': wireUnit,
          'threshold_direction': 'high_bad',
        });

        final FakeNetworkDriver fake = Http.fake((request) {
          if (request.method == 'POST') {
            return Http.response({'data': {}}, 201);
          }
          return Http.response({'data': []});
        });
        final MonitorMetricsController controller =
            MonitorMetricsController.instance;

        await controller.create('api', decoded.form);

        final Map<String, dynamic> payload = fake.recorded
            .firstWhere((entry) => entry.$1.method == 'POST')
            .$1
            .data as Map<String, dynamic>;
        expect(payload['unit'], equals(wireUnit));
      });
    }
  });

  // ---------------------------------------------------------------------------
  // String-band wire round trip (Step 12): decode, type-gated encode, and the
  // dot-notation 422 mapping.
  // ---------------------------------------------------------------------------

  group('string-band fields', () {
    test('MonitorMetricRecord.fromMap decodes all four string-band fields', () {
      final MonitorMetricRecord decoded = MonitorMetricRecord.fromMap({
        'id': 'm1',
        'label': 'Health status',
        'key': 'health_status',
        'type': 'string',
        'source': 'json_path',
        'extraction_path': r'$.status',
        'ok_values': ['ok'],
        'warn_values': ['degraded'],
        'critical_values': ['down'],
        'unmatched_band': 'critical',
      });

      expect(decoded.form.okValues, equals(['ok']));
      expect(decoded.form.warnValues, equals(['degraded']));
      expect(decoded.form.criticalValues, equals(['down']));
      expect(decoded.form.unmatchedBand, equals('critical'));
    });

    test(
      'a string metric create payload carries the string-band fields and omits the numeric ones',
      () async {
        final FakeNetworkDriver fake = Http.fake((request) {
          if (request.method == 'POST') {
            return Http.response({'data': {}}, 201);
          }
          return Http.response({'data': []});
        });
        final MonitorMetricsController controller =
            MonitorMetricsController.instance;
        final MetricForm form = kEmptyMetricForm.copyWith(
          label: 'Health status',
          key: 'health_status',
          type: 'string',
          path: r'$.status',
          okValues: ['ok'],
          warnValues: ['degraded'],
          criticalValues: ['down'],
          unmatchedBand: 'critical',
        );

        await controller.create('api', form);

        final Map<String, dynamic> payload = fake.recorded
            .firstWhere((entry) => entry.$1.method == 'POST')
            .$1
            .data as Map<String, dynamic>;
        expect(payload['ok_values'], equals(['ok']));
        expect(payload['warn_values'], equals(['degraded']));
        expect(payload['critical_values'], equals(['down']));
        expect(payload['unmatched_band'], equals('critical'));
        expect(payload.containsKey('threshold_direction'), isFalse);
        expect(payload.containsKey('warn_bound'), isFalse);
        expect(payload.containsKey('critical_bound'), isFalse);
      },
    );

    test(
      'a numeric metric create payload carries the numeric fields and omits the string-band ones',
      () async {
        final FakeNetworkDriver fake = Http.fake((request) {
          if (request.method == 'POST') {
            return Http.response({'data': {}}, 201);
          }
          return Http.response({'data': []});
        });
        final MonitorMetricsController controller =
            MonitorMetricsController.instance;
        final MetricForm form = kEmptyMetricForm.copyWith(
          label: 'Memory usage',
          key: 'memory_usage',
          type: 'numeric',
          warn: '80',
          critical: '95',
        );

        await controller.create('api', form);

        final Map<String, dynamic> payload = fake.recorded
            .firstWhere((entry) => entry.$1.method == 'POST')
            .$1
            .data as Map<String, dynamic>;
        expect(payload['threshold_direction'], equals('high_bad'));
        expect(payload['warn_bound'], equals(80));
        expect(payload['critical_bound'], equals(95));
        expect(payload.containsKey('ok_values'), isFalse);
        expect(payload.containsKey('warn_values'), isFalse);
        expect(payload.containsKey('critical_values'), isFalse);
        expect(payload.containsKey('unmatched_band'), isFalse);
      },
    );

    test('a dot-notation 422 key like ok_values.1 maps back to its owning field', () async {
      Http.fake({
        'monitors/api/metrics': Http.response({
          'message': 'The ok values.1 field is invalid.',
          'errors': {
            'ok_values.1': ['The ok_values.1 field is invalid.'],
          },
        }, 422),
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      final Map<String, String> result = await controller.create(
        'api',
        kEmptyMetricForm,
      );

      expect(result, equals({'ok_values': 'The ok_values.1 field is invalid.'}));
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

      final Map<String, String> result = await controller.create('api', form);

      expect(result, isEmpty);
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

    test('maps a 422 field error inline and does not reload', () async {
      Http.fake({
        'monitors/api/metrics': Http.response({
          'message': 'The key has already been taken.',
          'errors': {
            'key': ['The key has already been taken.'],
          },
        }, 422),
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      final Map<String, String> result = await controller.create(
        'api',
        kEmptyMetricForm,
      );

      expect(result, equals({'key': 'The key has already been taken.'}));
      expect(controller.metricsFor('api'), isEmpty);
    });

    test(
      'returns an empty map on a non-field failure and does not reload',
      () async {
        Http.fake({
          'monitors/api/metrics': Http.response({'message': 'Server error'}, 500),
        });
        final MonitorMetricsController controller =
            MonitorMetricsController.instance;

        final Map<String, String> result = await controller.create(
          'api',
          kEmptyMetricForm,
        );

        expect(result, isEmpty);
        expect(controller.metricsFor('api'), isEmpty);
      },
    );
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

      final Map<String, String> result = await controller.update(
        'api',
        'm1',
        form,
      );

      expect(result, isEmpty);
      fake.assertSent(
        (r) => r.method == 'PUT' && r.url == '/monitors/api/metrics/m1',
      );
    });

    test('maps a 422 field error inline on a failed update', () async {
      Http.fake({
        'monitors/api/metrics/m1': Http.response({
          'message': 'The label field is required.',
          'errors': {
            'label': ['The label field is required.'],
          },
        }, 422),
      });
      final MonitorMetricsController controller = MonitorMetricsController.instance;

      final Map<String, String> result = await controller.update(
        'api',
        'm1',
        kEmptyMetricForm,
      );

      expect(result, equals({'label': 'The label field is required.'}));
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

  // ---------------------------------------------------------------------------
  // candidates: decoding the extraction-candidate envelope. The rows come from
  // `MetricCandidate::toDigestRow()`, whose `src` is the BACKEND enum value and
  // whose `label` key is absent (not null) when there is no hint, so a decode
  // that trusted the form vocabulary or a present key would drop the row's
  // source or crash on the missing one.
  // ---------------------------------------------------------------------------

  group('candidates', () {
    test('maps the backend source enum back to the form vocabulary', () async {
      Http.fake({
        'monitors/api/content/candidates': Http.response({
          'has_sample': true,
          'data': [
            {
              'ref': 'c1',
              'src': 'json_path',
              'path': 'checks.database.status',
              'value': 'ok',
              'label': 'status',
              'types': ['string', 'status'],
            },
            {
              'ref': 'c4',
              'src': 'header',
              'path': 'x-cache',
              'value': 'HIT',
              'types': ['string'],
            },
          ],
        }),
      });
      final MonitorMetricsController controller =
          MonitorMetricsController.instance;

      final MetricCandidateSet? set = await controller.candidates('api');

      expect(set, isNotNull);
      expect(set!.hasSample, isTrue);
      expect(set.candidates, hasLength(2));
      // `json_path` is the enum value; the form field speaks `json`. Handing the
      // raw wire value to MetricForm.source would post an unknown source back.
      expect(set.candidates.first.source, equals('json'));
      expect(set.candidates.first.ref, equals('c1'));
      expect(set.candidates.first.label, equals('status'));
      expect(set.candidates.first.types, equals(['string', 'status']));
      // A ref gap is expected, not corruption: the backend drops an over-long
      // path rather than renumbering what follows it.
      expect(set.candidates.last.ref, equals('c4'));
      expect(set.candidates.last.source, equals('header'));
      // The key is omitted entirely rather than sent as null.
      expect(set.candidates.last.label, isNull);
    });

    test('an empty list with has_sample true is not the no-sample state', () async {
      Http.fake({
        'monitors/api/content/candidates': Http.response({
          'has_sample': true,
          'data': <Object?>[],
        }),
      });
      final MonitorMetricsController controller =
          MonitorMetricsController.instance;

      final MetricCandidateSet? set = await controller.candidates('api');

      // The two states drive different copy: "run a check first" against "look
      // at your endpoint", so collapsing them would misdirect the operator.
      expect(set!.hasSample, isTrue);
      expect(set.candidates, isEmpty);
    });

    test('a payload with no has_sample flag reads as no sample', () async {
      Http.fake({
        'monitors/api/content/candidates': Http.response({
          'data': <Object?>[],
        }),
      });
      final MonitorMetricsController controller =
          MonitorMetricsController.instance;

      final MetricCandidateSet? set = await controller.candidates('api');

      // Absent is malformed, and claiming a sample existed would send the
      // operator hunting for a body nothing ever recorded.
      expect(set!.hasSample, isFalse);
    });

    test('returns null on a failed request', () async {
      Http.fake({
        'monitors/api/content/candidates': Http.response(
          {'message': 'Too Many Requests'},
          429,
        ),
      });
      final MonitorMetricsController controller =
          MonitorMetricsController.instance;

      final MetricCandidateSet? set = await controller.candidates('api');

      // Null is the form's "could not offer candidates" signal; an empty set
      // would render as "your response held nothing extractable".
      expect(set, isNull);
    });
  });

  // ---------------------------------------------------------------------------
  // resetForSession: clear every monitor's cached catalog. There is nothing to
  // refetch (reload is per monitor id, and the cached ids belong to the
  // previous session's monitors).
  // ---------------------------------------------------------------------------

  group('resetForSession', () {
    test('clears every cached catalog and notifies, without refetching', () async {
      final fake = Http.fake();
      final MonitorMetricsController controller =
          MonitorMetricsController.instance;
      controller.seedForTest('api', [
        MonitorMetricRecord(
          id: 'm1',
          form: kEmptyMetricForm.copyWith(
            label: 'Cart items',
            key: 'cart_items',
          ),
        ),
      ]);
      controller.seedForTest('web', [
        MonitorMetricRecord(
          id: 'm2',
          form: kEmptyMetricForm.copyWith(
            label: 'Queue depth',
            key: 'queue_depth',
          ),
        ),
      ]);
      expect(controller.metricsFor('api'), hasLength(1));
      var notifications = 0;
      controller.addListener(() => notifications++);

      await controller.resetForSession();

      expect(notifications, equals(1));
      expect(controller.metricsFor('api'), isEmpty);
      expect(controller.metricsFor('web'), isEmpty);
      // Refetching the cached ids would probe the previous team's monitors and
      // collect a masked 404 per id; the metrics tab reloads per monitor when
      // it mounts instead.
      fake.assertNothingSent();
    });
  });

  group('MonitorMetricRecord.fromMap recorded_at', () {
    test('decodes an ISO-8601 string', () {
      final MonitorMetricRecord record = MonitorMetricRecord.fromMap(const {
        'id': 'm1',
        'label': 'Redis state',
        'key': 'redis',
        'type': 'string',
        'latest': {'string_value': 'ok', 'recorded_at': '2026-08-05T12:00:00+00:00'},
      });

      expect(record.latestRecordedAt, DateTime.parse('2026-08-05T12:00:00Z'));
    });

    test('a non-string recorded_at degrades to null instead of throwing', () {
      // `as String?` would throw a CastError here, and a decoder that crashes on
      // one malformed field shows the operator nothing at all rather than a
      // reading it cannot date.
      expect(
        () => MonitorMetricRecord.fromMap(const {
          'id': 'm1',
          'label': 'Redis state',
          'key': 'redis',
          'type': 'string',
          'latest': {'string_value': 'ok', 'recorded_at': 1785880108},
        }),
        returnsNormally,
      );

      final MonitorMetricRecord record = MonitorMetricRecord.fromMap(const {
        'id': 'm1',
        'label': 'Redis state',
        'key': 'redis',
        'type': 'string',
        'latest': {'string_value': 'ok', 'recorded_at': 1785880108},
      });

      expect(record.latestRecordedAt, isNull);
      expect(record.latestString, 'ok', reason: 'the rest of the reading survives');
    });

    test('an absent latest block leaves it null', () {
      final MonitorMetricRecord record = MonitorMetricRecord.fromMap(const {
        'id': 'm1',
        'label': 'Redis state',
        'key': 'redis',
        'type': 'string',
      });

      expect(record.latestRecordedAt, isNull);
    });
  });
}
