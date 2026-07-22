import 'package:magic/magic.dart';

import '../models/incident.dart';
import '../enums/ai_confidence.dart' show aiConfidenceFromWire;
import '../enums/incident_lifecycle.dart' show IncidentLifecycle;
import '../support/incident_types.dart'
    show AiEvidence, AiSuggestedAction, IncidentAi;

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

  /// Enriched AI analysis per incident id, populated by [loadAnalysis].
  ///
  /// Kept as a separate cache rather than merged into [Incident.ai] so the
  /// fast first-paint `trigger`/`confidence`/`tldr` from `GET /incidents/{id}`
  /// keeps rendering unchanged while the richer analysis fetch is in flight;
  /// [analysisFor] combines the two views for the detail screen.
  final Map<String, IncidentAi> _analysisById = {};

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

  /// The enriched AI analysis for [incident], or `null` when neither the
  /// `GET /incidents/{id}` payload nor [loadAnalysis] has attached one.
  ///
  /// Combines the fast first-paint `trigger`/`confidence`/`tldr` from
  /// [Incident.ai] with the `evidenceFor`/`evidenceAgainst`/`suggestedActions`
  /// fetched by [loadAnalysis], once resolved. Before [loadAnalysis] resolves
  /// this returns the un-enriched [Incident.ai] unchanged, so the detail
  /// screen's AI analysis card shows the inline summary immediately and
  /// re-renders with evidence/actions once they arrive. `similarIncidents`
  /// stays empty (deferred, see the plan's Deferred Ideas).
  IncidentAi? analysisFor(Incident incident) {
    final IncidentAi? enriched = _analysisById[incident.id];
    if (enriched == null) return incident.ai;

    final IncidentAi? base = incident.ai;
    return IncidentAi(
      trigger: base?.trigger ?? '',
      confidence: base?.confidence ?? enriched.confidence,
      tldr: base?.tldr ?? enriched.tldr,
      evidenceFor: enriched.evidenceFor,
      evidenceAgainst: enriched.evidenceAgainst,
      suggestedActions: enriched.suggestedActions,
      similarIncidents: const [],
    );
  }

  /// Fetches the enriched AI analysis for incident [id] via `GET
  /// /incidents/{id}/analysis`, decodes it into an [IncidentAi], and caches it
  /// in [_analysisById] so [analysisFor] enriches its result on the next
  /// build. Fired once from `IncidentDetailView.initState` (never from
  /// `build`).
  ///
  /// Degrades silently on any failure (network error, non-2xx, or a malformed
  /// payload, including an unregistered `network` service in a bare test
  /// host): [analysisFor] keeps returning the un-enriched [Incident.ai] first
  /// paint instead of the screen crashing or flashing an error state for a
  /// surface that already has a working fallback.
  Future<void> loadAnalysis(String id) async {
    try {
      final response = await Http.get('/incidents/$id/analysis');
      if (!response.successful) {
        Log.error(
          '[IncidentController.loadAnalysis] $id: ${response.errorMessage}',
        );
        return;
      }

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (data is! Map<String, dynamic>) return;

      _analysisById[id] = IncidentAi(
        trigger: '',
        confidence: aiConfidenceFromWire(data['confidence'] as String?),
        tldr: (data['summary'] as String?) ?? '',
        evidenceFor: _decodeEvidence(data['evidence_for']),
        evidenceAgainst: _decodeEvidence(data['evidence_against']),
        suggestedActions: _decodeActions(data['suggested_actions']),
        similarIncidents: const [],
      );
      refreshUI();
    } catch (error) {
      Log.error('[IncidentController.loadAnalysis] $id failed: $error');
    }
  }

  /// Decodes an `evidence_for`/`evidence_against` wire list into
  /// [AiEvidence]s, tolerating a non-list or absent value as empty (the
  /// over-budget fallback shape).
  List<AiEvidence> _decodeEvidence(Object? raw) {
    if (raw is! List) return const [];
    return raw.whereType<Map<String, dynamic>>().map(AiEvidence.fromMap).toList();
  }

  /// Decodes a `suggested_actions` wire list into [AiSuggestedAction]s,
  /// tolerating a non-list or absent value as empty.
  List<AiSuggestedAction> _decodeActions(Object? raw) {
    if (raw is! List) return const [];
    return raw
        .whereType<Map<String, dynamic>>()
        .map(AiSuggestedAction.fromMap)
        .toList();
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
  /// Returns the backend per-field validation errors (single message per field,
  /// keyed by the wire field name: `title`, `monitor_id`, `severity`,
  /// `message`) so the form can render a server 422 inline; an empty map means
  /// success (or a navigation-only call). A `false` save that carries field
  /// errors ([Incident.validationErrors]) STAYS on the form with no toast so the
  /// user corrects the flagged fields; a `false` save with NO field errors (a
  /// transport error / 500) keeps the generic error toast and returns an empty
  /// map. [Incident.save] absorbs transport failures internally and returns
  /// `false` rather than throwing.
  Future<Map<String, String>> create([Map<String, dynamic>? fields]) async {
    if (fields != null) {
      final Incident incident = Incident()..fill(fields);
      final bool ok = await incident.save();
      if (!ok) {
        final Map<String, String>? fieldErrors = _fieldErrorsOrToast(incident);
        if (fieldErrors != null) return fieldErrors;
        return const {};
      }
      await reload();
    }
    MagicRoute.to('/incidents');
    return const {};
  }

  /// Resolves a failed [incident] save into either its per-field validation
  /// errors or a generic toast.
  ///
  /// Returns the field errors (single message per field, keyed by the wire
  /// field name) when the failed save carried the Laravel 422 shape via
  /// [Incident.validationErrors], so the caller hands them back to the form for
  /// inline display and stays put. Returns `null` for a non-field failure (a
  /// transport error / 500) after surfacing the generic error toast and logging
  /// the cause, so the caller falls back to its empty-map contract.
  Map<String, String>? _fieldErrorsOrToast(Incident incident) {
    final Map<String, List<String>> errors = incident.validationErrors;
    if (errors.isNotEmpty) {
      return {
        for (final MapEntry<String, List<String>> entry in errors.entries)
          entry.key: entry.value.first,
      };
    }

    Log.error('[IncidentController.create] save returned false with no errors');
    Magic.error(
      trans('common.error_occurred'),
      trans('common.error_occurred'),
    );
    return null;
  }
}
