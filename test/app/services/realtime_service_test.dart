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

  @override
  Future<void> reload() async {
    reloadCount++;
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

/// Monitor spy: mirrors [_SpyDashboardController] for the monitor target.
class _SpyMonitorController extends MonitorController {
  int reloadCount = 0;

  @override
  Future<void> reload() async {
    reloadCount++;
  }
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
