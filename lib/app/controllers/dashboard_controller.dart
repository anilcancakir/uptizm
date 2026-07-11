import 'package:magic/magic.dart';

import '../mocks/incidents.dart';
import '../mocks/monitors.dart';

/// Controller backing [DashboardView].
///
/// Sources every dashboard surface from the backend `api/v1` aggregate
/// endpoints instead of the design-lab fixtures: `GET /dashboard/stats` for
/// the KPI row, `GET /dashboard/active-incidents` for the active-incidents
/// panel, `GET /dashboard/monitors-snapshot` for the monitor snapshot, and
/// `GET /dashboard/ai-inbox` for the AI inbox (the backend always returns an
/// empty list there; AI triage is deferred, so [aiSuggestions] naturally
/// renders the existing empty state).
///
/// Data-only: the dashboard has no mutable state and no mock actions, so this
/// controller only exposes reads over the fetched data, refreshed once via
/// [onInit] and re-fetchable via the non-destructive [reload].
class DashboardController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static DashboardController get instance =>
      Magic.findOrPut(DashboardController.new);

  /// Cached `GET /dashboard/stats` counters. Empty (all-zero) defaults until
  /// the first successful fetch resolves.
  int _monitorsUp = 0;
  int _monitorsDown = 0;
  int _monitorsDegraded = 0;
  int _monitorsPaused = 0;
  int? _avgResponseMs;
  int _openIncidents = 0;

  /// Cached `GET /dashboard/active-incidents` result.
  List<IncidentSummary> _activeIncidents = [];

  /// Cached `GET /dashboard/monitors-snapshot` result.
  List<MonitorSummary> _monitorsSnapshot = [];

  /// Cached `GET /dashboard/ai-inbox` result (always empty today; the
  /// backend reserves the contract ahead of AI triage).
  List<IncidentSummary> _aiInbox = [];

  /// Active incidents: everything the backend still considers open.
  List<IncidentSummary> get activeIncidents => _activeIncidents;

  /// The team's monitors with their last-known health status.
  List<MonitorSummary> get monitorsSnapshot => _monitorsSnapshot;

  /// AI inbox entries. Always empty today (AI triage is deferred
  /// server-side), which drives the existing empty-state rendering.
  List<IncidentSummary> get aiSuggestions => _aiInbox;

  /// Count of monitors currently up.
  int get upCount => _monitorsUp;

  /// Count of monitors currently down.
  int get downCount => _monitorsDown;

  /// Total number of monitors (sum of every last-known status bucket).
  int get monitorCount =>
      _monitorsUp + _monitorsDown + _monitorsDegraded + _monitorsPaused;

  /// Count of active incidents currently owned by the AI.
  ///
  /// AI ownership is not yet exposed by `dashboard/stats` or
  /// `IncidentResource`, so this stays derived client-side from
  /// [activeIncidents] until the backend surfaces it.
  int get aiActiveCount => activeIncidents.where((i) => i.aiOwned).length;

  /// Average response time (ms) across monitors that report timing, per
  /// `dashboard/stats`. `0` until the first successful fetch, matching the
  /// prior fixture-derived default.
  int get avgResponseMs => _avgResponseMs ?? 0;

  /// Bootstraps every dashboard surface the first time this controller backs
  /// a view.
  @override
  void onInit() {
    super.onInit();
    reload();
  }

  /// Non-destructive refresh: fetches the four dashboard aggregate endpoints
  /// in parallel and republishes each on success, independently of the
  /// others. Any endpoint that fails (network error, non-2xx) leaves its
  /// previously loaded data untouched so the dashboard never flickers into
  /// an error or empty state on a partial failure.
  Future<void> reload() async {
    await Future.wait([
      _reloadStats(),
      _reloadActiveIncidents(),
      _reloadMonitorsSnapshot(),
      _reloadAiInbox(),
    ]);
  }

  /// Refreshes the `GET /dashboard/stats` counters.
  Future<void> _reloadStats() async {
    try {
      final response = await Http.get('/dashboard/stats');
      if (!response.successful) return;

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (data is! Map<String, dynamic>) return;

      _monitorsUp = (data['monitors_up'] as num?)?.toInt() ?? 0;
      _monitorsDown = (data['monitors_down'] as num?)?.toInt() ?? 0;
      _monitorsDegraded = (data['monitors_degraded'] as num?)?.toInt() ?? 0;
      _monitorsPaused = (data['monitors_paused'] as num?)?.toInt() ?? 0;
      _avgResponseMs = (data['avg_response_ms'] as num?)?.toInt();
      _openIncidents = (data['open_incidents'] as num?)?.toInt() ?? 0;
      refreshUI();
    } catch (_) {
      // Deliberate degradation: a transport failure (including an unregistered
      // `network` service in a bare test host) leaves the last-known-good
      // counters (all-zero before the first fetch) untouched, so `onInit`
      // never throws and the KPI row renders its zeroed state.
    }
  }

  /// Refreshes the `GET /dashboard/active-incidents` list.
  Future<void> _reloadActiveIncidents() async {
    try {
      final response = await Http.get('/dashboard/active-incidents');
      if (!response.successful) return;

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return;

      _activeIncidents = raw
          .whereType<Map<String, dynamic>>()
          .map(IncidentSummary.fromMap)
          .toList();
      refreshUI();
    } catch (_) {
      // Deliberate degradation: keep the last-known-good active incidents
      // (empty before the first fetch) so a transport failure never throws
      // and the panel renders its empty state.
    }
  }

  /// Refreshes the `GET /dashboard/monitors-snapshot` list.
  Future<void> _reloadMonitorsSnapshot() async {
    try {
      final response = await Http.get('/dashboard/monitors-snapshot');
      if (!response.successful) return;

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return;

      _monitorsSnapshot = raw
          .whereType<Map<String, dynamic>>()
          .map(MonitorSummary.fromMap)
          .toList();
      refreshUI();
    } catch (_) {
      // Deliberate degradation: keep the last-known-good snapshot (empty
      // before the first fetch) so a transport failure never throws and the
      // monitor snippet renders its empty state.
    }
  }

  /// Refreshes the `GET /dashboard/ai-inbox` list. The backend always
  /// returns an empty list today (AI triage is deferred); this still hits
  /// the real endpoint rather than hardcoding the empty result, so the inbox
  /// picks up entries the moment the backend starts populating them.
  Future<void> _reloadAiInbox() async {
    try {
      final response = await Http.get('/dashboard/ai-inbox');
      if (!response.successful) return;

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return;

      _aiInbox = raw
          .whereType<Map<String, dynamic>>()
          .map(IncidentSummary.fromMap)
          .toList();
      refreshUI();
    } catch (_) {
      // Deliberate degradation: keep the last-known-good inbox (empty before
      // the first fetch) so a transport failure never throws and the AI inbox
      // renders its empty state.
    }
  }

  // Exposed for the KPI row's open-incidents count, sourced from
  // `dashboard/stats` (`open_incidents`) rather than re-deriving it from
  // `activeIncidents.length`, since the two can diverge (stats counts the
  // whole team; `active-incidents` is capped at 20).
  /// Count of open incidents per `dashboard/stats`.
  int get openIncidentsCount => _openIncidents;
}
