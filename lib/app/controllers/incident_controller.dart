import 'package:magic/magic.dart';

import '../models/incident.dart';
import '../mocks/incidents.dart' show IncidentLifecycle;

/// Controller backing the three incident screens ([IncidentsListView],
/// [IncidentDetailView], [IncidentCreateView]).
///
/// Reads the incident list and detail from the live `api/v1` backend
/// (`GET /incidents`, `GET /incidents/{id}`), and drives the incident-write
/// actions ([resolve], [reopen], [acknowledge], [postUpdate], [create])
/// against the matching write endpoints, following the
/// `monitor_controller.dart` action pattern: `Http.post` -> [reload] on
/// success -> success toast; error toast + stay on failure, no silent
/// swallow. [editPostmortem] has no backend counterpart (there is no
/// postmortem-write endpoint and no postmortem content available at its call
/// site) and stays a mock toast; see its docblock. All transient compose
/// state (the detail composer + lifecycle / assignee toggles, the create
/// form, the list filter / query) stays local to its own view.
///
/// [activeIncidents] derives from the fetched [incidents] list, so it (and
/// anything reading it, e.g. `DashboardController`) reflects live data once
/// [load] resolves. [aiSuggestions] stays empty: AI analysis attachment is a
/// separate, not-yet-wired concern.
class IncidentController extends MagicController
    with MagicStateMixin<List<Incident>> {
  /// Singleton accessor, registering the controller on first access.
  static IncidentController get instance =>
      Magic.findOrPut(IncidentController.new);

  /// The incident currently resolved by [incidentById], keyed by [_detailId]
  /// so a stale in-flight fetch never overwrites a later selection.
  Incident? _detail;

  /// The id [_detail] was (or is being) fetched for.
  String? _detailId;

  // ---------------------------------------------------------------------------
  // Lifecycle
  // ---------------------------------------------------------------------------

  @override
  void onInit() {
    super.onInit();
    load();
  }

  // ---------------------------------------------------------------------------
  // Wire reads
  // ---------------------------------------------------------------------------

  /// Every incident, newest-first as returned by the backend. Empty until
  /// [load] resolves.
  List<Incident> get incidents => rxState ?? const [];

  /// Fetches the incident list, optionally scoped to a monitor or lifecycle
  /// stage (`monitor_id`/`lifecycle` query filters), and publishes it through
  /// the `MagicStateMixin` rx shape.
  ///
  /// The two paths differ deliberately:
  ///
  /// 1. UNFILTERED (the common `onInit`/[reload] case): the list is sourced
  ///    from the [Incident] ORM (`GET /incidents` via [Incident.all]).
  ///    [Incident.all] absorbs transport failures internally and resolves `[]`
  ///    rather than throwing (including for an unregistered `network` service
  ///    in a bare test host); it cannot distinguish that failure from a genuine
  ///    empty result. A non-empty fetch republishes as success; an empty fetch
  ///    keeps the last-known-good list so a transient failure never clobbers
  ///    loaded incidents, and surfaces the empty state only when nothing was
  ///    ever loaded.
  /// 2. FILTERED (`monitorId`/`lifecycle` present): there is no remote query
  ///    builder on the ORM, so this stays a raw `GET /incidents?...` whose
  ///    envelope is hydrated through [Incident.fromMap]. The raw response lets
  ///    a real transport failure surface as the error state (unlike the ORM
  ///    path, which hides it).
  Future<void> load({String? monitorId, String? lifecycle}) async {
    if (monitorId != null || lifecycle != null) {
      await _loadFiltered(monitorId: monitorId, lifecycle: lifecycle);
      return;
    }

    final List<Incident> fetched = await Incident.all();
    if (fetched.isNotEmpty) {
      setSuccess(fetched);
      return;
    }

    // Empty result: keep the last-known-good list when one exists; otherwise
    // surface the empty state. Never throws out of `onInit`/`reload`.
    final List<Incident>? current = rxState;
    if (current == null || current.isEmpty) {
      setEmpty();
    }
  }

  /// Fetches a monitor-/lifecycle-scoped incident list via a raw
  /// `GET /incidents?...`, hydrating the envelope through [Incident.fromMap]
  /// and driving the rx state directly (loading -> success/empty/error).
  Future<void> _loadFiltered({String? monitorId, String? lifecycle}) async {
    setLoading();
    final response = await Http.get(
      '/incidents',
      query: <String, dynamic>{
        'monitor_id': ?monitorId,
        'lifecycle': ?lifecycle,
      },
    );

    if (response.failed) {
      setError(response.errorMessage ?? trans('common.error_occurred'));
      return;
    }

    final Object? payload = response.data;
    final Object? raw = payload is Map<String, dynamic> ? payload['data'] : null;
    if (raw is! List) {
      setEmpty();
      return;
    }

    final List<Incident> incidents = raw
        .whereType<Map<String, dynamic>>()
        .map(Incident.fromMap)
        .toList();
    if (incidents.isEmpty) {
      setEmpty();
      return;
    }

    setSuccess(incidents);
  }

  /// Re-runs [load] with the same (no) filters. Non-destructive: safe to call
  /// from a pull-to-refresh or a manual retry action.
  Future<void> reload() => load();

  /// Resolves an incident by [id] for the detail screen.
  ///
  /// Returns the already-fetched list entry when [load] already covers it
  /// (matching the create-flow / list-to-detail navigation), otherwise
  /// returns the cached detail fetch, and kicks off `GET /incidents/{id}`
  /// on first access for an [id] not yet seen. `null` while the detail is
  /// still in flight or when [id] is `null`; the view rebuilds once the
  /// fetch lands and calls this again.
  ///
  /// Tracks [_detailId] on every non-null lookup (not only the fetch-caching
  /// branch): [IncidentDetailView.build] resolves the incident it is showing
  /// through this getter before any business action fires, so [_detailId]
  /// doubles as "the incident currently in view" for [acknowledge] (whose
  /// call site carries no incident id of its own).
  Incident? incidentById(String? id) {
    if (id == null) return null;

    for (final Incident incident in incidents) {
      if (incident.id == id) {
        _detailId = id;
        return incident;
      }
    }

    if (_detailId == id) return _detail;

    _detailId = id;
    _detail = null;
    _loadDetail(id);
    return null;
  }

  /// Fetches a single incident for [incidentById] and caches it on [_detail].
  ///
  /// Discards the response when a newer [incidentById] call has moved
  /// [_detailId] on (stale-fetch guard), so rapid navigation between
  /// incidents never lets an older response clobber a newer selection.
  Future<void> _loadDetail(String id) async {
    try {
      final response = await Http.get('/incidents/$id');
      if (_detailId != id || !response.successful) {
        return;
      }

      final Object? data = response.data?['data'];
      if (data is! Map<String, dynamic>) {
        return;
      }

      _detail = Incident.fromMap(data);
      refreshUI();
    } catch (_) {
      // Silent no-op on failure (including an unregistered `network` service):
      // the synchronous `incidentById` caller keeps its `null` detail and the
      // view renders its not-found state instead of seeing a throw.
    }
  }

  /// Active incidents: everything not yet resolved, derived from the fetched
  /// [incidents] list.
  List<Incident> get activeIncidents => incidents
      .where((i) => i.lifecycle != IncidentLifecycle.resolved)
      .toList();

  /// AI inbox entries. Stays empty: AI analysis attachment is deferred, so
  /// there is no wired source of AI-owned suggestions yet.
  List<Incident> get aiSuggestions => const [];

  // ---------------------------------------------------------------------------
  // Business actions
  // ---------------------------------------------------------------------------

  /// Resolves [incident] via `POST /incidents/{id}/resolve`, reloads the
  /// list on success, and surfaces the resolved toast. The lifecycle flip
  /// itself is ephemeral compose state owned by the detail view; this
  /// centralizes the write + reconciliation. Errors surface a toast and
  /// leave the incident as-is (no silent swallow).
  Future<void> resolve(Incident incident) async {
    try {
      final response = await Http.post('/incidents/${incident.id}/resolve');
      if (!response.successful) {
        Log.error(
          '[IncidentController.resolve] ${incident.id}: ${response.errorMessage}',
        );
        Magic.error(
          trans('common.error_occurred'),
          response.errorMessage ?? trans('common.error_occurred'),
        );
        return;
      }

      await reload();
      Magic.success(trans('uptizm.incidents.detail_resolve'), incident.title);
    } catch (error) {
      Log.error('[IncidentController.resolve] ${incident.id} failed: $error');
      Magic.error(
        trans('common.error_occurred'),
        trans('common.error_occurred'),
      );
    }
  }

  /// Reopens [incident] via `POST /incidents/{id}/reopen`, reloads the list
  /// on success, and surfaces the reopened toast. Errors surface a toast and
  /// leave the incident as-is.
  Future<void> reopen(Incident incident) async {
    try {
      final response = await Http.post('/incidents/${incident.id}/reopen');
      if (!response.successful) {
        Log.error(
          '[IncidentController.reopen] ${incident.id}: ${response.errorMessage}',
        );
        Magic.error(
          trans('common.error_occurred'),
          response.errorMessage ?? trans('common.error_occurred'),
        );
        return;
      }

      await reload();
      Magic.success(trans('uptizm.incidents.detail_reopen'), incident.title);
    } catch (error) {
      Log.error('[IncidentController.reopen] ${incident.id} failed: $error');
      Magic.error(
        trans('common.error_occurred'),
        trans('common.error_occurred'),
      );
    }
  }

  /// Acknowledges the incident currently in view (tracked by [_detailId] via
  /// [incidentById]), naming the responder [by], via `POST
  /// /incidents/{id}/acknowledge`. Reloads on success and surfaces the
  /// acknowledgement toast. No-ops (logged) when no detail incident is
  /// tracked yet, matching `MonitorController`'s "no cached target" guard;
  /// the detail view always resolves one via `build()` before this fires.
  Future<void> acknowledge(String by) async {
    final String? id = _detailId;
    if (id == null) {
      Log.error('[IncidentController.acknowledge] no incident in view for $by');
      return;
    }

    try {
      final response = await Http.post(
        '/incidents/$id/acknowledge',
        data: {'message': 'Acknowledged by $by'},
      );
      if (!response.successful) {
        Log.error(
          '[IncidentController.acknowledge] $id: ${response.errorMessage}',
        );
        Magic.error(
          trans('common.error_occurred'),
          response.errorMessage ?? trans('common.error_occurred'),
        );
        return;
      }

      await reload();
      Magic.success(
        trans('uptizm.incidents.detail_acknowledged_toast_title'),
        trans('uptizm.incidents.detail_acknowledged_toast_description', {
          'name': by,
        }),
      );
    } catch (error) {
      Log.error('[IncidentController.acknowledge] $id failed: $error');
      Magic.error(
        trans('common.error_occurred'),
        trans('common.error_occurred'),
      );
    }
  }

  /// Posts [message] to [incident]'s unified timeline via `POST
  /// /incidents/{id}/updates`, reloads on success, and surfaces the posted
  /// toast. [message] is optional because the detail view's composer clears
  /// its local text before calling this (see `IncidentDetailView._onPostUpdate`);
  /// a call site with no text has nothing honest to send (the backend
  /// requires a non-empty `message`), so it degrades to the toast-only
  /// notice instead of posting an empty or invented note.
  Future<void> postUpdate(Incident incident, [String? message]) async {
    if (message == null || message.trim().isEmpty) {
      Magic.success(
        trans('uptizm.incidents.detail_composer_post'),
        incident.title,
      );
      return;
    }

    try {
      final response = await Http.post(
        '/incidents/${incident.id}/updates',
        data: {'message': message},
      );
      if (!response.successful) {
        Log.error(
          '[IncidentController.postUpdate] ${incident.id}: ${response.errorMessage}',
        );
        Magic.error(
          trans('common.error_occurred'),
          response.errorMessage ?? trans('common.error_occurred'),
        );
        return;
      }

      await reload();
      Magic.success(
        trans('uptizm.incidents.detail_composer_post'),
        incident.title,
      );
    } catch (error) {
      Log.error(
        '[IncidentController.postUpdate] ${incident.id} failed: $error',
      );
      Magic.error(
        trans('common.error_occurred'),
        trans('common.error_occurred'),
      );
    }
  }

  /// Surfaces the postmortem-edit toast (resolved incidents only). Mock:
  /// nothing persists. There is no backend postmortem-write endpoint (S5
  /// ships resolve/acknowledge/reopen/updates/create only), and the call
  /// site carries neither an incident nor postmortem content to post, so
  /// this stays a mock action rather than inventing either. The toast is an
  /// honest info signal, not a success claim, since no write actually happens.
  void editPostmortem() {
    MagicFeedback.info(
      trans('uptizm.incidents.detail_postmortem_edit_toast_title'),
      trans('uptizm.incidents.detail_postmortem_edit_toast_description'),
    );
  }

  /// Creates a manual incident and returns to the incidents list.
  ///
  /// [fields] is the raw create-form field map (`monitor_id`, `severity`,
  /// `title`, `message`); omitted, this stays navigation-only exactly as
  /// before (Cancel takes this path). When present, it mass-assigns the fields
  /// into a fresh [Incident] and persists it through the ORM (`POST
  /// /incidents`), then reloads the inventory before navigating.
  ///
  /// [Incident.save] absorbs transport failures internally and returns `false`
  /// rather than throwing; on a `false` result the create surfaces the
  /// error toast and STAYS on the form so the user can correct and retry,
  /// instead of being bounced to the list with no incident created and no
  /// feedback (mirroring `MonitorController.create`).
  Future<void> create([Map<String, dynamic>? fields]) async {
    if (fields != null) {
      final Incident incident = Incident()..fill(fields);
      final bool ok = await incident.save();
      if (!ok) {
        Log.error('[IncidentController.create] save returned false');
        Magic.error(
          trans('common.error_occurred'),
          trans('common.error_occurred'),
        );
        return;
      }
      await reload();
    }
    MagicRoute.to('/incidents');
  }
}
