import 'package:magic/magic.dart';

import '../models/incident.dart';
import '../models/monitor.dart';
import '../mocks/status.dart';

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
  List<Incident> _activeIncidents = [];

  /// Cached `GET /dashboard/monitors-snapshot` result.
  List<Monitor> _monitorsSnapshot = [];

  /// Cached `GET /dashboard/ai-inbox` result (always empty today; the
  /// backend reserves the contract ahead of AI triage).
  List<Incident> _aiInbox = [];

  /// Active incidents: everything the backend still considers open.
  List<Incident> get activeIncidents => _activeIncidents;

  /// The team's monitors with their last-known health status.
  List<Monitor> get monitorsSnapshot => _monitorsSnapshot;

  /// AI inbox entries. Always empty today (AI triage is deferred
  /// server-side), which drives the existing empty-state rendering.
  List<Incident> get aiSuggestions => _aiInbox;

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
          .map(Incident.fromMap)
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
          .map(Monitor.fromMap)
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
          .map(Incident.fromMap)
          .toList();
      refreshUI();
    } catch (_) {
      // Deliberate degradation: keep the last-known-good inbox (empty before
      // the first fetch) so a transport failure never throws and the AI inbox
      // renders its empty state.
    }
  }

  // ---------------------------------------------------------------------------
  // Business actions
  // ---------------------------------------------------------------------------

  /// Accepts the AI-suggested incident [suggestionId] via
  /// `POST /ai-suggestions/:id/accept`, then navigates to the created
  /// incident's detail page. No-op when the request fails (surfaces an error
  /// log and leaves the inbox untouched instead of throwing out of the tap
  /// handler).
  Future<void> acceptSuggestion(String suggestionId) async {
    try {
      final response = await Http.post(
        '/ai-suggestions/$suggestionId/accept',
      );
      if (!response.successful) {
        Log.error(
          '[DashboardController.acceptSuggestion] $suggestionId: '
          '${response.errorMessage}',
        );
        return;
      }

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      final String? incidentId = data is Map<String, dynamic>
          ? data['incident_id']?.toString()
          : null;

      await _reloadAiInbox();
      if (incidentId != null) {
        MagicRoute.to('/incidents/$incidentId');
      }
    } catch (error) {
      Log.error(
        '[DashboardController.acceptSuggestion] $suggestionId failed: $error',
      );
    }
  }

  /// Dismisses the AI-suggested incident [suggestionId] via
  /// `POST /ai-suggestions/:id/dismiss`, then refreshes the inbox so the
  /// dismissed suggestion drops out of [aiSuggestions]. No-op when the
  /// request fails.
  Future<void> dismissSuggestion(String suggestionId) async {
    try {
      final response = await Http.post(
        '/ai-suggestions/$suggestionId/dismiss',
      );
      if (!response.successful) {
        Log.error(
          '[DashboardController.dismissSuggestion] $suggestionId: '
          '${response.errorMessage}',
        );
        return;
      }

      await _reloadAiInbox();
    } catch (error) {
      Log.error(
        '[DashboardController.dismissSuggestion] $suggestionId failed: $error',
      );
    }
  }

  // Exposed for the KPI row's open-incidents count, sourced from
  // `dashboard/stats` (`open_incidents`) rather than re-deriving it from
  // `activeIncidents.length`, since the two can diverge (stats counts the
  // whole team; `active-incidents` is capped at 20).
  /// Count of open incidents per `dashboard/stats`.
  int get openIncidentsCount => _openIncidents;

  /// A factual, live-derived one-line fleet summary for the dashboard banner.
  ///
  /// Replaces the previous static marketing copy (which described a fictional
  /// fleet and contradicted the real monitors) with a grounded statement
  /// composed only from the fetched dashboard data: monitor health plus the
  /// open-incident count. This honors the honest-AI boundary: it never asserts
  /// a state the backend has not reported.
  String get fleetSummary {
    final int total = monitorCount;
    if (total == 0) {
      return 'No monitors yet. Add your first monitor to start tracking uptime.';
    }

    final List<String> down = _monitorsSnapshot
        .where((Monitor m) => m.status == StatusKey.down)
        .map((Monitor m) => m.name ?? '')
        .toList();
    final List<String> degraded = _monitorsSnapshot
        .where((Monitor m) => m.status == StatusKey.degraded)
        .map((Monitor m) => m.name ?? '')
        .toList();

    if (down.isEmpty && degraded.isEmpty && _openIncidents == 0) {
      return 'All $total monitors are operational. No open incidents.';
    }

    final List<String> parts = <String>['$upCount of $total monitors operational'];
    if (down.isNotEmpty) parts.add('${_joinNames(down)} down');
    if (degraded.isNotEmpty) parts.add('${_joinNames(degraded)} degraded');
    final String incidentSentence = _openIncidents == 0
        ? 'No open incidents.'
        : '$_openIncidents open incident${_openIncidents == 1 ? '' : 's'}.';
    return '${parts.join(', ')}. $incidentSentence';
  }

  /// Joins names as `A`, `A and B`, or `A, B and C`.
  String _joinNames(List<String> names) {
    if (names.length == 1) return names.first;
    if (names.length == 2) return '${names[0]} and ${names[1]}';
    return '${names.sublist(0, names.length - 1).join(', ')} and ${names.last}';
  }
}
