import 'package:magic/magic.dart';

import '../mocks/incidents.dart';
import '../mocks/incidents.dart' as incidents_fixture;

/// Controller backing the three incident screens ([IncidentsListView],
/// [IncidentDetailView], [IncidentCreateView]).
///
/// Exposes the incident fixture access and the incident business actions that
/// were previously inline in the views. The fixtures are compile-time
/// constants, so no action persists a mutation: each is the mock side-effect
/// (a `Magic.success` toast, or the create-flow navigation) the React source
/// performs. All transient compose state (the detail composer + lifecycle /
/// assignee toggles, the create form, the list filter / query) stays local to
/// its own view.
///
/// The active-incident / AI-suggestion filters delegate to the shared getters
/// in `lib/app/mocks/incidents.dart`, so this controller and
/// [DashboardController] read one source instead of re-deriving the filters.
class IncidentController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static IncidentController get instance =>
      Magic.findOrPut(IncidentController.new);

  // ---------------------------------------------------------------------------
  // Fixture access
  // ---------------------------------------------------------------------------

  /// Every incident fixture, newest-first as authored.
  List<IncidentSummary> get incidents => incidents_fixture.incidents;

  /// Resolves an incident by [id], or `null` when none matches.
  IncidentSummary? incidentById(String? id) =>
      incidents_fixture.findIncident(id);

  /// Active incidents: everything not yet resolved.
  ///
  /// Delegates to the shared `activeIncidents` getter in `incidents.dart`.
  List<IncidentSummary> get activeIncidents => incidents_fixture.activeIncidents;

  /// AI inbox entries: active incidents that carry an AI analysis payload.
  ///
  /// Delegates to the shared `aiSuggestions` getter in `incidents.dart`.
  List<IncidentSummary> get aiSuggestions => incidents_fixture.aiSuggestions;

  // ---------------------------------------------------------------------------
  // Business actions (mock side-effects)
  // ---------------------------------------------------------------------------

  /// Surfaces the "resolved" toast for [incident].
  ///
  /// The lifecycle flip is ephemeral compose state owned by the detail view;
  /// this centralizes only the business side-effect (the toast copy).
  void resolve(IncidentSummary incident) {
    Magic.success(trans('uptizm.incidents.detail_resolve'), incident.title);
  }

  /// Surfaces the "reopened" toast for [incident].
  void reopen(IncidentSummary incident) {
    Magic.success(trans('uptizm.incidents.detail_reopen'), incident.title);
  }

  /// Surfaces the acknowledgement toast, naming the responder [by].
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
  /// Clearing the composer body is ephemeral compose state owned by the detail
  /// view; this centralizes only the toast.
  void postUpdate(IncidentSummary incident) {
    Magic.success(
      trans('uptizm.incidents.detail_composer_post'),
      incident.title,
    );
  }

  /// Surfaces the postmortem-edit toast (resolved incidents only).
  void editPostmortem() {
    Magic.success(
      trans('uptizm.incidents.detail_postmortem_heading'),
      trans('uptizm.incidents.detail_postmortem_edit'),
    );
  }

  /// Completes the create flow by returning to the incidents list.
  ///
  /// Mock: nothing persists, matching the current view behavior (no toast).
  void create() {
    MagicRoute.to('/incidents');
  }
}
