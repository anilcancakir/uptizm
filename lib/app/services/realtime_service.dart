import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';

import '../controllers/dashboard_controller.dart';
import '../controllers/incident_controller.dart';
import '../controllers/monitor_controller.dart';
import '../models/user.dart';

/// The monitoring controllers a realtime event can refresh.
///
/// Each value maps to exactly one registered `MagicController` whose public
/// `reload()` the service invokes when the target is marked dirty.
enum _ReloadTarget {
  /// The dashboard aggregate surface ([DashboardController]).
  dashboard,

  /// The incident list + detail surface ([IncidentController]).
  incident,

  /// The monitor inventory surface ([MonitorController]).
  monitor,
}

/// Subscribes the app to its team's private Reverb channel and refreshes the
/// mounted monitoring controllers whenever the backend broadcasts a change.
///
/// The backend is the sole author of monitoring truth: it broadcasts
/// `incident.opened`/`incident.resolved`/`monitor.status` on the private
/// `teams.{teamId}` channel. This service is the thin client half. It mirrors
/// `AppServiceProvider._syncPollingWithAuthState`'s idempotent auth lifecycle
/// (safe to call [syncWithAuthState] on every `Auth.stateNotifier` bump) and
/// adds team-awareness: it re-reads the current team on each sync and moves the
/// subscription when the team changes.
///
/// On any event it schedules a single coalesced (debounced) reload pass over
/// the monitoring controllers, and it reloads a controller ONLY when it is
/// already registered in the IoC container, so a background event never
/// instantiates a screen the user is not viewing. A reload failure is caught
/// and logged as a documented degradation, never thrown out of the listener.
///
/// Reverb has no replay, so any change that fired while the socket was down is
/// lost from the stream. The service therefore also refetches every registered
/// controller on each (re)connect (via `Echo.onReconnect` and the
/// `connectionState` transition to `connected`), closing that gap the same way
/// `NotificationPoller` refetches on resume.
class RealtimeService {
  /// Creates a realtime service.
  ///
  /// [debounce] is the coalescing window for the reload pass; the default of
  /// 400ms batches a burst of events (and the reconnect refetch) into one
  /// reload. Tests pass a shorter window (e.g. `Duration.zero`) to flush the
  /// pass deterministically.
  RealtimeService({this.debounce = const Duration(milliseconds: 400)});

  /// The coalescing window for the reload pass.
  final Duration debounce;

  /// The team id currently subscribed to, or `null` when not subscribed.
  ///
  /// Doubles as the subscribed-team marker: `null` means "no live
  /// subscription", so a logged-out sync short-circuits and a re-sync for the
  /// same team is a no-op.
  String? _subscribedTeamId;

  /// The private channel currently subscribed to, retained so the previous
  /// channel can be left by its fully-qualified name on a team change.
  BroadcastChannel? _channel;

  /// The pending reload targets, drained by the debounced [_flushReload].
  final Set<_ReloadTarget> _pending = <_ReloadTarget>{};

  /// The active debounce timer, or `null` when no reload is scheduled.
  Timer? _reloadTimer;

  /// Whether a [syncWithAuthState] reconcile is currently running. The latch
  /// serializes overlapping syncs so the subscription markers are never mutated
  /// across two interleaved await points.
  bool _syncing = false;

  /// Set when a sync is requested while another is in flight, so the running
  /// sync re-runs once more afterwards and picks up the latest team.
  bool _resyncRequested = false;

  /// The `Echo.onReconnect` subscription, or `null` when not subscribed.
  StreamSubscription<void>? _reconnectSubscription;

  /// The `Echo.connectionState` subscription, or `null` when not subscribed.
  StreamSubscription<BroadcastConnectionState>? _connectionSubscription;

  /// Whether the connect-time refetch listeners are currently wired.
  ///
  /// Exposed for tests: the fake broadcast manager cannot emit a synthetic
  /// reconnect (its streams are empty), so a test asserts the subscription is
  /// wired here and drives the refetch dispatch directly via [refetchAll].
  @visibleForTesting
  bool get isListeningToReconnect => _reconnectSubscription != null;

  /// The team id currently subscribed to, or `null` when not subscribed.
  /// Exposed so a test can assert the serialized sync settled on the latest
  /// team after overlapping calls.
  @visibleForTesting
  String? get subscribedTeamId => _subscribedTeamId;

  // ---------------------------------------------------------------------------
  // Auth-lifecycle sync
  // ---------------------------------------------------------------------------

  /// Reconciles the channel subscription with the current auth state.
  ///
  /// Idempotent and safe to call on every `Auth.stateNotifier` bump:
  ///
  /// 1. Logged out: tears down any live subscription + connection, then returns.
  /// 2. Logged in with no current team: nothing to subscribe to, returns.
  /// 3. Logged in on the already-subscribed team: no-op.
  /// 4. Logged in on a new team: leaves the previous channel, connects, and
  ///    subscribes to the new team's private channel.
  Future<void> syncWithAuthState() async {
    // Serialize overlapping syncs. `Auth.stateNotifier` fires synchronously and
    // the provider dispatches each sync unawaited, so a login-then-quick-team
    // -switch can start a second sync before the first's `Echo.connect()`
    // resolves. Running them concurrently would mutate the shared subscription
    // markers across await points and could leave the app subscribed to two
    // channels at once. The latch runs one reconcile at a time and re-runs once
    // if a sync arrived mid-flight, so the final state reflects the latest team.
    if (_syncing) {
      _resyncRequested = true;
      return;
    }
    _syncing = true;
    try {
      do {
        _resyncRequested = false;
        await _reconcileSubscription();
      } while (_resyncRequested);
    } finally {
      _syncing = false;
    }
  }

  /// The single-flight body of [syncWithAuthState]; assumes the [syncWithAuthState]
  /// latch serializes it, so it never runs concurrently with itself.
  Future<void> _reconcileSubscription() async {
    // 1. Logged out: drop any live subscription and connection.
    if (!Auth.check()) {
      await _teardown();
      return;
    }

    // 2. No current team means there is no channel to subscribe to.
    final String? teamId = User.current.currentTeam?.id;
    if (teamId == null || teamId.isEmpty) {
      return;
    }

    // 3. Already on this team's channel: nothing to do.
    if (_subscribedTeamId == teamId) {
      return;
    }

    // 4. Team changed (or first subscribe): leave the old channel and clear the
    //    marker BEFORE the first await, so a failed `Echo.connect()` leaves a
    //    clean unsubscribed state the next sync retries, never a stale marker
    //    pointing at a team the app is not actually subscribed to.
    _leaveCurrentChannel();
    _subscribedTeamId = null;
    await Echo.connect();
    final BroadcastChannel channel = Echo.private('teams.$teamId');
    channel
      ..listen('incident.opened', onIncidentEvent)
      ..listen('incident.resolved', onIncidentEvent)
      ..listen('monitor.status', onMonitorEvent);
    _channel = channel;
    _subscribedTeamId = teamId;

    // 5. Wire the connect-time refetch so a reconnect closes the replay gap.
    _listenForReconnect();
  }

  /// Tears down the live subscription, connection, and reconnect listeners.
  ///
  /// A no-op when never subscribed, keeping a logged-out sync idempotent.
  Future<void> _teardown() async {
    if (_subscribedTeamId == null) {
      return;
    }

    _leaveCurrentChannel();
    _cancelReconnectListeners();
    _cancelPendingReload();
    _subscribedTeamId = null;
    await Echo.disconnect();
  }

  /// Leaves the current private channel by its fully-qualified name.
  void _leaveCurrentChannel() {
    final BroadcastChannel? channel = _channel;
    if (channel == null) {
      return;
    }
    Echo.leave(channel.name);
    _channel = null;
  }

  // ---------------------------------------------------------------------------
  // Reconnect refetch
  // ---------------------------------------------------------------------------

  /// Subscribes to the reconnect signals, refetching all registered
  /// controllers on each (re)connect.
  ///
  /// The magic `ReverbBroadcastDriver` re-subscribes to channels automatically
  /// on reconnect, so the service only needs to trigger the refetch, not
  /// re-`listen`.
  void _listenForReconnect() {
    _cancelReconnectListeners();
    _reconnectSubscription = Echo.onReconnect.listen((_) => refetchAll());
    _connectionSubscription = Echo.connectionState
        .where(
          (BroadcastConnectionState state) =>
              state == BroadcastConnectionState.connected,
        )
        .listen((_) => refetchAll());
  }

  /// Cancels the reconnect + connection-state subscriptions, if any.
  void _cancelReconnectListeners() {
    _reconnectSubscription?.cancel();
    _reconnectSubscription = null;
    _connectionSubscription?.cancel();
    _connectionSubscription = null;
  }

  // ---------------------------------------------------------------------------
  // Event handlers (exposed for tests: the fake channel discards its callback)
  // ---------------------------------------------------------------------------

  /// Handles an `incident.opened`/`incident.resolved` event by scheduling a
  /// coalesced reload of the dashboard and incident controllers.
  @visibleForTesting
  void onIncidentEvent(BroadcastEvent event) {
    _markDirty(const <_ReloadTarget>{
      _ReloadTarget.dashboard,
      _ReloadTarget.incident,
    });
  }

  /// Handles a `monitor.status` event by scheduling a coalesced reload of the
  /// dashboard and monitor controllers.
  @visibleForTesting
  void onMonitorEvent(BroadcastEvent event) {
    _markDirty(const <_ReloadTarget>{
      _ReloadTarget.dashboard,
      _ReloadTarget.monitor,
    });
  }

  /// Schedules a coalesced reload of every monitoring controller.
  ///
  /// Invoked on each (re)connect to close the Reverb replay gap, and exposed
  /// so a test can drive the refetch dispatch directly.
  @visibleForTesting
  void refetchAll() {
    _markDirty(const <_ReloadTarget>{
      _ReloadTarget.dashboard,
      _ReloadTarget.incident,
      _ReloadTarget.monitor,
    });
  }

  // ---------------------------------------------------------------------------
  // Coalesced reload pass
  // ---------------------------------------------------------------------------

  /// Adds [targets] to the pending set and (re)arms the debounce timer.
  void _markDirty(Set<_ReloadTarget> targets) {
    _pending.addAll(targets);
    _reloadTimer?.cancel();
    _reloadTimer = Timer(debounce, _flushReload);
  }

  /// Drains the pending set, reloading each registered target once.
  void _flushReload() {
    final Set<_ReloadTarget> targets = Set<_ReloadTarget>.from(_pending);
    _pending.clear();
    for (final _ReloadTarget target in targets) {
      _reloadTarget(target);
    }
  }

  /// Cancels the pending reload pass, if any.
  void _cancelPendingReload() {
    _reloadTimer?.cancel();
    _reloadTimer = null;
    _pending.clear();
  }

  /// Reloads [target] iff its controller is already registered.
  ///
  /// Gating on `Magic.isRegistered` (which never instantiates) guarantees a
  /// background event never mounts a controller the user is not viewing.
  void _reloadTarget(_ReloadTarget target) {
    switch (target) {
      case _ReloadTarget.dashboard:
        if (Magic.isRegistered<DashboardController>()) {
          _safeReload('dashboard', Magic.find<DashboardController>().reload);
        }
      case _ReloadTarget.incident:
        if (Magic.isRegistered<IncidentController>()) {
          _safeReload('incident', Magic.find<IncidentController>().reload);
        }
      case _ReloadTarget.monitor:
        if (Magic.isRegistered<MonitorController>()) {
          _safeReload('monitor', Magic.find<MonitorController>().reload);
        }
    }
  }

  /// Runs [reload] guarded: a synchronous throw or an async rejection is
  /// caught and logged as a documented degradation, never rethrown into the
  /// listener that scheduled the pass.
  void _safeReload(String label, Future<void> Function() reload) {
    try {
      unawaited(
        reload().catchError((Object error) {
          Log.error('[RealtimeService] $label reload failed: $error');
        }),
      );
    } catch (error) {
      Log.error('[RealtimeService] $label reload failed: $error');
    }
  }

  // ---------------------------------------------------------------------------
  // Disposal
  // ---------------------------------------------------------------------------

  /// Releases the timer and stream subscriptions this service holds.
  ///
  /// The service is bound for the app's lifetime, so disposal matters only for
  /// tests and hot-restart hygiene; it does not touch the connection.
  void dispose() {
    _cancelPendingReload();
    _cancelReconnectListeners();
  }
}
