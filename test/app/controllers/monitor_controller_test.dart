import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/entitlement_controller.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/app/support/monitor_types.dart';

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

  test('reload keeps the measured reliability a list fetch cannot carry', () async {
    // `GET /monitors/:id` measures uptime_24h plus the eight 7-day / 30-day
    // reliability minutes from the raw check stream; `GET /monitors` deliberately
    // does NOT (it would cost an N+1 of aggregate queries per row), so the list
    // sends them as null. Both write into the same inventory, so a list fetch
    // that replaces the collection wholesale erases what the show fetch
    // measured, and the detail screen's reliability section reads "not enough
    // data yet" for a monitor that has been checked for days. On production the
    // list is fetched about ten times more often than the show, so the erasure
    // always wins.
    //
    // This is also the only test that fails when `_measuredUptimeAttributes`
    // still names the retired uptime-percentage keys instead of these eight:
    // every widget test passes against a payload that decoded once.
    final MonitorController controller = MonitorController.instance;
    Http.fake({
      // The show path, which is where the reliability minutes come from.
      '*monitors/api': Http.response({
        'data': {
          'id': 'api',
          'name': 'API',
          'uptime_24h': 100,
          'slo_down_minutes_7d': 2,
          'slo_observed_minutes_7d': 10080,
          'slo_gap_minutes_7d': 30,
          'slo_measured_minutes_7d': 10050,
          'slo_down_minutes_30d': 2,
          'slo_observed_minutes_30d': 43200,
          'slo_gap_minutes_30d': 45,
          'slo_measured_minutes_30d': 43155,
        },
      }),
      // The list path, which cannot speak for any of them.
      'monitors': Http.response({
        'data': [
          {
            'id': 'api',
            'name': 'API',
            'last_status': 'up',
            'last_response_ms': 120,
            'uptime_24h': null,
            'slo_down_minutes_7d': null,
            'slo_observed_minutes_7d': null,
            'slo_gap_minutes_7d': null,
            'slo_measured_minutes_7d': null,
            'slo_down_minutes_30d': null,
            'slo_observed_minutes_30d': null,
            'slo_gap_minutes_30d': null,
            'slo_measured_minutes_30d': null,
          },
        ],
      }),
    });

    // Open the detail screen (the show fetch), then let the inventory refresh
    // land on top of it, which is the production sequence.
    await controller.refreshOne('api');
    await controller.reload();

    final Monitor? refreshed = controller.monitorById('api');
    expect(refreshed, isNotNull);
    expect(refreshed!.sloDownMinutes7d, equals(2.0));
    expect(refreshed.sloObservedMinutes7d, equals(10080.0));
    expect(refreshed.sloGapMinutes7d, equals(30.0));
    expect(refreshed.sloMeasuredMinutes7d, equals(10050.0));
    expect(refreshed.sloDownMinutes30d, equals(2.0));
    expect(refreshed.sloObservedMinutes30d, equals(43200.0));
    expect(refreshed.sloGapMinutes30d, equals(45.0));
    expect(refreshed.sloMeasuredMinutes30d, equals(43155.0));
    expect(refreshed.uptime24h, equals(100.0));
    // The list stays authoritative for everything it DOES measure.
    expect(refreshed.status, equals(StatusKey.up));
    expect(refreshed.responseMs, equals(120));
  });

  test('reload does not invent a measurement the show fetch never made', () async {
    // The carry-forward is one-directional: it only rescues a value the show
    // fetch actually measured. A monitor the operator has never opened has no
    // measured reliability anywhere, and the list must not gain one.
    final MonitorController controller = MonitorController.instance;
    controller.seedForTest([
      Monitor.fromMap(const {'id': 'api', 'name': 'API'}),
    ]);

    Http.fake({
      'monitors': Http.response({
        'data': [
          {'id': 'api', 'name': 'API', 'slo_down_minutes_7d': null},
        ],
      }),
    });

    await controller.reload();

    expect(controller.monitorById('api')!.sloDownMinutes7d, isNull);
  });

  // ---------------------------------------------------------------------------
  // noteCheckRecorded: a socket reading applied in place
  // ---------------------------------------------------------------------------

  /// A `check.recorded` frame for [id] as the backend shapes it.
  Map<String, dynamic> reading({
    String id = 'api',
    String lastStatus = 'up',
    String checkedAt = '2026-08-19T09:30:00+00:00',
    int? responseMs = 143,
  }) => <String, dynamic>{
    'monitor_id': id,
    'region': 'eu-west',
    'result': lastStatus,
    'response_ms': responseMs,
    'checked_at': checkedAt,
    'last_status': lastStatus,
    'last_checked_at': checkedAt,
    'last_response_ms': responseMs,
  };

  test('noteCheckRecorded patches the cached monitor without a fetch', () {
    final MonitorController controller = MonitorController.instance;
    controller.seedForTest([
      Monitor.fromMap(const {
        'id': 'api',
        'name': 'API',
        'last_status': 'up',
        'last_checked_at': '2026-08-19T09:00:00+00:00',
        'last_response_ms': 90,
      }),
    ]);
    // No Http.fake at all: a reading that reached for the network would throw
    // here rather than pass quietly, which is the property under test.

    controller.noteCheckRecorded(
      reading(lastStatus: 'degraded', responseMs: 512),
    );

    final Monitor patched = controller.monitorById('api')!;
    expect(patched.status, equals(StatusKey.degraded));
    expect(patched.responseMs, equals(512));
    expect(patched.lastCheckedAt!.format('yyyy-MM-dd HH:mm'), '2026-08-19 09:30');
  });

  test('noteCheckRecorded ignores a monitor that is not in the inventory', () {
    // The channel is team-wide, so a reading arrives for every monitor the team
    // owns. Inserting one from a reading would add a row with no url, no type and
    // no interval to a list that renders all three.
    final MonitorController controller = MonitorController.instance;
    controller.seedForTest([
      Monitor.fromMap(const {'id': 'api', 'name': 'API'}),
    ]);

    controller.noteCheckRecorded(reading(id: 'never-listed'));

    expect(controller.monitors.map((Monitor m) => m.id).toList(), ['api']);
  });

  test('noteCheckRecorded ignores a reading older than the one held', () {
    // Regions land in whatever order the socket delivers them and each frame
    // carries the denorm state as of ITS write. Applying a late one would wind
    // last_checked_at backwards, and the detail view refetches its history on
    // exactly that field moving.
    final MonitorController controller = MonitorController.instance;
    controller.seedForTest([
      Monitor.fromMap(const {
        'id': 'api',
        'name': 'API',
        'last_status': 'down',
        'last_checked_at': '2026-08-19T09:30:00+00:00',
        'last_response_ms': 512,
      }),
    ]);

    controller.noteCheckRecorded(
      reading(lastStatus: 'up', checkedAt: '2026-08-19T09:29:00+00:00', responseMs: 90),
    );

    final Monitor held = controller.monitorById('api')!;
    expect(held.status, equals(StatusKey.down));
    expect(held.responseMs, equals(512));
  });

  test('noteCheckRecorded applies a reading with the same timestamp', () {
    // Only STRICTLY older is dropped. A re-delivery at the same instant carries
    // the same truth, and treating equal as stale would drop the first reading of
    // a monitor whose held timestamp came from the same check.
    final MonitorController controller = MonitorController.instance;
    controller.seedForTest([
      Monitor.fromMap(const {
        'id': 'api',
        'name': 'API',
        'last_status': 'up',
        'last_checked_at': '2026-08-19T09:30:00+00:00',
        'last_response_ms': 90,
      }),
    ]);

    controller.noteCheckRecorded(reading(responseMs: 143));

    expect(controller.monitorById('api')!.responseMs, equals(143));
  });

  test('noteCheckRecorded survives a malformed or absent timestamp', () {
    final MonitorController controller = MonitorController.instance;
    controller.seedForTest([
      Monitor.fromMap(const {
        'id': 'api',
        'name': 'API',
        'last_response_ms': 90,
      }),
    ]);

    // A frame the device cannot place in time is dropped, not thrown: the
    // listener that delivered it must survive a bad payload.
    controller.noteCheckRecorded(reading(checkedAt: 'not-a-timestamp'));
    controller.noteCheckRecorded(const <String, dynamic>{'monitor_id': 'api'});
    controller.noteCheckRecorded(const <String, dynamic>{});

    expect(controller.monitorById('api')!.responseMs, equals(90));
  });

  test('noteCheckRecorded carries a null latency through', () {
    // A down check has no latency. The field has to become null rather than keep
    // the last good number, or a dead endpoint reads as fast.
    final MonitorController controller = MonitorController.instance;
    controller.seedForTest([
      Monitor.fromMap(const {
        'id': 'api',
        'name': 'API',
        'last_status': 'up',
        'last_checked_at': '2026-08-19T09:00:00+00:00',
        'last_response_ms': 90,
      }),
    ]);

    controller.noteCheckRecorded(reading(lastStatus: 'down', responseMs: null));

    expect(controller.monitorById('api')!.responseMs, isNull);
    expect(controller.monitorById('api')!.status, equals(StatusKey.down));
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

  group('runCheckNow', () {
    /// Seeds one cached monitor so the action has something to resolve, and
    /// returns the fake so a test can assert what went over the wire.
    Future<FakeNetworkDriver> seedOne() async {
      final FakeNetworkDriver fake = Http.fake({
        'monitors': Http.response({
          'data': [
            {
              'id': 'api',
              'name': 'API',
              'url': 'https://api.uptizm.com',
              'last_status': 'up',
              'check_interval_sec': 30,
              'regions': ['us-east'],
            },
          ],
        }),
      });
      await MonitorController.instance.reload();
      fake.reset();

      return fake;
    }

    test('POSTs the out-of-schedule check and refreshes that monitor', () async {
      // The endpoint exists and answers 202, but nothing in the UI reached it:
      // an operator had no way to ask "check this right now" and had to wait
      // out the interval.
      final FakeNetworkDriver fake = await seedOne();

      await MonitorController.instance.runCheckNow('api');

      fake.assertSent(
        (r) => r.method == 'POST' && r.url == '/monitors/api/test',
      );
      // 202 carries no result, so the refresh is how anything that already
      // landed reaches the screen.
      fake.assertSent((r) => r.method == 'GET' && r.url == '/monitors/api');
    });

    test('an unknown id sends nothing', () async {
      final FakeNetworkDriver fake = await seedOne();

      await MonitorController.instance.runCheckNow('does-not-exist');

      fake.assertNothingSent();
    });
  });

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

    testWidgets('a successful create lands on the new monitor detail route', (
      tester,
    ) async {
      // Reported after a live create: clicking Create dropped the operator on
      // the LIST, so they had to hunt for the row they had just made and were
      // told nothing about the check. `store()` sets next_check_at to now and
      // queues the probes, so the detail screen is where what they just asked
      // for actually happens (it shows "Waiting for the first check", then swaps
      // the real chart and table in).
      Http.fake({
        '*monitors': Http.response({
          'data': {'id': 'brand-new-id', 'name': 'Fresh', 'type': 'http'},
        }),
      });

      // The router has to be MOUNTED for `currentLocation` to report anything:
      // `MagicRoute.to` asks GoRouter to navigate, and the location is read back
      // off the router's own state, which only exists under a widget tree.
      MagicRouter.reset();
      MagicRoute.page('/monitors', () => const SizedBox());
      MagicRoute.page('/monitors/:id', () => const SizedBox());
      addTearDown(MagicRouter.reset);
      await tester.pumpWidget(
        MaterialApp.router(routerConfig: MagicRouter.instance.routerConfig),
      );
      await tester.pump();

      final MonitorController controller = MonitorController.instance;

      await controller.create(<String, dynamic>{
        'name': 'Fresh',
        'url': 'https://fresh.test/health',
        'type': 'http',
        'method': 'get',
        'check_interval_sec': 180,
        'timeout_sec': 30,
        'regions': const <String>['eu-central'],
      });

      await tester.pumpAndSettle();

      expect(
        MagicRouter.instance.currentLocation,
        '/monitors/brand-new-id',
        reason: 'the create opens the monitor it just made, not the list',
      );
    });

    testWidgets('a create with no id in the response falls back to the list', (
      tester,
    ) async {
      // The fallback is not decoration: without an id there is no detail route
      // to open, and navigating to `/monitors/` would resolve to a not-found
      // screen for a monitor that was in fact created.
      Http.fake({
        '*monitors': Http.response({
          'data': {'name': 'Fresh', 'type': 'http'},
        }),
      });

      // The router has to be MOUNTED for `currentLocation` to report anything:
      // `MagicRoute.to` asks GoRouter to navigate, and the location is read back
      // off the router's own state, which only exists under a widget tree.
      MagicRouter.reset();
      MagicRoute.page('/monitors', () => const SizedBox());
      MagicRoute.page('/monitors/:id', () => const SizedBox());
      addTearDown(MagicRouter.reset);
      await tester.pumpWidget(
        MaterialApp.router(routerConfig: MagicRouter.instance.routerConfig),
      );
      await tester.pump();

      final MonitorController controller = MonitorController.instance;

      await controller.create(<String, dynamic>{
        'name': 'Fresh',
        'url': 'https://fresh.test/health',
        'type': 'http',
        'method': 'get',
        'check_interval_sec': 180,
        'timeout_sec': 30,
        'regions': const <String>['eu-central'],
      });

      await tester.pumpAndSettle();

      expect(MagicRouter.instance.currentLocation, '/monitors');
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

    test('an edit that omits auth_config does not put it back on the wire', () async {
      // The form omitting the key is not the same as the request omitting it,
      // and that gap was a live defect: save() re-fetches the monitor through
      // Monitor.find(), which hydrates the REDACTED map the API publishes
      // (type + username, never a secret), and then PUTs the whole toArray().
      // So renaming a basic-auth monitor shipped {type: basic, username: svc}
      // with no password and 422'd on the backend's required_if rule, for an
      // edit the operator never made.
      //
      // Every other credential test in this branch reads what the form BUILT.
      // This one reads what left the client, which is the only level the bug
      // was ever visible at: delete the makeHidden line and the rest stay green.
      final FakeNetworkDriver fake = Http.fake({
        'monitors/api': Http.response({
          'data': {
            'id': 'api',
            'name': 'API',
            'url': 'https://api.uptizm.com',
            'type': 'http',
            'auth_config': {'type': 'basic', 'username': 'svc'},
          },
        }),
      });

      await MonitorController.instance.save('api', {'name': 'Renamed'});

      final put = fake.recorded.firstWhere(
        (entry) => entry.$1.method.toUpperCase() == 'PUT',
        orElse: () => throw StateError('no PUT was recorded'),
      );
      final Map<String, dynamic> body = put.$1.data as Map<String, dynamic>;

      expect(body['name'], equals('Renamed'));
      expect(
        body.containsKey('auth_config'),
        isFalse,
        reason: 'the stored credential must not ride along on an edit that '
            'never touched it',
      );
    });
  });

  // ---------------------------------------------------------------------------
  // analyze (S38, async since the 202 split): `POST /monitors/analyze` accepts
  // a run and a worker does the model calls, so the client OWNS a run: it holds
  // the id, polls `GET /monitors/analyze/{run}` as the source of truth, lets a
  // team-wide broadcast advance the state early, and treats a run that is GONE
  // as a failure instead of one that is still coming.
  //
  // Every test here drives the run through the real published surface
  // ([MonitorController.analyzeProgress] / [analyzeResult]) rather than through
  // the returned future alone, because that surface is what the create view
  // renders while the future is still pending.
  // ---------------------------------------------------------------------------

  group('analyze', () {
    const String url = 'https://api.example.com/health';

    /// The 202 body: the run's first snapshot, in the same shape the poll uses.
    ///
    /// `steps` is a json ARRAY here and an OBJECT once a step has reported,
    /// because that is what PHP encodes an empty array as; both shapes have to
    /// decode, so the fixtures keep the difference rather than smoothing it.
    Map<String, dynamic> acceptedBody({String runId = 'run-1'}) => {
      'data': {
        'run_id': runId,
        'status': 'queued',
        'step': 0,
        'steps': <dynamic>[],
        'probe': {
          'region': 'eu-central',
          'status_code': 200,
          'response_ms': 180,
        },
        'reason': null,
        'result': null,
      },
    };

    /// One `GET /monitors/analyze/{run}` body.
    Map<String, dynamic> runBody({
      String runId = 'run-1',
      required String status,
      int step = 0,
      Map<String, String> steps = const {},
      String? reason,
      Map<String, dynamic>? result,
    }) => {
      'data': {
        'run_id': runId,
        'status': status,
        'step': step,
        'steps': steps,
        'probe': {
          'region': 'eu-central',
          'status_code': 200,
          'response_ms': 180,
        },
        'reason': reason,
        'result': result,
      },
    };

    /// The completed run's `result`: the old synchronous body verbatim under
    /// `data`, plus the `meta` that now carries the metered allowance (the 202
    /// cannot, because the worker spends the trial long after it returned).
    Map<String, dynamic> resultBody() => {
      'data': {
        'url': url,
        'name': 'api.example.com',
        'recommended_interval_seconds': 60,
        'recommended_warn_threshold_ms': 300,
        'recommended_critical_threshold_ms': 1000,
        'recommended_regions': ['us-east', 'eu-west'],
        'rationale': 'Stable JSON API, 60s checks are sufficient.',
      },
      'meta': {'ai_analysis_trials_remaining': 2},
    };

    /// How many run reads went over the wire.
    int reads(FakeNetworkDriver fake) => fake.recorded
        .where(
          (entry) =>
              entry.$1.method == 'GET' &&
              entry.$1.url.contains('monitors/analyze/'),
        )
        .length;

    test('accepts the 202 and publishes a queued run without answering', () async {
      final FakeNetworkDriver fake = Http.fake({
        '*monitors/analyze': Http.response(acceptedBody(), 202),
      });
      final MonitorController controller = MonitorController.instance;
      addTearDown(controller.abandonAnalyzeRun);

      MonitorAnalysis? resolved;
      bool settled = false;
      controller.analyze(url).then((MonitorAnalysis? value) {
        resolved = value;
        settled = true;
      });
      await pumpEventQueue();

      // Contract point 1: the 202 is an ACCEPT, not an answer. The future the
      // caller holds must still be pending, or the create view would flip to
      // its review step with nothing to review.
      expect(settled, isFalse);
      expect(resolved, isNull);
      expect(controller.analyzeResult, isNull);
      // Contract point 2: the run is published the moment it is accepted, so a
      // view has something to render for the four minutes that follow.
      expect(controller.analyzeProgress, isNotNull);
      expect(controller.analyzeProgress!.runId, equals('run-1'));
      expect(controller.analyzeProgress!.status, equals(AnalyzeRunStatus.queued));
      expect(controller.analyzeProgress!.stepStates, isEmpty);
      // Terminal-only ticks: nothing has reported, so step 1 is in flight.
      expect(controller.analyzeProgress!.inFlightStep, equals(1));
      fake.assertSent(
        (request) =>
            request.method == 'POST' &&
            request.url.contains('monitors/analyze') &&
            (request.data as Map)['url'] == url,
      );
    });

    test('drives a run through queued -> analyzing -> completed', () async {
      // Resolve the entitlement first so its own one-shot load cannot land on
      // top of the allowance the completion payload republishes below.
      EntitlementController.instance;
      await pumpEventQueue();

      int reads = 0;
      Http.fake((request) {
        if (request.method == 'POST') {
          return Http.response(acceptedBody(), 202);
        }
        if (!request.url.contains('monitors/analyze/')) {
          return Http.response(<String, dynamic>{});
        }

        reads++;
        return switch (reads) {
          1 => Http.response(
            runBody(status: 'analyzing', step: 1, steps: {'1': 'done'}),
          ),
          2 => Http.response(
            runBody(
              status: 'analyzing',
              step: 2,
              steps: {'1': 'done', '2': 'skipped'},
            ),
          ),
          _ => Http.response(
            runBody(
              status: 'completed',
              step: 5,
              steps: {
                '1': 'done',
                '2': 'skipped',
                '3': 'done',
                '4': 'done',
                '5': 'done',
              },
              result: resultBody(),
            ),
          ),
        };
      });
      final MonitorController controller = MonitorController.instance;
      addTearDown(controller.abandonAnalyzeRun);

      MonitorAnalysis? resolved;
      bool settled = false;
      controller.analyze(url).then((MonitorAnalysis? value) {
        resolved = value;
        settled = true;
      });
      await pumpEventQueue();

      final AnalyzeRunProgress? first = await controller.fetchAnalyzeRun('run-1');
      expect(first, isNotNull);
      expect(first!.status, equals(AnalyzeRunStatus.analyzing));
      expect(first.stateOf(1), equals(AnalyzeStepState.done));
      expect(first.inFlightStep, equals(2));
      expect(controller.analyzeProgress, same(first));
      expect(settled, isFalse);

      // A SKIPPED step is terminal too, so the row after it is the one working:
      // this is the state a naive client leaves spinning on work that was never
      // going to run.
      final AnalyzeRunProgress? second = await controller.fetchAnalyzeRun(
        'run-1',
      );
      expect(second!.stateOf(2), equals(AnalyzeStepState.skipped));
      expect(second.inFlightStep, equals(3));
      expect(settled, isFalse);

      final AnalyzeRunProgress? third = await controller.fetchAnalyzeRun('run-1');
      expect(third!.status, equals(AnalyzeRunStatus.completed));
      expect(third.failure, isNull);
      expect(
        third.inFlightStep,
        isNull,
        reason: 'a finished run has no row in flight',
      );

      await pumpEventQueue();
      expect(settled, isTrue);
      expect(resolved, isNotNull);
      expect(resolved, same(controller.analyzeResult));
      expect(controller.analyzeResult!.name, equals('api.example.com'));
      expect(controller.analyzeResult!.recommendedIntervalSeconds, equals(60));
      expect(
        controller.analyzeResult!.recommendedRegions,
        equals(['us-east', 'eu-west']),
      );
      // Re-homed from the old synchronous `meta`: the worker spends the trial,
      // so the number can only ride the completion payload.
      expect(
        EntitlementController.instance.aiAnalysisTrialsRemaining,
        equals(2),
      );
    });

    testWidgets('the poll advances the run with no broadcast at all', (
      tester,
    ) async {
      // The poll is the SOURCE OF TRUTH, not a backstop: the progress event is
      // `ShouldRescue` (a push failure is swallowed by design), Reverb has no
      // replay, and a backgrounded tab hears nothing. So the whole lifecycle
      // has to complete without a single tick arriving.
      int reads = 0;
      Http.fake((request) {
        if (request.method == 'POST') {
          return Http.response(acceptedBody(), 202);
        }
        if (!request.url.contains('monitors/analyze/')) {
          return Http.response(<String, dynamic>{});
        }

        reads++;
        return Http.response(
          reads == 1
              ? runBody(status: 'analyzing', step: 1, steps: {'1': 'done'})
              : runBody(
                  status: 'completed',
                  step: 5,
                  steps: {'5': 'done'},
                  result: resultBody(),
                ),
        );
      });
      await tester.pumpWidget(const SizedBox());
      final MonitorController controller = MonitorController.instance;
      addTearDown(controller.abandonAnalyzeRun);

      MonitorAnalysis? resolved;
      controller.analyze(url).then((MonitorAnalysis? value) => resolved = value);
      await tester.pump();
      expect(controller.analyzeProgress!.status, equals(AnalyzeRunStatus.queued));
      expect(reads, equals(0), reason: 'the accept does not read the run');

      await tester.pump(const Duration(milliseconds: 2600));
      await tester.pump();
      expect(reads, equals(1));
      expect(controller.analyzeProgress!.stateOf(1), equals(AnalyzeStepState.done));

      await tester.pump(const Duration(milliseconds: 2600));
      await tester.pump();
      expect(controller.analyzeProgress!.status, equals(AnalyzeRunStatus.completed));
      expect(resolved, isNotNull);

      // And it stops on its own: a finished run is not read again.
      await tester.pump(const Duration(milliseconds: 5200));
      await tester.pump();
      expect(reads, equals(2));
    });

    testWidgets('a run that is gone fails rather than spinning forever', (
      tester,
    ) async {
      // THE ASSERTION THAT SEPARATES A FAILURE FROM AN ETERNAL SPINNER. The run
      // lives in a cache entry with a 900s TTL inside a Redis on `volatile-lru`
      // at a 512 MB ceiling, so a 404 means the entry was evicted or expired and
      // is never coming back. Reading it as "still running" leaves the operator
      // watching a spinner for a run nothing will ever report on again.
      int reads = 0;
      Http.fake((request) {
        if (request.method == 'POST') {
          return Http.response(acceptedBody(), 202);
        }
        if (!request.url.contains('monitors/analyze/')) {
          return Http.response(<String, dynamic>{});
        }

        reads++;
        return Http.response({'message': 'Not found.'}, 404);
      });
      await tester.pumpWidget(const SizedBox());
      final MonitorController controller = MonitorController.instance;
      addTearDown(controller.abandonAnalyzeRun);

      MonitorAnalysis? resolved;
      bool settled = false;
      controller.analyze(url).then((MonitorAnalysis? value) {
        resolved = value;
        settled = true;
      });
      await tester.pump();

      await tester.pump(const Duration(milliseconds: 2600));
      await tester.pump();

      expect(reads, equals(1));
      expect(controller.analyzeProgress!.status, equals(AnalyzeRunStatus.failed));
      expect(controller.analyzeProgress!.failure, equals(AnalyzeFailure.lost));
      expect(
        controller.analyzeProgress!.inFlightStep,
        isNull,
        reason: 'nothing is in flight on a run that no longer exists',
      );
      expect(settled, isTrue, reason: 'the caller must stop waiting');
      expect(resolved, isNull);

      // And the poll stops: a dead run is not re-read for four more minutes.
      await tester.pump(const Duration(milliseconds: 7800));
      await tester.pump();
      expect(reads, equals(1));
    });

    testWidgets('a run that never reaches a terminal state is not polled forever', (
      tester,
    ) async {
      // The other half of the same guarantee: a worker killed without its
      // `failed()` hook running (or a job that never reached a worker) leaves a
      // run that answers `analyzing` for as long as its TTL lasts.
      Http.fake((request) {
        if (request.method == 'POST') {
          return Http.response(acceptedBody(), 202);
        }
        if (!request.url.contains('monitors/analyze/')) {
          return Http.response(<String, dynamic>{});
        }

        return Http.response(
          runBody(status: 'analyzing', step: 1, steps: {'1': 'done'}),
        );
      });
      await tester.pumpWidget(const SizedBox());
      final MonitorController controller = MonitorController.instance;
      addTearDown(controller.abandonAnalyzeRun);

      bool settled = false;
      controller.analyze(url).then((MonitorAnalysis? value) => settled = true);
      await tester.pump();

      // 97 reads: the budget is 96, and the read past it is the one that gives
      // up. Driven on the fake clock, so this costs no wall time.
      for (int i = 0; i < 97; i++) {
        await tester.pump(const Duration(milliseconds: 2600));
        await tester.pump();
      }

      expect(controller.analyzeProgress!.status, equals(AnalyzeRunStatus.failed));
      expect(
        controller.analyzeProgress!.failure,
        equals(AnalyzeFailure.timedOut),
      );
      expect(settled, isTrue);
    });

    test('a broadcast tick advances the state early, without a read', () async {
      final FakeNetworkDriver fake = Http.fake({
        '*monitors/analyze': Http.response(acceptedBody(), 202),
      });
      final MonitorController controller = MonitorController.instance;
      addTearDown(controller.abandonAnalyzeRun);
      controller.analyze(url);
      await pumpEventQueue();

      controller.noteAnalyzeProgress(const <String, dynamic>{
        'run_id': 'run-1',
        'sequence': 1,
        'step': 1,
        'state': 'done',
        'status': 'analyzing',
      });

      expect(controller.analyzeProgress!.status, equals(AnalyzeRunStatus.analyzing));
      expect(controller.analyzeProgress!.stateOf(1), equals(AnalyzeStepState.done));
      expect(controller.analyzeProgress!.inFlightStep, equals(2));
      expect(
        reads(fake),
        equals(0),
        reason: 'a non-terminal tick carries everything the rows need',
      );
    });

    test('a tick for another run on the team channel is ignored', () async {
      // `private-teams.{id}` is team-wide, so a teammate's analyze reports to
      // this client too. Applying one would show this operator another
      // operator's progress on their own form.
      Http.fake({'*monitors/analyze': Http.response(acceptedBody(), 202)});
      final MonitorController controller = MonitorController.instance;
      addTearDown(controller.abandonAnalyzeRun);
      controller.analyze(url);
      await pumpEventQueue();

      controller.noteAnalyzeProgress(const <String, dynamic>{
        'run_id': 'someone-elses-run',
        'sequence': 7,
        'step': 4,
        'state': 'done',
        'status': 'analyzing',
      });

      expect(controller.analyzeProgress!.status, equals(AnalyzeRunStatus.queued));
      expect(controller.analyzeProgress!.step, equals(0));
      expect(controller.analyzeProgress!.stateOf(4), isNull);
      expect(controller.analyzeProgress!.inFlightStep, equals(1));
    });

    test('an out-of-order tick does not wind the rows backwards', () async {
      // Ten Horizon processes drain the queue the broadcast jobs land on and
      // Laravel guarantees ordering only for SQS FIFO, which is why the payload
      // carries a monotonic sequence at all.
      Http.fake({'*monitors/analyze': Http.response(acceptedBody(), 202)});
      final MonitorController controller = MonitorController.instance;
      addTearDown(controller.abandonAnalyzeRun);
      controller.analyze(url);
      await pumpEventQueue();

      controller.noteAnalyzeProgress(const <String, dynamic>{
        'run_id': 'run-1',
        'sequence': 3,
        'step': 3,
        'state': 'done',
        'status': 'analyzing',
      });
      controller.noteAnalyzeProgress(const <String, dynamic>{
        'run_id': 'run-1',
        'sequence': 2,
        'step': 2,
        'state': 'done',
        'status': 'analyzing',
      });

      expect(controller.analyzeProgress!.step, equals(3));
      expect(controller.analyzeProgress!.stateOf(2), isNull);
    });

    test('a terminal tick reads the result immediately', () async {
      // The result never travels in a broadcast (Reverb caps an inbound request
      // at 10,000 bytes), so a completed tick is a prompt to read, not an
      // answer: without the immediate read the operator waits out the poll
      // interval for a result that already exists.
      final FakeNetworkDriver fake = Http.fake((request) {
        if (request.method == 'POST') {
          return Http.response(acceptedBody(), 202);
        }
        if (!request.url.contains('monitors/analyze/')) {
          return Http.response(<String, dynamic>{});
        }

        return Http.response(
          runBody(
            status: 'completed',
            step: 5,
            steps: {'5': 'done'},
            result: resultBody(),
          ),
        );
      });
      final MonitorController controller = MonitorController.instance;
      addTearDown(controller.abandonAnalyzeRun);
      MonitorAnalysis? resolved;
      controller.analyze(url).then((MonitorAnalysis? value) => resolved = value);
      await pumpEventQueue();

      controller.noteAnalyzeProgress(const <String, dynamic>{
        'run_id': 'run-1',
        'sequence': 6,
        'step': 5,
        'state': 'done',
        'status': 'completed',
      });
      await pumpEventQueue();

      expect(reads(fake), equals(1));
      expect(controller.analyzeProgress!.status, equals(AnalyzeRunStatus.completed));
      expect(controller.analyzeResult, isNotNull);
      expect(resolved, same(controller.analyzeResult));
    });

    test('abandoning a run stops waiting for it', () async {
      Http.fake({'*monitors/analyze': Http.response(acceptedBody(), 202)});
      final MonitorController controller = MonitorController.instance;

      MonitorAnalysis? resolved;
      bool settled = false;
      controller.analyze(url).then((MonitorAnalysis? value) {
        resolved = value;
        settled = true;
      });
      await pumpEventQueue();
      expect(settled, isFalse);

      controller.abandonAnalyzeRun();
      await pumpEventQueue();

      expect(settled, isTrue, reason: 'an abandoned run must not hang a caller');
      expect(resolved, isNull);
      expect(controller.analyzeProgress, isNull);
      expect(controller.analyzeResult, isNull);
    });

    test('an identity change drops the previous team\'s run', () async {
      // A run is authorised on the team that started it, so the incoming
      // identity's poll could only ever read the masked 404 that means "gone",
      // and the view would show the new team a failure for work it never asked
      // for.
      Http.fake({'*monitors/analyze': Http.response(acceptedBody(), 202)});
      final MonitorController controller = MonitorController.instance;
      controller.analyze(url);
      await pumpEventQueue();
      expect(controller.analyzeProgress, isNotNull);

      await controller.resetForSession();

      expect(controller.analyzeProgress, isNull);
      expect(controller.analyzeResult, isNull);
    });

    test('a 409 refusal answers immediately and tracks no run', () async {
      // The team already has an analysis running. Nothing to watch, and the
      // backend's own message is what the form renders.
      Http.fake({
        '*monitors/analyze': Http.response({
          'message':
              'An analysis is already running for this team. Wait for it to '
              'finish before starting another.',
        }, 409),
      });
      final MonitorController controller = MonitorController.instance;

      final MonitorAnalysis? result = await controller.analyze(url);

      expect(result, isNull);
      expect(controller.analyzeProgress, isNull);
      expect(controller.lastAnalyzeWasGated, isFalse);
    });

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

  // ---------------------------------------------------------------------------
  // resetForSession: clear the previous identity's inventory, then refetch.
  // ---------------------------------------------------------------------------

  group('resetForSession', () {
    test('clears the cached inventory even when the refetch fails', () async {
      final MonitorController controller = MonitorController.instance;
      controller.seedForTest([
        Monitor.fromMap({'id': 'api', 'name': 'API', 'last_status': 'up'}),
      ]);
      expect(controller.monitors, isNotEmpty);

      // The new identity's refetch resolves nothing (`Monitor.all` absorbs the
      // failure and returns []). `reload` alone reads that as "keep the
      // last-known-good inventory", which would leave the PREVIOUS team's
      // monitors on screen; the reset must leave the list empty.
      Http.fake((r) => Http.response({'message': 'down'}, 500));
      var notified = 0;
      controller.addListener(() => notified++);

      await controller.resetForSession();

      expect(notified, greaterThan(0));
      expect(controller.monitors, isEmpty);
      expect(controller.monitorById('api'), isNull);
      expect(controller.lastAnalyzeWasGated, isFalse);
    });

    test('refetches the inventory of the new identity', () async {
      final MonitorController controller = MonitorController.instance;
      controller.seedForTest([
        Monitor.fromMap({'id': 'api', 'name': 'API', 'last_status': 'up'}),
      ]);

      Http.fake({
        'monitors': Http.response({
          'data': [
            {'id': 'other-team-web', 'name': 'Web', 'last_status': 'down'},
          ],
        }),
      });

      await controller.resetForSession();

      expect(
        controller.monitors.map((Monitor m) => m.id).toList(),
        equals(['other-team-web']),
      );
      expect(controller.monitorById('api'), isNull);
    });
  });
}
