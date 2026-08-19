import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/dashboard_controller.dart';
import 'package:uptizm/app/controllers/incident_controller.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/models/user.dart';
import 'package:uptizm/app/services/realtime_service.dart';

/// Dashboard spy: records how many times [reload] fired without touching the
/// network, so a test can assert the coalesced reload pass reached it.
class _SpyDashboardController extends DashboardController {
  int reloadCount = 0;
  final List<Map<String, dynamic>> notedReadings = <Map<String, dynamic>>[];

  @override
  Future<void> reload() async {
    reloadCount++;
  }

  @override
  void noteCheckRecorded(Map<String, dynamic> payload) {
    notedReadings.add(payload);
  }
}

/// Incident spy: mirrors [_SpyDashboardController] for the incident target.
class _SpyIncidentController extends IncidentController {
  int reloadCount = 0;

  @override
  Future<void> reload() async {
    reloadCount++;
  }
}

/// Monitor spy: mirrors [_SpyDashboardController] for the monitor target, and
/// additionally records every `analyze.progress` payload handed to
/// [MonitorController.noteAnalyzeProgress] so a test can assert the wiring
/// without exercising the real run-id/sequence filter that method owns (that
/// filter is step 8's, measured in `evidence/step-08-null-run-red.md`).
class _SpyMonitorController extends MonitorController {
  int reloadCount = 0;
  final List<Map<String, dynamic>> notedPayloads = <Map<String, dynamic>>[];
  final List<Map<String, dynamic>> notedReadings = <Map<String, dynamic>>[];

  @override
  Future<void> reload() async {
    reloadCount++;
  }

  @override
  void noteAnalyzeProgress(Map<String, dynamic> payload) {
    notedPayloads.add(payload);
  }

  @override
  void noteCheckRecorded(Map<String, dynamic> payload) {
    notedReadings.add(payload);
  }
}

/// Counts `connect()` calls, which the shipped fake driver does not.
///
/// `FakeBroadcastDriver.connect()` just sets a bool, so calling it twice is
/// invisible there, while on the REAL Reverb driver a second call opens a second
/// WebSocket and leaks the first. Counting is the only way to pin the guard.
class _CountingBroadcastDriver extends FakeBroadcastDriver {
  int connectCount = 0;

  @override
  Future<void> connect() {
    connectCount++;
    return super.connect();
  }
}

/// A [FakeBroadcastManager] handing out [_CountingBroadcastDriver].
///
/// Overriding `connection()` rather than replacing the parent's driver, which is
/// private and final. The inherited `assertConnected()` therefore speaks for the
/// parent's unused driver; assert on [spy] instead.
class _CountingBroadcastManager extends FakeBroadcastManager {
  final _CountingBroadcastDriver spy = _CountingBroadcastDriver();

  @override
  BroadcastDriver connection([String? name]) => spy;
}

void main() {
  late FakeBroadcastManager echo;

  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so the service's documented degradation path (a caught
    // reload failure logs rather than throwing) resolves the `log` service.
    Magic.singleton('log', () => LogManager());
    echo = Echo.fake();
  });

  tearDown(() {
    Echo.unfake();
    Auth.unfake();
    MagicApp.reset();
    Magic.flush();
  });

  /// An authenticated user whose current team carries [teamId].
  User userWithTeam(String teamId) => User.fromMap({
    'id': 'u1',
    'name': 'Alice',
    'current_team': {'id': teamId, 'name': 'Team $teamId'},
  });

  /// A synthetic broadcast event for driving the handlers directly (the fake
  /// channel's `listen` discards its callback, so events cannot be pushed
  /// through the wire in a test).
  BroadcastEvent event(String name) => BroadcastEvent(
    event: name,
    channel: 'private-teams.t1',
    data: const <String, dynamic>{},
    receivedAt: DateTime(2026),
  );

  /// A synthetic `analyze.progress` event carrying [data], for driving
  /// [RealtimeService.onAnalyzeProgress] directly (see [event] above for why
  /// the fake channel cannot push one through the wire).
  BroadcastEvent progressEvent(Map<String, dynamic> data) => BroadcastEvent(
    event: 'analyze.progress',
    channel: 'private-teams.t1',
    data: data,
    receivedAt: DateTime(2026),
  );

  /// Lets the zero-duration debounce timer fire before assertions run.
  Future<void> flushDebounce() =>
      Future<void>.delayed(const Duration(milliseconds: 20));

  test('connects and subscribes to the team channel on login', () async {
    Auth.fake(user: userWithTeam('t1'));
    final RealtimeService service = RealtimeService(debounce: Duration.zero);

    await service.syncWithAuthState();

    echo.assertConnected();
    echo.assertSubscribed('private-teams.t1');
    expect(service.isListeningToReconnect, isTrue);
  });

  test('a team switch re-subscribes without opening a second connection', () async {
    // THE LIVE DEFECT. Production devtools showed TWO 101 responses for one
    // Reverb URL, because magic's `BroadcastServiceProvider` connects at boot and
    // this service connected again once auth resolved, and the Reverb driver's
    // `connect()` assigns a fresh channel unconditionally: no already-connected
    // check, no close of the previous one. Every team switch reaches the same
    // line, so the leak was per switch as well as per boot.
    final _CountingBroadcastManager counting = _CountingBroadcastManager();
    Magic.app.setInstance('broadcasting', counting);

    Auth.fake(user: userWithTeam('t1'));
    final RealtimeService service = RealtimeService(debounce: Duration.zero);
    await service.syncWithAuthState();
    expect(counting.spy.connectCount, 1, reason: 'the first sync must connect');

    // A different team: the channel has to change, the socket must not.
    Auth.fake(user: userWithTeam('t2'));
    await service.syncWithAuthState();

    expect(counting.spy.connectCount, 1);
    expect(counting.spy.subscribedChannels, contains('private-teams.t2'));
  });

  test('a dropped connection is re-established on the next sync', () async {
    // The other half of the guard: skipping `connect()` is only correct while a
    // connection exists. A driver that reports disconnected must be reconnected,
    // or the guard would trade two sockets for none.
    final _CountingBroadcastManager counting = _CountingBroadcastManager();
    Magic.app.setInstance('broadcasting', counting);

    Auth.fake(user: userWithTeam('t1'));
    final RealtimeService service = RealtimeService(debounce: Duration.zero);
    await service.syncWithAuthState();

    await counting.spy.disconnect();
    Auth.fake(user: userWithTeam('t2'));
    await service.syncWithAuthState();

    expect(counting.spy.connectCount, 2);
    expect(counting.spy.isConnected, isTrue);
  });

  test('every event this service depends on is actually registered', () async {
    // THE GAP THIS CLOSES. `assertSubscribed` above proves a channel was opened
    // and nothing more: an app can hold a live channel and register no handler at
    // all. Until `magic`'s fake recorded them, `listen()` discarded both the event
    // name and the callback, so deleting a `..listen(...)` line from
    // `_reconcileSubscription` left this entire suite green. Two independent
    // reviews landed on exactly that, and the realtime half of the analyze
    // feature hangs off one of these four lines.
    Auth.fake(user: userWithTeam('t1'));

    await RealtimeService(debounce: Duration.zero).syncWithAuthState();

    echo.assertListening('private-teams.t1', 'incident.opened');
    echo.assertListening('private-teams.t1', 'incident.resolved');
    // The backend has broadcast `incident.escalated` since the warn->critical
    // escalation landed (`IncidentDispatcher::dispatch()`), and this client
    // listened for four names, none of them that one. Magic's Reverb channel
    // filters by EXACT event name, so every escalation arrived on the socket and
    // was dropped: a warn incident became critical server-side and the open
    // dashboard kept rendering it as warn until the operator navigated.
    echo.assertListening('private-teams.t1', 'incident.escalated');
    echo.assertListening('private-teams.t1', 'monitor.status');
    echo.assertListening('private-teams.t1', 'check.recorded');
    echo.assertListening('private-teams.t1', 'analyze.progress');
  });

  test('a dispatched analyze frame reaches the controller intact', () async {
    // And this is the half an assertion on registration still cannot give you:
    // that a real frame, delivered the way the driver delivers one, runs the
    // handler and arrives with its payload undamaged. Everything else in this
    // feature's tests calls `noteAnalyzeProgress` directly, which is honest about
    // its own subject and proves nothing about the socket path.
    Auth.fake(user: userWithTeam('t1'));
    final _SpyMonitorController monitor = _SpyMonitorController();
    Magic.put<MonitorController>(monitor);

    await RealtimeService(debounce: Duration.zero).syncWithAuthState();

    echo.dispatch('private-teams.t1', 'analyze.progress', <String, dynamic>{
      'run_id': 'run-1',
      'sequence': 3,
      'step': 2,
      'state': 'done',
      'status': 'analyzing',
    });

    expect(monitor.notedPayloads, hasLength(1));
    expect(monitor.notedPayloads.single['run_id'], 'run-1');
    expect(monitor.notedPayloads.single['step'], 2);
    expect(monitor.notedPayloads.single['state'], 'done');
  });

  test(
    'an incident event reloads the registered dashboard and incident controllers',
    () async {
      Auth.fake(user: userWithTeam('t1'));
      final _SpyDashboardController dashboard = _SpyDashboardController();
      final _SpyIncidentController incident = _SpyIncidentController();
      final _SpyMonitorController monitor = _SpyMonitorController();
      Magic.put<DashboardController>(dashboard);
      Magic.put<IncidentController>(incident);
      Magic.put<MonitorController>(monitor);
      final RealtimeService service = RealtimeService(debounce: Duration.zero);
      await service.syncWithAuthState();

      service.onIncidentEvent(event('incident.opened'));
      await flushDebounce();

      expect(dashboard.reloadCount, 1);
      expect(incident.reloadCount, 1);
      expect(monitor.reloadCount, 0);
    },
  );

  test(
    'a monitor event reloads the registered dashboard and monitor controllers',
    () async {
      Auth.fake(user: userWithTeam('t1'));
      final _SpyDashboardController dashboard = _SpyDashboardController();
      final _SpyIncidentController incident = _SpyIncidentController();
      final _SpyMonitorController monitor = _SpyMonitorController();
      Magic.put<DashboardController>(dashboard);
      Magic.put<IncidentController>(incident);
      Magic.put<MonitorController>(monitor);
      final RealtimeService service = RealtimeService(debounce: Duration.zero);
      await service.syncWithAuthState();

      service.onMonitorEvent(event('monitor.status'));
      await flushDebounce();

      expect(dashboard.reloadCount, 1);
      expect(monitor.reloadCount, 1);
      expect(incident.reloadCount, 0);
    },
  );

  test(
    'an analyze.progress event forwards the decoded payload to the registered '
    'MonitorController exactly once, and marks nothing dirty',
    () async {
      Auth.fake(user: userWithTeam('t1'));
      final _SpyDashboardController dashboard = _SpyDashboardController();
      final _SpyIncidentController incident = _SpyIncidentController();
      final _SpyMonitorController monitor = _SpyMonitorController();
      Magic.put<DashboardController>(dashboard);
      Magic.put<IncidentController>(incident);
      Magic.put<MonitorController>(monitor);
      final RealtimeService service = RealtimeService(debounce: Duration.zero);
      await service.syncWithAuthState();

      final Map<String, dynamic> payload = <String, dynamic>{
        'run_id': 'run-1',
        'sequence': 3,
        'step': 2,
        'state': 'done',
        'status': 'analyzing',
      };
      service.onAnalyzeProgress(progressEvent(payload));
      await flushDebounce();

      // The wiring forwards the decoded payload untouched, exactly once. The
      // run-id/sequence filter is `noteAnalyzeProgress`'s own (step 8), so
      // this test's subject is that the handler reaches it with the data
      // intact, not that filter.
      expect(monitor.notedPayloads, <Map<String, dynamic>>[payload]);
      // Unlike the other three handlers, a progress tick carries the progress
      // itself, so it never schedules the coalesced reload pass: no target,
      // including the monitor list, is refetched.
      expect(dashboard.reloadCount, 0);
      expect(incident.reloadCount, 0);
      expect(monitor.reloadCount, 0);
    },
  );

  test(
    'an analyze.progress event never instantiates an unregistered MonitorController',
    () async {
      Auth.fake(user: userWithTeam('t1'));
      final RealtimeService service = RealtimeService(debounce: Duration.zero);
      await service.syncWithAuthState();

      service.onAnalyzeProgress(
        progressEvent(const <String, dynamic>{'run_id': 'run-1'}),
      );
      await flushDebounce();

      expect(Magic.isRegistered<MonitorController>(), isFalse);
    },
  );

  test(
    'an escalated incident event reloads the dashboard and incident controllers',
    () async {
      Auth.fake(user: userWithTeam('t1'));
      final _SpyDashboardController dashboard = _SpyDashboardController();
      final _SpyIncidentController incident = _SpyIncidentController();
      final _SpyMonitorController monitor = _SpyMonitorController();
      Magic.put<DashboardController>(dashboard);
      Magic.put<IncidentController>(incident);
      Magic.put<MonitorController>(monitor);
      final RealtimeService service = RealtimeService(debounce: Duration.zero);
      await service.syncWithAuthState();

      // Delivered through the wire rather than by calling the handler, because
      // the defect was never in the handler: `onIncidentEvent` always did the
      // right thing with an escalation, nothing ever handed it one.
      echo.dispatch('private-teams.t1', 'incident.escalated', const {});
      await flushDebounce();

      expect(dashboard.reloadCount, 1);
      expect(incident.reloadCount, 1);
      expect(monitor.reloadCount, 0);
    },
  );

  test(
    'a check.recorded frame patches the registered controllers and refetches nothing',
    () async {
      Auth.fake(user: userWithTeam('t1'));
      final _SpyDashboardController dashboard = _SpyDashboardController();
      final _SpyIncidentController incident = _SpyIncidentController();
      final _SpyMonitorController monitor = _SpyMonitorController();
      Magic.put<DashboardController>(dashboard);
      Magic.put<IncidentController>(incident);
      Magic.put<MonitorController>(monitor);
      final RealtimeService service = RealtimeService(debounce: Duration.zero);
      await service.syncWithAuthState();

      const Map<String, dynamic> reading = <String, dynamic>{
        'monitor_id': 'm1',
        'region': 'eu-west',
        'result': 'up',
        'response_ms': 143,
        'checked_at': '2026-08-19T09:30:00+00:00',
        'last_status': 'up',
        'last_checked_at': '2026-08-19T09:30:00+00:00',
        'last_response_ms': 143,
      };
      echo.dispatch('private-teams.t1', 'check.recorded', reading);
      await flushDebounce();

      // The payload IS the update, so it is applied rather than used as a dirty
      // flag. A reload here would turn a 60s check cadence into four HTTP
      // requests per monitor per cycle, which is the polling this replaces.
      expect(monitor.notedReadings, <Map<String, dynamic>>[reading]);
      expect(dashboard.notedReadings, <Map<String, dynamic>>[reading]);
      expect(dashboard.reloadCount, 0);
      expect(incident.reloadCount, 0);
      expect(monitor.reloadCount, 0);
    },
  );

  test(
    'a check.recorded event never instantiates an unregistered controller',
    () async {
      Auth.fake(user: userWithTeam('t1'));
      final RealtimeService service = RealtimeService(debounce: Duration.zero);
      await service.syncWithAuthState();

      echo.dispatch('private-teams.t1', 'check.recorded', const {
        'monitor_id': 'm1',
      });
      await flushDebounce();

      expect(Magic.isRegistered<DashboardController>(), isFalse);
      expect(Magic.isRegistered<MonitorController>(), isFalse);
    },
  );

  test('a burst of events coalesces into a single debounced reload pass', () async {
    Auth.fake(user: userWithTeam('t1'));
    final _SpyDashboardController dashboard = _SpyDashboardController();
    Magic.put<DashboardController>(dashboard);
    final RealtimeService service = RealtimeService(debounce: Duration.zero);
    await service.syncWithAuthState();

    service.onIncidentEvent(event('incident.opened'));
    service.onIncidentEvent(event('incident.resolved'));
    service.onMonitorEvent(event('monitor.status'));
    await flushDebounce();

    expect(dashboard.reloadCount, 1);
  });

  test('a background event never instantiates an unregistered controller', () async {
    Auth.fake(user: userWithTeam('t1'));
    final RealtimeService service = RealtimeService(debounce: Duration.zero);
    await service.syncWithAuthState();

    service.onIncidentEvent(event('incident.opened'));
    await flushDebounce();

    expect(Magic.isRegistered<DashboardController>(), isFalse);
    expect(Magic.isRegistered<IncidentController>(), isFalse);
    expect(Magic.isRegistered<MonitorController>(), isFalse);
  });

  test('a team change re-subscribes to the new channel and leaves the old', () async {
    Auth.fake(user: userWithTeam('t1'));
    final RealtimeService service = RealtimeService(debounce: Duration.zero);
    await service.syncWithAuthState();
    echo.assertSubscribed('private-teams.t1');

    Auth.fake(user: userWithTeam('t2'));
    await service.syncWithAuthState();

    echo.assertSubscribed('private-teams.t2');
    echo.assertNotSubscribed('private-teams.t1');
  });

  test('overlapping syncs serialize and settle on the latest team', () async {
    Auth.fake(user: userWithTeam('t1'));
    final RealtimeService service = RealtimeService(debounce: Duration.zero);

    // Start the first sync (team t1) without awaiting; it suspends at
    // `await Echo.connect()` with the subscription marker cleared.
    final Future<void> first = service.syncWithAuthState();
    // A team switch arrives before the first sync completes. Without the
    // in-flight latch this would run concurrently and leave the app subscribed
    // to BOTH channels; the latch defers it and re-runs once afterwards.
    Auth.fake(user: userWithTeam('t2'));
    final Future<void> second = service.syncWithAuthState();

    await Future.wait(<Future<void>>[first, second]);

    // The serialized sync settles on the latest team with exactly one active
    // channel: the interim t1 subscription was left, never double-subscribed.
    expect(service.subscribedTeamId, 't2');
    echo.assertSubscribed('private-teams.t2');
    echo.assertNotSubscribed('private-teams.t1');
  });

  test('syncWithAuthState is idempotent for the same team', () async {
    Auth.fake(user: userWithTeam('t1'));
    final RealtimeService service = RealtimeService(debounce: Duration.zero);

    await service.syncWithAuthState();
    await service.syncWithAuthState();

    echo.assertSubscribed('private-teams.t1');
    expect(
      echo.driver.subscribedChannels
          .where((String c) => c == 'private-teams.t1')
          .length,
      1,
    );
  });

  test('refetchAll reloads every registered controller', () async {
    Auth.fake(user: userWithTeam('t1'));
    final _SpyDashboardController dashboard = _SpyDashboardController();
    final _SpyIncidentController incident = _SpyIncidentController();
    final _SpyMonitorController monitor = _SpyMonitorController();
    Magic.put<DashboardController>(dashboard);
    Magic.put<IncidentController>(incident);
    Magic.put<MonitorController>(monitor);
    final RealtimeService service = RealtimeService(debounce: Duration.zero);
    await service.syncWithAuthState();

    service.refetchAll();
    await flushDebounce();

    expect(dashboard.reloadCount, 1);
    expect(incident.reloadCount, 1);
    expect(monitor.reloadCount, 1);
  });

  test('logout leaves the channel, disconnects, and stops the reconnect listener', () async {
    Auth.fake(user: userWithTeam('t1'));
    final RealtimeService service = RealtimeService(debounce: Duration.zero);
    await service.syncWithAuthState();
    echo.assertConnected();

    Auth.fake();
    await service.syncWithAuthState();

    echo.assertDisconnected();
    echo.assertNotSubscribed('private-teams.t1');
    expect(service.isListeningToReconnect, isFalse);
  });

  test('syncWithAuthState while logged out and never subscribed is a safe no-op', () async {
    Auth.fake();
    final RealtimeService service = RealtimeService(debounce: Duration.zero);

    await service.syncWithAuthState();

    echo.assertDisconnected();
    expect(service.isListeningToReconnect, isFalse);
  });
}
