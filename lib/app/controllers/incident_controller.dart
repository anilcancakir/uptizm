import 'package:magic/magic.dart';

import '../mocks/incidents.dart';

/// Controller backing the three incident screens ([IncidentsListView],
/// [IncidentDetailView], [IncidentCreateView]).
///
/// Reads the incident list and detail from the live `api/v1` backend
/// (`GET /incidents`, `GET /incidents/{id}`): the monitoring engine is the
/// sole author of an incident's lifecycle, so this controller is read-only
/// over the wire. The business actions below stay mock side-effects (a
/// `Magic.success` toast, or the create-flow navigation): manual incident
/// authoring (resolve/reopen/acknowledge/postUpdate/editPostmortem/create)
/// is deferred until the corresponding write endpoints ship, so none of them
/// persists a mutation. All transient compose state (the detail composer +
/// lifecycle / assignee toggles, the create form, the list filter / query)
/// stays local to its own view.
///
/// [activeIncidents] derives from the fetched [incidents] list, so it (and
/// anything reading it, e.g. `DashboardController`) reflects live data once
/// [load] resolves. [aiSuggestions] stays empty: AI analysis attachment is a
/// separate, not-yet-wired concern.
class IncidentController extends MagicController
    with MagicStateMixin<List<IncidentSummary>> {
  /// Singleton accessor, registering the controller on first access.
  static IncidentController get instance =>
      Magic.findOrPut(IncidentController.new);

  /// The incident currently resolved by [incidentById], keyed by [_detailId]
  /// so a stale in-flight fetch never overwrites a later selection.
  IncidentSummary? _detail;

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
  List<IncidentSummary> get incidents => rxState ?? const [];

  /// Fetches the incident list, optionally scoped to a monitor or lifecycle
  /// stage (`monitor_id`/`lifecycle` query filters).
  ///
  /// Populates `rxState` through the `MagicStateMixin` fetch helper; errors
  /// surface through `rxStatus` for the bound view to render.
  Future<void> load({String? monitorId, String? lifecycle}) async {
    final query = <String, dynamic>{
      'monitor_id': ?monitorId,
      'lifecycle': ?lifecycle,
    };
    try {
      await fetchList<IncidentSummary>(
        '/incidents',
        IncidentSummary.fromMap,
        query: query.isEmpty ? null : query,
      );
    } catch (error) {
      // Deliberate degradation: a transport failure (including an unregistered
      // `network` service in a bare test host) surfaces as the controller's
      // error state instead of throwing out of `onInit`/`reload`, so the bound
      // view renders its error state rather than crashing.
      setError('$error');
    }
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
  IncidentSummary? incidentById(String? id) {
    if (id == null) return null;

    for (final IncidentSummary incident in incidents) {
      if (incident.id == id) return incident;
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

      _detail = IncidentSummary.fromMap(data);
      refreshUI();
    } catch (_) {
      // Silent no-op on failure (including an unregistered `network` service):
      // the synchronous `incidentById` caller keeps its `null` detail and the
      // view renders its not-found state instead of seeing a throw.
    }
  }

  /// Active incidents: everything not yet resolved, derived from the fetched
  /// [incidents] list.
  List<IncidentSummary> get activeIncidents =>
      incidents.where((i) => i.lifecycle != IncidentLifecycle.resolved).toList();

  /// AI inbox entries. Stays empty: AI analysis attachment is deferred, so
  /// there is no wired source of AI-owned suggestions yet.
  List<IncidentSummary> get aiSuggestions => const [];

  // ---------------------------------------------------------------------------
  // Business actions (mock side-effects; write endpoints are deferred)
  // ---------------------------------------------------------------------------

  /// Surfaces the "resolved" toast for [incident].
  ///
  /// The lifecycle flip is ephemeral compose state owned by the detail view;
  /// this centralizes only the business side-effect (the toast copy). Mock:
  /// nothing persists, the write endpoint is deferred.
  void resolve(IncidentSummary incident) {
    Magic.success(trans('uptizm.incidents.detail_resolve'), incident.title);
  }

  /// Surfaces the "reopened" toast for [incident]. Mock: nothing persists.
  void reopen(IncidentSummary incident) {
    Magic.success(trans('uptizm.incidents.detail_reopen'), incident.title);
  }

  /// Surfaces the acknowledgement toast, naming the responder [by]. Mock:
  /// nothing persists.
  void acknowledge(String by) {
    Magic.success(
      trans('uptizm.incidents.detail_acknowledged_toast_title'),
      trans('uptizm.incidents.detail_acknowledged_toast_description', {
        'name': by,
      }),
    );
  }

  /// Surfaces the "update posted" toast for [incident].
  ///
  /// Clearing the composer body is ephemeral compose state owned by the
  /// detail view; this centralizes only the toast. Mock: nothing persists.
  void postUpdate(IncidentSummary incident) {
    Magic.success(
      trans('uptizm.incidents.detail_composer_post'),
      incident.title,
    );
  }

  /// Surfaces the postmortem-edit toast (resolved incidents only). Mock:
  /// nothing persists.
  void editPostmortem() {
    Magic.success(
      trans('uptizm.incidents.detail_postmortem_heading'),
      trans('uptizm.incidents.detail_postmortem_edit'),
    );
  }

  /// Completes the create flow by returning to the incidents list.
  ///
  /// Mock: nothing persists, matching the current view behavior (no toast).
  /// Manual incident authoring stays deferred until the write endpoint ships.
  void create() {
    MagicRoute.to('/incidents');
  }
}
