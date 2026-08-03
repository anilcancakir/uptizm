import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../models/incident.dart';
import '../models/monitor.dart';
import '../enums/status_key.dart';

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
/// controller only exposes reads over the fetched data, loaded once through
/// [_ensureLoading] (whichever of [onInit] / [instance] comes first) and
/// re-fetchable via the non-destructive [reload].
class DashboardController extends MagicController
    implements SessionScopedController {
  /// Singleton accessor, registering the controller and kicking off its
  /// one-shot initial load on first access.
  static DashboardController get instance =>
      Magic.findOrPut(DashboardController.new).._ensureLoading();

  /// Cached `GET /dashboard/stats` counters. Empty (all-zero) defaults until
  /// the first successful fetch resolves.
  int _monitorsUp = 0;
  int _monitorsDown = 0;
  int _monitorsDegraded = 0;
  int _monitorsPaused = 0;
  int _monitorsPending = 0;
  int? _avgResponseMs;
  int _openIncidents = 0;

  /// The team's real monitor count per `dashboard/stats`, or null against a
  /// backend that does not report `monitors_total` yet (see [monitorCount]).
  int? _monitorsTotal;

  /// Cached rolling-24h fleet uptime percentage and its change against the
  /// prior 24h, per `GET /dashboard/stats`. Null when the window has no checks
  /// yet (no data), so the KPI renders a no-data placeholder instead of a
  /// fabricated 100%.
  double? _uptime24h;
  double? _uptime24hDelta;

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

  /// Count of monitors awaiting their first check (no last-known status yet).
  int get pendingCount => _monitorsPending;

  /// Total number of monitors the team owns.
  ///
  /// Reads the backend's `monitors_total`, which counts every monitor including
  /// the ones still awaiting a first check. Summing the four status buckets is
  /// only the fallback for a backend that predates that field: a monitor with a
  /// null `last_status` sits in none of those buckets, so the sum reads a team
  /// that just created its first monitors as having zero.
  int get monitorCount =>
      _monitorsTotal ??
      (_monitorsUp +
          _monitorsDown +
          _monitorsDegraded +
          _monitorsPaused +
          _monitorsPending);

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

  /// Whether `dashboard/stats` reported an average response time. False when
  /// no monitor has a recorded timing, so the KPI shows a no-data placeholder
  /// instead of a misleading `0ms`.
  bool get hasAvgResponse => _avgResponseMs != null;

  /// Rolling 24h fleet uptime percentage, or null when no checks exist yet.
  double? get uptime24h => _uptime24h;

  /// Change in 24h uptime against the prior 24h (percentage points), or null
  /// when either window has no data to compare.
  double? get uptime24hDelta => _uptime24hDelta;

  /// Guards the one-shot initial load, shared by [onInit] and [instance] so
  /// the two entry points never fetch twice.
  bool _loadStarted = false;

  /// Whether the first [reload] has finished, successfully or not.
  bool _resolvedOnce = false;

  /// Whether the FIRST dashboard read is still in flight.
  ///
  /// Every counter here starts at `0` and every panel starts empty, which is
  /// indistinguishable from a genuinely empty account. The view therefore has to
  /// know the difference: while this is true it renders a skeleton, because
  /// otherwise a populated team lands on the ZERO-MONITOR ONBOARDING HERO
  /// ("create your first monitor") until the fetch answers, and the KPI grid
  /// would report `0` open incidents and `0%` uptime as though measured.
  ///
  /// Only the first read counts. A later refetch (a realtime event, or
  /// re-entering the route) keeps the current numbers on screen rather than
  /// flashing a skeleton over data the operator is reading.
  bool get isFirstLoad => !_resolvedOnce;

  /// Bootstraps every dashboard surface the first time this controller backs
  /// a view.
  @override
  void onInit() {
    super.onInit();
    _ensureLoading();
  }

  /// Kicks off the initial [reload] the first time the data is needed, from
  /// whichever entry point comes first: this controller backing a view
  /// ([onInit]) or another view resolving [instance].
  ///
  /// magic's `onInit` hook only fires for a [MagicView]'s BACKING controller,
  /// so a view that consults this one as a secondary never triggered the load.
  /// The monitors list does exactly that (it sources its OPEN INCIDENTS and
  /// AI-active KPIs from [openIncidentsCount]/[aiActiveCount] so they agree
  /// with the dashboard), and rendered the untouched all-zero counters: a
  /// fabricated `0 open incidents` on a team that has some, which reads as a
  /// fact rather than as missing data. Both paths now go through this one-shot
  /// guard, so a secondary reader warms the counters and the dashboard view
  /// itself still fetches exactly once.
  Future<void> _ensureLoading() {
    if (_loadStarted) return Future<void>.value();

    _loadStarted = true;
    return reload();
  }

  /// Non-destructive refresh: fetches the four dashboard aggregate endpoints
  /// in parallel and republishes each on success, independently of the
  /// others. Any endpoint that fails (network error, non-2xx) leaves its
  /// previously loaded data untouched so the dashboard never flickers into
  /// an error or empty state on a partial failure.
  Future<void> reload() async {
    final bool firstLoad = isFirstLoad;

    await Future.wait([
      _reloadStats(),
      _reloadActiveIncidents(),
      _reloadMonitorsSnapshot(),
      _reloadAiInbox(),
    ]);

    // Resolved once all four legs have answered, so the view stops skeletoning
    // only when every surface it paints has real data (or a real failure) behind
    // it. Each leg already republishes its own slice; this repaint is what swaps
    // the skeleton out, including when every leg failed and the honest answer is
    // the empty dashboard.
    _resolvedOnce = true;
    if (firstLoad) refreshUI();
  }

  /// Drops every counter and panel the previous session loaded, publishes the
  /// cleared state, then refetches for the identity that is now authenticated.
  ///
  /// Clears BEFORE refetching (see [SessionScopedController]): [reload]'s legs
  /// deliberately keep their last-known-good data on failure, which across an
  /// identity change would leave another team's incidents and monitors on the
  /// dashboard. Re-arms [_loadStarted] as part of the reset and immediately
  /// claims it again through [_ensureLoading], so the refetch this awaits is
  /// also the new session's one-shot load and a later [instance] read does not
  /// fire a second one.
  @override
  Future<void> resetForSession() async {
    _monitorsUp = 0;
    _monitorsDown = 0;
    _monitorsDegraded = 0;
    _monitorsPaused = 0;
    _monitorsPending = 0;
    _monitorsTotal = null;
    _avgResponseMs = null;
    _openIncidents = 0;
    _uptime24h = null;
    _uptime24hDelta = null;
    _activeIncidents = [];
    _monitorsSnapshot = [];
    _aiInbox = [];
    _loadStarted = false;
    // Back to "not asked yet" for the incoming identity: without this the new
    // team's dashboard would render the cleared zeros as fact, which on this
    // screen means the create-your-first-monitor hero.
    _resolvedOnce = false;
    refreshUI();

    await _ensureLoading();
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
      _monitorsPending = (data['monitors_pending'] as num?)?.toInt() ?? 0;
      _monitorsTotal = (data['monitors_total'] as num?)?.toInt();
      _avgResponseMs = (data['avg_response_ms'] as num?)?.toInt();
      _openIncidents = (data['open_incidents'] as num?)?.toInt() ?? 0;
      _uptime24h = (data['uptime_24h'] as num?)?.toDouble();
      _uptime24hDelta = (data['uptime_24h_delta'] as num?)?.toDouble();
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

  /// Refreshes the `GET /dashboard/ai-inbox` list.
  ///
  /// The endpoint is live: it returns the team's pending `AiSuggestion` rows,
  /// which the backend's `SweepAiSuggestions` job creates every two minutes for
  /// any monitor whose `ai_mode` is `suggest` or `auto`. An empty list means the
  /// fleet has no open anomaly right now, not that the feature is unfinished.
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
      final response = await Http.post('/ai-suggestions/$suggestionId/accept');
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
      final response = await Http.post('/ai-suggestions/$suggestionId/dismiss');
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
      return trans('uptizm.dashboard.fleet_empty');
    }

    final List<String> down = _monitorsSnapshot
        .where((Monitor m) => m.status == StatusKey.down)
        .map((Monitor m) => m.name ?? '')
        .toList();
    final List<String> degraded = _monitorsSnapshot
        .where((Monitor m) => m.status == StatusKey.degraded)
        .map((Monitor m) => m.name ?? '')
        .toList();

    // "All operational" may only be claimed when every monitor has actually
    // reported up. A monitor awaiting its first check has reported nothing, so
    // counting it as operational would assert a state the backend never sent.
    if (down.isEmpty &&
        degraded.isEmpty &&
        _openIncidents == 0 &&
        _monitorsPending == 0) {
      return trans('uptizm.dashboard.fleet_all_operational', {'count': '$total'});
    }

    final List<String> parts = <String>[
      trans('uptizm.dashboard.fleet_operational_ratio', {
        'up': '$upCount',
        'total': '$total',
      }),
    ];
    if (_monitorsPending > 0) {
      parts.add(
        trans('uptizm.dashboard.fleet_pending_suffix', {
          'count': '$_monitorsPending',
        }),
      );
    }
    if (down.isNotEmpty) {
      parts.add(
        trans('uptizm.dashboard.fleet_down_suffix', {'names': _joinNames(down)}),
      );
    }
    if (degraded.isNotEmpty) {
      parts.add(
        trans('uptizm.dashboard.fleet_degraded_suffix', {
          'names': _joinNames(degraded),
        }),
      );
    }
    // English needs singular/plural on the noun; Turkish does not, so the two
    // count variants select the right English wording while sharing one TR value.
    final String incidentSentence = _openIncidents == 0
        ? trans('uptizm.dashboard.fleet_no_open_incidents')
        : trans(
            _openIncidents == 1
                ? 'uptizm.dashboard.fleet_open_incidents_one'
                : 'uptizm.dashboard.fleet_open_incidents_other',
            {'count': '$_openIncidents'},
          );
    return '${parts.join(', ')}. $incidentSentence';
  }

  /// Joins names as `A`, `A and B`, or `A, B and C`, with a localized connector.
  String _joinNames(List<String> names) {
    if (names.length == 1) return names.first;
    final String and = trans('uptizm.common.list_and');
    if (names.length == 2) return '${names[0]} $and ${names[1]}';
    return '${names.sublist(0, names.length - 1).join(', ')} $and ${names.last}';
  }
}
