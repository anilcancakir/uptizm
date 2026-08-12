import 'dart:async';

import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../models/incident.dart';
import '../enums/ai_confidence.dart' show aiConfidenceFromWire;
import '../enums/ai_degrade_reason.dart' show aiDegradeReasonFromWire;
import '../enums/incident_lifecycle.dart' show IncidentLifecycle;
import '../support/incident_types.dart'
    show AiEvidence, AiSuggestedAction, IncidentAi;

/// Controller backing the three incident screens ([IncidentsListView],
/// [IncidentDetailView], [IncidentCreateView]).
///
/// Reads the incident list and detail from the live `api/v1` backend
/// (`GET /incidents`, `GET /incidents/{id}`), and drives the incident-write
/// actions ([resolve], [reopen], [acknowledge], [postUpdate], [assign],
/// [savePostmortem], [create]) against the matching write endpoints,
/// following the `monitor_controller.dart` action pattern: `Http.post` ->
/// [reload] on success -> success toast; error toast + stay on failure, no
/// silent swallow.
///
/// Two of those writes are persisted STATE, not compose state, so they are
/// deliberately not mirrored in view-local fields: the assignment
/// ([assign] -> `POST /incidents/{id}/assign`) and the postmortem
/// ([savePostmortem] -> `POST /incidents/{id}/postmortem`) both reload and
/// re-render from the incident the backend returns. Likewise [acknowledge]
/// posts NO composed message: the backend stamps the acknowledging author from
/// the request user and supplies its own default note, and the detail screen
/// renders the acknowledgement off the persisted timeline entry
/// ([Incident.acknowledgement]). The remaining transient compose state (the
/// detail composer text + lifecycle toggle, the create form, the list filter /
/// query) stays local to its own view.
///
/// [activeIncidents] derives from the fetched [incidents] list, so it (and
/// anything reading it, e.g. `DashboardController`) reflects live data once
/// [load] resolves. [aiSuggestions] stays empty: AI analysis attachment is a
/// separate, not-yet-wired concern.
class IncidentController extends MagicController
    with MagicStateMixin<List<Incident>>
    implements SessionScopedController {
  /// Singleton accessor, registering the controller and kicking off its
  /// one-shot initial load on first access.
  ///
  /// The load is self-triggered for the same reason [DashboardController] does
  /// it: `onInit` fires only for a view's BACKING controller, and the monitor
  /// detail reads this one as a secondary to answer "which incidents touch this
  /// monitor". Without it that screen saw an empty list and reported zero open
  /// incidents on a monitor that was actively down.
  static IncidentController get instance =>
      Magic.findOrPut(IncidentController.new).._ensureLoading();

  /// The incident currently resolved by [incidentById], keyed by [_detailId]
  /// so a stale in-flight fetch never overwrites a later selection.
  Incident? _detail;

  /// The id [_detail] was (or is being) fetched for.
  String? _detailId;

  /// Whether the lookup behind [_detailId] has answered, successfully or not.
  ///
  /// [isFirstLoad] cannot speak for a single incident: the LIST read can have
  /// landed long ago while a deep-linked incident absent from that list is still
  /// being fetched by [_loadDetail]. Without this bit, an [incidentById] of
  /// `null` is indistinguishable from "no such incident", which is what made a
  /// cold deep link render the not-found screen for an incident that exists.
  ///
  /// One bool rather than a set of ids, because [_detailId] already encodes that
  /// exactly one detail is current at a time; it resets whenever that selection
  /// moves, so a revisit re-enters the pending state instead of reading a stale
  /// verdict from a previous fetch.
  bool _detailSettled = false;

  /// Enriched AI analysis per incident id, populated by [loadAnalysis].
  ///
  /// Kept as a separate cache rather than written onto [Incident.ai] because the
  /// analysis is transient and endpoint-only: `GET /incidents/{id}/analysis`
  /// recomputes it per call and `IncidentResource` never carries it, so there is
  /// no model field for it to belong to. [analysisFor] reads this cache first.
  final Map<String, IncidentAi> _analysisById = {};

  /// The incident ids whose analysis fetch is in flight.
  ///
  /// A set rather than one id, even though the detail screen shows one incident
  /// at a time: this controller is a Type-keyed singleton that outlives every
  /// screen, and a request takes long enough that opening A, going back, and
  /// opening B leaves two of them running. With one slot, whichever answered
  /// first cleared the other's flag.
  ///
  /// It exists because the retry re-asks the model and spends an AI budget unit,
  /// so without an in-flight signal the operator taps, watches nothing happen for
  /// the better part of a minute, and taps again.
  final Set<String> _analysisPendingIds = {};

  // ---------------------------------------------------------------------------
  // Lifecycle
  // ---------------------------------------------------------------------------

  @override
  void onInit() {
    super.onInit();
    unawaited(_ensureLoading());
  }

  /// Guards the one-shot initial [load] so whichever entry point comes first,
  /// this controller backing a view ([onInit]) or another view resolving
  /// [instance], fetches exactly once and the other is a no-op.
  bool _loadStarted = false;

  /// Kicks off the initial [load] the first time the data is needed, and
  /// returns the in-flight future so [resetForSession] can re-arm the guard and
  /// then await the very load it claimed.
  Future<void> _ensureLoading() {
    if (_loadStarted) return Future<void>.value();

    _loadStarted = true;

    return load();
  }

  // ---------------------------------------------------------------------------
  // Wire reads
  // ---------------------------------------------------------------------------

  /// Every incident, newest-first as returned by the backend. Empty until
  /// [load] resolves.
  List<Incident> get incidents => rxState ?? const [];

  /// Whether the FIRST list read is still in flight.
  ///
  /// Separates "we have not asked yet" from "we asked and there are none". The
  /// list view renders a skeleton while this is true instead of asserting an
  /// empty history before the first answer arrives, which is what made a team
  /// with open incidents flash "No incidents yet" on every cold open.
  ///
  /// Derived from the `MagicStateMixin` state this controller already
  /// publishes, deliberately NOT from a second bookkeeping flag: `rxState` is
  /// `null` only while no read has answered yet, because a resolved-empty read
  /// publishes an empty LIST rather than a null (see [_publishResolvedEmpty]).
  /// An errored read has answered too, so it stops counting as a first load and
  /// the view falls back to its empty state rather than skeletoning forever.
  bool get isFirstLoad => rxState == null && !isError;

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
      _publishResolvedEmpty();
    }
  }

  /// Publishes a resolved-empty read: the `RxStatus.empty()` status the mixin
  /// helper [setEmpty] would set, but carrying an empty LIST instead of a
  /// `null` state.
  ///
  /// The distinction is what makes [isFirstLoad] derivable without a parallel
  /// "have we resolved yet" flag. `MagicStateMixin` starts life at
  /// `RxStatus.empty()` with a `null` state, so a pristine controller and one
  /// whose fetch came back with zero incidents are otherwise the same value,
  /// and the list view cannot tell a pending read from a genuinely empty
  /// history. An empty list is a real answer ("zero incidents"), so `rxState ==
  /// null` keeps its narrower meaning: nothing has answered yet.
  void _publishResolvedEmpty() {
    setState(const <Incident>[], status: const RxStatus.empty());
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
      _publishResolvedEmpty();
      return;
    }

    final List<Incident> incidents = raw
        .whereType<Map<String, dynamic>>()
        .map(Incident.fromMap)
        .toList();
    if (incidents.isEmpty) {
      _publishResolvedEmpty();
      return;
    }

    setSuccess(incidents);
  }

  /// Re-runs [load] with the same (no) filters. Non-destructive: safe to call
  /// from a pull-to-refresh or a manual retry action.
  Future<void> reload() => load();

  /// Drops the previous session's incident list, cached detail, and AI analysis
  /// cache, publishes the cleared state, then refetches for the identity that
  /// is now authenticated.
  ///
  /// Clears BEFORE refetching (see [SessionScopedController]): [load] keeps the
  /// last-known-good list on an empty fetch, so across an identity change a
  /// failed refetch would otherwise leave the previous team's incidents listed
  /// and its detail still resolvable through [incidentById]. The rx state goes
  /// back to the mixin's initial dataless-empty status with `notify: false`, so
  /// the single [refreshUI] below publishes the whole cleared shape at once.
  /// [load] is the refetch entry point here ([reload] delegates to it).
  @override
  Future<void> resetForSession() async {
    _detail = null;
    _detailId = null;
    _detailSettled = false;
    _analysisById.clear();
    setState(null, status: const RxStatus.empty(), notify: false);
    refreshUI();

    // Re-arm the one-shot guard: the previous identity's load already claimed
    // it, and without this the new identity would never fetch.
    _loadStarted = false;
    await _ensureLoading();
  }

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
        // The roster answered for this id, so nothing is pending.
        _detailSettled = true;
        return incident;
      }
    }

    if (_detailId == id) return _detail;

    _detailId = id;
    _detail = null;
    _detailSettled = false;
    _loadDetail(id);
    return null;
  }

  /// Whether the lookup behind [incidentById] for [id] has yet to answer.
  ///
  /// Callers resolve the incident first and consult this only when that came
  /// back `null`, to tell "still loading" apart from "no such incident". A null
  /// [id], or an id no lookup has been made for, counts as answered: there is
  /// nothing in flight, so the caller's not-found branch is the honest one.
  bool isFirstLoadFor(String? id) {
    if (id == null || _detailId != id) return false;

    return !_detailSettled;
  }

  /// Fetches a single incident for [incidentById] and caches it on [_detail].
  ///
  /// Discards the response when a newer [incidentById] call has moved
  /// [_detailId] on (stale-fetch guard), so rapid navigation between
  /// incidents never lets an older response clobber a newer selection.
  Future<void> _loadDetail(String id) async {
    try {
      final response = await Http.get('/incidents/$id');
      // The stale guard and the failure paths are deliberately separate: a
      // response for an id the screen has already navigated away from says
      // nothing about the id now selected, so it must not settle it. A failure
      // for the CURRENT id has answered, and leaving it unsettled would
      // skeleton forever.
      if (_detailId != id) {
        return;
      }
      if (!response.successful) {
        _settleDetail();
        return;
      }

      final Object? data = response.data?['data'];
      if (data is! Map<String, dynamic>) {
        _settleDetail();
        return;
      }

      _detail = Incident.fromMap(data);
      _settleDetail();
    } catch (_) {
      // Deliberate degradation on failure (including an unregistered `network`
      // service): the synchronous `incidentById` caller keeps its `null` detail
      // and the view renders its not-found state instead of seeing a throw. The
      // read has still ANSWERED, so it settles: an unsettled failure would leave
      // the screen skeletoning with nothing left in flight to end it.
      _settleDetail();
    }
  }

  /// Marks the current [_detailId] lookup as answered and repaints.
  ///
  /// Every non-stale exit of [_loadDetail] routes through here, including the
  /// failures, so "pending" always has something in flight behind it.
  void _settleDetail() {
    _detailSettled = true;
    refreshUI();
  }

  /// The AI analysis to render for [incident]: the one [loadAnalysis] fetched
  /// when it has landed, otherwise whatever the incident itself carried.
  ///
  /// The enriched object is taken WHOLE rather than merged field by field. It
  /// used to be rebuilt argument by argument from both sources, and that shape
  /// dropped a field twice: `degradeReason` was simply forgotten when it was
  /// added, and `confidence` was written so the fetched value could never win.
  /// A whole-object pick cannot drop a field a future addition introduces,
  /// because it enumerates none.
  ///
  /// Nothing is lost by the switch, because the two sources never coexist on
  /// this screen: [Incident.ai] is only ever populated by the dashboard's
  /// AI-suggestion shape, and `IncidentResource` (the roster and the detail
  /// endpoint both) emits no `ai` key at all, so an incident resolved here
  /// carries none. The `??` therefore reads as "the fetch, or nothing yet".
  IncidentAi? analysisFor(Incident incident) =>
      _analysisById[incident.id] ?? incident.ai;

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
  ///
  /// Skips the request entirely when the cache already holds a MODEL-authored
  /// analysis for [id]. The endpoint recomputes per call and spends one AI budget
  /// unit each time (`IncidentAnalysisService::analyzeFor()` consumes the budget
  /// before it calls the gateway), so a mount that re-asks for an answer we
  /// already have is money for nothing: three responders opening one incident a
  /// few times each is a double-digit share of a 100-per-day team cap. A cached
  /// DEGRADE is not skipped, because that one is worth re-asking, which is what
  /// [retryAnalysis] is for.
  Future<void> loadAnalysis(String id) async {
    final IncidentAi? cached = _analysisById[id];
    if (cached != null && cached.degradeReason == null) return;

    await _fetchAnalysis(id, announceStart: false, reportFailure: false);
  }

  /// Re-asks for the analysis of incident [id] because the operator pressed the
  /// retry on a degraded one.
  ///
  /// Differs from [loadAnalysis] in the two ways an explicit action differs from
  /// a background one: it repaints on START so the button can disable itself
  /// while the request runs, and it REPORTS the outcome. A silent retry is the
  /// same defect class this screen was just cleaned of, because a failed re-ask
  /// and a re-ask that degraded again both repaint byte-identically, leaving the
  /// operator unable to tell either from a button that does nothing.
  Future<void> retryAnalysis(String id) =>
      _fetchAnalysis(id, announceStart: true, reportFailure: true);

  /// The shared fetch behind [loadAnalysis] and [retryAnalysis].
  ///
  /// Degrades silently on any failure (network error, non-2xx, or a malformed
  /// payload, including an unregistered `network` service in a bare test host):
  /// [analysisFor] keeps returning whatever it had instead of the screen crashing
  /// or flashing an error state for a surface that already has a working
  /// fallback. [reportFailure] adds a toast on top of that, for the retry only.
  Future<void> _fetchAnalysis(
    String id, {
    required bool announceStart,
    required bool reportFailure,
  }) async {
    // Re-entrancy guard. The disabled retry button is a UI courtesy, not a
    // mechanism: a remount inside the request window would otherwise fire a
    // second one, and every extra request spends another budget unit.
    if (_analysisPendingIds.contains(id)) return;

    _analysisPendingIds.add(id);
    // Only the retry announces itself. `loadAnalysis` runs from `initState`, and
    // `refreshUI` is a bare `notifyListeners()`, so notifying there would mark
    // listening elements dirty during a build that is still running.
    if (announceStart) refreshUI();
    try {
      final response = await Http.get('/incidents/$id/analysis');
      if (!response.successful) {
        Log.error(
          '[IncidentController._fetchAnalysis] $id: ${response.errorMessage}',
        );
        if (reportFailure) {
          Magic.error(
            trans('common.error_occurred'),
            response.errorMessage ?? trans('common.error_occurred'),
          );
        }
        return;
      }

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (data is! Map<String, dynamic>) {
        // A 200 whose body is not the shape we asked for. Silent on the mount
        // path like every other failure there, but the retry has to speak: this
        // was the one exit that reported nothing, so an operator-initiated
        // re-ask against a malformed payload looked exactly like a button that
        // did nothing, which is what this method's own contract rules out.
        Log.error(
          '[IncidentController._fetchAnalysis] $id: malformed payload',
        );
        if (reportFailure) {
          Magic.error(
            trans('common.error_occurred'),
            trans('common.error_occurred'),
          );
        }

        return;
      }

      final IncidentAi analysis = IncidentAi(
        trigger: '',
        confidence: aiConfidenceFromWire(data['confidence'] as String?),
        tldr: (data['summary'] as String?) ?? '',
        evidenceFor: _decodeEvidence(data['evidence_for']),
        evidenceAgainst: _decodeEvidence(data['evidence_against']),
        suggestedActions: _decodeActions(data['suggested_actions']),
        similarIncidents: const [],
        degradeReason: aiDegradeReasonFromWire(
          data['degrade_reason'] as String?,
        ),
      );
      _analysisById[id] = analysis;

      // Say what came back, so "asked again, same answer" is distinguishable
      // from "the button did nothing". A second degrade renders the identical
      // sentence, which is exactly the case a repaint cannot report.
      if (reportFailure && analysis.degradeReason != null) {
        // `snackbar` with the info type, not `Magic.success`: the request
        // succeeded and the answer did not, and the two read differently.
        Magic.snackbar(
          trans('uptizm.incidents.analysis_degraded_heading'),
          trans('uptizm.incidents.analysis_retry_unchanged'),
        );
      }
    } catch (error) {
      Log.error('[IncidentController._fetchAnalysis] $id failed: $error');
      if (reportFailure) {
        Magic.error(
          trans('common.error_occurred'),
          trans('common.error_occurred'),
        );
      }
    } finally {
      // Keyed to THIS request. An unconditional clear released the retry of
      // whichever incident was pending when any other one finished: open A, go
      // back, open B, and A's answer re-enabled B's button while B's request was
      // still running, so the next tap spent a second budget unit on a request
      // already in flight.
      _analysisPendingIds.remove(id);
      refreshUI();
    }
  }

  /// Whether the analysis fetch for incident [id] is in flight, so the degraded
  /// section can show its retry as running rather than idle.
  bool analysisPending(String id) => _analysisPendingIds.contains(id);

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
      Magic.success(
        trans('uptizm.incidents.detail_resolve'),
        incident.displayTitle,
      );
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
      Magic.success(
        trans('uptizm.incidents.detail_reopen'),
        incident.displayTitle,
      );
    } catch (error) {
      Log.error('[IncidentController.reopen] ${incident.id} failed: $error');
      Magic.error(
        trans('common.error_occurred'),
        trans('common.error_occurred'),
      );
    }
  }

  /// Acknowledges the incident currently in view (tracked by [_detailId] via
  /// [incidentById]) via `POST /incidents/{id}/acknowledge`. Reloads on
  /// success and surfaces the acknowledgement toast. No-ops (logged) when no
  /// detail incident is tracked yet, matching `MonitorController`'s "no cached
  /// target" guard; the detail view always resolves one via `build()` before
  /// this fires.
  ///
  /// Sends NO payload, deliberately. The acknowledging identity belongs to the
  /// backend: `IncidentWriteService.acknowledge` stamps the timeline note's
  /// `author` from `$request->user()->name` and falls back to its own default
  /// message when none is given. A client-composed "Acknowledged by X" would be
  /// the client asserting who responded to an outage, which it cannot know.
  Future<void> acknowledge() async {
    final String? id = _detailId;
    if (id == null) {
      Log.error('[IncidentController.acknowledge] no incident in view');
      return;
    }

    try {
      final response = await Http.post('/incidents/$id/acknowledge');
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
        trans('uptizm.incidents.detail_acknowledged_toast_description'),
      );
    } catch (error) {
      Log.error('[IncidentController.acknowledge] $id failed: $error');
      Magic.error(
        trans('common.error_occurred'),
        trans('common.error_occurred'),
      );
    }
  }

  /// Assigns [incident] to the team member [assigneeId], or clears the
  /// assignment when [assigneeId] is `null`, via
  /// `POST /incidents/{id}/assign`. Reloads on success so the rendered owner
  /// comes from the persisted incident, then surfaces the toast.
  ///
  /// The roster is the team's REAL membership (the detail view reads
  /// `MagicStarterTeamController.members`); the backend re-validates the id
  /// against that same roster and answers 422 for anyone else, so a stale
  /// client cannot assign an outsider. Errors surface a toast and leave the
  /// assignment as-is (no silent swallow).
  Future<void> assign(Incident incident, String? assigneeId) async {
    try {
      final response = await Http.post(
        '/incidents/${incident.id}/assign',
        data: {'assignee_id': assigneeId},
      );
      if (!response.successful) {
        Log.error(
          '[IncidentController.assign] ${incident.id}: ${response.errorMessage}',
        );
        Magic.error(
          trans('common.error_occurred'),
          response.errorMessage ?? trans('common.error_occurred'),
        );
        return;
      }

      await reload();
      Magic.success(
        assigneeId == null
            ? trans('uptizm.incidents.detail_unassigned_toast_title')
            : trans('uptizm.incidents.detail_assigned_toast_title'),
        incident.displayTitle,
      );
    } catch (error) {
      Log.error('[IncidentController.assign] ${incident.id} failed: $error');
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
  Future<void> postUpdate(
    Incident incident, {
    String? message,
    bool isPublic = true,
    IncidentLifecycle? status,
  }) async {
    if (message == null || message.trim().isEmpty) {
      Magic.success(
        trans('uptizm.incidents.detail_composer_post'),
        incident.displayTitle,
      );
      return;
    }

    try {
      final response = await Http.post(
        '/incidents/${incident.id}/updates',
        data: {
          'message': message,
          // Always sent explicitly. The backend resolves an ABSENT `is_public`
          // as `true` (`IncidentController::postUpdate` ->
          // `validated('is_public', true)`), so omitting the key publishes an
          // update the operator marked internal onto the public status page.
          'is_public': isPublic,
          if (status != null) 'status': status.name,
        },
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
        incident.displayTitle,
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

  /// Saves [body] as [incident]'s postmortem via
  /// `POST /incidents/{id}/postmortem`, publishing it to the public status
  /// page when [publish] is set. Reloads on success so the stored body (not a
  /// regenerated draft) is what renders afterwards, then surfaces a toast whose
  /// copy distinguishes the two outcomes: a saved draft is explicitly internal,
  /// a publish explicitly customer-visible.
  ///
  /// Returns `true` when the write landed, so the caller can close its composer
  /// only on success and keep the operator's text on screen otherwise. A blank
  /// body is rejected client-side (the backend requires one) without a request.
  Future<bool> savePostmortem(
    Incident incident,
    String body, {
    required bool publish,
  }) async {
    if (body.trim().isEmpty) {
      Magic.error(
        trans('common.error_occurred'),
        trans('uptizm.incidents.detail_postmortem_error_empty'),
      );
      return false;
    }

    try {
      final response = await Http.post(
        '/incidents/${incident.id}/postmortem',
        data: {'body': body.trim(), 'publish': publish},
      );
      if (!response.successful) {
        Log.error(
          '[IncidentController.savePostmortem] ${incident.id}: '
          '${response.errorMessage}',
        );
        Magic.error(
          trans('common.error_occurred'),
          response.errorMessage ?? trans('common.error_occurred'),
        );
        return false;
      }

      await reload();
      Magic.success(
        publish
            ? trans('uptizm.incidents.detail_postmortem_publish_toast_title')
            : trans('uptizm.incidents.detail_postmortem_save_toast_title'),
        publish
            ? trans(
                'uptizm.incidents.detail_postmortem_publish_toast_description',
              )
            : trans('uptizm.incidents.detail_postmortem_save_toast_description'),
      );
      return true;
    } catch (error) {
      Log.error(
        '[IncidentController.savePostmortem] ${incident.id} failed: $error',
      );
      Magic.error(
        trans('common.error_occurred'),
        trans('common.error_occurred'),
      );
      return false;
    }
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
